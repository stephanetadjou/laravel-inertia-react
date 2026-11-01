<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Texte de recherche des fiches clients (nom, entreprise, contact, code
 * client) en minuscules sans accents : recherche « aicha » = « Aïcha » sur
 * SQLite comme sur MySQL. Tenu à jour par le modèle Customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('search_text', 600)->nullable();
        });

        DB::table('customers')->select(['id', 'name', 'company_name', 'contact_name', 'external_code'])->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('customers')->where('id', $row->id)->update(['search_text' => Customer::searchTextFor($row->name, $row->company_name, $row->contact_name, $row->external_code)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};
