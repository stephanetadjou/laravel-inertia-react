<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Services\BrandMetrics;
use App\Services\CustomerScoring;
use App\Services\LoyaltyEngine;
use Illuminate\Console\Command;

/**
 * Cas pédagogiques de fidélité sur RETAILGROUP MODE (données fictives) : un
 * client au nom parlant par règle à comprendre. La commande pose le programme
 * de la période en cours, crée les tickets manquants, recalcule, puis compare
 * chaque carte au résultat attendu. Sans danger si on la relance.
 * Voir docs/fidelite-cas-pedagogiques.md.
 */
class SeedLoyaltyCases extends Command
{
    protected $signature = 'demo:fidelite-cas {--force : Autoriser l’exécution en production}';

    protected $description = 'Pose le programme 2026-2027 et crée les cas pédagogiques de fidélité sur RETAILGROUP MODE (données fictives)';

    public const PROGRAM = 'Fidélité 2026-2027';

    private const STARTS_ON = '2026-10-01';

    private const ENDS_ON = '2027-09-30';

    private const RULES = [
        'Adidas' => ['threshold' => 400000, 'tranche_amount' => 10000, 'activation_bonus' => 50, 'tiers' => [['points' => 300, 'percent' => 10], ['points' => 350, 'percent' => 15]]],
        'Mango' => ['threshold' => 400000, 'tranche_amount' => 10000, 'activation_bonus' => 50, 'tiers' => [['points' => 350, 'percent' => 10], ['points' => 400, 'percent' => 15]]],
        'Carré' => ['threshold' => 650000, 'tranche_amount' => 15000, 'activation_bonus' => 75, 'tiers' => [['points' => 450, 'percent' => 10], ['points' => 550, 'percent' => 15]]],
    ];

    /**
     * Cas : nom, tickets [enseigne, n° du magasin, date, montant, remise fidélité],
     * et cartes attendues par enseigne [statut, points, récompense disponible].
     */
    public const CASES = [
        ['Cas 01 Sous le seuil', [['Adidas', 0, '2026-10-05', 150000], ['Adidas', 0, '2026-10-20', 100000]], ['Adidas' => ['none', 0, null]]],
        ['Cas 02 Pile au seuil Adidas', [['Adidas', 0, '2026-10-03', 250000], ['Adidas', 0, '2026-10-18', 150000]], ['Adidas' => ['active', 90, null]]],
        ['Cas 03 Exemple Adidas 43+50', [['Adidas', 0, '2026-10-06', 430000]], ['Adidas' => ['active', 93, null]]],
        ['Cas 04 Carré 43+75', [['Carré', 0, '2026-10-07', 400000], ['Carré', 0, '2026-10-22', 250000]], ['Carré' => ['active', 118, null]]],
        ['Cas 05 Tranches sur le cumul', [['Adidas', 0, '2026-10-02', 400000], ['Adidas', 0, '2026-10-12', 7000], ['Adidas', 0, '2026-10-13', 7000]], ['Adidas' => ['active', 91, null]]],
        ['Cas 06 Proche du seuil', [['Mango', 0, '2026-10-09', 350000]], ['Mango' => ['none', 0, null]]],
        ['Cas 07 Proche palier 10 pourcent', [['Adidas', 0, '2026-10-01', 1200000], ['Adidas', 0, '2026-10-16', 1200000]], ['Adidas' => ['active', 290, null]]],
        ['Cas 08 Palier 10 pourcent atteint', [['Adidas', 0, '2026-10-02', 1300000], ['Adidas', 0, '2026-10-19', 1200000]], ['Adidas' => ['active', 300, 'Remise de 10 %']]],
        ['Cas 09 Palier 15 pourcent atteint', [['Mango', 0, '2026-10-04', 2000000], ['Mango', 0, '2026-10-21', 1500000]], ['Mango' => ['active', 400, 'Remise de 15 %']]],
        ['Cas 10 Récompense utilisée puis recommence', [['Adidas', 0, '2026-10-03', 2500000], ['Adidas', 0, '2026-10-24', 120000, 10]], ['Adidas' => ['active', 12, null]]],
        ['Cas 11 Retour qui retire des points', [['Adidas', 0, '2026-10-08', 500000], ['Adidas', 0, '2026-10-15', -60000]], ['Adidas' => ['active', 94, null]]],
        ['Cas 12 Multi-enseignes compteurs séparés', [['Adidas', 0, '2026-10-10', 500000], ['Mango', 0, '2026-10-11', 200000]], ['Adidas' => ['active', 100, null], 'Mango' => ['none', 0, null]]],
        ['Cas 13 Deux magasins même enseigne', [['Adidas', 0, '2026-10-10', 250000], ['Adidas', 1, '2026-10-17', 250000]], ['Adidas' => ['active', 100, null]]],
        ['Cas 14 Achat avant le programme', [['Adidas', 0, '2026-09-20', 900000], ['Adidas', 0, '2026-10-20', 100000]], ['Adidas' => ['none', 0, null]]],
        // Sans téléphone : aucune fiche client, aucune carte.
        ['Cas 15 Vente anonyme', [['Adidas', 0, '2026-10-25', 900000]], []],
    ];

    public function handle(LoyaltyEngine $engine): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Données fictives : refusé en production sans --force.');

