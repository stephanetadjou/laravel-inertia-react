<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modules à la carte (organizations.modules) : modules actifs et leurs
 * réglages. Les entreprises existantes reçoivent les modules de leur secteur,
 * plus ceux qu'elles utilisent déjà (fidélité lancée, plusieurs lieux,
 * catalogue rempli) : rien ne disparaît de leur menu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('modules')->nullable();
        });

        $presets = [
            'beaute' => ['rdv', 'catalogue', 'fidelite'],
            'sante' => ['rdv', 'catalogue'],
            'sport' => ['seances_groupe', 'abonnements', 'tranches', 'catalogue', 'fidelite'],
            'technique' => ['rdv', 'catalogue'],
            'pro' => ['rdv', 'catalogue'],
            'commerce' => ['familles', 'lieux', 'fidelite'],
        ];
        // Organisation historique (sans secteur) ou « autre » : tout ce qui existait avant les modules.
        $legacy = ['rdv', 'catalogue', 'lieux', 'fidelite'];

        DB::table('organizations')->orderBy('id')->each(function ($organization) use ($presets, $legacy) {
            $enabled = $presets[$organization->sector] ?? $legacy;
            $loyalty = json_decode((string) $organization->loyalty_settings, true);

            if (is_array($loyalty) && ! empty($loyalty['enabled'])) {
                $enabled[] = 'fidelite';
            }
            if (DB::table('stores')->where('organization_id', $organization->id)->count() > 1) {
                $enabled[] = 'lieux';
            }
            if ($organization->sector !== 'commerce' && DB::table('catalog_items')->where('organization_id', $organization->id)->exists()) {
                $enabled[] = 'catalogue';
            }
            // Agenda déjà utilisé (rendez-vous enregistrés) : il reste affiché.
            if ($organization->sector !== 'commerce' && DB::table('appointments')->join('stores', 'stores.id', '=', 'appointments.store_id')->where('stores.organization_id', $organization->id)->exists()) {
                $enabled[] = 'rdv';
            }

            DB::table('organizations')->where('id', $organization->id)->update([
                'modules' => json_encode(['enabled' => array_values(array_unique($enabled)), 'settings' => []]),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('modules');
        });
    }
};
