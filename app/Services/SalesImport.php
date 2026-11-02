<?php

namespace App\Services;

use App\Jobs\ProcessSalesImportJob;
use App\Jobs\RefreshBrandMetricsJob;
use App\Models\Article;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\ImportRow;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Import des ventes v2 (Menentia Retail, utilisable par tous les secteurs).
 *
 * 1. upload()   : le fichier est gardé dans le stockage privé avec son
 *                 empreinte sha256 ; un fichier déjà importé est refusé
 *                 (sauf si son lot a été annulé).
 * 2. validate() : les colonnes du fichier sont associées aux champs de
 *                 Menentia (mapping, réutilisable), chaque ligne est lue et
 *                 enregistrée (import_rows) : valide, rejetée (avec code et
 *                 message par n° de ligne) ou doublon d'un ticket déjà importé.
 * 3. confirm()  : le traitement part en file d'attente (ProcessSalesImportJob).
 * 4. process()  : les lignes valides sont regroupées en tickets (purchases)
 *                 et lignes de ticket (purchase_items), par paquets, dans des
 *                 transactions ; un ticket déjà présent (même point de vente,
 *                 même clé d'origine) n'est jamais créé deux fois.
 * 5. cancel()   : un lot traité peut être annulé ; ses ventes sont
 *                 supprimées proprement (fidélité, fiches clients) et
 *                 l'action est journalisée.
 *
 * Un ticket = les lignes d'un même n° de ticket / facture dans un point de
 * vente. Sans n° de ticket : un ticket par client, par jour et par point de
 * vente ; une vente anonyme sans n° est un ticket à elle seule.
 * Champs obligatoires : date, point de vente (colonne ou fixé à l'import),
 * montant (ou quantité × prix) et famille / catégorie (colonne ou famille
 * par défaut choisie au mapping). Une famille inconnue n'est pas une erreur :
 * la ligne part dans « À classer » (cf. FamilyMatcher).
 * Sans numéro de téléphone valide (ni code client connu), la vente est
 * conservée sans créer de client : vente anonyme.
 */
class SalesImport
{
    /**
     * Champs de Menentia proposés au mapping : libellé, aide et en-têtes
     * reconnus automatiquement (comparés sans accents ni ponctuation).
     *
     * @var array<string, array{label: string, help: string, aliases: list<string>}>
     */
    public const FIELDS = [
        'date' => ['label' => 'Date', 'help' => 'Obligatoire. JJ/MM/AAAA ou AAAA-MM-JJ.', 'aliases' => ['date', 'date achat', 'date vente', 'date ticket', 'date facture', 'jour']],
        'store' => ['label' => 'Point de vente', 'help' => 'Obligatoire si aucun point de vente n’est fixé : nom ou code.', 'aliases' => ['boutique', 'magasin', 'point de vente', 'pdv', 'store', 'site', 'code boutique', 'code magasin', 'restaurant', 'agence', 'depot']],
        'receipt' => ['label' => 'N° de ticket / facture', 'help' => 'Regroupe les lignes d’un même ticket.', 'aliases' => ['ticket', 'n ticket', 'no ticket', 'numero ticket', 'facture', 'n facture', 'numero facture', 'piece', 'n piece', 'receipt', 'bon']],
        'original_receipt' => ['label' => 'Ticket d’origine (retour)', 'help' => 'Pour relier un retour au ticket vendu.', 'aliases' => ['ticket origine', 'facture origine', 'ticket initial']],
        'phone' => ['label' => 'Téléphone du client', 'help' => 'Clé de la fiche client. Vide ou invalide : vente anonyme.', 'aliases' => ['telephone', 'tel', 'mobile', 'portable', 'phone', 'whatsapp', 'numero client', 'gsm']],
        'customer_code' => ['label' => 'Code client', 'help' => 'Code du client dans votre caisse ou votre ERP.', 'aliases' => ['code client', 'n client', 'id client', 'compte client', 'customer code', 'ref client']],
        'name' => ['label' => 'Nom du client', 'help' => 'Nom, ou raison sociale pour une entreprise.', 'aliases' => ['nom', 'client', 'nom client', 'raison sociale', 'customer', 'nom prenom']],
        'customer_type' => ['label' => 'Type de client', 'help' => 'Particulier ou entreprise (pro, société, grossiste…).', 'aliases' => ['type client', 'type de client', 'categorie client', 'segment client']],
        'family' => ['label' => 'Famille', 'help' => 'Famille de l’article (ou catégorie, ou famille par défaut).', 'aliases' => ['famille', 'famille article', 'sous categorie', 'rayon', 'family']],
        'category' => ['label' => 'Catégorie', 'help' => 'Niveau au-dessus de la famille (ex. Boissons).', 'aliases' => ['categorie', 'categorie article', 'univers', 'departement', 'category']],
        'article' => ['label' => 'Article (désignation)', 'help' => 'Facultatif.', 'aliases' => ['article', 'designation', 'libelle', 'produit', 'description', 'plat', 'item']],
        'article_code' => ['label' => 'Code article', 'help' => 'Facultatif (code-barres, référence).', 'aliases' => ['code article', 'reference', 'ref', 'sku', 'ean', 'code barre', 'code produit']],
        'quantity' => ['label' => 'Quantité', 'help' => '1 par défaut.', 'aliases' => ['quantite', 'qte', 'qty', 'nombre']],
        'unit_price' => ['label' => 'Prix unitaire', 'help' => 'Montant = quantité × prix − remise si pas de montant.', 'aliases' => ['prix', 'prix unitaire', 'pu', 'prix vente', 'unit price']],
        'discount' => ['label' => 'Remise', 'help' => 'Montant de remise de la ligne.', 'aliases' => ['remise', 'reduction', 'discount', 'rabais']],
        'line_total' => ['label' => 'Montant de la ligne', 'help' => 'Montant payé (TTC) ; négatif pour un retour.', 'aliases' => ['montant', 'total', 'montant ttc', 'total ligne', 'ca', 'chiffre affaire', 'amount', 'net']],
        'line_type' => ['label' => 'Type de ligne', 'help' => 'Vente ou retour (avoir, remboursement…).', 'aliases' => ['type', 'type ligne', 'sens', 'nature', 'mouvement']],
        'payment_method' => ['label' => 'Moyen de paiement', 'help' => 'Espèces, carte, Mobile Money…', 'aliases' => ['paiement', 'mode paiement', 'moyen paiement', 'reglement', 'mode reglement']],
        'consent' => ['label' => 'Consentement WhatsApp', 'help' => 'Oui / non, recueilli en caisse.', 'aliases' => ['consentement', 'consentement whatsapp', 'opt in', 'optin']],
    ];

    /** Messages d'erreur par code (rapport d'erreurs). */
    public const ERRORS = [
        'date_missing' => 'Date manquante',
        'date_invalid' => 'Date illisible ou dans le futur',
        'store_missing' => 'Point de vente manquant',
        'store_unknown' => 'Point de vente inconnu ou hors de votre périmètre',
        'amount_missing' => 'Ni montant, ni prix renseigné',
        'amount_invalid' => 'Montant illisible',
        'family_missing' => 'Famille ou catégorie manquante (choisissez une famille par défaut si le fichier n’en a pas)',
        'duplicate' => 'Ticket déjà importé',
    ];

    /** Colonnes libres gardées comme attributs génériques de la vente (au-delà : ignorées). */
    private const MAX_PROPERTIES = 10;

    private const SAMPLE_ROWS = 5;

    private const TICKETS_PER_TRANSACTION = 200;

    /**
     * Dépose un fichier : stockage privé, empreinte, lecture des en-têtes et
     * mapping proposé (dernier mapping enregistré dont les colonnes
     * correspondent, sinon reconnaissance automatique des en-têtes).
     *
     * @throws ValidationException fichier déjà importé ou illisible
     */
    public function upload(UploadedFile $file, User $user, ?int $brandId = null, ?int $storeId = null): ImportBatch
    {
        $hash = hash_file('sha256', $file->getRealPath());

        $previous = ImportBatch::where('organization_id', $user->organization_id)
            ->where('file_hash', $hash)
            ->whereNotIn('status', [ImportBatch::STATUS_CANCELLED, ImportBatch::STATUS_FAILED])
            ->first();

        if ($previous) {
            throw ValidationException::withMessages([
                'file' => "Ce fichier a déjà été déposé le {$previous->created_at->format('d/m/Y à H:i')} (import n° {$previous->id}, {$previous->statusLabel()}). Annulez cet import pour le déposer à nouveau.",
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension()) ?: 'csv';
        $path = $file->storeAs("imports/{$user->organization_id}", Str::uuid().'.'.$extension, 'local');

        $rows = SpreadsheetReader::readPath(Storage::disk('local')->path($path), $extension);
        $headers = array_map(fn ($h) => trim((string) $h), $rows[0] ?? []);

        if (count(array_filter($headers, fn ($h) => $h !== '')) === 0) {
            Storage::disk('local')->delete($path);

            throw ValidationException::withMessages(['file' => 'Le fichier est vide ou sa première ligne ne contient pas de titres de colonnes.']);
        }

        $batch = ImportBatch::create([
            'organization_id' => $user->organization_id,
            'brand_id' => $brandId,
            'store_id' => $storeId,
            'user_id' => $user->id,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_path' => $path,
            'file_hash' => $hash,
            'headers' => $headers,
            'mapping' => $this->suggestMapping($headers, $user->organization_id),
            'status' => ImportBatch::STATUS_MAPPING_REQUIRED,
            'total_rows' => count(array_filter(array_slice($rows, 1), fn ($row) => ! self::isEmptyRow($row))),
        ]);

        Audit::record('import.created', $batch, "Fichier « {$batch->original_filename} » déposé ({$batch->total_rows} ligne(s))", ['hash' => $hash], $user);

        return $batch;
    }

    /**
     * Mapping proposé : un mapping enregistré dont toutes les colonnes
     * existent dans le fichier, sinon les en-têtes reconnus.
     *
     * @param  list<string>  $headers
     * @return array<string, int> champ => n° de colonne
     */
    public function suggestMapping(array $headers, int $organizationId): array
    {
        $byName = array_flip(array_map(fn ($h) => self::normalizeHeader($h), $headers));

        foreach (ImportMapping::where('organization_id', $organizationId)->latest('updated_at')->get() as $saved) {
            $mapping = [];

            foreach ((array) $saved->mapping as $field => $header) {
                if (! isset($byName[self::normalizeHeader((string) $header)])) {
                    continue 2;
                }
                $mapping[$field] = $byName[self::normalizeHeader((string) $header)];
            }

            if ($mapping !== []) {
                return $mapping;
            }
        }

        $mapping = [];

        foreach (self::FIELDS as $field => $definition) {
            foreach ($definition['aliases'] as $alias) {
                if (isset($byName[$alias]) && ! in_array($byName[$alias], $mapping, true)) {
                    $mapping[$field] = $byName[$alias];
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Premières lignes du fichier pour l'écran de mapping.
     *
     * @return list<list<string>>
     */
    public function sample(ImportBatch $batch): array
    {
        return array_map(
            fn ($row) => array_map(fn ($cell) => mb_substr(trim((string) $cell), 0, 60), $row),
            array_slice(array_values(array_filter(array_slice($this->readRows($batch), 1), fn ($row) => ! self::isEmptyRow($row))), 0, self::SAMPLE_ROWS),
        );
    }

    /**
     * Enregistre le mapping et vérifie tout le fichier, ligne par ligne :
     * aucune vente n'est encore créée.
     *
     * @param  array<string, int|null>  $mapping  champ => n° de colonne
     */
    public function validate(ImportBatch $batch, array $mapping, ?int $defaultCategoryId, User $user, ?string $saveAs = null): ImportBatch
    {
        $headers = $batch->headers ?? [];
        $mapping = array_filter(
            array_intersect_key($mapping, self::FIELDS),
            fn ($index) => $index !== null && $index !== '' && array_key_exists((int) $index, $headers),
        );
        $mapping = array_map('intval', $mapping);

        if ($saveAs !== null && trim($saveAs) !== '') {
            $named = array_map(fn ($index) => $headers[$index], $mapping);
            $existing = ImportMapping::where('organization_id', $batch->organization_id)->where('name', trim($saveAs))->first();
            $saved = $existing
                ? tap($existing)->update(['mapping' => $named, 'version' => $existing->version + 1, 'brand_id' => $batch->brand_id])
                : ImportMapping::create(['organization_id' => $batch->organization_id, 'brand_id' => $batch->brand_id, 'name' => trim($saveAs), 'mapping' => $named]);
            $batch->mapping_id = $saved->id;
        }

        $batch->fill(['mapping' => $mapping, 'default_category_id' => $defaultCategoryId])->save();
        $batch->rows()->delete();

        $context = $this->context($batch, $user);
        $rows = $this->readRows($batch);
        array_shift($rows);

        $records = [];
        $occurrences = [];
        $counts = ['valid' => 0, 'rejected' => 0, 'duplicate' => 0];
        $ticketKeys = [];

        foreach ($rows as $i => $row) {
            if (self::isEmptyRow($row)) {
                continue;
            }

            $rowNumber = $i + 2;
            $payload = [];
            foreach ($headers as $index => $header) {
                $payload[$header !== '' ? $header : 'Colonne '.($index + 1)] = trim((string) ($row[$index] ?? ''));
            }

            [$data, $error] = $this->parseRow($row, $mapping, $context);

            if ($data !== null && ! str_starts_with($data['source_key'], 'r:') && ! str_starts_with($data['source_key'], 'd:')) {
                // Vente anonyme sans n° de ticket : l'empreinte de la ligne, numérotée si elle se répète.
                $fingerprint = sha1(json_encode($payload));
                $occurrences[$fingerprint] = ($occurrences[$fingerprint] ?? 0) + 1;
                $data['source_key'] = 'a:'.$data['date'].':'.$fingerprint.':'.$occurrences[$fingerprint];
            }

            $status = $error !== null ? ImportRow::STATUS_REJECTED : ImportRow::STATUS_VALID;
            $counts[$status]++;

            if ($data !== null) {
                $ticketKeys[$data['store_id']][$data['source_key']] = true;
            }

            $records[] = [
                'import_batch_id' => $batch->id,
                'row_number' => $rowNumber,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'data' => $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
                'status' => $status,
                'error_code' => $error,
                'error_message' => $error !== null ? self::ERRORS[$error] : null,
            ];

            if (count($records) >= 500) {
                ImportRow::insert($records);
                $records = [];
            }
        }

        if ($records !== []) {
            ImportRow::insert($records);
        }

        // Tickets déjà présents (autre import, saisie…) : leurs lignes sont des doublons.
        foreach ($ticketKeys as $storeId => $keys) {
            foreach (array_chunk(array_keys($keys), 500) as $chunk) {
                $existing = Purchase::withCancelled()->where('store_id', $storeId)->whereIn('source_key', $chunk)->pluck('source_key')->all();

                if ($existing === []) {
                    continue;
                }

                $batch->rows()->where('status', ImportRow::STATUS_VALID)->get(['id', 'data'])
                    ->filter(fn (ImportRow $row) => $row->data['store_id'] === $storeId && in_array($row->data['source_key'], $existing, true))
                    ->each(function (ImportRow $row) use (&$counts) {
                        $row->update(['status' => ImportRow::STATUS_DUPLICATE, 'error_code' => 'duplicate', 'error_message' => self::ERRORS['duplicate']]);
                        $counts['valid']--;
                        $counts['duplicate']++;
                    });
            }
        }

        $batch->update([
            'status' => ImportBatch::STATUS_VALIDATED,
            'total_rows' => array_sum($counts),
            'accepted_rows' => $counts['valid'],
            'rejected_rows' => $counts['rejected'],
            'duplicate_rows' => $counts['duplicate'],
        ]);

        return $batch;
    }

    /**
     * Résumé de la vérification : tickets à créer, montants, anonymes,
     * lignes à classer, nouveaux clients, exemples de tickets.
     *
     * @return array<string, mixed>
     */
    public function preview(ImportBatch $batch): array
    {
        $valid = $batch->rows()->where('status', ImportRow::STATUS_VALID)->orderBy('row_number')->get(['row_number', 'data']);
        $tickets = $valid->groupBy(fn (ImportRow $row) => $row->data['store_id'].'|'.$row->data['source_key']);
        $context = $this->context($batch, $batch->user);
        $stores = Store::whereIn('id', $valid->pluck('data.store_id')->unique())->pluck('name', 'id');

        $phones = $valid->pluck('data.phone')->filter()->unique();
        $known = $phones->isEmpty() ? collect() : Customer::where('organization_id', $batch->organization_id)->whereIn('phone_e164', $phones)->pluck('phone_e164');

        $context['parents'] = array_fill_keys(Category::where('organization_id', $batch->organization_id)->pluck('id')->all(), true);
        $unclassified = $valid->filter(fn (ImportRow $row) => $this->categoryFor($row->data, $context) === null);

        return [
            'tickets' => $tickets->count(),
            'lines' => $valid->count(),
            'returns' => $tickets->filter(fn ($rows) => $rows->sum('data.amount') < 0)->count(),
            'amount' => (int) $valid->sum('data.amount'),
            'anonymous' => $tickets->filter(fn ($rows) => $rows->every(fn ($row) => $row->data['phone'] === null && $row->data['customer_code'] === null))->count(),
            'new_customers' => $phones->diff($known)->count(),
            'unclassified' => $unclassified->count(),
            'period' => $valid->isEmpty() ? null : ['from' => $valid->min('data.date'), 'to' => $valid->max('data.date')],
            'sample' => $tickets->take(6)->map(fn (Collection $rows) => [
                'date' => $rows->first()->data['date'],
                'store' => $stores[$rows->first()->data['store_id']] ?? '—',
                'receipt' => $rows->first()->data['receipt'],
                'customer' => $rows->first()->data['name'] ?? $rows->first()->data['phone'] ?? $rows->first()->data['customer_code'],
                'anonymous' => $rows->every(fn ($row) => $row->data['phone'] === null && $row->data['customer_code'] === null),
                'lines' => $rows->count(),
                'amount' => (int) $rows->sum('data.amount'),
            ])->values()->all(),
        ];
    }

    /** Lance le traitement en file d'attente. */
    public function confirm(ImportBatch $batch): void
    {
        $batch->update(['status' => ImportBatch::STATUS_PROCESSING, 'started_at' => now()]);

        ProcessSalesImportJob::dispatch($batch->id);
    }

    /**
     * Crée les tickets du lot (appelé par ProcessSalesImportJob). Rejouable :
     * un ticket déjà créé n'est pas recréé.
     */
    public function process(ImportBatch $batch): void
    {
        $batch->refresh();

        if ($batch->status !== ImportBatch::STATUS_PROCESSING) {
            return;
        }

        $user = $batch->user;
        $customersBefore = Customer::where('organization_id', $batch->organization_id)->count();
        $context = $this->context($batch, $user);
        $stats = ['tickets' => 0, 'lines' => 0, 'anonymous' => 0, 'unclassified' => 0, 'duplicates' => 0, 'to_review' => 0];

        try {
            CustomerLifecycle::batch(function () use ($batch, &$context, &$stats) {
                $groups = $batch->rows()->where('status', ImportRow::STATUS_VALID)->orderBy('row_number')->get()
                    ->groupBy(fn (ImportRow $row) => $row->data['store_id'].'|'.$row->data['source_key']);

                foreach ($groups->chunk(self::TICKETS_PER_TRANSACTION) as $chunk) {
                    DB::transaction(function () use ($chunk, $batch, &$context, &$stats) {
                        foreach ($chunk as $rows) {
                            $this->createTicket($rows, $batch, $context, $stats);
                        }
                    });
                }
            });

            app(CustomerScoring::class)->refreshOrganization($batch->organization);
            RefreshBrandMetricsJob::dispatch($batch->organization_id);

            $hasErrors = $batch->rejected_rows > 0 || $batch->duplicate_rows + $stats['duplicates'] > 0;
            $batch->update([
                'status' => $hasErrors ? ImportBatch::STATUS_COMPLETED_WITH_ERRORS : ImportBatch::STATUS_COMPLETED,
                'tickets_count' => $stats['tickets'],
                'lines_count' => $stats['lines'],
                'anonymous_tickets' => $stats['anonymous'],
                'unclassified_lines' => $stats['unclassified'],
                'duplicate_rows' => $batch->duplicate_rows + $stats['duplicates'],
                'accepted_rows' => $batch->accepted_rows - $stats['duplicates'],
                'customers_created' => max(0, Customer::where('organization_id', $batch->organization_id)->count() - $customersBefore),
                'completed_at' => now(),
            ]);

            Audit::record('import.completed', $batch, "Import n° {$batch->id} : {$stats['tickets']} ticket(s), {$stats['lines']} ligne(s)", $stats, $user, $batch->organization_id);
        } catch (Throwable $e) {
            report($e);
            $batch->update(['status' => ImportBatch::STATUS_FAILED, 'error_message' => mb_substr($e->getMessage(), 0, 1000), 'completed_at' => now()]);
        }
    }

    /**
     * Annule un lot. Lot traité : ses ventes sont supprimées (points de
     * fidélité retirés, fiches recalculées) ; le fichier peut ensuite être
     * déposé à nouveau. Toujours journalisé.
     */
    public function cancel(ImportBatch $batch, User $user): int
    {
        $deleted = 0;

        if ($batch->isProcessed() || $batch->status === ImportBatch::STATUS_FAILED) {
            CustomerLifecycle::batch(function () use ($batch, &$deleted) {
                $batch->purchases()->chunkById(200, function ($purchases) use (&$deleted) {
                    DB::transaction(function () use ($purchases, &$deleted) {
                        foreach ($purchases as $purchase) {
                            $purchase->delete();
                            $deleted++;
                        }
                    });
                });
            });

            app(CustomerScoring::class)->refreshOrganization($batch->organization);
            RefreshBrandMetricsJob::dispatch($batch->organization_id);
        }

        $batch->update(['status' => ImportBatch::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $user->id]);

        Audit::record('import.cancelled', $batch, "Import n° {$batch->id} annulé : {$deleted} vente(s) supprimée(s)", ['deleted' => $deleted, 'file' => $batch->original_filename], $user);

        return $deleted;
    }

    /**
     * Rapport d'erreurs : lignes rejetées et doublons, avec leur contenu
     * d'origine, en CSV (séparateur « ; » pour Excel en français) ou XLSX.
     *
     * @return array{0: list<string>, 1: list<list<string|int>>} en-têtes, lignes
     */
    public function errorRows(ImportBatch $batch): array
    {
        $headers = ['N° de ligne', 'Statut', 'Code', 'Erreur', ...array_map(fn ($h, $i) => $h !== '' ? $h : 'Colonne '.($i + 1), $batch->headers ?? [], array_keys($batch->headers ?? []))];
        $lines = [];

        $batch->rows()->whereIn('status', [ImportRow::STATUS_REJECTED, ImportRow::STATUS_DUPLICATE])->orderBy('row_number')
            ->each(function (ImportRow $row) use (&$lines) {
                $lines[] = [
                    $row->row_number,
                    $row->status === ImportRow::STATUS_DUPLICATE ? 'Doublon' : 'Rejetée',
                    (string) $row->error_code,
                    (string) $row->error_message,
                    ...array_values($row->payload),
                ];
            });

        return [$headers, $lines];
    }

    /**
     * @param  Collection<int, ImportRow>  $rows  lignes d'un même ticket
     * @param  array<string, mixed>  $context
     * @param  array<string, int>  $stats
     */
    private function createTicket(Collection $rows, ImportBatch $batch, array &$context, array &$stats): void
    {
        $first = $rows->first()->data;

        if (Purchase::withCancelled()->where('store_id', $first['store_id'])->where('source_key', $first['source_key'])->exists()) {
            $rows->each(fn (ImportRow $row) => $row->update(['status' => ImportRow::STATUS_DUPLICATE, 'error_code' => 'duplicate', 'error_message' => self::ERRORS['duplicate']]));
            $stats['duplicates'] += $rows->count();

            return;
        }

        $identity = $rows->first(fn (ImportRow $row) => $row->data['phone'] !== null || $row->data['customer_code'] !== null)?->data ?? $first;
        [$contact, $review] = $this->resolveContact($identity, $batch);

        $lines = $rows->map(function (ImportRow $row) use (&$context) {
            // Module Catalogue : la ligne est rattachée au produit (nom ou code) et à sa catégorie.
            $item = $this->catalogItemFor($row->data, $context);

            return [
                'row' => $row,
                'data' => $row->data,
                'catalog_item' => $item,
                'category_id' => $item?->category_id ?? $this->categoryFor($row->data, $context),
                'label' => $row->data['family'] ?? $row->data['category'] ?? $item?->category?->name,
            ];
        });

        $amount = (int) $lines->sum(fn ($line) => $line['data']['amount']);
        $main = $lines->sortByDesc(fn ($line) => abs($line['data']['amount']))->first();
        $isReturn = $amount < 0 || $lines->every(fn ($line) => $line['data']['is_return']);

        $original = $first['original_receipt'] !== null
            ? Purchase::where('store_id', $first['store_id'])->where('receipt_number', $first['original_receipt'])->value('id')
            : null;

        $purchase = Purchase::create([
            'contact_id' => $contact?->id,
            'store_id' => $first['store_id'],
            'amount' => $amount,
            'quantity' => (int) $lines->sum(fn ($line) => $line['data']['quantity']),
            'subtotal' => (int) $lines->sum(fn ($line) => $line['data']['amount'] + $line['data']['discount']),
            'discount_amount' => (int) $lines->sum(fn ($line) => $line['data']['discount']),
            'currency' => $context['currency'],
            'category_id' => $main['category_id'],
            'product_category' => $main['label'],
            'article' => $lines->count() === 1 ? $main['data']['article'] : null,
            'properties' => $first['properties'] ?: null,
            'purchased_at' => $first['date'],
            'payment_method' => $first['payment_method'],
            'receipt_number' => $first['receipt'],
            'source_key' => $first['source_key'],
            'type' => $isReturn ? Purchase::TYPE_RETURN : Purchase::TYPE_SALE,
            'import_batch_id' => $batch->id,
            'source' => 'import',
            'original_purchase_id' => $original,
            'customer_code' => $identity['customer_code'],
            'buyer_name' => $contact === null ? $identity['name'] : null,
        ]);

        if ($review !== null) {
            app(CustomerIdentity::class)->queue($review['customer'], $first['store_id'], $identity['name'], $review['reason'], [$purchase->id], $batch->id);
            $stats['to_review']++;
        }

        foreach ($lines as $line) {
            $data = $line['data'];

            PurchaseItem::create([
                'purchase_id' => $purchase->id,
                'catalog_item_id' => $line['catalog_item']?->id,
                'type' => $line['catalog_item']?->type,
                'name' => mb_substr($data['article'] ?? $line['catalog_item']?->name ?? $line['label'] ?? 'Vente', 0, 255),
                'quantity' => $data['quantity'],
                'unit_price' => $data['unit_price'] ?? ($data['quantity'] !== 0 ? intdiv($data['amount'] + $data['discount'], $data['quantity']) : $data['amount']),
                'amount' => $data['amount'],
                'discount_amount' => $data['discount'],
                'category_id' => $line['category_id'],
                'product_category' => $line['label'],
                'article_id' => $this->articleId($data, $line['category_id'], $context),
                'article_code' => $data['article_code'],
                'properties' => $data['properties'] ?: null,
                'import_row_id' => $line['row']->id,
            ]);

            $stats['lines']++;
            $stats['unclassified'] += $line['category_id'] === null ? 1 : 0;
        }

        $rows->each(fn (ImportRow $row) => $row->update(['status' => ImportRow::STATUS_IMPORTED]));
        $stats['tickets']++;
        $stats['anonymous'] += $contact === null && $review === null ? 1 : 0;
    }

    /**
     * Produit du catalogue d'une ligne (module Catalogue) : par code
     * (référence), sinon par libellé de l'article, sans tenir compte des
     * accents ni des majuscules.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    private function catalogItemFor(array $data, array $context): ?CatalogItem
    {
        if ($context['catalog'] === null) {
            return null;
        }

        if ($data['article_code'] !== null && isset($context['catalog']['by_code'][mb_strtoupper(trim($data['article_code']))])) {
            return $context['catalog']['by_code'][mb_strtoupper(trim($data['article_code']))];
        }

        return $data['article'] !== null ? ($context['catalog']['by_name'][FamilyMatcher::normalize($data['article'])] ?? null) : null;
    }

    /**
     * Famille d'une ligne : famille du fichier, sinon sa catégorie, reconnue
     * par FamilyMatcher (avec l'article) ; sans libellé, la famille par
     * défaut du lot. Libellé inconnu : null, la ligne part dans « À classer ».
     * Famille et catégorie reconnues toutes deux : la famille est rangée sous
     * la catégorie si elle n'en a pas encore (hiérarchie catégorie › famille).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    private function categoryFor(array $data, array &$context): ?int
    {
        $matcher = $context['matcher'];
        $label = $data['family'] ?? $data['category'];

        if ($label === null && $data['article'] === null) {
            return $context['default_category_id'];
        }

        $categoryId = $matcher->match($label, $data['article']);

        if ($data['family'] !== null && $data['category'] !== null) {
            $parentId = $matcher->match($data['category'], null);

            if ($categoryId === null) {
                return $parentId;
            }

            if ($parentId !== null && $parentId !== $categoryId && ! isset($context['parents'][$categoryId])) {
                Category::whereKey($categoryId)->whereNull('parent_id')->update(['parent_id' => $parentId]);
                $context['parents'][$categoryId] = true;
            }
        }

        return $categoryId ?? ($label === null ? $context['default_category_id'] : null);
    }

    /**
     * Fiche client de la vente : par numéro de téléphone (le contact du point
     * de vente est créé au besoin), sinon par code client déjà connu. Sans
     * l'un ni l'autre : vente anonyme, aucun client n'est créé.
     * Numéro connu mais nom très différent, ou numéro partagé : pas de
     * rattachement automatique, la vente part dans « À vérifier ».
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Contact|null, 1: array{customer: Customer, reason: string}|null}
     */
    protected function resolveContact(array $data, ImportBatch $batch): array
    {
        $phone = $data['phone'];

        if ($phone === null && $data['customer_code'] !== null) {
            $phone = Customer::where('organization_id', $batch->organization_id)->where('external_code', $data['customer_code'])->value('phone_e164');
        } elseif ($phone !== null) {
            $known = Customer::where('organization_id', $batch->organization_id)->where('phone_e164', $phone)->first();
            $reason = $known ? app(CustomerIdentity::class)->reviewReason($known, $data['name']) : null;

            // Le code client de la caisse confirme l'identité.
            if ($reason !== null && ! ($data['customer_code'] !== null && $known->external_code === $data['customer_code'])) {
                return [null, ['customer' => $known, 'reason' => $reason]];
            }
        }

        if ($phone === null) {
            return [null, null];
        }

        $contact = Contact::where('store_id', $data['store_id'])->where('phone_e164', $phone)->first();

        if (! $contact) {
            $contact = Contact::create([
                'store_id' => $data['store_id'],
                'phone_e164' => $phone,
                'name' => $data['name'] ?? "Client {$phone}",
                'consent_status' => $data['consent'] ?? 'unknown',
                'consent_source' => $data['consent'] !== null ? 'import' : null,
            ]);
        } elseif ($data['consent'] !== null && $data['consent'] !== $contact->consent_status) {
            $contact->update(['consent_status' => $data['consent'], 'consent_source' => 'import']);
        }

        $this->completeCustomer($contact, $data);

        return [$contact, null];
    }

    /**
     * Complète la fiche : entreprise (raison sociale), code client de la
     * caisse. Ne remplace jamais une information déjà saisie.
     *
     * @param  array<string, mixed>  $data
     */
    private function completeCustomer(Contact $contact, array $data): void
    {
        $customer = $contact->customer;

        if (! $customer) {
            return;
        }

        $changes = [];

        if ($data['customer_type'] === Customer::TYPE_BUSINESS && $customer->type !== Customer::TYPE_BUSINESS) {
            $changes['type'] = Customer::TYPE_BUSINESS;
            $changes['company_name'] = $customer->company_name ?: $data['name'];
        }

        if ($data['customer_code'] !== null && blank($customer->external_code)) {
            $changes['external_code'] = $data['customer_code'];
        }

        if ($changes !== []) {
            $customer->update($changes);
        }
    }

    /**
     * Article vu dans le fichier : créé au premier passage (dédoublonné par
     * code, sinon par libellé normalisé) et rangé dans la famille de la ligne.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    private function articleId(array $data, ?int $categoryId, array &$context): ?int
    {
        $key = Article::matchKey($data['article_code'], $data['article']);

        if ($key === null) {
            return null;
        }

        if (! array_key_exists($key, $context['articles'])) {
            $article = Article::firstOrCreate(
                ['organization_id' => $context['organization_id'], 'match_key' => $key],
                [
                    'code' => $data['article_code'],
                    'label' => mb_substr($data['article'] ?? $data['article_code'], 0, 255),
                    'normalized_label' => mb_substr(FamilyMatcher::normalize($data['article'] ?? $data['article_code']), 0, 255),
                    'category_id' => $categoryId,
                    'first_seen_at' => now(),
                ],
            );

            if ($article->category_id === null && $categoryId !== null) {
                $article->update(['category_id' => $categoryId]);
            }

            $context['articles'][$key] = $article->id;
            Article::whereKey($article->id)->update(['last_seen_at' => now()]);
        }

        return $context['articles'][$key];
    }

    /**
     * Lit une ligne du fichier selon le mapping.
     *
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $mapping
     * @param  array<string, mixed>  $context
     * @return array{0: array<string, mixed>|null, 1: string|null} données, code d'erreur
     */
    public function parseRow(array $row, array $mapping, array $context): array
    {
        $value = function (string $field) use ($row, $mapping): ?string {
            if (! isset($mapping[$field])) {
                return null;
            }
            $cell = trim(preg_replace('/\s+/u', ' ', (string) ($row[$mapping[$field]] ?? '')));

            return $cell === '' ? null : $cell;
        };

        $rawDate = $value('date');
        if ($rawDate === null) {
            return [null, 'date_missing'];
        }
        $date = ImportedDate::parse($rawDate);
        if ($date === null) {
            return [null, 'date_invalid'];
        }

        $storeId = $context['store_id'];
        if ($storeId === null) {
            $rawStore = $value('store');
            if ($rawStore === null) {
                return [null, 'store_missing'];
            }
            $storeId = $context['stores_by_code'][mb_strtoupper($rawStore)] ?? $context['stores_by_name'][mb_strtolower($rawStore)] ?? null;
            if ($storeId === null) {
                return [null, 'store_unknown'];
            }
        }

        $quantity = self::parseNumber($value('quantity'));
        $quantity = $quantity === null || (int) round($quantity) === 0 ? 1 : (int) round($quantity);
        $unitPrice = self::parseNumber($value('unit_price'));
        $discount = (int) round(abs(self::parseNumber($value('discount')) ?? 0));
        $lineTotal = self::parseNumber($value('line_total'));

        if ($value('line_total') !== null && $lineTotal === null) {
            return [null, 'amount_invalid'];
        }
        if ($lineTotal === null && $unitPrice === null) {
            return [null, $value('unit_price') !== null ? 'amount_invalid' : 'amount_missing'];
        }

        $amount = (int) round($lineTotal ?? ($quantity * $unitPrice - $discount));
        $isReturn = self::isReturn($value('line_type')) || $amount < 0 || $quantity < 0;

        if ($isReturn) {
            $amount = -abs($amount);
            $quantity = -abs($quantity);
        }

        $family = $value('family');
        $category = $value('category');
        // Famille facultative sans le module Familles, ou quand l'article vient du catalogue.
        $familyOptional = ! $context['family_required'] || ($context['catalog'] !== null && ($value('article') !== null || $value('article_code') !== null));
        if ($family === null && $category === null && $context['default_category_id'] === null && ! $familyOptional) {
            return [null, 'family_missing'];
        }

        $phone = $value('phone') !== null ? PhoneNormalizer::normalize($value('phone')) : null;
        $customerCode = $value('customer_code') !== null ? mb_substr($value('customer_code'), 0, 100) : null;
        $receipt = $value('receipt') !== null ? mb_substr($value('receipt'), 0, 100) : null;
        $consent = ImportedConsent::parse($value('consent'));

        $properties = [];
        foreach ($context['property_columns'] as $index => $key) {
            $propertyValue = PurchaseImporter::propertyValue($row[$index] ?? null);
            if ($propertyValue !== null) {
                $properties[$key] = $propertyValue;
            }
        }

        $identity = $phone ?? ($customerCode !== null ? 'code:'.$customerCode : null);

        return [[
            'date' => $date,
            'store_id' => $storeId,
            'receipt' => $receipt,
            'original_receipt' => $value('original_receipt'),
            // Clé du ticket : n° de ticket, sinon client + jour ; une vente anonyme sans n° reçoit
            // l'empreinte de sa ligne (cf. validate()).
            'source_key' => $receipt !== null ? 'r:'.$receipt : ($identity !== null ? 'd:'.$date.':'.$identity : 'a:'),
            'phone' => $phone,
            'phone_invalid' => $value('phone') !== null && $phone === null,
            'customer_code' => $customerCode,
            'name' => $value('name') !== null ? mb_substr($value('name'), 0, 255) : null,
            'customer_type' => self::isBusiness($value('customer_type')) ? Customer::TYPE_BUSINESS : null,
            'family' => $family !== null ? mb_substr($family, 0, 255) : null,
            'category' => $category !== null ? mb_substr($category, 0, 255) : null,
            'article' => $value('article') !== null ? mb_substr($value('article'), 0, 255) : null,
            'article_code' => $value('article_code') !== null ? mb_substr($value('article_code'), 0, 100) : null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice !== null ? (int) round(abs($unitPrice)) : null,
            'discount' => $discount,
            'amount' => $amount,
            'is_return' => $isReturn,
            'payment_method' => self::paymentMethod($value('payment_method')),
            'consent' => $consent === false ? null : $consent,
            'properties' => $properties,
        ], null];
    }

    /**
     * Données fixes d'un lot : points de vente autorisés (périmètre de
     * l'utilisateur, marque fixée), colonnes libres, famille par défaut.
     *
     * @return array<string, mixed>
     */
    private function context(ImportBatch $batch, ?User $user): array
    {
        $visible = $user?->visibleStoreIds();
        $stores = Store::where('organization_id', $batch->organization_id)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->when($batch->brand_id !== null, fn ($q) => $q->where('brand_id', $batch->brand_id))
            ->get(['id', 'name', 'code']);

        $catalog = null;
        if ($batch->organization?->hasModule('catalogue')) {
            $items = CatalogItem::where('organization_id', $batch->organization_id)->with('category:id,name')->get();
            $catalog = [
                'by_code' => $items->filter(fn ($i) => filled($i->reference))->keyBy(fn ($i) => mb_strtoupper(trim($i->reference)))->all(),
                'by_name' => $items->keyBy(fn ($i) => FamilyMatcher::normalize($i->name))->all(),
            ];
        }

        $mapped = array_flip(array_values($batch->mapping ?? []));
        $propertyColumns = collect($batch->headers ?? [])
            ->reject(fn ($header, $index) => $header === '' || isset($mapped[$index]))
            ->map(fn ($header) => Str::slug($header, '_'))
            ->filter(fn ($key) => $key !== '')
            ->unique()
            ->take(self::MAX_PROPERTIES)
            ->all();

        return [
            'organization_id' => $batch->organization_id,
            'store_id' => $batch->store_id !== null && $stores->contains('id', $batch->store_id) ? $batch->store_id : null,
            'stores_by_code' => $stores->filter(fn ($s) => filled($s->code))->mapWithKeys(fn ($s) => [mb_strtoupper($s->code) => $s->id])->all(),
            'stores_by_name' => $stores->mapWithKeys(fn ($s) => [mb_strtolower($s->name) => $s->id])->all(),
            'property_columns' => $propertyColumns,
            'default_category_id' => $batch->default_category_id !== null
                ? Category::where('organization_id', $batch->organization_id)->whereKey($batch->default_category_id)->value('id')
                : null,
            'matcher' => FamilyMatcher::for($batch->organization_id),
            'catalog' => $catalog,
            'family_required' => $batch->organization?->hasModule('familles') ?? true,
            'currency' => $batch->organization?->currency ?? 'XAF',
            'articles' => [],
            'parents' => [],
        ];
    }

    /** @return array<int, array<int, mixed>> */
    private function readRows(ImportBatch $batch): array
    {
        $path = Storage::disk('local')->path($batch->file_path);

        return SpreadsheetReader::readPath($path, strtolower(pathinfo($batch->file_path, PATHINFO_EXTENSION)));
    }

    /** @param array<int, mixed> $row */
    private static function isEmptyRow(array $row): bool
    {
        return count(array_filter($row, fn ($cell) => trim((string) $cell) !== '')) === 0;
    }

    public static function normalizeHeader(string $header): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($header))));
    }

    /**
     * « 12 500 », « 12.500 », « 12,500.00 », « 12500,50 », « -3 000 FCFA » → nombre.
     */
    public static function parseNumber(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $text = str_replace(["\u{00A0}", "\u{202F}", ' '], '', $raw);
        $negative = str_starts_with($text, '-') || (str_starts_with($text, '(') && str_ends_with($text, ')'));
        $text = preg_replace('/[^0-9.,]/', '', $text);

        if ($text === '' || ! preg_match('/\d/', $text)) {
            return null;
        }

        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Le dernier séparateur est le décimal.
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $text = str_replace($decimal === ',' ? '.' : ',', '', $text);
            $text = str_replace(',', '.', $text);
        } elseif ($lastComma !== false || $lastDot !== false) {
            $separator = $lastComma !== false ? ',' : '.';
            $parts = explode($separator, $text);
            // Séparateur de milliers (12.500, 1,250,000) ou décimal (12,5) ?
            $thousands = count($parts) > 2 || strlen(end($parts)) === 3;
            $text = $thousands ? str_replace($separator, '', $text) : str_replace(',', '.', $text);
        }

        $number = (float) $text;

        return $negative ? -$number : $number;
    }

    private static function isReturn(?string $value): bool
    {
        $text = FamilyMatcher::normalize($value);

        return $text !== '' && preg_match('/\b(retour|return|avoir|rembour\w*|refund|annul\w*|r)\b/', $text) === 1;
    }

    private static function isBusiness(?string $value): bool
    {
        $text = FamilyMatcher::normalize($value);

        return $text !== '' && preg_match('/\b(entreprise|societe|pro|professionnel|b2b|grossiste|revendeur|distributeur|company|business|sarl|sa|sas|ets|etablissement)\b/', $text) === 1;
    }

    private static function paymentMethod(?string $value): ?string
    {
        $text = FamilyMatcher::normalize($value);

        return match (true) {
            $text === '' => null,
            str_contains($text, 'airtel') => 'airtel_money',
            str_contains($text, 'moov') => 'moov_money',
            str_contains($text, 'mtn') || str_contains($text, 'momo') => 'mtn_momo',
            str_contains($text, 'orange') => 'orange_money',
            str_contains($text, 'espece') || str_contains($text, 'cash') || str_contains($text, 'liquide') => 'cash',
            str_contains($text, 'carte') || str_contains($text, 'card') || str_contains($text, 'cb') || str_contains($text, 'visa') => 'card',
            str_contains($text, 'virement') || str_contains($text, 'transfer') || str_contains($text, 'cheque') => 'transfer',
            default => 'other',
        };
    }
}