            return self::FAILURE;
        }

        $organization = Organization::where('slug', 'retailgroup-mode')->first();

        if (! $organization) {
            $this->error('Chargez d’abord la démo : php artisan demo:retailgroup-mode');

            return self::FAILURE;
        }

        $brands = Brand::where('organization_id', $organization->id)->get()->keyBy('name');
        $program = $this->program($organization, $brands);

        foreach (self::CASES as $number => [$name, $tickets]) {
            $this->tickets($number + 1, $name, $tickets, $brands);
        }

        app(CustomerScoring::class)->refreshOrganization($organization);
        app(BrandMetrics::class)->refreshOrganization($organization);
        $engine->recalculate($organization);

        return $this->check($organization, $program, $brands);
    }

    private function program(Organization $organization, $brands): LoyaltyProgram
    {
        $program = LoyaltyProgram::where('organization_id', $organization->id)->where('name', self::PROGRAM)->first()
            ?? LoyaltyProgram::where('organization_id', $organization->id)->where('name', 'Fidélité novembre')->first()
            ?? new LoyaltyProgram(['organization_id' => $organization->id]);

        $program->fill(['name' => self::PROGRAM, 'starts_on' => self::STARTS_ON, 'ends_on' => self::ENDS_ON, 'same_rules' => false])->save();

        foreach (self::RULES as $brand => $rule) {
            $program->rules()->updateOrCreate(['brand_id' => $brands[$brand]->id], [...$rule, 'points_per_tranche' => 1, 'validity_months' => 12, 'reset_after_use' => true]);
        }

        // Un ticket ne doit compter que dans un programme : un programme qui chevauche passe après celui-ci.
        LoyaltyProgram::where('organization_id', $organization->id)->whereKeyNot($program->id)
            ->whereDate('starts_on', '<=', self::ENDS_ON)->whereDate('ends_on', '>=', self::STARTS_ON)->get()
            ->each(function (LoyaltyProgram $other) use ($program) {
                $days = (int) $other->starts_on->diffInDays($other->ends_on);
                $start = $program->ends_on->copy()->addDay();
                $other->update(['starts_on' => $start, 'ends_on' => $start->copy()->addDays($days)]);
                $this->warn("« {$other->name} » déplacé au {$start->format('d/m/Y')} pour éviter le chevauchement.");
            });

        return $program;
    }

    /** @param list<array<int, mixed>> $tickets */
    private function tickets(int $number, string $name, array $tickets, $brands): void
    {
        $code = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
        $anonymous = str_contains($name, 'anonyme');

        foreach ($tickets as $i => $ticket) {
            [$brand, $storeIndex, $date, $amount] = $ticket;
            $store = Store::where('brand_id', $brands[$brand]->id)->orderBy('id')->skip($storeIndex)->first();
            $receipt = "CAS{$code}-".($i + 1);

            if (Purchase::withCancelled()->where('store_id', $store->id)->where('source_key', "r:{$receipt}")->exists()) {
                continue;
            }

            $contact = $anonymous ? null : Contact::firstOrCreate(
                ['store_id' => $store->id, 'phone_e164' => "2416900{$code}00"],
                ['name' => $name, 'consent_status' => 'unknown'],
            );

            Purchase::create([
                'contact_id' => $contact?->id,
                'store_id' => $store->id,
                'amount' => $amount,
                'quantity' => $amount < 0 ? -1 : 1,
                'subtotal' => $amount,
                'purchased_at' => $date,
                'receipt_number' => $receipt,
                'source_key' => "r:{$receipt}",
                'type' => $amount < 0 ? Purchase::TYPE_RETURN : Purchase::TYPE_SALE,
                'loyalty_discount_percent' => $ticket[4] ?? null,
                'source' => 'import',
                'buyer_name' => $anonymous ? $name : null,
            ]);
        }
    }

    /** Compare chaque carte au résultat attendu et affiche le bilan. */
    private function check(Organization $organization, LoyaltyProgram $program, $brands): int
    {
        $lines = [];
        $wrong = 0;

        foreach (self::CASES as [$name, , $expected]) {
            $customer = Customer::where('organization_id', $organization->id)->where('name', $name)->first();

            foreach ($expected as $brand => [$status, $points, $reward]) {
                $card = $customer ? LoyaltyCard::where('loyalty_program_id', $program->id)->where('brand_id', $brands[$brand]->id)->where('customer_id', $customer->id)->first() : null;
                $good = $card && $card->status === $status && $card->points === $points && $card->reward_label === $reward;
                $wrong += $good ? 0 : 1;
                $lines[] = [$name, $brand, $card->status ?? '—', $card->points ?? '—', $card->reward_label ?? '—', $good ? 'OK' : "ATTENDU {$status} {$points}"];
            }

            if ($expected === []) {
                $lines[] = [$name, '—', 'sans carte', '—', '—', $customer ? 'ÉCART' : 'OK'];
                $wrong += $customer ? 1 : 0;
            }
        }

        $this->table(['Cas', 'Enseigne', 'Statut', 'Points', 'Récompense', 'Contrôle'], $lines);
        $this->line('Programme : '.self::PROGRAM.' (01/10/2026 → 30/09/2027). Connexion : admin.mode@retailgroup-demo.test');

        return $wrong === 0 ? self::SUCCESS : self::FAILURE;
    }
}
