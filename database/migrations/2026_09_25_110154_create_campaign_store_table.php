<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_store', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unique(['campaign_id', 'store_id']);
        });

        // Bascule des campagnes existantes (une seule boutique) vers la table pivot,
        // avant de retirer campaigns.store_id qui ne peut plus représenter qu'une
        // seule boutique par campagne.
        DB::table('campaigns')->whereNotNull('store_id')->get(['id', 'store_id'])->each(function ($campaign) {
            DB::table('campaign_store')->insert([
                'campaign_id' => $campaign->id,
                'store_id' => $campaign->store_id,
            ]);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
        });

        DB::table('campaign_store')->orderBy('id')->get()->each(function ($row) {
            DB::table('campaigns')->where('id', $row->campaign_id)->update(['store_id' => $row->store_id]);
        });

        Schema::dropIfExists('campaign_store');
    }
};
