<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agenda : pauses, fermetures et intervenants.
 *
 * - stores.booking_break : pause quotidienne de l'établissement (ex. 12:00-14:00) ;
 * - closures : jours fériés, congés et fermetures exceptionnelles, pour un
 *   établissement ou toute l'entreprise (store_id null) ;
 * - staff_members : intervenants (coiffeuse, praticien, coach…), sans compte
 *   obligatoire, avec leurs horaires (null = ceux de l'établissement), leurs
 *   prestations (aucune = toutes) et leur visibilité sur la page publique ;
 * - appointments.staff_member_id : intervenant du rendez-vous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('booking_break')->nullable();
        });

        Schema::create('closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('label');
            $table->timestamps();

            $table->index(['organization_id', 'starts_on', 'ends_on']);
        });

        Schema::create('staff_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->json('hours')->nullable();
            $table->boolean('bookable_online')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'active']);
        });

        Schema::create('catalog_item_staff_member', function (Blueprint $table) {
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_member_id')->constrained()->cascadeOnDelete();
            $table->primary(['catalog_item_id', 'staff_member_id']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('staff_member_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['staff_member_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['staff_member_id', 'starts_at']);
            $table->dropConstrainedForeignId('staff_member_id');
        });

        Schema::dropIfExists('catalog_item_staff_member');
        Schema::dropIfExists('staff_members');
        Schema::dropIfExists('closures');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('booking_break');
        });
    }
};
