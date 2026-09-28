<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Filtres appliqués au moment de la création (boutique déjà couverte
            // par store_id) : conservés pour l'audit/l'affichage de la campagne.
            $table->json('segment_filters')->nullable()->after('template_name');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('segment_filters');
        });
    }
};
