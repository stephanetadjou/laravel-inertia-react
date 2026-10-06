<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Statut VIP par boutique : un client peut être VIP chez Mango et pas chez
 * Adidas. Porté par le contact (relation client ↔ boutique), calculé par
 * App\Services\CustomerLifecycle (top X % des acheteurs de la boutique sur
 * la période VIP). « VIP » n'est plus une étape du parcours du Groupe : les
 * fiches concernées sont reclassées au prochain `crm:refresh-lifecycle`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_vip')->default(false)->after('consent_status');
            $table->timestamp('vip_since')->nullable()->after('is_vip');
            $table->index(['store_id', 'is_vip']);
        });

        // L'étape « vip » du Groupe disparaît : repassée en « active » en
        // attendant le recalcul (qui choisira new/active/at_risk/churned).
        DB::table('customers')->where('lifecycle_stage', 'vip')->update(['lifecycle_stage' => 'active']);
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'is_vip']);
            $table->dropColumn(['is_vip', 'vip_since']);
        });
    }
};
