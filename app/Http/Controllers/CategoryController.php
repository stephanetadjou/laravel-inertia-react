<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Purchase;
use App\Models\Store;
use App\Services\FamilyAnalytics;
use App\Services\FamilyMatcher;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Familles de produits (catégories) et analyse du comportement d'achat par
 * famille : un commerce a des milliers de références mais quelques dizaines
 * de familles. Les libellés importés sont rangés automatiquement
 * (FamilyMatcher) ; ceux qui restent sans famille se classent par lot dans
 * « À classer ».
 */
class CategoryController extends Controller
{
    /** Périodes d'analyse : nombre de mois (0 = tout l'historique). */
    public const PERIODS = [3 => '3 derniers mois', 6 => '6 derniers mois', 12 => '12 derniers mois', 0 => 'Tout l’historique'];

    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;
        $months = array_key_exists($request->integer('period', 12), self::PERIODS) ? $request->integer('period', 12) : 12;
        [$from, $to] = $this->period($months);
        $lapseDays = $organization->lifecycleSettings()['at_risk_days'];

        $categories = Category::where('organization_id', $organization->id)->orderBy('name')->get();
        $analytics = new FamilyAnalytics($this->storeIds($request));
        $overview = $analytics->overview($categories, $from, $to, $lapseDays);
        $properties = $analytics->propertyValues($from, $to);
        $by = array_key_exists((string) $request->query('by'), $properties) ? (string) $request->query('by') : null;

        $purchaseCounts = Purchase::whereIn('store_id', Store::where('organization_id', $organization->id)->select('id'))
            ->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as aggregate')
            ->groupBy('category_id')
            ->pluck('aggregate', 'category_id');

        $unclassified = FamilyMatcher::for($organization->id)->unclassified();

