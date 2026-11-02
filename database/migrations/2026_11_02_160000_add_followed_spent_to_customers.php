<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Montant payé par un responsable pour ses personnes suivies (enfants…) :
 * affiché sur sa fiche, sans l'ajouter à son total dépensé (le CA n'est
 * jamais compté deux fois).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('followed_spent')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('followed_spent');
        });
    }
};
