<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Importe un historique d'achats (CSV/Excel) toutes boutiques de
 * l'organisation confondues — la boutique est une colonne du fichier, pas
 * une sélection préalable (fichier de ventes consolidé). Si le client
 * (boutique + téléphone) n'existe pas encore, il est créé à la volée avec un
 * consentement "unknown" : le numéro WhatsApp est l'identifiant du client,
 * l'achat n'est jamais traité comme une preuve de consentement marketing.
 * Seule la colonne « consentement » (oui / non, accord recueilli en caisse)
 * fixe le consentement, cf. ImportedConsent.
 *
 * Colonnes attendues (en-tête, insensible à la casse) :
 *   telephone, boutique, date_achat   -> obligatoires
 *   nom                                -> optionnelle (sert si le contact est créé)
 *   categorie                          -> optionnelle (famille, cf. FamilyMatcher)
 *   article (ou produit, libelle)      -> optionnelle : libellé de l'article, sert aussi à
 *                                          reconnaître la famille ; aucune fiche produit créée
 *   quantite                           -> optionnelle (défaut 1)
 *   consentement                       -> optionnelle : oui / non (vide = inchangé)
 *   prix (prix unitaire) et/ou montant -> l'un des deux obligatoire
 *                                          (montant, si présent, prime sur quantite × prix)
 *   toute autre colonne                -> attribut générique de la vente (genre, marque,
 *                                          taille…), enregistré dans purchases.properties
 */
class PurchaseImporter
{
    private const SAMPLE_SIZE = 5;

    /** Colonnes reconnues ; les autres deviennent des attributs de la vente. */
    private const KNOWN_COLUMNS = ['telephone', 'boutique', 'date_achat', 'nom', 'categorie', 'quantite', 'prix', 'montant', 'article', 'produit', 'libelle'];

    /** Au-delà, les colonnes supplémentaires sont ignorées (fichier de caisse très large). */
    private const MAX_PROPERTIES = 10;

    /**
     * @param  array<int, int>|null  $allowedStoreIds  Périmètre de l'utilisateur (marque/boutique) ;
     *                                                 null = organisation entière.
     */
    public function preview(UploadedFile $file, Organization $organization, ?array $allowedStoreIds = null): array
    {
        ['valid' => $valid, 'skipped' => $skipped] = $this->parseRows(SpreadsheetReader::readRows($file), $organization, $allowedStoreIds);

        $existingKeys = Contact::query()
            ->whereIn('store_id', collect($valid)->pluck('store')->pluck('id')->unique())
            ->get(['store_id', 'phone_e164'])
            ->map(fn (Contact $contact) => "{$contact->store_id}:{$contact->phone_e164}")
            ->all();

        $contactsToCreate = 0;
        $sample = [];
        $matcher = FamilyMatcher::for($organization->id);
        $classified = 0;

        foreach ($valid as $row) {
            $isNew = ! in_array("{$row['store']->id}:{$row['phone']}", $existingKeys, true);
            if ($isNew) {
                $contactsToCreate++;
            }

            $family = $row['product_category'] !== null || $row['article'] !== null
                ? $matcher->match($row['product_category'], $row['article'])
                : null;
            $classified += $family !== null ? 1 : 0;

            if (count($sample) < self::SAMPLE_SIZE) {
                $sample[] = [
                    'contact_name' => $row['name'] ?? "Client {$row['phone']}",
                    'store_name' => $row['store']->name,
                    'category' => $row['product_category'],
                    'article' => $row['article'],
                    'family' => $family !== null ? Category::whereKey($family)->value('name') : null,
                    'quantity' => $row['quantity'],
                    'amount' => $row['amount'],
                    'purchased_at' => $row['purchased_at'],
                    'new_contact' => $isNew,
                ];
            }
        }

        return [
            'purchases_to_record' => count($valid),
            'contacts_to_create' => $contactsToCreate,
            'total_amount' => array_sum(array_column($valid, 'amount')),
            'consents' => ContactImporter::consentCounts(array_column($valid, 'consent')),
            'skipped' => $skipped,
            'sample' => $sample,
            // Familles : lignes rangées automatiquement, et celles à classer après l'import.
            'classified' => $classified,
            'to_classify' => count(array_filter($valid, fn ($row) => $row['product_category'] !== null || $row['article'] !== null)) - $classified,
            'properties' => collect($valid)->flatMap(fn ($row) => array_keys($row['properties']))->unique()->values()->all(),
        ];
    }

