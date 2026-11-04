<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Suivi fidélité : nombre de points en dessous duquel un client est « proche » (vide : 20 % de l'écart). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            $table->unsignedInteger('near_points')->nullable()->after('same_rules');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_programs', fn (Blueprint $table) => $table->dropColumn('near_points'));
    }
};
