<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Courses / transferts vendus au passager (ex. aéroport → domicile) : une
 * course est un contrat `rentals` de type « ride » qui réserve le véhicule
 * pendant sa durée estimée. Forfaits réglables par entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->string('ride_from')->nullable();
            $table->string('ride_to')->nullable();
            $table->unsignedTinyInteger('ride_passengers')->nullable();
            $table->unsignedTinyInteger('ride_luggage')->nullable();
            $table->string('flight_number', 20)->nullable();
            // booked → en_route → on_board → done, ou cancelled.
            $table->string('ride_status', 12)->nullable();
            $table->dateTime('ride_reminded_at')->nullable();
        });

        Schema::table('vehicle_requests', function (Blueprint $table) {
            $table->string('ride_from')->nullable();
            $table->string('ride_to')->nullable();
            $table->unsignedTinyInteger('ride_passengers')->nullable();
            $table->unsignedTinyInteger('ride_luggage')->nullable();
            $table->string('flight_number', 20)->nullable();
        });

        Schema::create('ride_fares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->unsignedInteger('price');
            $table->unsignedSmallInteger('minutes')->default(60);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_fares');
        Schema::table('vehicle_requests', fn (Blueprint $table) => $table->dropColumn(['ride_from', 'ride_to', 'ride_passengers', 'ride_luggage', 'flight_number']));
        Schema::table('rentals', fn (Blueprint $table) => $table->dropColumn(['ride_from', 'ride_to', 'ride_passengers', 'ride_luggage', 'flight_number', 'ride_status', 'ride_reminded_at']));
    }
};
