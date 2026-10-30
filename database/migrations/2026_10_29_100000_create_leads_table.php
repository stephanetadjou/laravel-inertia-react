<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes de démonstration envoyées depuis le site vitrine (menentia.com,
 * page Contact). Ce sont des prospects de Menentia, pas les clients d'une
 * organisation : aucune clé vers organizations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company');
            // Numéro tel que saisi, et sa forme E.164 quand il est reconnu.
            $table->string('whatsapp_raw');
            $table->string('whatsapp')->nullable()->index();
            $table->string('city')->nullable();
            // Famille de métiers du site (beaute, sante, sport, technique, pro).
            $table->string('activity')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('consent_at');
            $table->string('source')->default('site');
            $table->string('status')->default('new');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
