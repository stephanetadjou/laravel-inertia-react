<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NENTIX par secteur (09/11/2026) :
 * - customers : source d'acquisition (Pub Facebook / Instagram / Direct) et données brutes du referral ;
 * - contacts : état de la discussion WhatsApp (à traiter, attribuée, robot en pause, accueil envoyé) ;
 * - whatsapp_outbound_messages : messages de session et réponses automatiques envoyés ;
 * - tasks : tâches de « À faire » (parler à quelqu'un, être rappelé, RDV à recaser…) ;
 * - appointments : confirmation par bouton du rappel de la veille ;
 * - catalog_items.return_after_days : cycle de la prestation (« revenir après N jours ») ;
 * - ad_budgets : budget pub par source et par mois ;
 * - crédits de messages (solde, coût d'un message marketing, mouvements) ;
 * - organizations.auto_replies : accueil, hors horaires, menu à boutons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('acquisition_source', 20)->nullable()->index();
            $table->json('acquisition_data')->nullable();
            $table->timestamp('acquired_at')->nullable();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('last_inbound_at')->nullable()->index();
            $table->timestamp('last_greeting_at')->nullable();
            $table->timestamp('last_away_at')->nullable();
            $table->timestamp('bot_paused_until')->nullable();
            $table->boolean('needs_attention')->default(false)->index();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('whatsapp_outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // text | interactive | template
            $table->string('type', 20);
            // manual (réponse d'un utilisateur) | auto (robot) | reminder (rappel de la veille)
            $table->string('origin', 20)->default('manual');
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('wa_message_id')->nullable()->index();
            $table->string('status', 20)->default('sent');
            $table->text('error')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->index(['contact_id', 'sent_at']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            // talk_to_human | callback | reschedule | other
            $table->string('kind', 30);
            $table->string('title');
            $table->text('details')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('done_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable();
            // confirmed | reschedule : réponse du client au rappel de la veille.
            $table->string('client_response', 20)->nullable();
            $table->timestamp('client_response_at')->nullable();
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('return_after_days')->nullable();
        });

        Schema::create('ad_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('month', 7);
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'source', 'month']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->json('auto_replies')->nullable();
            // null = sans limite (organisations historiques) ; FCFA.
            $table->bigInteger('message_credits')->nullable();
            $table->unsignedInteger('marketing_message_cost')->nullable();
            $table->timestamp('sector_preset_applied_at')->nullable();
        });

        Schema::create('credit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_movements');
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['auto_replies', 'message_credits', 'marketing_message_cost', 'sector_preset_applied_at']));
        Schema::dropIfExists('ad_budgets');
        Schema::table('catalog_items', fn (Blueprint $t) => $t->dropColumn('return_after_days'));
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn(['reminder_sent_at', 'client_response', 'client_response_at']));
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('whatsapp_outbound_messages');
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('assigned_user_id');
            $t->dropColumn(['last_inbound_at', 'last_greeting_at', 'last_away_at', 'bot_paused_until', 'needs_attention']);
        });
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['acquisition_source', 'acquisition_data', 'acquired_at']));
    }
};
