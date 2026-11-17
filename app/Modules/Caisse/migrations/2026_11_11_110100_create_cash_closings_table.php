<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedBigInteger('opening_float')->default(0);
            $table->bigInteger('cash_expected')->default(0);
            $table->bigInteger('cash_counted')->default(0);
            $table->bigInteger('difference')->default(0);
            $table->unsignedBigInteger('total_in')->default(0);
            $table->unsignedBigInteger('total_out')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->unique(['store_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closings');
    }
};
