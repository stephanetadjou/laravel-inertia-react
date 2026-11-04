<?php

namespace App\Services;

use App\Jobs\RefreshBrandMetricsJob;
use App\Models\Article;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vente saisie à la main (Ventes › Ajouter une vente) : un ticket comme ceux
 * de l'import, avec ses lignes, rattaché à un client existant, à un nouveau
 * client (nom + téléphone) ou anonyme. Les événements du modèle Purchase
 * font le reste (fiche client, famille, points de fidélité) ; on recalcule
 * ensuite segments, scores et métriques par marque, et on journalise.
 *
 * Données déjà validées par le contrôleur (point de vente dans le périmètre).
 */
class ManualSale
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function record(User $user, array $data): Purchase
    {
        $organization = $user->organization;
        $storeId = (int) $data['store_id'];
        $receipt = filled($data['receipt_number'] ?? null) ? trim($data['receipt_number']) : null;

        if ($receipt !== null && Purchase::withCancelled()->where('store_id', $storeId)->where('source_key', 'r:'.$receipt)->exists()) {
            throw ValidationException::withMessages(['receipt_number' => 'Ce numéro de ticket existe déjà pour ce point de vente.']);
        }

        $lines = $this->lines($data['lines'], $organization->id, $organization->sellsProducts());

        $purchase = DB::transaction(function () use ($user, $data, $storeId, $receipt, $lines, $organization) {
            $contact = $this->contact($data, $storeId, $user);
            $main = $lines->sortByDesc('amount')->first();

            $purchase = Purchase::create([
                'contact_id' => $contact?->id,
                'store_id' => $storeId,
                'amount' => $lines->sum('amount'),
                'quantity' => $lines->sum('quantity'),
                'subtotal' => $lines->sum(fn ($line) => $line['amount'] + $line['discount_amount']),
                'discount_amount' => $lines->sum('discount_amount'),
                'currency' => $organization->currency ?? 'XAF',
                'category_id' => $main['category_id'],
                'product_category' => $main['product_category'] ?? $main['name'],
                'article' => $lines->count() === 1 ? $main['article'] : null,
                'purchased_at' => $data['purchased_at'],
                'payment_method' => $data['payment_method'] ?? null,
                'receipt_number' => $receipt,
                'source_key' => $receipt !== null ? 'r:'.$receipt : null,
                'type' => Purchase::TYPE_SALE,
                'source' => 'manual',
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'buyer_name' => $contact === null && filled($data['buyer_name'] ?? null) ? trim($data['buyer_name']) : null,
            ]);

            foreach ($lines as $line) {
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'catalog_item_id' => $line['catalog_item_id'],
                    'type' => $line['type'],
                    'name' => $line['name'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'amount' => $line['amount'],
                    'discount_amount' => $line['discount_amount'],
                    'category_id' => $line['category_id'],
                    'product_category' => $line['product_category'],
                    'article_id' => $line['article'] !== null ? $this->articleId($organization->id, $line['article'], $line['category_id']) : null,
                ]);
            }

            return $purchase;
        });

        app(CustomerScoring::class)->refreshOrganization($organization);
        if ($organization->sellsProducts()) {
            RefreshBrandMetricsJob::dispatch($organization->id);
        }
        LoyaltyEngine::queue($organization->id, [$purchase->contact?->customer_id]);

        Audit::record('sale.created', $purchase, 'Vente saisie : '.number_format((int) $purchase->amount, 0, ',', ' ').' '.$organization->currencySymbol().($receipt ? " (ticket {$receipt})" : ''), [
            'store_id' => $storeId,
            'amount' => (int) $purchase->amount,
            'lines' => $lines->count(),
            'anonymous' => $purchase->contact_id === null,
        ], $user);

        return $purchase;
    }

    /**
     * Lignes : prestation / produit du catalogue (ou ligne libre) pour les
     * secteurs à catalogue ; famille obligatoire et libellé d'article
     * facultatif pour le commerce.
     *
     * @param  array<int, array<string, mixed>>  $input
     * @return Collection<int, array<string, mixed>>
     */
    private function lines(array $input, int $organizationId, bool $retail): Collection
    {
        $catalog = CatalogItem::where('organization_id', $organizationId)
            ->whereIn('id', collect($input)->pluck('catalog_item_id')->filter())
            ->with('category:id,name')
            ->get()
            ->keyBy('id');
        $categories = Category::where('organization_id', $organizationId)
            ->whereIn('id', collect($input)->pluck('category_id')->filter())
            ->pluck('name', 'id');

        return collect($input)->values()->map(function (array $line, int $index) use ($catalog, $categories, $retail) {
            $item = ! $retail && isset($line['catalog_item_id']) ? $catalog->get($line['catalog_item_id']) : null;
            $categoryId = $retail ? (int) $line['category_id'] : $item?->category_id;
            $article = $retail && filled($line['article'] ?? null) ? mb_substr(trim($line['article']), 0, 255) : null;
            $name = $retail ? ($article ?? $categories[$categoryId]) : ($item?->name ?? trim((string) ($line['name'] ?? '')));

            if ($name === '') {
                throw ValidationException::withMessages(["lines.{$index}.name" => 'Indiquez le libellé de la ligne '.($index + 1).'.']);
            }

            $quantity = (int) $line['quantity'];
            $unitPrice = (int) $line['unit_price'];
            $discount = (int) ($line['discount'] ?? 0);

            if ($discount > $quantity * $unitPrice) {
                throw ValidationException::withMessages(["lines.{$index}.discount" => 'La remise de la ligne '.($index + 1).' dépasse son montant.']);
            }

            return [
                'catalog_item_id' => $item?->id,
                'type' => $item?->type,
                'name' => mb_substr($name, 0, 255),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'amount' => $quantity * $unitPrice - $discount,
                'category_id' => $categoryId,
                'product_category' => $retail ? $categories[$categoryId] : $item?->category?->name,
                'article' => $article,
            ];
        });
    }

    /**
     * Relation client de la vente dans le point de vente : celle du client
     * choisi (créée si besoin), celle du nouveau client (le rattachement à
     * une fiche existante par téléphone est fait par CustomerLifecycle), ou
     * aucune pour une vente anonyme.
     *
     * @param  array<string, mixed>  $data
     */
    private function contact(array $data, int $storeId, User $user): ?Contact
    {
        if ($data['customer_mode'] === 'anonymous') {
            return null;
        }

        if ($data['customer_mode'] === 'existing') {
            $customer = Customer::where('organization_id', $user->organization_id)->findOrFail($data['customer_id']);

            $contact = Contact::where('store_id', $storeId)->where('customer_id', $customer->id)->first()
                ?? Contact::where('store_id', $storeId)->where('phone_e164', $customer->phone_e164)->first();

            if ($contact === null && blank($customer->phone_e164)) {
                throw ValidationException::withMessages(['customer_id' => 'Ce client n’a pas de numéro : ajoutez-le sur sa fiche ou saisissez un nouveau client.']);
            }

            return $contact ?? Contact::create([
                'store_id' => $storeId,
                'phone_e164' => $customer->phone_e164,
                'name' => $customer->displayName(),
                'consent_status' => 'unknown',
            ]);
        }

        $phone = PhoneNormalizer::normalize((string) $data['phone']);

        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'Numéro de téléphone invalide.']);
        }

        return Contact::where('store_id', $storeId)->where('phone_e164', $phone)->first()
            ?? Contact::create([
                'store_id' => $storeId,
                'phone_e164' => $phone,
                'name' => trim((string) $data['name']),
                'consent_status' => 'unknown',
            ]);
    }

    /** Article vu en caisse (libellé dédoublonné, cf. SalesImport). */
    private function articleId(int $organizationId, string $label, ?int $categoryId): int
    {
        $article = Article::firstOrCreate(
            ['organization_id' => $organizationId, 'match_key' => Article::matchKey(null, $label)],
            [
                'label' => $label,
                'normalized_label' => mb_substr(FamilyMatcher::normalize($label), 0, 255),
                'category_id' => $categoryId,
                'first_seen_at' => now(),
            ],
        );
        $article->update(['last_seen_at' => now(), 'category_id' => $article->category_id ?? $categoryId]);

        return $article->id;
    }
}
