<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agents IA (module agents_ia) : assistants qui répondent aux messages
 * WhatsApp avec le fournisseur choisi par l'entreprise (sa propre clé API,
 * chiffrée). Réglés en 5 étapes : persona, modèle, connaissances, sécurité,
 * paramètres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // 1. Persona
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('tone', 20)->default('chaleureux');
            $table->text('instructions')->nullable();
            // 2. Modèle
            $table->string('provider', 20);
            $table->string('model', 80);
            $table->text('api_key');
            // 3. Connaissances
            $table->boolean('use_business_info')->default(true);
            $table->text('knowledge')->nullable();
            // 4. Sécurité
            $table->text('forbidden_topics')->nullable();
            $table->text('handoff_message')->nullable();
            $table->unsignedSmallInteger('max_replies_per_day')->default(20);
            // 5. Paramètres
            $table->json('store_ids')->nullable();
            $table->string('schedule', 20)->default('always');
            $table->boolean('active')->default(false);
            // Suivi
            $table->unsignedInteger('replies_count')->default(0);
            $table->timestamp('last_reply_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agents');
    }
};
