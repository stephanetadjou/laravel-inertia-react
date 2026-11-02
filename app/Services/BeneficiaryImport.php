<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\GroupClass;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Import des personnes suivies (modèle « personnes suivies ») : une ligne
 * par enfant / élève, avec son responsable. Le responsable est retrouvé par
 * son numéro (sa fiche est créée au besoin) ; une personne déjà suivie par
 * ce responsable sous le même nom n'est pas recréée. Cours facultatif :
 * inscription au cours de ce nom.
 */
class BeneficiaryImport
{
    public const COLUMNS = [
        'responsible' => ['responsable', 'parent', 'nom du parent', 'nom du responsable', 'tuteur'],
        'phone' => ['telephone du responsable', 'telephone', 'tel', 'whatsapp', 'telephone parent', 'numero'],
        'name' => ['personne suivie', 'enfant', 'eleve', 'adherent', 'nom de l enfant', 'patient', 'beneficiaire'],
        'birthdate' => ['date de naissance', 'naissance', 'ne le'],
        'relation' => ['lien', 'relation'],
        'class' => ['cours', 'activite', 'groupe'],
    ];

    /**
     * @param  list<list<string|null>>  $rows  première ligne = en-têtes
     * @return array{created: int, existing: int, enrolled: int, errors: list<string>}
     */
    public function import(array $rows, Store $store): array
    {
        $headers = array_map(fn ($h) => SalesImport::normalizeHeader((string) $h), array_shift($rows) ?? []);
        $index = [];
        foreach (self::COLUMNS as $key => $aliases) {
            $found = array_search(true, array_map(fn ($h) => in_array($h, $aliases, true), $headers), true);
            $index[$key] = $found === false ? null : $found;
        }

        $report = ['created' => 0, 'existing' => 0, 'enrolled' => 0, 'errors' => []];
        if ($index['responsible'] === null || $index['phone'] === null || $index['name'] === null) {
            $report['errors'][] = 'Colonnes « Responsable », « Téléphone du responsable » et « Personne suivie » introuvables : partez du modèle à télécharger.';

            return $report;
        }

        $classes = GroupClass::where('organization_id', $store->organization_id)->get()->keyBy(fn ($c) => FamilyMatcher::normalize($c->name));
        $groupClasses = app(GroupClasses::class);
        $value = fn (array $row, string $key) => $index[$key] !== null ? trim((string) ($row[$index[$key]] ?? '')) : '';

        CustomerLifecycle::batch(function () use ($rows, $store, $value, $classes, $groupClasses, &$report) {
            foreach ($rows as $i => $row) {
                $line = $i + 2;
                if (implode('', array_map(fn ($c) => trim((string) $c), $row)) === '') {
                    continue;
                }

                $phone = PhoneNormalizer::normalize($value($row, 'phone'));
                $name = $value($row, 'name');
                if ($phone === null || $name === '' || $value($row, 'responsible') === '') {
                    $report['errors'][] = "Ligne {$line} : responsable, téléphone valide et personne suivie sont obligatoires.";

                    continue;
                }

                DB::transaction(function () use ($row, $line, $store, $value, $phone, $name, $classes, $groupClasses, &$report) {
                    $responsible = Contact::where('store_id', $store->id)->where('phone_e164', $phone)->first()?->customer
                        ?? Customer::where('organization_id', $store->organization_id)->where('phone_e164', $phone)->first();
                    if ($responsible === null || ! $responsible->contacts()->where('store_id', $store->id)->exists()) {
                        $responsible = Contact::create(['store_id' => $store->id, 'name' => $value($row, 'responsible'), 'phone_e164' => $phone])->customer;
                    }

                    $birthdate = ImportedDate::parse($value($row, 'birthdate'), 1900);
                    $relation = array_key_exists(mb_strtolower($value($row, 'relation')), Customer::RELATIONS) ? mb_strtolower($value($row, 'relation')) : 'parent';
                    $member = $responsible->beneficiaries()->get()->first(fn ($b) => FamilyMatcher::normalize($b->name) === FamilyMatcher::normalize($name));

                    if ($member === null) {
                        $member = Customer::create([
                            'organization_id' => $store->organization_id, 'phone_e164' => null, 'name' => $name,
                            'birthdate' => $birthdate, 'responsible_id' => $responsible->id, 'responsible_relation' => $relation, 'identity_confirmed_at' => now(),
                        ]);
                        Contact::create(['store_id' => $store->id, 'customer_id' => $member->id, 'name' => $name, 'phone_e164' => null, 'birthdate' => $birthdate]);
                        $report['created']++;
                    } else {
                        $report['existing']++;
                    }

                    $className = $value($row, 'class');
                    if ($className !== '') {
                        $class = $classes->get(FamilyMatcher::normalize($className));
                        if ($class === null) {
                            $report['errors'][] = "Ligne {$line} : cours « {$className} » inconnu (créez-le d’abord dans Agenda › Cours).";
                        } else {
                            try {
                                $groupClasses->enroll($class, $member, null, today());
                                $report['enrolled']++;
                            } catch (ValidationException $e) {
                                $report['errors'][] = "Ligne {$line} : ".collect($e->errors())->flatten()->first();
                            }
                        }
                    }
                });
            }
        });

        return $report;
    }
}
