<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Annonce publique d'un véhicule à vendre (lien à partager) et rappel de l'essai la veille. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookable_resources', function (Blueprint $table) {
            $table->string('sale_token', 40)->nullable()->unique();
        });

        Schema::table('vehicle_sales', function (Blueprint $table) {
            $table->dateTime('test_drive_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_sales', fn (Blueprint $table) => $table->dropColumn('test_drive_reminded_at'));
        Schema::table('bookable_resources', function (Blueprint $table) {
            $table->dropUnique(['sale_token']);
            $table->dropColumn('sale_token');
        });
    }
};
