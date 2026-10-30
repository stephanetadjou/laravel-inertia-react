<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Purchase;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Range une vente dans une famille (catégorie) à partir des libellés du
 * fichier importé, sans fiche produit : un commerce a des milliers de
 * références mais quelques dizaines de familles, et c'est à ce niveau qu'on
 * lit le comportement d'un client.
 *
 * Ordre de reconnaissance, sur les libellés normalisés (casse, accents,
 * ponctuation, pluriels) :
 * 1. correspondance enregistrée dans « À classer » (category_rules) ;
 * 2. nom de la famille (« boisson » = « Boissons ») ;
 * 3. mot-clé de la famille ou son nom contenu dans le libellé (« COCA 33CL »
 *    → Boissons si « coca » est un mot-clé), le plus long l'emporte.
 * La colonne « categorie » est essayée avant la colonne « article ».
 * Un libellé non reconnu reste sans famille et apparaît dans « À classer ».
 */
class FamilyMatcher
{
    /** @var array<int, self> */
    private static array $instances = [];

    /** Application pour laquelle le cache est valable (une par test, par requête Octane…). */
    private static ?object $application = null;

    /** @var array<string, int> libellé normalisé => famille */
    private array $exact = [];

    /** @var list<array{0: string, 1: int}> mot-clé normalisé, famille (plus longs d'abord) */
    private array $keywords = [];

    private function __construct(public readonly int $organizationId)
    {
        $categories = Category::where('organization_id', $organizationId)->get(['id', 'name', 'keywords']);

        foreach ($categories as $category) {
            $name = self::normalize($category->name);
            $this->exact[$name] ??= $category->id;
            $this->keywords[] = [$name, $category->id];

            foreach ($category->keywordList() as $keyword) {
                $this->keywords[] = [self::normalize($keyword), $category->id];
            }
        }

        // Les correspondances choisies à la main priment sur les noms.
        foreach (CategoryRule::where('organization_id', $organizationId)->get(['label', 'category_id']) as $rule) {
            $this->exact[$rule->label] = $rule->category_id;
        }

        $this->keywords = array_values(array_filter($this->keywords, fn ($k) => $k[0] !== ''));
        usort($this->keywords, fn ($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));
    }

    public static function for(int $organizationId): self
    {
        if (self::$application !== app()) {
            self::$application = app();
            self::$instances = [];
        }

        return self::$instances[$organizationId] ??= new self($organizationId);
    }

    /** À appeler après un changement de famille, de mot-clé ou de correspondance. */
    public static function forget(?int $organizationId = null): void
    {
        if ($organizationId === null) {
            self::$instances = [];
        } else {
            unset(self::$instances[$organizationId]);
        }
    }

    /**
     * « Crèmes  Visage ! » → « creme visage » : minuscules sans accents, mots
     * séparés par une espace, s / x final retiré des mots de plus de 3 lettres.
     */
    public static function normalize(?string $label): string
    {
        $text = Str::lower(Str::ascii((string) $label));
        $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(fn (string $w) => strlen($w) > 3 ? preg_replace('/[sx]$/', '', $w) : $w, $words));
    }

    public function match(?string $category, ?string $article = null): ?int
    {
        foreach ([$category, $article] as $label) {
            $key = self::normalize($label);

            if ($key !== '' && isset($this->exact[$key])) {
                return $this->exact[$key];
            }
        }

        foreach ([$category, $article] as $label) {
            $text = ' '.self::normalize($label).' ';

            if (trim($text) === '') {
                continue;
            }

            foreach ($this->keywords as [$keyword, $categoryId]) {
                if (str_contains($text, ' '.$keyword.' ')) {
                    return $categoryId;
                }
            }
        }

        return null;
    }

    /**
     * Libellé présenté dans « À classer » : la catégorie du fichier, à défaut l'article.
     */
    public static function labelOf(?string $category, ?string $article): ?string
    {
        $label = trim((string) ($category ?: $article));

        return $label === '' ? null : $label;
    }

    /**
     * Ventes sans famille de l'organisation, regroupées par libellé
     * normalisé, les plus gros chiffres d'affaires d'abord.
     *
     * @return Collection<int, array{label: string, key: string, count: int, amount: int, examples: list<string>}>
     */
    public function unclassified(): Collection
    {
        return $this->unclassifiedRows()
            ->groupBy(fn ($row) => self::normalize(self::labelOf($row->product_category, $row->article)))
            ->filter(fn ($rows, $key) => $key !== '')
            ->map(fn (Collection $rows, string $key) => [
                'key' => $key,
                'label' => (string) self::labelOf($rows->sortByDesc('count')->first()->product_category, $rows->sortByDesc('count')->first()->article),
                'count' => (int) $rows->sum('count'),
                'amount' => (int) $rows->sum('amount'),
                'examples' => $rows->pluck('article')->filter()->unique()->take(3)->values()->all(),
            ])
            ->sortByDesc('amount')
            ->values();
    }

    /**
     * Range les libellés choisis dans une famille : la correspondance est
     * enregistrée pour les prochains imports, puis les ventes sont reclassées.
     *
     * @param  list<string>  $labels  libellés affichés dans « À classer »
     */
    public function assign(array $labels, int $categoryId): int
    {
        foreach ($labels as $label) {
            $key = self::normalize($label);

            if ($key !== '') {
                CategoryRule::updateOrCreate(
                    ['organization_id' => $this->organizationId, 'label' => $key],
                    ['category_id' => $categoryId, 'raw_label' => mb_substr($label, 0, 255)],
                );
            }
        }

        self::forget($this->organizationId);

        return self::for($this->organizationId)->reclassify();
    }

    /**
     * Applique les familles, mots-clés et correspondances actuels aux ventes
     * encore sans famille. Les ventes déjà classées ne bougent pas.
     *
     * @return int ventes rangées dans une famille
     */
    public function reclassify(): int
    {
        $classified = 0;

        foreach ($this->unclassifiedRows() as $row) {
            $categoryId = $this->match($row->product_category, $row->article);

            if ($categoryId === null) {
                continue;
            }

            // Mise à jour groupée : pas d'événement de modèle (fidélité et
            // fiche client ne dépendent pas de la famille).
            $classified += DB::table('purchases')
                ->whereNull('category_id')
                ->whereIn('store_id', $this->storeIds())
                ->where(fn ($q) => $row->product_category === null ? $q->whereNull('product_category') : $q->where('product_category', $row->product_category))
                ->where(fn ($q) => $row->article === null ? $q->whereNull('article') : $q->where('article', $row->article))
                ->update(['category_id' => $categoryId]);
        }

        return $classified;
    }

    /** @return Collection<int, object{product_category: ?string, article: ?string, count: int, amount: int}> */
    private function unclassifiedRows(): Collection
    {
        return Purchase::query()
            ->whereIn('store_id', $this->storeIds())
            ->whereNull('category_id')
            ->where(fn ($q) => $q->whereNotNull('product_category')->orWhereNotNull('article'))
            ->selectRaw('product_category, article, count(*) as count, sum(amount) as amount')
            ->groupBy('product_category', 'article')
            ->toBase()
            ->get();
    }

    private function storeIds(): Builder
    {
        return DB::table('stores')->where('organization_id', $this->organizationId)->select('id');
    }
}
