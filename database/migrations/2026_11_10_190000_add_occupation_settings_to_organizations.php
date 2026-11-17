<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Moteur d'occupation : seuils de creux, de saturation et d'immobilisation
 * (Paramètres › Occupation). Null = valeurs par défaut du secteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('occupation_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('occupation_settings');
        });
    }
};
