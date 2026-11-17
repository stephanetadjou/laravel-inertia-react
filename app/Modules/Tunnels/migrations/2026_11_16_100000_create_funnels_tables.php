<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Tunnels de vente : la page d'offre, ses demandes (avec leur canal) et la séquence de relances. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug', 80);
            $table->string('template', 40)->nullable();
            $table->string('title');
            $table->string('offer', 500);
            $table->json('benefits')->nullable();
            $table->json('reviews')->nullable();
            $table->json('fields')->nullable();
            // Type de demande créé (cf. App\Support\OnlinePage::CHOICES).
            $table->string('choice', 20)->default('quote');
            $table->dateTime('ends_at')->nullable();
            $table->unsignedSmallInteger('seats')->nullable();
            $table->string('image_path')->nullable();
            $table->string('message', 1000)->nullable();
            $table->unsignedInteger('budget')->nullable();
            $table->unsignedInteger('visits')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('funnel_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_request_id')->nullable()->constrained('vehicle_requests')->nullOnDelete();
            $table->string('source', 20)->default('direct');
            // Séquence : 0 = récapitulatif à envoyer, 1 = rappel J+1, 2 = dernière chance J+3, 3 = terminée.
            $table->unsignedTinyInteger('step')->default(0);
            $table->dateTime('stopped_at')->nullable();
            $table->string('stop_reason', 20)->nullable();
            $table->timestamps();

            $table->index(['funnel_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_leads');
        Schema::dropIfExists('funnels');
    }
};
