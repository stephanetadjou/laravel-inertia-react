<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        // Pré-remplit la liste avec les catégories déjà utilisées dans les
        // achats existants (importés en texte libre jusqu'ici).
        $now = now();

        DB::table('purchases')
            ->join('stores', 'stores.id', '=', 'purchases.store_id')
            ->whereNotNull('purchases.product_category')
            ->where('purchases.product_category', '!=', '')
            ->select('stores.organization_id', 'purchases.product_category')
            ->distinct()
            ->get()
            ->each(function ($row) use ($now) {
                DB::table('categories')->insertOrIgnore([
                    'organization_id' => $row->organization_id,
                    'name' => $row->product_category,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
