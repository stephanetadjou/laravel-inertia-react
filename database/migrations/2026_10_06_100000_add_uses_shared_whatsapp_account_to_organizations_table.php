<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le compte Meta partagé (.env) n'est plus un repli pour toutes les
 * organisations : une entreprise inscrite connecte son propre numéro. Les
 * organisations déjà présentes gardent le repli pour ne rien casser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('uses_shared_whatsapp_account')->default(false)->after('name');
        });

        DB::table('organizations')->update(['uses_shared_whatsapp_account' => true]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('uses_shared_whatsapp_account');
        });
    }
};
