<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menentia Retail : tickets de vente et import des ventes v2.
 *
 * Les tickets restent dans `purchases` (1 achat = 1 ticket) et leurs lignes
 * dans `purchase_items` : l'encaissement d'un rendez-vous, la fidélité, le
 * parcours client, le RFM, l'analyse par familles et le rapport du soir
 * continuent de lire la même source. On y ajoute :
 * - purchases : n° de ticket / facture, clé d'origine (unique par point de
 *   vente : idempotence des imports), type sale | return, statut valid |
 *   cancelled (une vente n'est jamais supprimée en silence), sous-total,
 *   remise, devise, lot d'import, ticket d'origine d'un retour, code client
 *   et nom de l'acheteur tels que lus dans le fichier. contact_id devient
 *   facultatif : une vente sans numéro valide est conservée, anonyme ;
 * - purchase_items : famille (category_id), article reconnu, code et libellé
 *   bruts, remise, attributs génériques, ligne du fichier d'origine ;
 * - categories.parent_id : catégorie (niveau au-dessus des familles) ;
 * - articles : libellés vus dans les imports, normalisés et dédoublonnés
 *   (pas de catalogue, ni stock, ni variante) ;
 * - customers : client particulier ou entreprise, raison sociale, personne
 *   à contacter, code client du logiciel de caisse / ERP ;
 * - import_mappings, import_batches, import_rows : modèles de colonnes,
 *   lots d'import (fichier privé, empreinte sha256, statut, compteurs) et
 *   lignes du fichier (contenu brut, statut, erreur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // c:CODE quand le code article est fourni, sinon l:libellé normalisé.
            $table->string('match_key', 191);
            $table->string('code', 100)->nullable();
            $table->string('label');
            $table->string('normalized_label');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'match_key']);
            $table->index(['organization_id', 'category_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('type', 16)->default('person');
            $table->string('company_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('external_code', 100)->nullable();

            $table->index(['organization_id', 'external_code']);
        });

        Schema::create('import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->json('mapping');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('file_hash', 64);
            $table->foreignId('mapping_id')->nullable()->constrained('import_mappings')->nullOnDelete();
            $table->json('mapping')->nullable();
            $table->json('headers')->nullable();
            $table->foreignId('default_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('status', 32)->default('uploaded');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('tickets_count')->default(0);
            $table->unsignedInteger('lines_count')->default(0);
            $table->unsignedInteger('anonymous_tickets')->default(0);
            $table->unsignedInteger('unclassified_lines')->default(0);
            $table->unsignedInteger('customers_created')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'file_hash']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('payload');
            $table->json('data')->nullable();
            $table->string('status', 16);
            $table->string('error_code', 32)->nullable();
            $table->string('error_message')->nullable();

            $table->index(['import_batch_id', 'status']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->change();
            $table->string('receipt_number', 100)->nullable();
            $table->string('source_key', 191)->nullable();
            $table->string('type', 16)->default('sale');
            $table->string('status', 16)->default('valid');
            $table->integer('subtotal')->nullable();
            $table->integer('discount_amount')->default(0);
            $table->string('currency', 3)->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('original_purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $table->string('customer_code', 100)->nullable();
            $table->string('buyer_name')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();

            $table->unique(['store_id', 'source_key']);
            $table->index(['store_id', 'purchased_at']);
            $table->index(['store_id', 'receipt_number']);
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->string('article_code', 100)->nullable();
            // Famille ou catégorie telle qu'écrite dans le fichier (« À classer »).
            $table->string('product_category')->nullable();
            $table->integer('discount_amount')->default(0);
            $table->json('properties')->nullable();
            $table->foreignId('import_row_id')->nullable()->constrained()->nullOnDelete();

            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_row_id');
            $table->dropConstrainedForeignId('article_id');
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['article_code', 'product_category', 'discount_amount', 'properties']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'source_key']);
            $table->dropIndex(['store_id', 'purchased_at']);
            $table->dropIndex(['store_id', 'receipt_number']);
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropConstrainedForeignId('original_purchase_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['receipt_number', 'source_key', 'type', 'status', 'subtotal', 'discount_amount', 'currency', 'customer_code', 'buyer_name', 'cancelled_at', 'cancel_reason']);
        });

        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('import_mappings');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'external_code']);
            $table->dropColumn(['type', 'company_name', 'contact_name', 'external_code']);
        });

        Schema::dropIfExists('articles');

        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
