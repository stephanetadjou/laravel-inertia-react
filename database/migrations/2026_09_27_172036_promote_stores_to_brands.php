<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduit le niveau Marque entre Organisation et Boutique : chaque
 * boutique existante (Adidas, Carré, Mango, Grand Store...) devient une
 * Marque, avec une boutique par défaut qui hérite de sa connexion WhatsApp
 * et de tous ses contacts/achats/campagnes existants. Rien n'est perdu.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('stores')->get() as $store) {
            $brandId = DB::table('brands')->insertGetId([
                'organization_id' => $store->organization_id,
                'name' => $store->name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $newStoreId = DB::table('stores')->insertGetId([
                'organization_id' => $store->organization_id,
                'brand_id' => $brandId,
                'name' => $store->name.' — Store principal',
                'whatsapp_phone_number_id' => $store->whatsapp_phone_number_id,
                'whatsapp_access_token' => $store->whatsapp_access_token,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('contacts')->where('store_id', $store->id)->update(['store_id' => $newStoreId]);
            DB::table('purchases')->where('store_id', $store->id)->update(['store_id' => $newStoreId]);
            DB::table('campaign_store')->where('store_id', $store->id)->update(['store_id' => $newStoreId]);

            DB::table('stores')->where('id', $store->id)->delete();
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable(false)->change();
        });
    }

    /**
     * Non réversible automatiquement : fusionner marque + boutique par défaut
     * en une seule boutique demanderait de choisir laquelle des boutiques
     * (s'il y en a plusieurs par marque) récupère les données. À faire à la
     * main si besoin.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->change();
        });
    }
};
