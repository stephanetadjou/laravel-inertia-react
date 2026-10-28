<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTransaction;
use App\Services\Loyalty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Programme de fidélité : réglages (Paramètres › Fidélité) et, depuis la
 * fiche client, échange d'une récompense ou ajustement de points.
 */
class LoyaltyController extends Controller
{
    public function settings(Request $request): Response
    {
        $organization = $request->user()->organization;

        return Inertia::render('Settings/Loyalty', [
            'settings' => $organization->loyaltySettings(),
            'rewards' => LoyaltyReward::where('organization_id', $organization->id)->orderBy('points_cost')->get(['id', 'name', 'points_cost', 'active']),
            'stats' => [
                'members' => LoyaltyTransaction::where('organization_id', $organization->id)->distinct()->count('customer_id'),
                'points_out' => (int) LoyaltyTransaction::where('organization_id', $organization->id)->sum('points'),
                'redeemed' => LoyaltyTransaction::where('organization_id', $organization->id)->where('type', 'redeem')->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'fcfa_per_point' => ['required', 'integer', 'min:1', 'max:1000000'],
            'starts_on' => ['nullable', 'date'],
            'tiers' => ['required', 'array', 'min:1', 'max:6'],
            'tiers.*.name' => ['required', 'string', 'max:40'],
            'tiers.*.min_points' => ['required', 'integer', 'min:0', 'max:10000000'],
        ], [
            'tiers.*.name.required' => 'Donnez un nom à chaque palier.',
        ]);

        $organization = $request->user()->organization;
        $previous = $organization->loyaltySettings();

        $organization->update(['loyalty_settings' => [
            'enabled' => $data['enabled'],
            'fcfa_per_point' => $data['fcfa_per_point'],
            // Lancement : à défaut de date choisie, le jour de la première activation.
            'starts_on' => $data['starts_on'] ?? ($previous['starts_on'] ?? ($data['enabled'] ? today()->toDateString() : null)),
            'tiers' => array_values($data['tiers']),
        ]]);

        return back()->with('success', $data['enabled'] ? 'Programme de fidélité enregistré et actif.' : 'Programme de fidélité enregistré (désactivé).');
    }

    public function storeReward(Request $request): RedirectResponse
    {
        $data = $this->validatedReward($request);
        LoyaltyReward::create([...$data, 'organization_id' => $request->user()->organization_id]);

        return back()->with('success', "Récompense « {$data['name']} » ajoutée.");
    }

    public function updateReward(Request $request, LoyaltyReward $reward): RedirectResponse
    {
        $this->authorizeOwn($request, $reward->organization_id);
        $reward->update($this->validatedReward($request));

        return back()->with('success', "Récompense « {$reward->name} » mise à jour.");
    }

    public function toggleReward(Request $request, LoyaltyReward $reward): RedirectResponse
    {
        $this->authorizeOwn($request, $reward->organization_id);
        $reward->update(['active' => ! $reward->active]);

        return back()->with('success', $reward->active ? "« {$reward->name} » réactivée." : "« {$reward->name} » retirée du catalogue.");
    }

    /** Échange d'une récompense depuis la fiche client. */
    public function redeem(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCustomer($request, $customer);
        $data = $request->validate([
            'reward_id' => ['required', 'integer', Rule::exists('loyalty_rewards', 'id')->where('organization_id', $request->user()->organization_id)->where('active', true)],
        ]);

        $reward = LoyaltyReward::findOrFail($data['reward_id']);
        Loyalty::for($request->user()->organization)->redeem($customer, $reward, $request->user());

        return back()->with('success', "« {$reward->name} » échangée : −{$reward->points_cost} points.");
    }

    /** Ajustement manuel (geste commercial, correction), réservé aux gestionnaires. */
    public function adjust(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCustomer($request, $customer);

        if (! $request->user()->canManageOrganization()) {
            throw new HttpException(403, 'Seuls les gestionnaires peuvent ajuster des points.');
        }

        $data = $request->validate([
            'points' => ['required', 'integer', 'not_in:0', 'min:-100000', 'max:100000'],
            'note' => ['required', 'string', 'max:120'],
        ], [
            'note.required' => 'Indiquez la raison de l’ajustement.',
        ]);

        Loyalty::for($request->user()->organization)->adjust($customer, (int) $data['points'], $data['note'], $request->user());

        return back()->with('success', 'Points ajustés.');
    }

    /** @return array{name: string, points_cost: int} */
    private function validatedReward(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'points_cost' => ['required', 'integer', 'min:1', 'max:10000000'],
        ]);
    }

    private function authorizeCustomer(Request $request, Customer $customer): void
    {
        $user = $request->user();
        $visible = $user->visibleStoreIds();

        if ($customer->organization_id !== $user->organization_id
            || ($visible !== null && ! $customer->contacts()->whereIn('store_id', $visible)->exists())) {
            throw new HttpException(403, "Cette fiche client n'est pas dans votre périmètre.");
        }
    }

    private function authorizeOwn(Request $request, int $organizationId): void
    {
        if ($organizationId !== $request->user()->organization_id) {
            throw new HttpException(403, "Cet élément n'appartient pas à votre entreprise.");
        }
    }
}
