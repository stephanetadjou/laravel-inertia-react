<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sans cet index, les analyses relisent toutes les lignes pour chaque ticket
 * (recherche des tickets sans ligne) : plusieurs secondes par requête dès
 * quelques milliers de tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->index('purchase_id', 'purchase_items_purchase_id_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropIndex('purchase_items_purchase_id_lookup_index');
        });
    }
};
