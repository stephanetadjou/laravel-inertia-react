<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue de l'entreprise : prestations (durée, réservables dans l'agenda)
 * et produits (vendus, pas de réservation), dans une seule liste.
 *
 * - catalog_item_store : établissements où l'article est proposé (aucun = tous) ;
 * - appointment_items : prestations choisies pour un rendez-vous (nom, prix et
 *   durée copiés au moment du choix) ; appointments.purchase_id : vente créée
 *   à l'encaissement ;
 * - purchase_items : lignes d'une vente. Les ventes existantes (une catégorie
 *   et un montant) restent valables sans ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // service (prestation) ou product (produit)
            $table->string('type');
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('price')->default(0);
            // Prestations uniquement.
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            // Produits uniquement (code article, code-barres…).
            $table->string('reference')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'type', 'active']);
        });

        Schema::create('catalog_item_store', function (Blueprint $table) {
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->primary(['catalog_item_id', 'store_id']);
        });

        Schema::create('appointment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedSmallInteger('duration_minutes')->default(0);
            $table->timestamps();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            // service, product, ou null pour une ligne libre.
            $table->string('type')->nullable();
            $table->string('name');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamps();

            $table->index(['catalog_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_id');
        });

        Schema::dropIfExists('appointment_items');
        Schema::dropIfExists('catalog_item_store');
        Schema::dropIfExists('catalog_items');
    }
};
