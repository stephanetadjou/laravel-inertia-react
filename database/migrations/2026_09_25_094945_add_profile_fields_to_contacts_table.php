<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->date('birthdate')->nullable()->after('phone_e164');
            $table->string('gender')->nullable()->after('birthdate');
            // Attributs libres pour de futurs champs d'analyse (profession, quartier, ...)
            // sans avoir besoin d'une nouvelle migration à chaque fois.
            $table->json('custom_attributes')->nullable()->after('gender');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['birthdate', 'gender', 'custom_attributes']);
        });
    }
};
