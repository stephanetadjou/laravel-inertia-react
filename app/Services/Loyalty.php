<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTransaction;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Programme de fidélité : 1 point par tranche de X FCFA dépensés (achats
 * datés à partir du lancement du programme), paliers selon les points
 * gagnés sur 12 mois, récompenses échangées depuis la fiche client.
 * Le solde est la somme des mouvements (loyalty_transactions).
 */
class Loyalty
{
    public function __construct(private Organization $organization) {}

    public static function for(Organization $organization): self
    {
        return new self($organization);
    }

    /** Points gagnés sur un achat (aucun si le programme est arrêté ou l'achat antérieur au lancement). */
    public function pointsFor(Purchase $purchase): int
    {
        $settings = $this->organization->loyaltySettings();

        if (! $settings['enabled'] || $settings['fcfa_per_point'] <= 0) {
            return 0;
        }

        if ($settings['starts_on'] && $purchase->purchased_at->toDateString() < $settings['starts_on']) {
            return 0;
        }

        return intdiv(max(0, (int) $purchase->amount), $settings['fcfa_per_point']);
    }

    /** Crédite (ou recrédite après modification) les points d'un achat. */
    public function syncPurchase(Purchase $purchase): void
    {
        $customerId = $purchase->contact()->value('customer_id');

        if ($customerId === null) {
            return;
        }

        $already = (int) LoyaltyTransaction::where('purchase_id', $purchase->id)->whereIn('type', ['earn', 'reverse'])->sum('points');
        $due = $this->pointsFor($purchase);

        // Programme arrêté : on ne retire pas les points déjà gagnés.
        if ($due === $already || ($due === 0 && ! $this->organization->loyaltySettings()['enabled'])) {
            return;
        }

        LoyaltyTransaction::create([
            'organization_id' => $this->organization->id,
            'customer_id' => $customerId,
            'type' => $due > $already ? 'earn' : 'reverse',
            'points' => $due - $already,
            'purchase_id' => $purchase->id,
            'note' => 'Achat du '.$purchase->purchased_at->format('d/m/Y'),
        ]);
    }

    /** Achat supprimé : ses points sont retirés. */
    public function reversePurchase(Purchase $purchase, ?int $customerId): void
    {
        $earned = (int) LoyaltyTransaction::where('purchase_id', $purchase->id)->whereIn('type', ['earn', 'reverse'])->sum('points');

        if ($earned === 0 || $customerId === null) {
            return;
        }

        LoyaltyTransaction::create([
            'organization_id' => $this->organization->id,
            'customer_id' => $customerId,
            'type' => 'reverse',
            'points' => -$earned,
            'note' => 'Achat du '.$purchase->purchased_at->format('d/m/Y').' supprimé',
        ]);
    }

    public function balance(Customer $customer): int
    {
        return (int) LoyaltyTransaction::where('customer_id', $customer->id)->sum('points');
    }

    /** Points gagnés sur les 12 derniers mois (base des paliers). */
    public function earnedLastYear(Customer $customer): int
    {
        return (int) LoyaltyTransaction::where('customer_id', $customer->id)
            ->whereIn('type', ['earn', 'reverse'])
            ->where('created_at', '>=', now()->subYear())
            ->sum('points');
    }

    /**
     * Palier atteint et suivant.
     *
     * @return array{current: array{name: string, min_points: int}|null, next: array{name: string, min_points: int}|null, earned: int}
     */
    public function tier(Customer $customer): array
    {
        $earned = $this->earnedLastYear($customer);
        $tiers = collect($this->organization->loyaltySettings()['tiers'])->sortBy('min_points')->values();

        return [
            'current' => $tiers->filter(fn ($t) => $earned >= $t['min_points'])->last(),
            'next' => $tiers->first(fn ($t) => $earned < $t['min_points']),
            'earned' => $earned,
        ];
    }

    /** Échange une récompense : le solde doit suffire (vérifié sous verrou). */
    public function redeem(Customer $customer, LoyaltyReward $reward, User $user): LoyaltyTransaction
    {
        return DB::transaction(function () use ($customer, $reward, $user) {
            Customer::whereKey($customer->id)->lockForUpdate()->first();

            if ($this->balance($customer) < $reward->points_cost) {
                throw ValidationException::withMessages(['reward' => 'Solde de points insuffisant pour « '.$reward->name.' ».']);
            }

            return LoyaltyTransaction::create([
                'organization_id' => $this->organization->id,
                'customer_id' => $customer->id,
                'type' => 'redeem',
                'points' => -$reward->points_cost,
                'reward_id' => $reward->id,
                'user_id' => $user->id,
                'note' => $reward->name,
            ]);
        });
    }

    public function adjust(Customer $customer, int $points, string $note, User $user): LoyaltyTransaction
    {
        return DB::transaction(function () use ($customer, $points, $note, $user) {
            Customer::whereKey($customer->id)->lockForUpdate()->first();

            if ($points < 0 && $this->balance($customer) + $points < 0) {
                throw ValidationException::withMessages(['points' => 'Le solde ne peut pas devenir négatif.']);
            }

            return LoyaltyTransaction::create([
                'organization_id' => $this->organization->id,
                'customer_id' => $customer->id,
                'type' => 'adjust',
                'points' => $points,
                'user_id' => $user->id,
                'note' => $note,
            ]);
        });
    }
}
