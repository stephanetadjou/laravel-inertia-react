<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // super_admin : paramètre l'organisation (marques, boutiques, utilisateurs).
            // admin : vision et communication sur toutes les boutiques, mais ne configure pas l'organisation.
            // brand_manager : limité aux boutiques de sa marque (brand_id).
            // store_manager : limité à sa seule boutique (store_id).
            $table->string('role')->default('super_admin')->after('organization_id');
            $table->foreignId('brand_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->after('brand_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
            $table->dropConstrainedForeignId('store_id');
            $table->dropColumn('role');
        });
    }
};
