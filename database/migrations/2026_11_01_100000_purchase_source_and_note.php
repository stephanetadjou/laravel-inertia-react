<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Origine d'une vente (import, encaissement d'un rendez-vous, saisie
 * manuelle) et note libre. Migration additive : les ventes existantes sont
 * rattachées à leur origine quand elle se déduit (lot d'import, rendez-vous).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('source', 20)->nullable();
            $table->text('note')->nullable();
        });

        DB::table('purchases')->whereNotNull('import_batch_id')->update(['source' => 'import']);
        DB::table('purchases')->whereIn('id', DB::table('appointments')->whereNotNull('purchase_id')->select('purchase_id'))->update(['source' => 'appointment']);
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['source', 'note']);
        });
    }
};
