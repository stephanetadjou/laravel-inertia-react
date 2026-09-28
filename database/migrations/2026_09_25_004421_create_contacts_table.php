<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone_e164');
            $table->string('consent_status')->default('unknown');
            $table->timestamps();

            $table->unique(['store_id', 'phone_e164']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
