<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segmentation RFM, score client et risque de churn enregistrés sur chaque
 * fiche (calculés pour toute l'organisation par App\Services\CustomerScoring),
 * pour filtrer, trier et construire les listes d'actions sans recalcul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedTinyInteger('rfm_r')->nullable();
            $table->unsignedTinyInteger('rfm_f')->nullable();
            $table->unsignedTinyInteger('rfm_m')->nullable();
            $table->string('rfm_segment')->nullable();
            // Score client de 0 à 100 (pondération des scores R, F, M).
            $table->unsignedTinyInteger('score')->nullable();
            // Intervalle moyen entre deux achats (au moins deux achats).
            $table->unsignedInteger('avg_purchase_interval_days')->nullable();
            $table->date('next_purchase_expected_at')->nullable();
            // null, « medium » ou « high » : retard sur le rythme d'achat habituel.
            $table->string('churn_risk')->nullable();
            $table->timestamp('scored_at')->nullable();

            $table->index(['organization_id', 'rfm_segment']);
            $table->index(['organization_id', 'churn_risk']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'rfm_segment']);
            $table->dropIndex(['organization_id', 'churn_risk']);
            $table->dropColumn([
                'rfm_r', 'rfm_f', 'rfm_m', 'rfm_segment', 'score',
                'avg_purchase_interval_days', 'next_purchase_expected_at', 'churn_risk', 'scored_at',
            ]);
        });
    }
};
