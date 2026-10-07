<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paramètres du modèle choisi pour la campagne : langue de la version
 * approuvée, valeurs des variables {{n}} (texte fixe ou {prenom}, {nom},
 * {boutique} remplacés pour chaque contact) et image de l'en-tête.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('template_language', 16)->nullable()->after('template_name');
            $table->json('template_variables')->nullable()->after('template_language');
            $table->string('header_image_url', 2048)->nullable()->after('template_variables');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['template_language', 'template_variables', 'header_image_url']);
        });
    }
};