    /**
     * @param  array<int, int>|null  $allowedStoreIds
     */
    public function import(UploadedFile $file, Organization $organization, ?array $allowedStoreIds = null): array
    {
        ['valid' => $valid, 'skipped' => $skipped] = $this->parseRows(SpreadsheetReader::readRows($file), $organization, $allowedStoreIds);

        $recorded = 0;
        $contactsCreated = 0;

        // Fiches clients recalculées une seule fois, en fin d'import.
        CustomerLifecycle::batch(function () use ($valid, &$recorded, &$contactsCreated) {
            $this->recordRows($valid, $recorded, $contactsCreated);
        });

        return [
            'purchases_recorded' => $recorded,
            'contacts_created' => $contactsCreated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $valid
     */
    private function recordRows(array $valid, int &$recorded, int &$contactsCreated): void
    {
        foreach ($valid as $row) {
            $contact = Contact::where('store_id', $row['store']->id)
                ->where('phone_e164', $row['phone'])
                ->first();

            if (! $contact) {
                $contact = Contact::create([
                    'store_id' => $row['store']->id,
                    'phone_e164' => $row['phone'],
                    'name' => $row['name'] ?? "Client {$row['phone']}",
                    'consent_status' => $row['consent'] ?? 'unknown',
                    'consent_source' => $row['consent'] !== null ? 'import' : null,
                ]);
                $contactsCreated++;
            } elseif ($row['consent'] !== null && $row['consent'] !== $contact->consent_status) {
                // Réponse explicite en caisse : elle remplace le statut enregistré.
                $contact->update(['consent_status' => $row['consent'], 'consent_source' => 'import']);
            }

            Purchase::create([
                'contact_id' => $contact->id,
                'store_id' => $row['store']->id,
                'amount' => $row['amount'],
                'quantity' => $row['quantity'],
                'product_category' => $row['product_category'],
                'article' => $row['article'],
                'properties' => $row['properties'] ?: null,
                'purchased_at' => $row['purchased_at'],
            ]);
            $recorded++;
        }
    }

    /**
     * Analyse pure (aucune écriture) : sépare les lignes valides des lignes
     * ignorées, partagée par preview() et import() pour ne jamais diverger.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, int>|null  $allowedStoreIds
     */
    private function parseRows(array $rows, Organization $organization, ?array $allowedStoreIds = null): array
    {
        $headerRow = array_shift($rows) ?: [];
        $header = array_map(fn ($col) => strtolower(trim((string) $col)), $headerRow);

        // Colonnes libres (genre, marque, taille…) : clé « tranche_age » pour « Tranche d'âge ».
        $propertyColumns = collect($header)
            ->reject(fn ($col) => $col === '' || in_array($col, self::KNOWN_COLUMNS, true) || in_array($col, ImportedConsent::COLUMNS, true))
            ->mapWithKeys(fn ($col) => [$col => Str::slug($col, '_')])
            ->filter(fn ($key) => $key !== '')
            ->unique()
            ->take(self::MAX_PROPERTIES);

        $stores = Store::where('organization_id', $organization->id)
            ->when($allowedStoreIds !== null, fn ($q) => $q->whereIn('id', $allowedStoreIds))
            ->get()
            ->keyBy(fn (Store $store) => strtolower($store->name));

        $valid = [];
        $skipped = [];
        $rowNumber = 1;

        foreach ($rows as $row) {
            $rowNumber++;

            // Ligne totalement vide (fréquent en fin de feuille Excel).
            if (count(array_filter($row, fn ($cell) => trim((string) $cell) !== '')) === 0) {
                continue;
            }

            $data = array_combine($header, array_pad($row, count($header), null));

            $storeName = trim((string) ($data['boutique'] ?? ''));
            $phoneRaw = trim((string) ($data['telephone'] ?? ''));
            $dateAchat = trim((string) ($data['date_achat'] ?? ''));

            if ($storeName === '' || $phoneRaw === '' || $dateAchat === '') {
                $skipped[] = "Ligne {$rowNumber} : boutique, téléphone ou date manquant";

                continue;
            }

            $purchasedAt = ImportedDate::parse($dateAchat);

            if ($purchasedAt === null) {
                $skipped[] = "Ligne {$rowNumber} : date d'achat invalide ({$dateAchat}), attendu JJ/MM/AAAA ou AAAA-MM-JJ";

                continue;
            }

            $store = $stores->get(strtolower($storeName));

            if (! $store) {
                $skipped[] = "Ligne {$rowNumber} : boutique inconnue ({$storeName})";

                continue;
            }

            $phone = PhoneNormalizer::normalize($phoneRaw);

            if ($phone === null) {
                $skipped[] = "Ligne {$rowNumber} : numéro invalide ({$phoneRaw}), attendu ".PhoneNormalizer::EXPECTED_FORMAT;

                continue;
            }

            $quantity = ! empty($data['quantite'])
                ? max(1, (int) preg_replace('/[^0-9]/', '', (string) $data['quantite']))
                : 1;

            $montant = ! empty($data['montant']) ? (int) preg_replace('/[^0-9]/', '', (string) $data['montant']) : null;
            $prixUnitaire = ! empty($data['prix']) ? (int) preg_replace('/[^0-9]/', '', (string) $data['prix']) : null;
            $amount = $montant ?? ($prixUnitaire !== null ? $prixUnitaire * $quantity : null);

            if ($amount === null) {
                $skipped[] = "Ligne {$rowNumber} : ni prix ni montant renseigné";

                continue;
            }

            // Accord WhatsApp recueilli en caisse ; vide = statut inchangé.
            $consentRaw = ImportedConsent::valueFrom($data);
            $consent = ImportedConsent::parse($consentRaw);

            if ($consent === false) {
                $skipped[] = "Ligne {$rowNumber} : consentement non reconnu ({$consentRaw}), attendu oui ou non ; laissé inchangé";
                $consent = null;
            }

            $properties = [];

            foreach ($propertyColumns as $column => $key) {
                $value = self::propertyValue($data[$column] ?? null);

                if ($value !== null) {
                    $properties[$key] = $value;
                }
            }

            $article = trim((string) ($data['article'] ?? $data['produit'] ?? $data['libelle'] ?? ''));

            $valid[] = [
                'consent' => $consent,
                'article' => $article !== '' ? mb_substr($article, 0, 255) : null,
                'properties' => $properties,
                'phone' => $phone,
                'name' => ! empty($data['nom']) ? trim((string) $data['nom']) : null,
                'store' => $store,
                'quantity' => $quantity,
                'amount' => $amount,
                'product_category' => ! empty($data['categorie']) ? trim((string) $data['categorie']) : null,
                'purchased_at' => $purchasedAt,
            ];
        }

        return ['valid' => $valid, 'skipped' => $skipped];
    }

    /**
     * Valeur d'attribut nettoyée : « HOMME » et « homme » deviennent « Homme »
     * pour être comptés ensemble ; les codes courts (XL, 42) restent tels quels.
     */
    public static function propertyValue(mixed $raw): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $raw));

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 3 && ! preg_match('/\d/', $value) && ($value === mb_strtoupper($value) || $value === mb_strtolower($value))) {
            $value = mb_strtoupper(mb_substr($value, 0, 1)).mb_strtolower(mb_substr($value, 1));
        }

        return mb_substr($value, 0, 100);
    }
}
