<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relances faites à la main depuis les listes d'actions (message WhatsApp
 * ouvert par lien wa.me et envoyé depuis le téléphone de l'établissement).
 * Une relance enregistrée retire le client de la liste pendant un délai.
 *
 * Et le choix, par entreprise, d'envoyer ou non par l'API WhatsApp Cloud :
 * sans API, campagnes, modèles et paramètres WhatsApp sont masqués. Les
 * organisations existantes la gardent ; une nouvelle inscription démarre sans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            // Liste d'actions d'origine (at_risk, second_purchase, appointment_reminder…).
            $table->string('action');
            $table->string('channel')->default('whatsapp_manual');
            $table->text('message')->nullable();
            $table->timestamp('done_at');
            $table->timestamps();

            $table->index(['customer_id', 'action', 'done_at']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('whatsapp_api_enabled')->default(true);
            // Messages préremplis des listes d'actions, modifiables par l'entreprise.
            $table->json('action_messages')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_api_enabled', 'action_messages']);
        });

        Schema::dropIfExists('customer_follow_ups');
    }
};
