<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des prospects (module « prospects ») : étape commerciale d'une fiche
 * avant son premier achat (étapes propres à l'entreprise, rangées sur 4 étapes
 * internes fixes), commercial responsable de la fiche, historique des passages
 * (taux de passage par commercial), code de parrainage par commercial.
 * Visites et démos sont des rendez-vous de l'agenda (appointments.kind), avec
 * position GPS facultative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('prospect_stage', 40)->nullable();
            $table->timestamp('prospect_stage_at')->nullable();
            $table->string('lost_reason')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['organization_id', 'prospect_stage']);
        });

        Schema::create('prospect_stage_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Commercial responsable au moment du passage (taux de passage par commercial).
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_stage', 40)->nullable();
            $table->string('to_stage', 40);
            $table->string('to_kind', 20);
            $table->timestamp('changed_at');
            $table->index(['customer_id', 'changed_at']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('kind', 20)->default('rdv');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 20)->nullable()->unique();
        });

        Schema::table('roles', function (Blueprint $table) {
            // Commercial : ne voit que les fiches dont il est responsable, et ses chiffres.
            $table->boolean('own_records_only')->default(false);
        });

        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);
            $table->unsignedInteger('visits')->default(0);
            $table->unsignedInteger('demos')->default(0);
            $table->unsignedInteger('trials')->default(0);
            $table->unsignedInteger('customers')->default(0);
            $table->unsignedBigInteger('revenue')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('own_records_only'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('referral_code'));
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn(['kind', 'latitude', 'longitude']));
        Schema::dropIfExists('prospect_stage_changes');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'prospect_stage']);
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropColumn(['prospect_stage', 'prospect_stage_at', 'lost_reason']);
        });
    }
};
