<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // quote = devis, invoice = facture (acompte, solde ou complète), credit = avoir.
            $table->string('type', 10);
            $table->string('kind', 10)->default('full');
            $table->foreignId('quote_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('number', 30);
            $table->string('status', 12)->default('draft');
            $table->string('title')->nullable();
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedTinyInteger('deposit_percent')->nullable();
            $table->text('notes')->nullable();
            $table->string('token', 40)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_by_name')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->timestamp('last_reminder_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'type', 'status']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('method', 20)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
