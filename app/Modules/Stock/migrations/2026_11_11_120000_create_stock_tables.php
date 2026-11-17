<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fiche de stock d'un produit du catalogue (un stock par entreprise).
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->unsignedInteger('min_quantity')->default(2);
            $table->unsignedBigInteger('cost_price')->nullable();
            $table->string('supplier')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->integer('quantity_after');
            // sale, cancel, receipt (entrée), adjustment (inventaire)
            $table->string('reason', 12);
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_items');
    }
};
