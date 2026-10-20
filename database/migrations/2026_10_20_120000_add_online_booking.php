<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réservation en ligne : page publique /rdv/{entreprise}.
 *
 * - organizations.booking_settings : activée, confirmation automatique ou
 *   non, pas des créneaux, délai minimum, horizon, message d'accueil ;
 * - stores : horaires d'ouverture par jour, nombre de rendez-vous en
 *   parallèle, numéro WhatsApp de l'établissement (récapitulatif wa.me) ;
 * - catalog_items.bookable_online : prestation proposée sur la page ;
 * - appointments.source (staff / online) et cancel_token (lien d'annulation
 *   donné au client).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('booking_settings')->nullable();
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->json('booking_hours')->nullable();
            $table->unsignedSmallInteger('booking_capacity')->default(1);
            $table->string('booking_phone', 20)->nullable();
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->boolean('bookable_online')->default(true);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('source')->default('staff');
            $table->string('cancel_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique(['cancel_token']);
            $table->dropColumn(['source', 'cancel_token']);
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('bookable_online');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['booking_hours', 'booking_capacity', 'booking_phone']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('booking_settings');
        });
    }
};
