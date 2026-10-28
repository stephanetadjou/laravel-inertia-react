<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapport quotidien au dirigeant.
 *
 * - purchases.payment_method : moyen de paiement choisi à l'encaissement
 *   (espèces, Airtel Money, Moov Money, carte…), null pour les ventes importées ;
 * - organizations.report_settings : envoi activé, destinataires, heure
 *   d'envoi, objectif de CA du mois, dernier jour envoyé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('payment_method')->nullable();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->json('report_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('report_settings');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
