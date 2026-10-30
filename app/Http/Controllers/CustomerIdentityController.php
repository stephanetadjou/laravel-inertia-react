<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerMatchReview;
use App\Models\Purchase;
use App\Models\User;
use App\Services\CustomerIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Qualité du référentiel client : file « À vérifier » (rapprochements
 * douteux de l'import), fusion de deux fiches et séparation. Réservé aux
 * rôles qui importent ou gèrent l'entreprise, dans leur périmètre.
 */
class CustomerIdentityController extends Controller
{
    public function reviews(Request $request): Response
    {
        $user = $this->authorizeSteward($request);
        $status = in_array($request->query('status'), ['attached', 'separate'], true) ? (string) $request->query('status') : CustomerMatchReview::STATUS_PENDING;

        $reviews = $this->scopedReviews($user)
            ->where('status', $status)
            ->with(['store:id,name', 'customer:id,name,company_name,type,phone_e164,purchases_count,total_spent,last_purchase_at'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $purchases = Purchase::withCancelled()->whereKey($reviews->getCollection()->flatMap(fn ($r) => $r->purchase_ids ?? [])->all())
            ->get(['id', 'purchased_at', 'amount', 'receipt_number'])->keyBy('id');

        return Inertia::render('Customers/Reviews', [
            'status' => $status,
            'pendingCount' => $this->scopedReviews($user)->where('status', CustomerMatchReview::STATUS_PENDING)->count(),
            'reviews' => $reviews->through(fn (CustomerMatchReview $review) => [
                'id' => $review->id,
                'reason' => CustomerMatchReview::REASONS[$review->reason] ?? $review->reason,
                'phone' => $review->phone_e164,
                'incoming_name' => $review->incoming_name,
                'store' => $review->store?->name,
                'created_at' => $review->created_at?->toIso8601String(),
                'customer' => $review->customer ? [
                    'id' => $review->customer->id,
                    'name' => $review->customer->displayName(),
                    'purchases_count' => $review->customer->purchases_count,
                    'total_spent' => $review->customer->total_spent,
                    'last_purchase_at' => $review->customer->last_purchase_at?->toDateString(),
                ] : null,
                'purchases' => collect($review->purchase_ids ?? [])->map(fn ($id) => $purchases->get($id))->filter()->map(fn (Purchase $p) => [
                    'id' => $p->id,
                    'date' => $p->purchased_at->toDateString(),
                    'amount' => (int) $p->amount,
                    'receipt_number' => $p->receipt_number,
                ])->values(),
            ]),
        ]);
    }

    public function resolve(Request $request, CustomerMatchReview $review, CustomerIdentity $identity): RedirectResponse
    {
        $user = $this->authorizeSteward($request);

        if (! $this->scopedReviews($user)->whereKey($review->id)->exists()) {
            throw new HttpException(403, 'Ce rapprochement n’est pas dans votre périmètre.');
        }

        $data = $request->validate(['decision' => ['required', Rule::in([CustomerMatchReview::STATUS_ATTACHED, CustomerMatchReview::STATUS_SEPARATE])]]);

        if ($review->status !== CustomerMatchReview::STATUS_PENDING) {
            return back()->with('error', 'Ce rapprochement est déjà traité.');
        }

        $identity->resolve($review, $data['decision'], $user);

        return back()->with('success', $data['decision'] === CustomerMatchReview::STATUS_ATTACHED
            ? 'Ventes rattachées à la fiche.'
            : 'Ventes gardées séparées ; le numéro est marqué partagé.');
    }

    /** Fiches proposées pour une fusion (recherche nom / numéro / code client). */
    public function lookup(Request $request): JsonResponse
    {
        $user = $this->authorizeSteward($request);
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $digits = ltrim(preg_replace('/\D+/', '', $q), '0');

        return response()->json(
            $this->scopedCustomers($user)
                ->whereKeyNot($request->integer('exclude'))
                ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('company_name', 'like', "%{$q}%")->orWhere('external_code', $q)
                    ->when($digits !== '', fn ($w) => $w->orWhere('phone_e164', 'like', "%{$digits}%")))
                ->orderBy('name')
                ->take(10)
                ->get()
                ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->displayName(), 'phone' => $c->phone_e164, 'purchases_count' => $c->purchases_count, 'total_spent' => $c->total_spent]),
        );
    }

    /** Fusionne la fiche choisie (source_id) dans celle-ci, qui est gardée. */
    public function merge(Request $request, Customer $customer, CustomerIdentity $identity): RedirectResponse
    {
        $user = $this->authorizeSteward($request);
        $this->authorizeCustomer($user, $customer);

        $data = $request->validate(['source_id' => ['required', 'integer']]);
        $source = Customer::where('organization_id', $user->organization_id)->findOrFail($data['source_id']);
        $this->authorizeCustomer($user, $source);

        $identity->merge($source, $customer, $user);

        return redirect()->route('customers.show', $customer)->with('success', "Fiche « {$source->displayName()} » fusionnée dans celle-ci.");
    }

    public function split(Request $request, Customer $customer, CustomerIdentity $identity): RedirectResponse
    {
        $user = $this->authorizeSteward($request);
        $this->authorizeCustomer($user, $customer);

        $data = $request->validate(['contact_ids' => ['required', 'array', 'min:1'], 'contact_ids.*' => ['integer']]);
        $new = $identity->split($customer, $data['contact_ids'], $user);

        return redirect()->route('customers.show', $new)->with('success', "Fiche « {$new->name} » séparée.");
    }

    private function authorizeSteward(Request $request): User
    {
        $user = $request->user();

        if (! $user->canImport()) {
            throw new HttpException(403, 'Votre rôle ne permet pas de corriger les fiches clients.');
        }

        return $user;
    }

    private function authorizeCustomer(User $user, Customer $customer): void
    {
        if (! $this->scopedCustomers($user)->whereKey($customer->id)->exists()) {
            throw new HttpException(403, 'Cette fiche n’est pas dans votre périmètre.');
        }
    }

    private function scopedCustomers(User $user)
    {
        $visible = $user->visibleStoreIds();

        return Customer::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn ($q) => $q->whereHas('contacts', fn ($c) => $c->whereIn('store_id', $visible)));
    }

    private function scopedReviews(User $user)
    {
        $visible = $user->visibleStoreIds();

        return CustomerMatchReview::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn ($q) => $q->whereIn('store_id', $visible ?: [0]));
    }
}
