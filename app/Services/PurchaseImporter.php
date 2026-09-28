<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use Illuminate\Http\UploadedFile;

/**
 * Importe un historique d'achats (CSV/Excel) toutes boutiques de
 * l'organisation confondues — la boutique est une colonne du fichier, pas
 * une sélection préalable (fichier de ventes consolidé). Si le client
 * (boutique + téléphone) n'existe pas encore, il est créé à la volée avec un
 * consentement "unknown" : le numéro WhatsApp est l'identifiant du client,
 * l'achat n'est jamais traité comme une preuve de consentement marketing.
 *
 * Colonnes attendues (en-tête, insensible à la casse) :
 *   telephone, boutique, date_achat   -> obligatoires
 *   nom                                -> optionnelle (sert si le contact est créé)
 *   categorie                          -> optionnelle
 *   quantite                           -> optionnelle (défaut 1)
 *   prix (prix unitaire) et/ou montant -> l'un des deux obligatoire
 *                                          (montant, si présent, prime sur quantite × prix)
 */
class PurchaseImporter
{
    private const SAMPLE_SIZE = 5;

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

        foreach ($valid as $row) {
            $isNew = ! in_array("{$row['store']->id}:{$row['phone']}", $existingKeys, true);
            if ($isNew) {
                $contactsToCreate++;
            }

            if (count($sample) < self::SAMPLE_SIZE) {
                $sample[] = [
                    'contact_name' => $row['name'] ?? "Client {$row['phone']}",
                    'store_name' => $row['store']->name,
                    'category' => $row['product_category'],
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
            'skipped' => $skipped,
            'sample' => $sample,
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

        foreach ($valid as $row) {
            $contact = Contact::where('store_id', $row['store']->id)
                ->where('phone_e164', $row['phone'])
                ->first();

            if (! $contact) {
                $contact = Contact::create([
                    'store_id' => $row['store']->id,
                    'phone_e164' => $row['phone'],
                    'name' => $row['name'] ?? "Client {$row['phone']}",
                    'consent_status' => 'unknown',
                ]);
                $contactsCreated++;
            }

            Purchase::create([
                'contact_id' => $contact->id,
                'store_id' => $row['store']->id,
                'amount' => $row['amount'],
                'quantity' => $row['quantity'],
                'product_category' => $row['product_category'],
                'purchased_at' => $row['purchased_at'],
            ]);
            $recorded++;
        }

        return [
            'purchases_recorded' => $recorded,
            'contacts_created' => $contactsCreated,
            'skipped' => $skipped,
        ];
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

            $store = $stores->get(strtolower($storeName));

            if (! $store) {
                $skipped[] = "Ligne {$rowNumber} : boutique inconnue ({$storeName})";

                continue;
            }

            $phone = PhoneNormalizer::normalize($phoneRaw);

            if (strlen($phone) < 10) {
                $skipped[] = "Ligne {$rowNumber} : numéro invalide ({$phoneRaw})";

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

            $valid[] = [
                'phone' => $phone,
                'name' => ! empty($data['nom']) ? trim((string) $data['nom']) : null,
                'store' => $store,
                'quantity' => $quantity,
                'amount' => $amount,
                'product_category' => ! empty($data['categorie']) ? trim((string) $data['categorie']) : null,
                'purchased_at' => $dateAchat,
            ];
        }

        return ['valid' => $valid, 'skipped' => $skipped];
    }
}
