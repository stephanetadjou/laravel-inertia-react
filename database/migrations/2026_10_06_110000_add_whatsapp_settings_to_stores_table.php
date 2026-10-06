<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paramètres → WhatsApp : chaque établissement connecte son propre compte
 * WhatsApp Cloud API (WABA, numéro, application Meta). L'App Secret est
 * chiffré comme le token ; les champs « constatés » reprennent le dernier
 * test de connexion (nom vérifié, qualité, limite d'envoi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('whatsapp_business_account_id')->nullable()->after('whatsapp_phone_number_id');
            $table->string('whatsapp_app_id')->nullable()->after('whatsapp_business_account_id');
            $table->text('whatsapp_app_secret')->nullable()->after('whatsapp_access_token');
            $table->string('whatsapp_verified_name')->nullable();
            $table->string('whatsapp_display_phone_number')->nullable();
            $table->string('whatsapp_quality_rating')->nullable();
            $table->string('whatsapp_messaging_limit_tier')->nullable();
            $table->timestamp('whatsapp_checked_at')->nullable();
            $table->timestamp('whatsapp_registered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_business_account_id',
                'whatsapp_app_id',
                'whatsapp_app_secret',
                'whatsapp_verified_name',
                'whatsapp_display_phone_number',
                'whatsapp_quality_rating',
                'whatsapp_messaging_limit_tier',
                'whatsapp_checked_at',
                'whatsapp_registered_at',
            ]);
        });
    }
};
