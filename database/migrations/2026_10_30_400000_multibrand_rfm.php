<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multimarque et RFM v2 (Menentia Retail) :
 * - customer_brand_metrics : chiffres d'un client dans chaque marque
 *   (premier / dernier achat, tickets, CA net) et son RFM dans la marque ;
 *   recalculés après chaque import et chaque nuit (BrandMetrics) ;
 * - customers.brands_count : nombre de marques où le client a acheté
 *   (filtre « multimarques ») ;
 * - customer_segment_history : segments successifs du client (groupe ou
 *   marque) avec date de calcul, période et version des règles ;
 * - organizations.rfm_settings : période d'analyse et seuils des segments
 *   (les 8 segments actuels restent le réglage par défaut).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_brand_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->date('first_purchase_at')->nullable();
            $table->date('last_purchase_at')->nullable();
            $table->unsignedInteger('order_count')->default(0);
            $table->bigInteger('net_spend')->default(0);
            $table->unsignedTinyInteger('rfm_r')->nullable();
            $table->unsignedTinyInteger('rfm_f')->nullable();
            $table->unsignedTinyInteger('rfm_m')->nullable();
            $table->string('rfm_segment', 32)->nullable();
            $table->timestamp('computed_at')->nullable();

            $table->unique(['customer_id', 'brand_id']);
            $table->index(['brand_id', 'rfm_segment']);
            $table->index(['organization_id', 'brand_id']);
        });

        Schema::create('customer_segment_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('segment', 32);
            $table->unsignedTinyInteger('r')->nullable();
            $table->unsignedTinyInteger('f')->nullable();
            $table->unsignedTinyInteger('m')->nullable();
            $table->date('computed_on');
            $table->unsignedSmallInteger('period_months')->default(0);
            $table->unsignedInteger('rules_version')->default(1);
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'brand_id', 'computed_on']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedSmallInteger('brands_count')->default(0);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->json('rfm_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('rfm_settings');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('brands_count');
        });
        Schema::dropIfExists('customer_segment_history');
        Schema::dropIfExists('customer_brand_metrics');
    }
};
