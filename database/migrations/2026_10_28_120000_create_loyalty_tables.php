<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programme de fidélité.
 *
 * - organizations.loyalty_settings : activé, FCFA par point, date de début
 *   (les achats antérieurs ne rapportent rien), paliers ;
 * - loyalty_rewards : récompenses échangeables contre des points ;
 * - loyalty_transactions : mouvements de points d'une fiche client (gain
 *   sur un achat, échange d'une récompense, ajustement, annulation). Le
 *   solde est la somme des mouvements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('loyalty_settings')->nullable();
        });

        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('points_cost');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // earn, redeem, adjust, reverse
            $table->string('type');
            $table->integer('points');
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reward_id')->nullable()->constrained('loyalty_rewards')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['purchase_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_rewards');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('loyalty_settings');
        });
    }
};
