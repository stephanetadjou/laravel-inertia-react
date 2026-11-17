<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Métier précis choisi à l'inscription (famille → métier) et adresse
 * d'intervention d'un rendez-vous (services à domicile).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('activity', 120)->nullable()->after('sector');
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('activity'));
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('address'));
    }
};
