<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('spent_on');
            $table->unsignedBigInteger('amount');
            $table->string('category', 30);
            $table->string('label');
            $table->string('supplier')->nullable();
            $table->string('method', 20)->nullable();
            $table->string('receipt_path')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
