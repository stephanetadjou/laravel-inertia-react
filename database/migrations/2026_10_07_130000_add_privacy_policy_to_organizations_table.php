<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Page publique de politique de confidentialité par entreprise (URL à
 * donner à Meta dans l'app WhatsApp) : adresse /confidentialite/{slug} et
 * coordonnées du responsable du traitement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('name');
            $table->string('legal_name')->nullable();
            $table->string('privacy_email')->nullable();
            $table->string('privacy_phone')->nullable();
            $table->string('privacy_address')->nullable();
            $table->text('privacy_extra')->nullable();
        });

        foreach (DB::table('organizations')->orderBy('id')->get(['id', 'name']) as $organization) {
            $base = Str::slug($organization->name) ?: 'entreprise';
            $slug = $base;
            $i = 2;

            while (DB::table('organizations')->where('slug', $slug)->exists()) {
                $slug = $base.'-'.$i++;
            }

            DB::table('organizations')->where('id', $organization->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'legal_name', 'privacy_email', 'privacy_phone', 'privacy_address', 'privacy_extra']);
        });
    }
};
