<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
        });

        // Rattache chaque utilisateur existant au rôle dynamique équivalent à
        // son ancienne valeur users.role (string), pour ne perdre aucun accès.
        $roleNameByLegacyValue = [
            'super_admin' => 'Super administrateur',
            'admin' => 'Administrateur',
            'brand_manager' => 'Responsable de marque',
            'store_manager' => 'Responsable de boutique',
        ];

        DB::table('users')->select('id', 'organization_id', 'role')->get()->each(function ($user) use ($roleNameByLegacyValue) {
            $roleName = $roleNameByLegacyValue[$user->role] ?? 'Administrateur';

            $role = DB::table('roles')
                ->where('organization_id', $user->organization_id)
                ->where('name', $roleName)
                ->first();

            if ($role) {
                DB::table('users')->where('id', $user->id)->update(['role_id' => $role->id]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('super_admin')->after('organization_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
