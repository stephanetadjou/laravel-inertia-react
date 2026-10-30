<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel client (Menentia Retail) :
 * - customer_phones : numéros d'une fiche (principal, anciens numéros avec
 *   leur période de validité, numéro partagé par plusieurs personnes) ;
 *   repris de customers.phone_e164 et customers.previous_phones ;
 * - customer_consents : consentements par canal et finalité, un par point
 *   de vente pour WhatsApp (numéro propre à chaque point de vente) ; repris
 *   du consentement des contacts ;
 * - customer_match_reviews : file « À vérifier » quand un numéro connu
 *   arrive avec un nom très différent ou est marqué partagé : aucun
 *   rattachement automatique, l'utilisateur décide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('phone_e164', 32);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_shared')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index('phone_e164');
            $table->index(['customer_id', 'is_primary']);
        });

        Schema::create('customer_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('channel', 16)->default('whatsapp');
            $table->string('purpose', 16)->default('marketing');
            $table->string('status', 16);
            $table->timestamp('consent_date')->nullable();
            $table->string('source', 32)->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'store_id', 'channel', 'purpose']);
        });

        Schema::create('customer_match_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_e164', 32);
            $table->string('incoming_name')->nullable();
            $table->string('existing_name')->nullable();
            $table->string('reason', 32);
            $table->json('purchase_ids')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        $now = now();

        DB::table('customers')->orderBy('id')->chunk(500, function ($customers) use ($now) {
            $rows = [];

            foreach ($customers as $customer) {
                $rows[] = ['customer_id' => $customer->id, 'phone_e164' => $customer->phone_e164, 'is_primary' => true, 'is_verified' => false, 'is_shared' => false,
                    'valid_from' => substr((string) $customer->created_at, 0, 10) ?: null, 'valid_to' => null, 'created_at' => $now, 'updated_at' => $now];

                foreach ((array) json_decode((string) $customer->previous_phones, true) as $phone) {
                    if (is_string($phone) && $phone !== '' && $phone !== $customer->phone_e164) {
                        $rows[] = ['customer_id' => $customer->id, 'phone_e164' => $phone, 'is_primary' => false, 'is_verified' => false, 'is_shared' => false,
                            'valid_from' => null, 'valid_to' => substr((string) $customer->updated_at, 0, 10) ?: null, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
            }

            DB::table('customer_phones')->insert($rows);
        });

        DB::table('contacts')->whereNotNull('customer_id')->where('consent_status', '!=', 'unknown')->orderBy('id')
            ->chunk(500, function ($contacts) use ($now) {
                $rows = [];

                foreach ($contacts as $contact) {
                    $rows[$contact->customer_id.'-'.$contact->store_id] = ['customer_id' => $contact->customer_id, 'store_id' => $contact->store_id, 'channel' => 'whatsapp', 'purpose' => 'marketing',
                        'status' => $contact->consent_status, 'consent_date' => $contact->consent_at, 'source' => $contact->consent_source, 'created_at' => $now, 'updated_at' => $now];
                }

                DB::table('customer_consents')->insertOrIgnore(array_values($rows));
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_match_reviews');
        Schema::dropIfExists('customer_consents');
        Schema::dropIfExists('customer_phones');
    }
};
