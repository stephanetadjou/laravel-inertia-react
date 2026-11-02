<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personnes suivies (module « beneficiaires ») : une fiche client à part
 * entière rattachée à un responsable (parent, tuteur, employeur) à qui
 * partent les messages. Un enfant n'a souvent pas de numéro : le numéro de
 * la fiche et de ses contacts devient facultatif (jamais pour un client
 * ordinaire, cf. CustomerLifecycle::linkContact).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('responsible_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('responsible_relation', 20)->nullable();
            // Note de santé (allergies, contre-indications) : chiffrée, accès restreint.
            $table->text('health_note')->nullable();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone_e164')->nullable()->change();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('phone_e164')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_id');
            $table->dropColumn(['responsible_relation', 'health_note']);
        });
    }
};
