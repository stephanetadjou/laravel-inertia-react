<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Réseaux sociaux de la carte : {facebook, instagram, tiktok, linkedin, youtube, x, link} => URL.
        Schema::table('digital_cards', function (Blueprint $table) {
            $table->json('socials')->nullable()->after('website');
        });
    }

    public function down(): void
    {
        Schema::table('digital_cards', fn (Blueprint $table) => $table->dropColumn('socials'));
    }
};
