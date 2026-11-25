<?php

namespace App\Modules\Socle\Services;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Modules\Depenses\Models\Expense;
use App\Modules\Facturation\Models\Invoice;
use App\Modules\Stock\Models\StockItem;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;

/**
 * « Pouls client » : dans chaque application, 1 à 3 signaux sur les clients
 * (devis sans réponse, solde à encaisser, client qui s'éloigne…), et le fil
 * « Ma journée » de l'écran d'accueil. Signature ClientlyX : le client d'abord.
 */
class CustomerPulse
{
    /** @return array<int, array{tone: string, text: string, href: string}> */
    public function forApp(User $user, string $app): array
    {
        $organization = $user->organization;
        $storeIds = $this->storeIds($user);
        $orgId = $organization->id;
        $items = [];
        $push = function (int $count, string $tone, string $text, string $href) use (&$items) {
            if ($count > 0) {
                $items[] = compact('tone', 'text', 'href');
            }
        };

        switch ($app) {
            case 'invoices':
                if (! $organization->hasModule('factures')) {
                    break;
                }
                $silent = Invoice::where('organization_id', $orgId)->whereIn('store_id', $storeIds)->where('type', Invoice::TYPE_QUOTE)->where('status', 'sent')
                    ->where('sent_at', '<', now()->subDays(3))->count();
                $push($silent, 'warn', $silent.' devis sans réponse depuis 3 jours', '/invoices?type=quote&status=pending');
                $late = $this->overdue($orgId, $storeIds)->count();
                $push($late, 'bad', $late.' facture'.($late > 1 ? 's' : '').' en retard de paiement', '/invoices?type=invoice&status=overdue');
                $accepted = Invoice::where('organization_id', $orgId)->whereIn('store_id', $storeIds)->where('type', Invoice::TYPE_QUOTE)->where('status', 'accepted')->count();
                $push($accepted, 'good', $accepted.' devis accepté'.($accepted > 1 ? 's' : '').' à facturer', '/invoices?type=quote&status=accepted');
                break;

            case 'cash':
                $served = Purchase::whereIn('purchases.store_id', $storeIds)->where('purchases.status', Purchase::STATUS_VALID)->where('purchased_at', '>=', today())
                    ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')->distinct()->count('contacts.customer_id');
                $push($served, 'good', $served.' client'.($served > 1 ? 's' : '').' servi'.($served > 1 ? 's' : '').' : un merci sur WhatsApp les fera revenir', '/cash');
                if ($organization->hasModule('factures')) {
                    $balances = Invoice::where('organization_id', $orgId)->whereIn('store_id', $storeIds)->where('type', Invoice::TYPE_INVOICE)->where('status', 'partial')->get();
                    $push($balances->count(), 'warn', number_format($balances->sum(fn ($i) => $i->balance()), 0, ',', ' ').' F de soldes à encaisser au retrait', '/invoices?type=invoice&status=unpaid');
                }
                break;

            case 'stock':
                $low = StockItem::with('product:id,name')->where('organization_id', $orgId)->whereColumn('quantity', '<=', 'min_quantity')->orderBy('quantity')->limit(2)->get();
                foreach ($low as $item) {
                    $push(1, $item->quantity <= 0 ? 'bad' : 'warn', ($item->product?->name ?? 'Produit').' : '.$item->quantity.' restant'.($item->quantity > 1 ? 's' : '').', vos clients vont le demander', '/stock?level=low');
                }
                break;

            case 'expenses':
                $spent = (int) Expense::where('organization_id', $orgId)->whereIn('store_id', $storeIds)->where('spent_on', '>=', today()->startOfMonth())->sum('amount');
                $revenue = (int) Purchase::whereIn('store_id', $storeIds)->where('status', Purchase::STATUS_VALID)->where('purchased_at', '>=', today()->startOfMonth())->sum('amount');
                if ($revenue > 0) {
                    $ratio = (int) round($spent * 100 / $revenue);
                    $push(1, $ratio > 80 ? 'bad' : ($ratio > 50 ? 'warn' : 'good'), "Vos dépenses font {$ratio} % de ce que vos clients ont payé ce mois", '/expenses');
                }
                break;

            case 'agenda':
                $tomorrow = Appointment::whereIn('store_id', $storeIds)->whereIn('status', Appointment::OPEN_STATUSES)->whereBetween('starts_at', [today()->addDay(), today()->addDays(2)])->count();
                $push($tomorrow, 'warn', $tomorrow.' client'.($tomorrow > 1 ? 's' : '').' à rappeler pour demain', '/appointments?view=day');
                break;

            case 'customers':
            case 'sales':
                $risk = Customer::where('organization_id', $orgId)->where('lifecycle_stage', Customer::STAGE_AT_RISK)->count();
                $push($risk, 'warn', $risk.' client'.($risk > 1 ? 's' : '').' s’éloigne'.($risk > 1 ? 'nt' : ''), '/actions');
                break;
        }

        if ($items === []) {
            $total = (int) (app(NotificationService::class)->forUser($user)['total'] ?? 0);
            $push($total, 'warn', $total.' action'.($total > 1 ? 's' : '').' client à faire aujourd’hui', '/actions');
        }

        return array_slice($items, 0, 3);
    }