        return Inertia::render('Categories/Index', [
            'categories' => $categories->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'keywords' => $category->keywords,
                'parent_id' => $category->parent_id,
                'purchases_count' => (int) ($purchaseCounts[$category->id] ?? 0),
            ]),
            'overview' => $overview,
            'period' => $months,
            'periods' => collect(self::PERIODS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'lapseDays' => $lapseDays,
            'properties' => collect($properties)->map(fn ($values, $key) => ['key' => $key, 'label' => FamilyAnalytics::propertyLabel($key), 'values' => $values])->values(),
            'by' => $by,
            'byProperty' => $by !== null ? $analytics->byProperty($by, $from, $to) : null,
            'unclassified' => [
                'labels' => $unclassified->count(),
                'purchases' => $unclassified->sum('count'),
                'amount' => $unclassified->sum('amount'),
            ],
            'familyWord' => $organization->sellsProducts() ? 'famille' : 'catégorie',
        ]);
    }

    /**
     * Détail d'une famille : chiffres de la période, achats liés (vente
     * croisée) et liens vers les clients à cibler.
     */
    public function show(Request $request, Category $category): Response
    {
        $this->authorizeSameOrganization($request, $category);

        $organization = $request->user()->organization;
        $months = array_key_exists($request->integer('period', 12), self::PERIODS) ? $request->integer('period', 12) : 12;
        [$from, $to] = $this->period($months);
        $lapseDays = $organization->lifecycleSettings()['at_risk_days'];

        $categories = Category::where('organization_id', $organization->id)->orderBy('name')->get();
        $analytics = new FamilyAnalytics($this->storeIds($request));
        $row = collect($analytics->overview($categories, $from, $to, $lapseDays)['rows'])->firstWhere('id', $category->id);

        return Inertia::render('Categories/Show', [
            'category' => ['id' => $category->id, 'name' => $category->name, 'keywords' => $category->keywords],
            'stats' => $row,
            'affinities' => $analytics->affinities($category, $categories, $from, $to),
            'period' => $months,
            'periods' => collect(self::PERIODS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'lapseDays' => $lapseDays,
            'examples' => Purchase::where('category_id', $category->id)
                ->whereIn('store_id', $this->storeIds($request))
                ->whereNotNull('article')
                ->selectRaw('article, count(*) as count, sum(amount) as amount')
                ->groupBy('article')
                ->orderByDesc('amount')
                ->take(10)
                ->get()
                ->map(fn ($r) => ['article' => $r->article, 'count' => (int) $r->count, 'amount' => (int) $r->amount]),
            'familyWord' => $organization->sellsProducts() ? 'famille' : 'catégorie',
        ]);
    }

    /** Libellés importés sans famille, les plus gros chiffres d'affaires d'abord. */
    public function unclassified(Request $request): Response
    {
        $organization = $request->user()->organization;

        return Inertia::render('Categories/Unclassified', [
            'labels' => FamilyMatcher::for($organization->id)->unclassified()->take(500),
            'categories' => Category::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'familyWord' => $organization->sellsProducts() ? 'famille' : 'catégorie',
        ]);
    }

    /**
     * Range des libellés dans une famille existante, dans une nouvelle
     * famille, ou crée une famille par libellé (fichier déjà bien rangé).
     */
    public function assign(Request $request): RedirectResponse
    {
        $organizationId = $request->user()->organization_id;

        $data = $request->validate([
            'labels' => ['required', 'array', 'min:1', 'max:500'],
            'labels.*' => ['string', 'max:255'],
            'mode' => ['required', 'in:existing,new,each'],
            'category_id' => ['required_if:mode,existing', 'nullable', 'integer', Rule::exists('categories', 'id')->where('organization_id', $organizationId)],
            'name' => ['required_if:mode,new', 'nullable', 'string', 'max:255'],
        ]);

        $classified = DB::transaction(function () use ($data, $organizationId) {
            if ($data['mode'] === 'each') {
                $count = 0;

                foreach ($data['labels'] as $label) {
                    $category = $this->firstOrCreateCategory($organizationId, $label);
                    $count += FamilyMatcher::for($organizationId)->assign([$label], $category->id);
                }

                return $count;
            }

            $categoryId = $data['mode'] === 'new'
                ? $this->firstOrCreateCategory($organizationId, $data['name'])->id
                : (int) $data['category_id'];

            return FamilyMatcher::for($organizationId)->assign($data['labels'], $categoryId);
        });

        return back()->with('success', "{$classified} vente(s) classée(s). Les prochains imports utiliseront ces correspondances.");
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $request->user()->organization->categories()->create($data);
        $classified = FamilyMatcher::for($request->user()->organization_id)->reclassify();

        return back()->with('success', "« {$data['name']} » créée.".($classified > 0 ? " {$classified} vente(s) reconnue(s) automatiquement." : ''));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $category);

        $category->update($this->validated($request, $category));
        $classified = FamilyMatcher::for($category->organization_id)->reclassify();

        return back()->with('success', "« {$category->name} » enregistrée.".($classified > 0 ? " {$classified} vente(s) reconnue(s) automatiquement." : ''));
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $category);

        // Les ventes repassent dans « À classer » (sans famille), leurs libellés sont conservés.
        Purchase::where('category_id', $category->id)->update(['category_id' => null]);
        $category->delete();

        return back()->with('success', 'Supprimée : ses ventes sont de nouveau à classer.');
    }

    /** @return array{name: string, keywords: ?string, parent_id?: ?int} */
    private function validated(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->where(
                    fn ($q) => $q->where('organization_id', $request->user()->organization_id),
                )->ignore($category?->id),
            ],
            'keywords' => ['nullable', 'string', 'max:2000'],
            // Catégorie parente (niveau au-dessus) : une catégorie de premier niveau, jamais elle-même.
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($q) => $q->where('organization_id', $request->user()->organization_id)->whereNull('parent_id')), Rule::notIn(array_filter([$category?->id]))],
        ]);

        $keywords = collect(explode(',', (string) ($data['keywords'] ?? '')))->map(fn ($k) => trim($k))->filter()->unique()->implode(', ');

        return [
            'name' => trim($data['name']),
            'keywords' => $keywords !== '' ? $keywords : null,
            ...($request->has('parent_id') ? ['parent_id' => $data['parent_id'] ?? null] : []),
        ];
    }

    private function firstOrCreateCategory(int $organizationId, string $name): Category
    {
        $name = mb_substr(trim($name), 0, 255);
        $key = FamilyMatcher::normalize($name);

        return Category::where('organization_id', $organizationId)->get()->first(fn (Category $c) => FamilyMatcher::normalize($c->name) === $key)
            ?? Category::create(['organization_id' => $organizationId, 'name' => $name]);
    }

    /** @return array{0: ?CarbonInterface, 1: CarbonInterface} */
    private function period(int $months): array
    {
        return [$months > 0 ? today()->subMonthsNoOverflow($months)->addDay() : null, today()];
    }

    /** @return array<int, int> boutiques du périmètre de l'utilisateur */
    private function storeIds(Request $request): array
    {
        $user = $request->user();
        $visibleIds = $user->visibleStoreIds();

        return Store::where('organization_id', $user->organization_id)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->pluck('id')
            ->all();
    }

    private function authorizeSameOrganization(Request $request, Category $category): void
    {
        if ($category->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Cette catégorie n\'appartient pas à votre organisation.');
        }
    }
}
