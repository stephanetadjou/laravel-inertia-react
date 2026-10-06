<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche client unique pour tout le Groupe.
 *
 * - customers : une personne par organisation et par numéro E.164, avec ses
 *   compteurs d'achats (dénormalisés) et son étape du parcours client ;
 * - contacts.customer_id : chaque contact reste la relation d'une boutique
 *   (consentement WhatsApp propre au numéro de la boutique), rattachée à la
 *   fiche client de la personne ;
 * - customer_stage_changes : historique des changements d'étape
 *   (entonnoir, conversions, churn) ;
 * - organizations.lifecycle_settings : seuils du parcours, modifiables.
 *
 * Les contacts existants sont regroupés par numéro : le nom retenu est celui
 * du contact le plus récent ; les autres noms restent sur chaque contact.
 * Les compteurs et étapes se calculent ensuite avec
 * `php artisan crm:refresh-lifecycle` (lancé aussi chaque nuit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('phone_e164');
            // Anciens numéros de la personne : conservés, plus utilisés pour l'envoi.
            $table->json('previous_phones')->nullable();
            $table->string('name');
            // Renseigné quand l'identité est corrigée à la main sur la fiche :
            // le nom n'est alors plus remplacé par celui du dernier contact.
            $table->timestamp('identity_confirmed_at')->nullable();
            $table->string('gender', 1)->nullable();
            $table->date('birthdate')->nullable();
            $table->date('first_purchase_at')->nullable();
            $table->date('last_purchase_at')->nullable();
            $table->unsignedInteger('purchases_count')->default(0);
            $table->unsignedBigInteger('total_spent')->default(0);
            $table->string('lifecycle_stage')->default('contact');
            $table->timestamp('lifecycle_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'phone_e164']);
            $table->index(['organization_id', 'lifecycle_stage']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('store_id')->constrained()->nullOnDelete();
        });

        Schema::create('customer_stage_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage')->nullable();
            $table->string('to_stage');
            $table->timestamp('changed_at');

            $table->index(['customer_id', 'changed_at']);
            $table->index(['to_stage', 'changed_at']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->json('lifecycle_settings')->nullable();
        });

        $this->groupExistingContacts();
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('lifecycle_settings');
        });

        Schema::dropIfExists('customer_stage_changes');

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('customers');
    }

    /**
     * Une fiche client par (organisation, numéro). Parcours du plus ancien au
     * plus récent contact : le dernier nom rencontré l'emporte, le sexe et la
     * date de naissance aussi dès qu'ils sont renseignés.
     */
    private function groupExistingContacts(): void
    {
        $people = [];

        DB::table('contacts')
            ->join('stores', 'stores.id', '=', 'contacts.store_id')
            ->orderBy('contacts.created_at')
            ->orderBy('contacts.id')
            ->select('contacts.id', 'contacts.phone_e164', 'contacts.name', 'contacts.gender', 'contacts.birthdate', 'contacts.created_at', 'stores.organization_id')
            ->cursor()
            ->each(function ($contact) use (&$people) {
                $key = $contact->organization_id.'|'.$contact->phone_e164;
                $person = $people[$key] ?? [
                    'organization_id' => $contact->organization_id,
                    'phone_e164' => $contact->phone_e164,
                    'gender' => null,
                    'birthdate' => null,
                    'created_at' => $contact->created_at,
                    'contact_ids' => [],
                ];

                // Un nom par défaut (« Client 241… ») ne remplace pas un vrai nom.
                $isPlaceholder = preg_match('/^Client \+?\d+$/u', trim((string) $contact->name)) === 1;
                if (! isset($person['name']) || ! $isPlaceholder) {
                    $person['name'] = $contact->name;
                }
                $person['gender'] = $contact->gender ?? $person['gender'];
                $person['birthdate'] = $contact->birthdate ?? $person['birthdate'];
                $person['contact_ids'][] = $contact->id;
                $people[$key] = $person;
            });

        $now = now();

        foreach ($people as $person) {
            $customerId = DB::table('customers')->insertGetId([
                'organization_id' => $person['organization_id'],
                'phone_e164' => $person['phone_e164'],
                'name' => $person['name'],
                'gender' => $person['gender'],
                'birthdate' => $person['birthdate'],
                'created_at' => $person['created_at'] ?? $now,
                'updated_at' => $now,
            ]);

            foreach (array_chunk($person['contact_ids'], 500) as $ids) {
                DB::table('contacts')->whereIn('id', $ids)->update(['customer_id' => $customerId]);
            }
        }
    }
};
