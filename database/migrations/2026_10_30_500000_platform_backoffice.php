<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Back-office plateforme Menentia : super-admins, offre et essai de chaque
 * entreprise, suivi des demandes de démo, journal plateforme. Migration
 * additive : les entreprises existantes passent « actives ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('plan', 20)->default('solo');
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->text('note')->nullable();
            $table->timestamp('contacted_at')->nullable();
        });
        DB::table('leads')->whereNotIn('status', ['new', 'contacted', 'converted', 'lost'])->update(['status' => 'new']);

        Schema::create('platform_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('description')->nullable();
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_logs');
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['note', 'contacted_at']));
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['plan', 'status', 'trial_ends_at', 'suspended_at', 'suspension_reason']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_platform_admin'));
    }
};
