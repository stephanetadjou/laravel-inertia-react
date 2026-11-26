<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Agent IA : informations captées dans la conversation (ville, adresse,
 * budget), résumé IA et instinct prospect (chaud / à suivre / froid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('city', 80)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('budget', 60)->nullable();
            $table->text('ai_summary')->nullable();
            $table->timestamp('ai_summary_at')->nullable();
            $table->string('temperature', 10)->nullable()->index();
            $table->string('temperature_reason', 160)->nullable();
            $table->timestamp('temperature_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['temperature']);
            $table->dropColumn(['city', 'address', 'budget', 'ai_summary', 'ai_summary_at', 'temperature', 'temperature_reason', 'temperature_at']);
        });
    }
};
