<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planning des séances d'un abonnement (salle de sport, cours particuliers…) :
 * jours et heures, durée, suivi par un intervenant ou en autonomie ; les
 * séances remplissent l'agenda (appointments.subscription_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // [{weekday: 1-7, time: "HH:MM"}]
            $table->json('planning')->nullable();
            $table->unsignedSmallInteger('session_minutes')->nullable();
            $table->boolean('coached')->default(false);
            $table->foreignId('staff_member_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $t) => $t->dropConstrainedForeignId('subscription_id'));
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('staff_member_id');
            $t->dropColumn(['planning', 'session_minutes', 'coached']);
        });
    }
};
