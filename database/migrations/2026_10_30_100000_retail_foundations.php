<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menentia Retail, fondations :
 * - organizations.currency / timezone : devise (XAF par défaut) et fuseau
 *   horaire (Africa/Libreville) de l'entreprise ;
 * - stores.code / city / is_active : code du point de vente (celui du
 *   logiciel de caisse, sert à reconnaître la boutique à l'import), ville,
 *   point de vente fermé (conservé pour l'historique) ;
 * - roles.can_import / can_export : droits d'importer des ventes et
 *   d'exporter des données (rôles types Marketing, Opérateur d'import…) ;
 * - audit_logs : journal des actions sensibles (imports, exports, fusions,
 *   corrections de vente, marques, boutiques, utilisateurs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('currency', 3)->default('XAF');
            $table->string('timezone', 64)->default('Africa/Libreville');
            // Niveau d'analyse des ventes : product (article), family, category.
            $table->string('product_analysis_level', 16)->default('family');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->string('code', 50)->nullable();
            $table->string('city', 100)->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('can_import')->default(true);
            $table->boolean('can_export')->default(true);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description')->nullable();
            $table->json('payload')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['can_import', 'can_export']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['code', 'city', 'is_active']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['currency', 'timezone', 'product_analysis_level']);
        });
    }
};
