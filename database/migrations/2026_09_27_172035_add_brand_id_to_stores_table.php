<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Nullable ici : la migration suivante (promote_stores_to_brands)
            // remplit cette colonne puis la rend obligatoire.
            $table->foreignId('brand_id')->nullable()->after('organization_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
