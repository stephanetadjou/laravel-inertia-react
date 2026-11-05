<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numéro WhatsApp « géré par Menentia » : le client a partagé son compte
 * WhatsApp Business avec le portefeuille Menentia, qui envoie avec son
 * propre token (MENENTIA_WHATSAPP_TOKEN) et reçoit les événements sur le
 * webhook unique /webhooks/whatsapp. Seul le back-office le règle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('whatsapp_managed')->default(false)->after('whatsapp_app_secret');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('whatsapp_managed');
        });
    }
};
