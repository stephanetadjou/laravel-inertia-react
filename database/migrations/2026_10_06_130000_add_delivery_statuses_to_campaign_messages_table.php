<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statuts renvoyés par le webhook Meta. `status` reste le résultat de
 * l'envoi (sent / failed, un échec signalé plus tard par Meta y compris) ;
 * la remise et la lecture sont des dates, qui ne reviennent jamais en arrière.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_messages', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->index('meta_message_id');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->unsignedInteger('delivered_count')->default(0)->after('sent_count');
            $table->unsignedInteger('read_count')->default(0)->after('delivered_count');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_messages', function (Blueprint $table) {
            $table->dropIndex(['meta_message_id']);
            $table->dropColumn(['delivered_at', 'read_at']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['delivered_count', 'read_count']);
        });
    }
};
