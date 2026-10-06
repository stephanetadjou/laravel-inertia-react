<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook Meta par établissement : clé aléatoire dans l'URL (non
 * devinable) et jeton de vérification (chiffré) à recopier chez Meta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('webhook_key', 64)->nullable()->unique();
            $table->text('webhook_verify_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['webhook_key']);
            $table->dropColumn(['webhook_key', 'webhook_verify_token']);
        });
    }
};
