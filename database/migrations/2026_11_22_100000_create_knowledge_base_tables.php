<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assistant IA ClientlyX : base de connaissances par entreprise (fiches
 * issues du document de l'entreprise ou apprises d'une réponse humaine),
 * questions restées sans réponse (à apprendre) et consommation IA mensuelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // presentation | prestations | horaires | regles | faq | interdit
            $table->string('section', 20);
            $table->string('title', 255);
            $table->text('content');
            // Autres formulations de la question et mots-clés (cherchés, jamais envoyés).
            $table->text('keywords')->nullable();
            // Identifiant dans le document source (réimport sans doublon).
            $table->string('external_id', 80)->nullable();
            // document (rempli ou importé) | human (appris d'une réponse de l'équipe)
            $table->string('source', 20)->default('document');
            $table->boolean('active')->default(true);
            $table->json('embedding')->nullable();
            $table->string('embedding_model', 80)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'active']);
        });

        Schema::create('knowledge_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            // pending (en attente de l'équipe) | answered (réponse humaine reçue) | learned | dismissed
            $table->string('status', 20)->default('pending');
            $table->text('answer')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->foreignId('knowledge_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);
            $table->unsignedInteger('questions')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'month']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->json('assistant_settings')->nullable();
        });

        // Besoin exprimé par le client à l'assistant et suite demandée (rdv, devis, demo, essai, rappel).
        Schema::table('customers', function (Blueprint $table) {
            $table->text('need')->nullable();
            $table->string('need_kind', 20)->nullable();
            $table->timestamp('need_at')->nullable();
        });

        // Étape en cours du parcours guidé de l'assistant (besoin, nom).
        Schema::table('contacts', function (Blueprint $table) {
            $table->json('assistant_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn('assistant_state'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['need', 'need_kind', 'need_at']));
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('assistant_settings');
        });
        Schema::dropIfExists('ai_usages');
        Schema::dropIfExists('knowledge_questions');
        Schema::dropIfExists('knowledge_entries');
    }
};
