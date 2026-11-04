<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fidélité par enseigne : programmes datés, règles par enseigne, état du
 * client par enseigne (recalculé à partir des tickets), journal des
 * mouvements et remises utilisées saisies à la main.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });

        Schema::create('loyalty_program_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('threshold');
            $table->unsignedBigInteger('tranche_amount');
            $table->unsignedInteger('points_per_tranche')->default(1);
            $table->unsignedInteger('activation_bonus')->default(0);
            $table->json('tiers');
            $table->unsignedSmallInteger('validity_months')->default(12);
            $table->boolean('reset_after_use')->default(true);
            $table->timestamps();

            $table->unique(['loyalty_program_id', 'brand_id']);
        });

        Schema::create('loyalty_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loyalty_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('none');
            $table->date('activated_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->integer('points')->default(0);
            $table->bigInteger('cycle_amount')->default(0);
            $table->bigInteger('program_amount')->default(0);
            $table->unsignedSmallInteger('discount_percent')->nullable();
            $table->unsignedInteger('tier_points')->nullable();
            $table->unsignedInteger('next_tier_points')->nullable();
            $table->unsignedInteger('points_to_next_tier')->nullable();
            $table->bigInteger('amount_to_threshold')->default(0);
            $table->unsignedSmallInteger('discounts_used')->default(0);
            $table->date('last_purchase_on')->nullable();
            $table->timestamp('computed_at')->nullable();

            $table->unique(['loyalty_program_id', 'brand_id', 'customer_id']);
            $table->index(['organization_id', 'status']);
            $table->index('customer_id');
        });

        Schema::create('loyalty_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loyalty_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->integer('points')->default(0);
            $table->integer('balance')->default(0);
            $table->bigInteger('amount')->nullable();
            $table->unsignedSmallInteger('discount_percent')->nullable();
            $table->date('happened_on');
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();

            $table->index(['customer_id', 'loyalty_program_id', 'brand_id']);
            $table->index(['organization_id', 'type', 'happened_on']);
        });

        Schema::create('loyalty_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loyalty_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('used_on');
            $table->unsignedSmallInteger('discount_percent');
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamps();

            $table->index(['customer_id', 'loyalty_program_id', 'brand_id']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            // Remise fidélité utilisée sur ce ticket (0 = utilisée, pourcentage non précisé).
            $table->unsignedSmallInteger('loyalty_discount_percent')->nullable()->after('discount_amount');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable()->after('birthdate');
            $table->string('district')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['email', 'district']));
        Schema::table('purchases', fn (Blueprint $table) => $table->dropColumn('loyalty_discount_percent'));
        Schema::dropIfExists('loyalty_redemptions');
        Schema::dropIfExists('loyalty_movements');
        Schema::dropIfExists('loyalty_cards');
        Schema::dropIfExists('loyalty_program_rules');
        Schema::dropIfExists('loyalty_programs');
    }
};
