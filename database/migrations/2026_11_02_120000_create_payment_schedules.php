<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiement en plusieurs fois (module « tranches ») : un échéancier par
 * client (payeur, éventuellement pour une personne suivie), ses tranches
 * (montant, échéance, payée ou non). Encaisser une tranche crée une vente
 * (purchases) : le CA, la fiche client et la fidélité lisent la même source.
 *
 * customer_follow_ups.reference : relance liée à un événement précis
 * (tranche, séance annulée…) pour ne la proposer qu'une fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // Payeur (le parent) et, s'il y a lieu, la personne suivie concernée.
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('subscription_id')->nullable()->index();
            $table->string('label');
            $table->unsignedBigInteger('total_amount');
            $table->string('status', 20)->default('active');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('label')->nullable();
            $table->unsignedBigInteger('amount');
            $table->date('due_on');
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'due_on']);
        });

        Schema::table('customer_follow_ups', function (Blueprint $table) {
            $table->string('reference', 64)->nullable()->after('appointment_id');
            $table->index(['action', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_follow_ups', function (Blueprint $table) {
            $table->dropIndex(['action', 'reference']);
            $table->dropColumn('reference');
        });
        Schema::dropIfExists('installments');
        Schema::dropIfExists('payment_schedules');
    }
};
