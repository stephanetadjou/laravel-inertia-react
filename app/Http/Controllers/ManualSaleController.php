<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Services\ManualSale;
use App\Services\RfmCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ventes › Ajouter une vente : saisie manuelle d'un ticket (cf. ManualSale),
 * dans les points de vente du périmètre de l'utilisateur.
 */
class ManualSaleController extends Controller
{
    public function store(Request $request, ManualSale $sales): RedirectResponse
    {
        $user = $this->authorizeUser($request);
        $organization = $user->organization;
        $retail = $organization->sellsProducts();
        $storeIds = self::stores($user)->pluck('id')->all();

        $data = $request->validate([
            'customer_mode' => ['required', Rule::in(['existing', 'new', 'anonymous'])],
            'customer_id' => ['required_if:customer_mode,existing', 'nullable', 'integer'],
            'name' => ['required_if:customer_mode,new', 'nullable', 'string', 'max:255'],
            'phone' => ['required_if:customer_mode,new', 'nullable', 'string', 'max:30'],
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'store_id' => ['required', 'integer', Rule::in($storeIds)],
            'purchased_at' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['nullable', Rule::in(array_keys(Purchase::PAYMENT_METHODS))],
            'receipt_number' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.catalog_item_id' => $retail ? ['prohibited'] : ['nullable', 'integer', Rule::exists('catalog_items', 'id')->where('organization_id', $organization->id)],
            'lines.*.category_id' => $retail ? ['required', 'integer', Rule::exists('categories', 'id')->where('organization_id', $organization->id)] : ['prohibited'],
            'lines.*.article' => ['nullable', 'string', 'max:255'],
            'lines.*.name' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'lines.*.discount' => ['nullable', 'integer', 'min:0'],
        ], [
            'customer_mode.required' => 'Choisissez le client : existant, nouveau ou vente anonyme.',
            'customer_id.required_if' => 'Choisissez le client.',
            'name.required_if' => 'Indiquez le nom du nouveau client.',
            'phone.required_if' => 'Indiquez le numéro de téléphone du nouveau client.',
            'store_id.required' => 'Choisissez le point de vente.',
            'store_id.in' => 'Ce point de vente n’est pas dans votre périmètre.',
            'purchased_at.required' => 'Indiquez la date de la vente.',
            'purchased_at.before_or_equal' => 'La date de la vente ne peut pas être dans le futur.',
            'lines.required' => 'Ajoutez au moins une ligne.',
            'lines.min' => 'Ajoutez au moins une ligne.',
            'lines.*.category_id.required' => $organization->analysisLevel() === 'category' ? 'Choisissez la catégorie de chaque ligne.' : 'Choisissez la famille de chaque ligne.',
            'lines.*.quantity.min' => 'La quantité doit être d’au moins 1.',
            'lines.*.unit_price.required' => 'Indiquez le prix unitaire de chaque ligne.',
            'lines.*.unit_price.min' => 'Le prix unitaire ne peut pas être négatif.',
            'lines.*.discount.min' => 'La remise ne peut pas être négative.',
        ]);

        if ($data['customer_mode'] === 'existing' && ! self::scopedCustomers($user)->whereKey($data['customer_id'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'Ce client n’est pas dans votre périmètre.']);
        }

        $purchase = $sales->record($user, $data);

        return redirect()->route('purchases.index')->with('success', 'Vente enregistrée : '.number_format((int) $purchase->amount, 0, ',', ' ').' '.$organization->currencySymbol().'.');
    }

    /**
     * Suggestions au fil de la frappe (dès 2 caractères), dans le périmètre :
     * chaque mot saisi doit se trouver dans le nom, l'entreprise, le contact
     * ou le code client (sans accents ni majuscules), ou bien les chiffres
     * saisis dans le numéro (partiel, avec ou sans 0 ou indicatif 241).
     */
    public function customers(Request $request): JsonResponse
    {
        $user = $this->authorizeUser($request);
        $q = Customer::normalizeSearch((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $terms = array_slice(explode(' ', $q), 0, 5);
        $digits = preg_replace('/\D+/', '', $q);
        $digits = preg_replace('/^(00)?241(?=\d{2})/', '', ltrim($digits, '+'));
        $digits = ltrim($digits, '0');

        return response()->json(
            self::scopedCustomers($user)
                ->where(function ($w) use ($terms, $digits) {
                    // Chaque mot saisi commence un mot de la fiche (« ma » : Makaya, pas Nguema) ;
                    // un mot avec des chiffres (code client) peut être n'importe où.
                    $w->where(function ($all) use ($terms) {
                        foreach ($terms as $term) {
                            $like = addcslashes($term, '%_\\');
                            $all->where(fn ($t) => preg_match('/\d/', $term)
                                ? $t->where('search_text', 'like', "%{$like}%")
                                : $t->where('search_text', 'like', "{$like}%")->orWhere('search_text', 'like', "% {$like}%")->orWhere('search_text', 'like', "%-{$like}%"));
                        }
                    });

                    if (strlen($digits) >= 2) {
                        $w->orWhere('phone_e164', 'like', "%{$digits}%");
                    }
                })
                // Le nom qui commence par la saisie d'abord.
                ->orderByRaw('CASE WHEN search_text LIKE ? THEN 0 ELSE 1 END', [addcslashes($terms[0], '%_\\').'%'])
                ->orderBy('name')
                ->take(10)
                ->get()
                ->map(fn (Customer $c) => [
                    'id' => $c->id,
                    'name' => $c->displayName(),
                    'phone' => $c->phone_e164,
                    'code' => $c->external_code,
                    'segment' => RfmCalculator::SEGMENTS[$c->rfm_segment]['label'] ?? null,
                    'last_purchase_at' => $c->last_purchase_at?->toDateString(),
                ]),
        );
    }

    /**
     * Données du formulaire, ajoutées à la page Ventes si l'utilisateur peut saisir.
     *
     * @return array<string, mixed>|null
     */
    public static function formOptions(User $user): ?array
    {
        if (! $user->canRecordSales()) {
            return null;
        }

        $organization = $user->organization;
        $retail = $organization->sellsProducts();

        return [
            'retail' => $retail,
            'stores' => self::stores($user)->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'catalog' => $retail ? [] : AppointmentController::catalogFor($organization->id),
            'families' => $retail
                ? Category::where('organization_id', $organization->id)->with('parent:id,name')->orderBy('name')->get()
                    ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name, 'parent' => $c->parent?->name])
                : [],
            'family_label' => $organization->analysisLevel() === 'category' ? 'Catégorie' : 'Famille',
            'payment_methods' => Purchase::PAYMENT_METHODS,
            'currency' => $organization->currencySymbol(),
        ];
    }

    private function authorizeUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->canRecordSales(), 403, 'Votre rôle ne permet pas de saisir des ventes.');

        return $user;
    }

    private static function stores(User $user)
    {
        $visible = $user->visibleStoreIds();

        return Store::where('organization_id', $user->organization_id)
            ->where('is_active', true)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible ?: [0]))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** Fiches clients du périmètre : au moins une relation dans un point de vente visible. */
    private static function scopedCustomers(User $user)
    {
        $visible = $user->visibleStoreIds();

        return Customer::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn ($q) => $q->whereHas('contacts', fn ($c) => $c->whereIn('store_id', $visible ?: [0])));
    }
}
