<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages reçus des clients via le webhook Meta. L'identifiant WhatsApp
 * (wamid) est unique : Meta peut livrer plusieurs fois le même événement,
 * il n'est enregistré qu'une fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('wa_message_id')->unique();
            $table->string('from_phone', 32);
            $table->string('type', 32);
            $table->text('body')->nullable();
            $table->timestamp('received_at');
            $table->json('payload');
            $table->timestamps();

            $table->index(['store_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_inbound_messages');
    }
};
