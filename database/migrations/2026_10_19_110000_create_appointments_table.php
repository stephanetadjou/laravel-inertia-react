<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rendez-vous des clients, par établissement (store_id) : prise de rendez-vous,
 * confirmation, présence ou absence, notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Membre de l'équipe qui reçoit le client (facultatif).
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->string('service')->nullable();
            // planned, confirmed, done, no_show, cancelled
            $table->string('status')->default('planned');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'starts_at']);
            $table->index(['customer_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
