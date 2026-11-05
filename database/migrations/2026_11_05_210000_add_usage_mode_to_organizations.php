<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Règle STEPH2 (05/11/2026) : les ventes ne s'importent qu'une fois, à la
 * reprise de l'historique, sauf pour les entreprises en « analyse client
 * seule » qui importent régulièrement. Commerce existant (RetailGroup) :
 * analyse seule ; prestataires : gestion complète.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('usage_mode', 20)->default('complete');
            $table->timestamp('history_import_closed_at')->nullable();
        });

        DB::table('organizations')->where('sector', 'commerce')->update(['usage_mode' => 'analyse']);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['usage_mode', 'history_import_closed_at']);
        });
    }
};
