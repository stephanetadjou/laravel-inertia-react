<?php

use App\Services\FamilyMatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Secteur d'activité et analyse par familles de produits (commerce).
 *
 * - organizations.sector : secteur choisi à l'inscription (beaute, sante,
 *   sport, technique, pro, commerce, autre ; null = organisation historique,
 *   toutes les fonctions affichées) ;
 * - categories.keywords : mots-clés qui rangent un libellé importé dans la
 *   famille (« coca, fanta, jus » pour Boissons) ;
 * - category_rules : correspondances « libellé du fichier → famille »
 *   enregistrées depuis l'écran « À classer » ;
 * - purchases.category_id : famille reconnue de la vente importée,
 *   purchases.article : libellé de l'article tel qu'il figure dans le fichier
 *   (simple texte, aucune fiche produit n'est créée) ;
 * - purchases.properties et catalog_items.properties : attributs génériques
 *   d'une vente ou d'un produit (genre Homme / Femme / Enfant, marque,
 *   taille…), clé => valeur, sans colonne propre à un secteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('sector')->nullable();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->text('keywords')->nullable();
        });

        Schema::create('category_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('raw_label');
            $table->timestamps();

            $table->unique(['organization_id', 'label']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('article')->nullable();
            $table->json('properties')->nullable();
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->json('properties')->nullable();
        });

        // Ventes déjà importées : catégorie texte égale au nom d'une catégorie existante.
        $categories = DB::table('categories')->get(['id', 'organization_id', 'name'])
            ->groupBy('organization_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($c) => [FamilyMatcher::normalize($c->name) => $c->id]));

        DB::table('purchases')
            ->join('stores', 'stores.id', '=', 'purchases.store_id')
            ->whereNotNull('purchases.product_category')
            ->select('stores.organization_id', 'purchases.product_category')
            ->distinct()
            ->get()
            ->each(function ($row) use ($categories) {
                $id = $categories->get($row->organization_id)?->get(FamilyMatcher::normalize($row->product_category));

                if ($id) {
                    DB::table('purchases')
                        ->where('product_category', $row->product_category)
                        ->whereIn('store_id', DB::table('stores')->where('organization_id', $row->organization_id)->select('id'))
                        ->update(['category_id' => $id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('properties');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['article', 'properties']);
        });

        Schema::dropIfExists('category_rules');

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('keywords');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('sector');
        });
    }
};
