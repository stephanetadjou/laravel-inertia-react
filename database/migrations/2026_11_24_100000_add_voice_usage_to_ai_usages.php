<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->unsignedInteger('voice_notes')->default(0);
            $table->unsignedInteger('voice_seconds')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->dropColumn(['voice_notes', 'voice_seconds']);
        });
    }
};
