<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Purchase;
use App\Models\Store;
use Illuminate\Http\UploadedFile;

/**
 * Importe un fichier CSV ou Excel (xlsx/xls) de contacts (et, optionnellement,
 * leur historique d'achats) pour une boutique donnée.
 *
 * Colonnes attendues (en-tête, insensible à la casse) :
 *   nom, telephone                        -> obligatoires
 *   categorie, montant, date_achat        -> optionnelles, ensemble (créent un achat)
 *   naissance, sexe                       -> optionnelles (profil client)
 */
class ContactImporter
{
    private const SAMPLE_SIZE = 5;

    /**
     * Aperçu sans écriture en base : compte ce qui serait créé/mis à jour et
     * remonte un échantillon, pour que l'admin valide avant de confirmer.
     */
    public function preview(UploadedFile $file, Store $store): array
    {
        ['valid' => $valid, 'skipped' => $skipped] = $this->parseRows(SpreadsheetReader::readRows($file));

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
                $sample[] = ['name' => $row['name'], 'phone_e164' => $row['phone']];
            }
        }

        return [
            'contacts_to_create' => $toCreate,
            'contacts_to_update' => $toUpdate,
            'purchases_to_record' => count(array_filter($valid, fn (array $row) => $row['purchase'] !== null)),
            'total_valid' => count($valid),
            'skipped' => $skipped,
            'sample' => $sample,
        ];
    }

    public function import(UploadedFile $file, Store $store): array
    {
        ['valid' => $valid, 'skipped' => $skipped] = $this->parseRows(SpreadsheetReader::readRows($file));

        $contactsCreated = 0;
        $contactsUpdated = 0;
        $purchasesRecorded = 0;
        $contactIds = [];

        foreach ($valid as $row) {
            $contact = Contact::where('store_id', $store->id)
                ->where('phone_e164', $row['phone'])
                ->first();

            if ($contact) {
                $contact->update($row['attributes']);
                $contactsUpdated++;
            } else {
                $contact = Contact::create([
                    'store_id' => $store->id,
                    'phone_e164' => $row['phone'],
                    'consent_status' => 'unknown',
                    ...$row['attributes'],
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

        return [
            'contacts_created' => $contactsCreated,
            'contacts_updated' => $contactsUpdated,
            'purchases_recorded' => $purchasesRecorded,
            'contact_ids' => $contactIds,
            'skipped' => $skipped,
        ];
    }

    /**
     * Analyse pure (aucune écriture) : sépare les lignes valides des lignes
     * ignorées, partagée par preview() et import() pour ne jamais diverger.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{valid: array<int, array{phone: string, name: string, attributes: array<string, mixed>, purchase: array<string, mixed>|null}>, skipped: array<int, string>}
     */
    private function parseRows(array $rows): array
    {
        $headerRow = array_shift($rows) ?: [];
        $header = array_map(fn ($col) => strtolower(trim((string) $col)), $headerRow);

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

            $name = trim((string) ($data['nom'] ?? ''));
            $phoneRaw = trim((string) ($data['telephone'] ?? ''));

            if ($name === '' || $phoneRaw === '') {
                $skipped[] = "Ligne {$rowNumber} : nom ou téléphone manquant";

                continue;
            }

            $phone = PhoneNormalizer::normalize($phoneRaw);

            if (strlen($phone) < 10) {
                $skipped[] = "Ligne {$rowNumber} : numéro invalide ({$phoneRaw})";

                continue;
            }

            $attributes = ['name' => $name];

            if (! empty($data['naissance'])) {
                $attributes['birthdate'] = $data['naissance'];
            }
            if (! empty($data['sexe'])) {
                $attributes['gender'] = $data['sexe'];
            }

            $purchase = null;

            if (! empty($data['categorie']) && ! empty($data['montant']) && ! empty($data['date_achat'])) {
                $purchase = [
                    'amount' => (int) preg_replace('/[^0-9]/', '', (string) $data['montant']),
                    'product_category' => trim((string) $data['categorie']),
                    'purchased_at' => $data['date_achat'],
                ];
            }

            $valid[] = ['phone' => $phone, 'name' => $name, 'attributes' => $attributes, 'purchase' => $purchase];
        }

        return ['valid' => $valid, 'skipped' => $skipped];
    }
}
