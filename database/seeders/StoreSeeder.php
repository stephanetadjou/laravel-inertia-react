<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
    /**
     * RETAIL GROUP et ses 4 enseignes (cf. cahier des charges §2), chacune
     * une Marque avec une boutique par défaut.
     */
    public function run(): void
    {
        $organization = Organization::firstOrCreate(['name' => 'RETAIL GROUP']);

        foreach (['Adidas', 'Carré', 'Mango', 'Grand Store'] as $name) {
            $brand = Brand::firstOrCreate(
                ['organization_id' => $organization->id, 'name' => $name],
            );

            Store::firstOrCreate(
                ['brand_id' => $brand->id, 'name' => $name.' — Store principal'],
                ['organization_id' => $organization->id],
            );
        }
    }
}
