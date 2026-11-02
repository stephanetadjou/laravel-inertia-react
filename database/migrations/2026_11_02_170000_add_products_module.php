<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Module « Vente de produits » (produits en complément des prestations) :
 * gardé pour les entreprises qui ont déjà des produits au catalogue et pour
 * les organisations historiques ou « autre », qui voyaient tout.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizations')->whereNotNull('modules')->orderBy('id')->each(function ($organization) {
            $modules = json_decode((string) $organization->modules, true) ?: [];
            $enabled = $modules['enabled'] ?? [];

            $keep = $organization->sector === null || $organization->sector === 'autre'
                || DB::table('catalog_items')->where('organization_id', $organization->id)->where('type', 'product')->exists();

            if ($keep && ! in_array('produits', $enabled, true)) {
                $modules['enabled'] = [...$enabled, 'produits'];
                DB::table('organizations')->where('id', $organization->id)->update(['modules' => json_encode($modules)]);
            }
        });
    }

    public function down(): void {}
};
