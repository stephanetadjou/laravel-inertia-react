<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Location et vente de véhicules : demandes (pipeline), réservations
 * (colonnes ajoutées aux contrats de la flotte), états des lieux, ventes
 * de véhicules (acheteurs suivis), comptes entreprise et photos des véhicules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookable_resources', function (Blueprint $table) {
            $table->json('photos')->nullable();
            $table->string('sale_description', 1000)->nullable();
        });

        Schema::create('vehicle_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('bookable_resources')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // interested → test_drive → offer → reserved → sold, ou lost.
            $table->string('status', 15)->default('interested');
            $table->dateTime('test_drive_at')->nullable();
            $table->unsignedInteger('offer_amount')->nullable();
            $table->string('trade_in_label')->nullable();
            $table->unsignedInteger('trade_in_value')->nullable();
            $table->unsignedTinyInteger('installments_count')->nullable();
            $table->foreignId('payment_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lost_reason', 30)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('sold_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('vehicle_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('need', 5)->default('rent');
            $table->string('purpose', 15)->nullable();
            $table->string('category', 30)->nullable();
            $table->foreignId('resource_id')->nullable()->constrained('bookable_resources')->nullOnDelete();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('with_driver')->default(false);
            $table->unsignedInteger('budget')->nullable();
            $table->string('source', 15)->default('whatsapp');
            // new → quoted → won (réservée ou vendue) | lost (raison obligatoire).
            $table->string('status', 10)->default('new');
            $table->string('lost_reason', 30)->nullable();
            $table->string('lost_note')->nullable();
            $table->foreignId('quote_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('rental_id')->nullable()->constrained('rentals')->nullOnDelete();
            $table->foreignId('vehicle_sale_id')->nullable()->constrained('vehicle_sales')->nullOnDelete();
            $table->dateTime('quoted_at')->nullable();
            $table->dateTime('reminded_24_at')->nullable();
            $table->dateTime('reminded_72_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->foreignId('vehicle_request_id')->nullable()->constrained('vehicle_requests')->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('pickup_place')->nullable();
            $table->string('driver_name')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->dateTime('thanked_at')->nullable();
            // Caution : held (encaissée au départ) → returned | retained | partial.
            $table->string('deposit_status', 10)->nullable();
            $table->unsignedInteger('deposit_retained')->nullable();
            $table->string('deposit_reason', 15)->nullable();
            $table->string('deposit_note')->nullable();
            $table->dateTime('deposit_closed_at')->nullable();
        });

        Schema::create('vehicle_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_id')->constrained('rentals')->cascadeOnDelete();
            $table->string('phase', 3);
            $table->unsignedInteger('mileage')->nullable();
            $table->unsignedTinyInteger('fuel')->nullable();
            $table->json('photos')->nullable();
            $table->text('damages')->nullable();
            $table->string('signed_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['rental_id', 'phase']);
        });

        Schema::create('rental_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->unsignedInteger('day_rate')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_accounts');
        Schema::dropIfExists('vehicle_inspections');
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_request_id');
            $table->dropConstrainedForeignId('quote_id');
            $table->dropColumn(['pickup_place', 'driver_name', 'confirmed_at', 'reminder_sent_at', 'thanked_at', 'deposit_status', 'deposit_retained', 'deposit_reason', 'deposit_note', 'deposit_closed_at']);
        });
        Schema::dropIfExists('vehicle_requests');
        Schema::dropIfExists('vehicle_sales');
        Schema::table('bookable_resources', function (Blueprint $table) {
            $table->dropColumn(['photos', 'sale_description']);
        });
    }
};
