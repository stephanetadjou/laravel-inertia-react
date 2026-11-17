<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lien public « Que souhaitez-vous ? » (réglages par entreprise), accessoires
 * cochés à l'état des lieux, kilomètres en plus facturés au retour, et
 * dépenses rattachées à un véhicule (bénéfice par véhicule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('online_page')->nullable();
        });

        Schema::table('vehicle_inspections', function (Blueprint $table) {
            $table->json('checklist')->nullable();
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->unsignedInteger('km_included')->nullable();
            $table->unsignedInteger('extra_km_amount')->nullable();
        });

        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->foreignId('resource_id')->nullable()->constrained('bookable_resources')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', fn (Blueprint $table) => $table->dropConstrainedForeignId('resource_id'));
        }
        Schema::table('rentals', fn (Blueprint $table) => $table->dropColumn(['km_included', 'extra_km_amount']));
        Schema::table('vehicle_inspections', fn (Blueprint $table) => $table->dropColumn('checklist'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('online_page'));
    }
};
