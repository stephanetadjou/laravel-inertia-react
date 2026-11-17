<?php

namespace App\Modules\Location\Http;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PlanController;
use App\Models\Customer;
use App\Models\Rental;
use App\Modules\Location\Models\RentalAccount;
use App\Modules\Location\Services\LocationDashboard;
use App\Modules\Location\Services\LocationFollowUps;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Tableau de bord du loueur, relances WhatsApp et comptes entreprise. */
class LocationController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $user = $request->user();
        $storeIds = PlanController::storeIds($user);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::parse($request->query('month').'-01') : today();

        return Inertia::render('Location/Dashboard', [
            'board' => LocationDashboard::build($user->organization, $storeIds, $month),
            'followUps' => LocationFollowUps::due($user->organization, $storeIds),
            'automatic' => $user->organization->automationAllowed() && $user->organization->usesWhatsAppApi(),
            'whatsappUrl' => session('whatsapp_url'),
            'share' => ['url' => $user->organization->bookingUrl(), 'rideUrl' => url('/rdv/'.$user->organization->slug.'/course'), 'name' => $user->organization->name],
        ]);
    }

    public function followUpDone(Request $request, string $kind, int $id): RedirectResponse
    {
        abort_unless(array_key_exists($kind, LocationFollowUps::KINDS), 404);
        LocationFollowUps::markDone($request->user()->organization_id, $kind, $id);

        return back()->with('success', 'Relance notée comme faite.');
    }

    public function accounts(Request $request): Response
    {
        $organization = $request->user()->organization;
        $accounts = RentalAccount::where('organization_id', $organization->id)->with('customer:id,name,company_name,phone_e164')->get();
        $stats = Rental::where('organization_id', $organization->id)->whereIn('customer_id', $accounts->pluck('customer_id'))->where('status', '!=', 'cancelled')
            ->get(['customer_id', 'amount', 'starts_at'])->groupBy('customer_id');

        return Inertia::render('Location/Accounts', [
            'accounts' => $accounts->map(fn (RentalAccount $a) => [
                'id' => $a->id,
                'customer_id' => $a->customer_id,
                'name' => $a->customer?->company_name ?: $a->customer?->name,
                'phone' => $a->customer?->phone_e164,
                'discount_percent' => $a->discount_percent,
                'day_rate' => $a->day_rate,
                'notes' => $a->notes,
                'bookings' => isset($stats[$a->customer_id]) ? $stats[$a->customer_id]->count() : 0,
                'revenue' => isset($stats[$a->customer_id]) ? (int) $stats[$a->customer_id]->sum('amount') : 0,
                'last' => isset($stats[$a->customer_id]) ? $stats[$a->customer_id]->max('starts_at')?->toDateString() : null,
            ])->sortByDesc('revenue')->values(),
            'companies' => Customer::where('organization_id', $organization->id)->where('type', 'business')->whereNotIn('id', $accounts->pluck('customer_id'))
                ->orderBy('name')->limit(300)->get(['id', 'name', 'company_name']),
        ]);
    }

    public function saveAccount(Request $request): RedirectResponse
    {
        $organization = $request->user()->organization;
        $data = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('organization_id', $organization->id)],
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:90'],
            'day_rate' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $account = RentalAccount::updateOrCreate(['customer_id' => $data['customer_id']], [
            'organization_id' => $organization->id, 'discount_percent' => (int) ($data['discount_percent'] ?? 0), 'day_rate' => $data['day_rate'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);
        Customer::whereKey($data['customer_id'])->where('type', '!=', 'business')->update(['type' => 'business']);
        Audit::record('location.account', $account, 'Compte entreprise enregistré (tarif négocié)', $data);

        return back()->with('success', 'Compte entreprise enregistré : son tarif s’appliquera à ses prochains devis.');
    }
}
