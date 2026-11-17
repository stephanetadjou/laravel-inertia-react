<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            // Collaborateur : compte utilisateur (responsable des fiches reçues) et/ou intervenant.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('job_title')->nullable();
            $table->string('company')->nullable();
            $table->string('phone_e164', 20)->nullable();
            $table->string('whatsapp_e164', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->text('bio')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('color', 7)->default('#E8590C');
            $table->boolean('booking_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Journal des visites et des clics (statistiques par carte et par période).
        Schema::create('digital_card_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digital_card_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // view, scan, whatsapp, call, vcard, booking, contact
            $table->timestamp('created_at')->useCurrent();
            $table->index(['digital_card_id', 'type', 'created_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('acquisition_card_id')->nullable()->constrained('digital_cards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acquisition_card_id');
        });
        Schema::dropIfExists('digital_card_events');
        Schema::dropIfExists('digital_cards');
    }
};
