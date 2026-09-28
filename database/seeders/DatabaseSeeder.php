<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(StoreSeeder::class);

        $organization = Organization::firstOrCreate(['name' => 'RETAIL GROUP']);

        Role::seedDefaultsFor($organization);
        $superAdminRole = Role::where('organization_id', $organization->id)->where('name', 'Super administrateur')->firstOrFail();

        User::firstOrCreate(
            ['email' => 'admin@retailgroup-brands.com'],
            [
                'name' => 'Admin RETAIL GROUP',
                'password' => Hash::make('password'),
                'organization_id' => $organization->id,
                'role_id' => $superAdminRole->id,
            ],
        );

        MessageTemplate::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'hello_world', 'language' => 'en_US'],
            ['category' => 'utility', 'description' => 'Template de test fourni par défaut par Meta.', 'is_active' => true],
        );
    }
}