    /** Fil « Ma journée » : les clients du jour, dans l'ordre où l'on s'en occupe. */
    public function day(User $user): array
    {
        $organization = $user->organization;
        $storeIds = $this->storeIds($user);
        $customer = fn ($c) => $c ? ['id' => $c->id, 'name' => $c->name ?: $c->phone_e164] : null;

        $appointments = Appointment::with('customer:id,name,phone_e164')->whereIn('store_id', $storeIds)->whereIn('status', Appointment::OPEN_STATUSES)
            ->whereBetween('starts_at', [today(), today()->addDay()])->orderBy('starts_at')->limit(6)->get()
            ->map(fn ($a) => ['time' => $a->starts_at->format('H:i'), 'customer' => $customer($a->customer), 'what' => $a->service ?: 'Rendez-vous'])->all();

        $quotes = $balances = [];
        if ($organization->hasModule('factures')) {
            $quotes = Invoice::with('customer:id,name,phone_e164')->where('organization_id', $organization->id)->whereIn('store_id', $storeIds)
                ->where('type', Invoice::TYPE_QUOTE)->where('status', 'sent')->orderBy('sent_at')->limit(4)->get()
                ->map(fn ($i) => ['id' => $i->id, 'customer' => $customer($i->customer), 'what' => $i->title ?: $i->number, 'amount' => $i->total, 'days' => (int) $i->sent_at?->diffInDays(now())])->all();
            $balances = Invoice::with('customer:id,name,phone_e164')->where('organization_id', $organization->id)->whereIn('store_id', $storeIds)
                ->where('type', Invoice::TYPE_INVOICE)->whereIn('status', ['open', 'partial'])->orderBy('due_on')->limit(4)->get()
                ->map(fn ($i) => ['id' => $i->id, 'customer' => $customer($i->customer), 'what' => $i->title ?: $i->number, 'amount' => $i->balance(), 'late' => (bool) ($i->due_on?->lt(today()))])->all();
        }

        $drifting = Customer::where('organization_id', $organization->id)->where('lifecycle_stage', Customer::STAGE_AT_RISK)
            ->when($user->visibleStoreIds() !== null, fn ($w) => $w->whereHas('contacts', fn ($c) => $c->whereIn('store_id', $storeIds)))
            ->orderByDesc('total_spent')->limit(4)->get(['id', 'name', 'phone_e164', 'last_purchase_at'])
            ->map(fn ($c) => ['customer' => $customer($c), 'since' => $c->last_purchase_at ? (int) $c->last_purchase_at->diffInDays(now()) : null])->all();

        return compact('appointments', 'quotes', 'balances', 'drifting');
    }

    /** @return Builder<Invoice> */
    private function overdue(int $orgId, array $storeIds)
    {
        return Invoice::where('organization_id', $orgId)->whereIn('store_id', $storeIds)->where('type', Invoice::TYPE_INVOICE)->whereIn('status', ['open', 'partial'])->where('due_on', '<', today());
    }

    /** @return array<int, int> */
    private function storeIds(User $user): array
    {
        $visible = $user->visibleStoreIds();

        return Store::where('organization_id', $user->organization_id)->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->pluck('id')->all();
    }
}
