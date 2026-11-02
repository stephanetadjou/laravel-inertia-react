<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formules et abonnements (module « abonnements »). Une formule : prix,
 * période (mois, saison, carte de séances, paiement unique), frais uniques
 * (inscription, assurance) et options (transport, tenue). Un abonnement relie
 * un client (payeur) et, s'il y a lieu, une personne suivie à une formule ;
 * il prépare son échéancier (payment_schedules).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Activité du catalogue (judo, natation…), facultative.
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('price');
            $table->string('period', 20)->default('month');
            $table->unsignedSmallInteger('sessions_count')->nullable();
            $table->json('fees')->nullable();
            $table->json('options')->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('sessions_left')->nullable();
            $table->unsignedBigInteger('price');
            $table->json('options')->nullable();
            $table->foreignId('payment_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
