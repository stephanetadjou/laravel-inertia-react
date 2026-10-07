<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trace du consentement WhatsApp : date et origine (import, saisie manuelle,
 * réponse « Stop »…), exigées pour justifier un opt-in auprès de Meta. Les
 * statuts déjà connus sont marqués « historique », sans date certaine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('consent_at')->nullable()->after('consent_status');
            $table->string('consent_source', 64)->nullable()->after('consent_at');
        });

        DB::table('contacts')->whereIn('consent_status', ['opt_in', 'opt_out'])->update(['consent_source' => 'historique']);
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['consent_at', 'consent_source']);
        });
    }
};
