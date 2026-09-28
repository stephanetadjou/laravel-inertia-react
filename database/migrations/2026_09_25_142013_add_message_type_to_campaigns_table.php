<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // 'template' (par défaut) ou 'text' (message libre, fenêtre de service 24h uniquement).
            $table->string('message_type')->default('template')->after('template_name');
            $table->text('text_body')->nullable()->after('message_type');
            $table->string('template_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['message_type', 'text_body']);
        });
    }
};
