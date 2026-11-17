<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Secteur « Vente et location de véhicules » (module « vehicules ») :
 * - la fiche d'un véhicule enrichit la ressource réservable (catégorie,
 *   kilométrage, tarifs, statut, prix de vente, échéances d'entretien) ;
 * - un contrat de location (courte ou longue durée) occupe le véhicule du
 *   départ au retour, avec ou sans chauffeur (un intervenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookable_resources', function (Blueprint $table) {
            $table->string('category', 30)->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->unsignedInteger('price_day')->nullable();
            $table->unsignedInteger('price_week')->nullable();
            $table->unsignedInteger('price_month')->nullable();
            $table->string('status', 20)->default('available');
            $table->unsignedInteger('sale_price')->nullable();
            $table->date('for_sale_since')->nullable();
            $table->date('sold_on')->nullable();
            $table->unsignedInteger('service_due_km')->nullable();
            $table->date('service_due_on')->nullable();
            $table->date('insurance_until')->nullable();
            $table->date('inspection_until')->nullable();
        });

        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('bookable_resources')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('staff_members')->nullOnDelete();
            $table->string('kind', 10)->default('short');
            $table->string('status', 15)->default('reserved');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('departed_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->unsignedInteger('rate')->default(0);
            $table->string('rate_period', 10)->default('day');
            $table->unsignedInteger('amount')->default(0);
            $table->unsignedInteger('deposit')->nullable();
            $table->unsignedInteger('km_out')->nullable();
            $table->unsignedInteger('km_in')->nullable();
            $table->unsignedTinyInteger('fuel_out')->nullable();
            $table->unsignedTinyInteger('fuel_in')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['resource_id', 'starts_at']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rentals');

        Schema::table('bookable_resources', function (Blueprint $table) {
            $table->dropColumn(['category', 'mileage', 'price_day', 'price_week', 'price_month', 'status', 'sale_price', 'for_sale_since', 'sold_on', 'service_due_km', 'service_due_on', 'insurance_until', 'inspection_until']);
        });
    }
};
