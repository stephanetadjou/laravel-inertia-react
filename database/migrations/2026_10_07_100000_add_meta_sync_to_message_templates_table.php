<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modèles synchronisés depuis Meta : statut d'approbation, structure
 * (composants), texte du corps, nombre de variables {{n}} et format de
 * l'en-tête (IMAGE, TEXT…). Les modèles saisis à la main gardent un statut nul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('status')->nullable()->after('category');
            $table->string('meta_template_id')->nullable()->after('status');
            $table->text('body_text')->nullable();
            $table->unsignedTinyInteger('body_variables')->default(0);
            $table->string('header_format')->nullable();
            $table->json('components')->nullable();
            $table->timestamp('synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn(['status', 'meta_template_id', 'body_text', 'body_variables', 'header_format', 'components', 'synced_at']);
        });
    }
};
