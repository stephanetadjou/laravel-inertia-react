<?php

namespace App\Modules\Location\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Segments de relance du loueur, utilisables dans Fiches clients, les
 * groupes et les campagnes (critère `follow=location:<clé>`).
 */
class LocationSegments
{
    public const SEGMENTS = [
        'renters' => 'Location : clients ayant déjà loué',
        'event' => 'Location : clients événements',
        'business' => 'Location : entreprises',
        'diaspora' => 'Location : diaspora',
        'buyers' => 'Location : acheteurs intéressés',
        'airport' => 'Location : clients transferts aéroport',
    ];

    public static function label(string $key): string
    {
        return self::SEGMENTS[$key] ?? 'Location';
    }

    /** Identifiants des fiches du segment (une seule colonne). */
    public static function query(int $organizationId, string $key): Builder
    {
        $purpose = fn (string $purpose) => DB::table('vehicle_requests')->where('organization_id', $organizationId)->where('purpose', $purpose)->select('customer_id');

        return match ($key) {
            'event' => $purpose('event'),
            'diaspora' => $purpose('diaspora'),
            'business' => DB::table('customers')->where('organization_id', $organizationId)->where('type', 'business')->select('id')
                ->union($purpose('business'))
                ->union(DB::table('rental_accounts')->where('organization_id', $organizationId)->select('customer_id')),
            'airport' => DB::table('rentals')->where('organization_id', $organizationId)->where('kind', 'ride')->where('ride_status', '!=', 'cancelled')->select('customer_id')
                ->union(DB::table('vehicle_requests')->where('organization_id', $organizationId)->where('need', 'ride')->select('customer_id')),
            'buyers' => DB::table('vehicle_sales')->where('organization_id', $organizationId)->whereIn('status', ['interested', 'test_drive', 'offer'])->select('customer_id'),
            default => DB::table('rentals')->where('organization_id', $organizationId)->whereIn('status', ['ongoing', 'returned'])->select('customer_id'),
        };
    }
}
