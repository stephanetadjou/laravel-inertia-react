<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Langue de l'interface : choix de l'utilisateur, sinon langue de l'entreprise (fr par défaut).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('email');
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('locale', 5)->default('fr')->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('locale'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('locale'));
    }
};
