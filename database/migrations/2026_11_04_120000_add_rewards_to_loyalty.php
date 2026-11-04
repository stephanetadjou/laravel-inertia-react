<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Récompenses paramétrables par palier et option « mêmes règles pour toutes les enseignes ». */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            $table->boolean('same_rules')->default(false)->after('ends_on');
        });

        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->string('reward_label')->nullable()->after('discount_percent');
        });

        Schema::table('loyalty_redemptions', function (Blueprint $table) {
            $table->string('reward_label')->nullable()->after('discount_percent');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->string('loyalty_reward_label')->nullable()->after('loyalty_discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', fn (Blueprint $table) => $table->dropColumn('loyalty_reward_label'));
        Schema::table('loyalty_redemptions', fn (Blueprint $table) => $table->dropColumn('reward_label'));
        Schema::table('loyalty_cards', fn (Blueprint $table) => $table->dropColumn('reward_label'));
        Schema::table('loyalty_programs', fn (Blueprint $table) => $table->dropColumn('same_rules'));
    }
};
