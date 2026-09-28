<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // organization : aucune restriction (toutes les boutiques de l'organisation).
            // brand : restreint aux boutiques de users.brand_id.
            // store : restreint à la seule boutique users.store_id.
            $table->string('scope')->default('organization');
            $table->boolean('can_manage_organization')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        // Pré-remplit chaque organisation existante avec les 4 rôles qui
        // existaient jusqu'ici en dur dans le code (App\Models\User::ROLES),
        // pour que la bascule vers des rôles dynamiques ne change le
        // périmètre d'accès d'aucun utilisateur existant.
        $now = now();

        $definitions = [
            ['name' => 'Super administrateur', 'scope' => 'organization', 'can_manage_organization' => true],
            ['name' => 'Administrateur', 'scope' => 'organization', 'can_manage_organization' => true],
            ['name' => 'Responsable de marque', 'scope' => 'brand', 'can_manage_organization' => false],
            ['name' => 'Responsable de boutique', 'scope' => 'store', 'can_manage_organization' => false],
        ];

        DB::table('organizations')->pluck('id')->each(function ($organizationId) use ($definitions, $now) {
            foreach ($definitions as $definition) {
                DB::table('roles')->insertOrIgnore([
                    'organization_id' => $organizationId,
                    'name' => $definition['name'],
                    'scope' => $definition['scope'],
                    'can_manage_organization' => $definition['can_manage_organization'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
