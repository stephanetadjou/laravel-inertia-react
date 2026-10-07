<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Purchase;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Importe un fichier CSV ou Excel (xlsx/xls) de contacts (et, optionnellement,
 * leur historique d'achats) pour une boutique donnée.
 *
 * Champs (cf. FIELDS) : nom et téléphone obligatoires ; achat (catégorie,
 * montant, date), profil (naissance, sexe) et consentement (oui / non, date
 * et source de l'accord) facultatifs. Chaque champ est associé à une colonne
 * du fichier : reconnue d'après son en-tête (synonymes, sans accents ni
 * casse), ou choisie par l'utilisateur dans l'aperçu (mapping). Les CSV
 * séparés par « , », « ; » ou tabulation sont acceptés (cf. SpreadsheetReader).
 */
class ContactImporter
{
    private const SAMPLE_SIZE = 5;

    /**
     * Champs importables : libellé, obligatoire, en-têtes reconnus d'office.
     *
     * @var array<string, array{label: string, required: bool, aliases: array<int, string>}>
     */
    public const FIELDS = [
        'nom' => ['label' => 'Nom', 'required' => true, 'aliases' => ['nom', 'name', 'client', 'nom complet', 'nom client']],
        'telephone' => ['label' => 'Téléphone', 'required' => true, 'aliases' => ['telephone', 'tel', 'phone', 'mobile', 'portable', 'numero', 'whatsapp', 'numero whatsapp']],
        'consentement' => ['label' => 'Consentement WhatsApp (oui / non)', 'required' => false, 'aliases' => ImportedConsent::COLUMNS],
        'date_consentement' => ['label' => 'Date du consentement', 'required' => false, 'aliases' => ['date consentement', 'date optin', 'date opt in', 'date accord']],
        'source_consentement' => ['label' => 'Source du consentement', 'required' => false, 'aliases' => ['source consentement', 'source', 'origine']],
        'naissance' => ['label' => 'Date de naissance', 'required' => false, 'aliases' => ['naissance', 'date naissance', 'date de naissance', 'anniversaire']],
        'sexe' => ['label' => 'Sexe', 'required' => false, 'aliases' => ['sexe', 'genre', 'gender']],
        'categorie' => ['label' => "Catégorie d'achat", 'required' => false, 'aliases' => ['categorie', 'category']],
        'montant' => ['label' => "Montant d'achat", 'required' => false, 'aliases' => ['montant', 'amount', 'total']],
        'date_achat' => ['label' => "Date d'achat", 'required' => false, 'aliases' => ['date achat', "date d'achat"]],
    ];

    /**
     * Colonne associée à chaque champ : choix de l'utilisateur (index de
     * colonne, ou vide = ignoré) s'il est fourni, sinon détection par en-tête.
     *
     * @param  array<int, mixed>  $headerRow
     * @param  array<string, mixed>|null  $mapping
     * @return array<string, int|null>
     */
    public static function resolveMapping(array $headerRow, ?array $mapping = null): array
    {
        $normalized = array_map(fn ($header) => self::normalizeHeader((string) $header), $headerRow);
        $resolved = [];

        foreach (self::FIELDS as $field => $definition) {
            if ($mapping !== null && array_key_exists($field, $mapping)) {
                $index = $mapping[$field];
                $resolved[$field] = is_numeric($index) && array_key_exists((int) $index, $headerRow) ? (int) $index : null;

                continue;
            }

            $aliases = array_map(self::normalizeHeader(...), $definition['aliases']);
            $found = array_search(true, array_map(fn ($header) => in_array($header, $aliases, true), $normalized), true);
            $resolved[$field] = $found === false ? null : $found;
        }

        return $resolved;
    }

    private static function normalizeHeader(string $header): string
    {
        return Str::of($header)->ascii()->lower()->replace(['_', '-'], ' ')->squish()->value();
    }

    /**
     * Aperçu sans écriture en base : compte ce qui serait créé/mis à jour et
     * remonte un échantillon, pour que l'admin valide avant de confirmer.
     *
     * @param  array<string, mixed>|null  $mapping  champ => index de colonne (cf. resolveMapping)
     */
    public function preview(UploadedFile $file, Store $store, ?array $mapping = null, ?string $delimiter = null): array
    {
        $rows = SpreadsheetReader::readRows($file, $delimiter);
        $headers = array_map(fn ($header) => trim((string) $header), $rows[0] ?? []);
        $resolved = self::resolveMapping($headers, $mapping);
        ['valid' => $valid, 'skipped' => $skipped] = $this->parseRows($rows, $resolved);

        $existingPhones = Contact::where('store_id', $store->id)
            ->whereIn('phone_e164', array_column($valid, 'phone'))
            ->pluck('phone_e164')
            ->all();

        $toCreate = 0;
        $toUpdate = 0;
        $sample = [];

        foreach ($valid as $row) {
            in_array($row['phone'], $existingPhones, true) ? $toUpdate++ : $toCreate++;

            if (count($sample) < self::SAMPLE_SIZE) {
                $sample[] = [
                    'name' => $row['name'],
                    'phone_e164' => $row['phone'],
                    'consent_status' => $row['attributes']['consent_status'] ?? null,
                    'consent_at' => $row['attributes']['consent_at'] ?? null,
                    'consent_source' => $row['attributes']['consent_source'] ?? null,
                ];
            }
        }

        return [
            'contacts_to_create' => $toCreate,
            'contacts_to_update' => $toUpdate,
            'purchases_to_record' => count(array_filter($valid, fn (array $row) => $row['purchase'] !== null)),
            'consents' => self::consentCounts(array_map(fn (array $row) => $row['attributes']['consent_status'] ?? null, $valid)),
            'total_valid' => count($valid),
            'skipped' => $skipped,
            'sample' => $sample,
            'headers' => $headers,
            'mapping' => $resolved,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $mapping  champ => index de colonne (cf. resolveMapping)
     */
    public function import(UploadedFile $file, Store $store, ?array $mapping = null, ?string $delimiter = null): array
    {
        $rows = SpreadsheetReader::readRows($file, $delimiter);
        ['valid' => $valid, 'skipped' => $skipped] = $this->parseRows($rows, self::resolveMapping($rows[0] ?? [], $mapping));

        $contactsCreated = 0;
        $contactsUpdated = 0;
        $purchasesRecorded = 0;
        $contactIds = [];

        // Fiches clients recalculées une seule fois, en fin d'import.
        CustomerLifecycle::batch(function () use ($valid, $store, &$contactsCreated, &$contactsUpdated, &$purchasesRecorded, &$contactIds) {
            $this->recordRows($valid, $store, $contactsCreated, $contactsUpdated, $purchasesRecorded, $contactIds);
        });

        return [
            'contacts_created' => $contactsCreated,
            'contacts_updated' => $contactsUpdated,
            'purchases_recorded' => $purchasesRecorded,
            'contact_ids' => $contactIds,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $valid
     * @param  array<int, int>  $contactIds
     */
    private function recordRows(array $valid, Store $store, int &$contactsCreated, int &$contactsUpdated, int &$purchasesRecorded, array &$contactIds): void
    {
        foreach ($valid as $row) {
            $contact = Contact::where('store_id', $store->id)
                ->where('phone_e164', $row['phone'])
                ->first();

            $attributes = $row['attributes'];

            // Même réponse que celle déjà enregistrée : on garde sa date et sa source d'origine.
            if ($contact && ($attributes['consent_status'] ?? null) === $contact->consent_status) {
                unset($attributes['consent_status'], $attributes['consent_at'], $attributes['consent_source']);
            }

            if ($contact) {
                $contact->update($attributes);
                $contactsUpdated++;
            } else {
                $contact = Contact::create([
                    'store_id' => $store->id,
                    'phone_e164' => $row['phone'],
                    'consent_status' => 'unknown',
                    ...$attributes,
                ]);
                $contactsCreated++;
            }

            $contactIds[] = $contact->id;

            if ($row['purchase'] !== null) {
                Purchase::create([
                    'contact_id' => $contact->id,
                    'store_id' => $store->id,
                    ...$row['purchase'],
                ]);
                $purchasesRecorded++;
            }
        }
    }

    /**
     * Nombre d'accords et de refus déclarés dans le fichier (aperçu).
     *
     * @param  array<int, string|null>  $consents
     * @return array{opt_in: int, opt_out: int}
     */
    public static function consentCounts(array $consents): array
    {
        $counts = array_count_values(array_filter($consents));

        return ['opt_in' => $counts['opt_in'] ?? 0, 'opt_out' => $counts['opt_out'] ?? 0];
    }

    /**
     * Analyse pure (aucune écriture) : sépare les lignes valides des lignes
     * ignorées, partagée par preview() et import() pour ne jamais diverger.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, int|null>  $mapping  champ => index de colonne (cf. resolveMapping)
     * @return array{valid: array<int, array{phone: string, name: string, attributes: array<string, mixed>, purchase: array<string, mixed>|null}>, skipped: array<int, string>}
     */
    private function parseRows(array $rows, array $mapping): array
    {
        array_shift($rows);

        if ($mapping['nom'] === null || $mapping['telephone'] === null) {
            return ['valid' => [], 'skipped' => ['Colonnes « nom » et « téléphone » introuvables : associez-les aux colonnes du fichier.']];
        }

        $valid = [];
        $skipped = [];
        $rowNumber = 1;

        foreach ($rows as $row) {
            $rowNumber++;

            // Ligne totalement vide (fréquent en fin de feuille Excel).
            if (count(array_filter($row, fn ($cell) => trim((string) $cell) !== '')) === 0) {
                continue;
            }

            $data = array_map(fn (?int $index) => $index === null ? null : ($row[$index] ?? null), $mapping);

            $name = trim((string) ($data['nom'] ?? ''));
            $phoneRaw = trim((string) ($data['telephone'] ?? ''));

            if ($name === '' || $phoneRaw === '') {
                $skipped[] = "Ligne {$rowNumber} : nom ou téléphone manquant";

                continue;
            }

            $phone = PhoneNormalizer::normalize($phoneRaw);

            if ($phone === null) {
                $skipped[] = "Ligne {$rowNumber} : numéro invalide ({$phoneRaw}), attendu ".PhoneNormalizer::EXPECTED_FORMAT;

                continue;
            }

            $attributes = ['name' => $name];

            // Date illisible : le contact est importé sans date de naissance.
            if (! empty($data['naissance']) && ($birthdate = ImportedDate::parse($data['naissance'], 1900)) !== null) {
                $attributes['birthdate'] = $birthdate;
            }
            if (! empty($data['sexe'])) {
                $attributes['gender'] = $data['sexe'];
            }

            // Accord WhatsApp recueilli en caisse ; vide = statut inchangé.
            // Daté (colonne, sinon jour de l'import) et sourcé (colonne, sinon « import »).
            $consentRaw = trim((string) ($data['consentement'] ?? ''));
            $consent = ImportedConsent::parse($consentRaw);

            if ($consent === false) {
                $skipped[] = "Ligne {$rowNumber} : consentement non reconnu ({$consentRaw}), attendu oui ou non ; laissé inchangé";
            } elseif ($consent !== null) {
                $consentDate = ! empty($data['date_consentement']) ? ImportedDate::parse($data['date_consentement'], 1990) : null;
                $source = Str::limit(trim((string) ($data['source_consentement'] ?? '')), 60, '');

                $attributes['consent_status'] = $consent;
                $attributes['consent_at'] = $consentDate ?? now()->toDateTimeString();
                $attributes['consent_source'] = $source !== '' ? $source : 'import';
            }

            $purchase = null;

            if (! empty($data['categorie']) && ! empty($data['montant']) && ! empty($data['date_achat'])) {
                $purchasedAt = ImportedDate::parse($data['date_achat']);

                if ($purchasedAt === null) {
                    // Le contact est importé, seul l'achat est écarté.
                    $skipped[] = "Ligne {$rowNumber} : achat non enregistré, date d'achat invalide ({$data['date_achat']})";
                } else {
                    $purchase = [
                        'amount' => (int) preg_replace('/[^0-9]/', '', (string) $data['montant']),
                        'product_category' => trim((string) $data['categorie']),
                        'purchased_at' => $purchasedAt,
                    ];
                }
            }

            $valid[] = ['phone' => $phone, 'name' => $name, 'attributes' => $attributes, 'purchase' => $purchase];
        }

        return ['valid' => $valid, 'skipped' => $skipped];
    }
}
