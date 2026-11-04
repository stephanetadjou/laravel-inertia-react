<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyMovement;
use App\Models\Store;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Rapports A4 générés côté serveur (dompdf, sans navigateur ni binaire, donc
 * identiques sous Windows et Linux) : direction, fidélité, magasin, clients à
 * relancer par segment, fiche client. Une seule mise en page
 * (reports/pdf.blade.php) : chaque rapport est une liste de sections
 * (chiffres clés, tableaux). Mêmes calculs que le pilotage.
 */
class PdfReport
{
    public const TYPES = [
        'direction' => 'Rapport mensuel direction',
        'fidelite' => 'Rapport fidélité',
        'magasin' => 'Rapport magasin',
        'relance' => 'Clients à relancer par segment',
    ];

    private const RELAUNCH_SEGMENTS = ['cant_lose', 'at_risk', 'hibernating', 'lost'];

    private const LIST_ROWS = 40;

    public function __construct(private User $user) {}

    /** Rapport d'un mois (AAAA-MM), sur le périmètre de l'utilisateur. */
    public function monthly(string $type, string $month, ?int $storeId = null): DomPdfWrapper
    {
        $from = Carbon::parse("{$month}-01")->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $period = $from->locale('fr')->isoFormat('MMMM YYYY');
        $organization = $this->user->organization;
        $stores = $this->stores();

        if ($type === 'magasin') {
            $store = $stores->firstWhere('id', $storeId);

            if (! $store) {
                throw ValidationException::withMessages(['store' => 'Choisissez un magasin.']);
            }
            $stores = collect([$store]);
        }

        $dashboard = $this->dashboard($stores->pluck('id')->all(), $from, $to);

        $sections = match ($type) {
            'fidelite' => $this->loyaltySections($dashboard),
            'magasin' => $this->storeSections($dashboard),
            'relance' => $this->relaunchSections(),
            default => $this->directionSections($dashboard),
        };

        $subtitle = $type === 'magasin' ? "{$stores->first()->name} · {$period}" : $period;

        return $this->render(self::TYPES[$type], $subtitle, $sections);
    }

    public function customer(Customer $customer): DomPdfWrapper
    {
        $money = fn (int $n) => $this->money($n);
        $cards = LoyaltyCard::where('customer_id', $customer->id)->with(['brand:id,name', 'program:id,name'])->get();
        $tickets = $customer->contacts()->whereIn('store_id', $this->stores()->pluck('id'))->get()
            ->flatMap(fn ($c) => $c->purchases()->with('store:id,name')->latest('purchased_at')->take(15)->get())
            ->sortByDesc('purchased_at')->take(15);
        $movements = LoyaltyMovement::where('customer_id', $customer->id)->orderByDesc('happened_on')->orderByDesc('id')->take(20)->get();

        return $this->render('Fiche client', $customer->name, [
            [
                'title' => 'Profil',
                'kpis' => [
                    ['label' => 'CA total', 'value' => $money((int) $customer->total_spent)],
                    ['label' => 'Achats', 'value' => (string) $customer->purchases_count],
                    ['label' => 'Segment RFM', 'value' => RfmCalculator::SEGMENTS[$customer->rfm_segment]['label'] ?? '—'],
                    ['label' => 'Score', 'value' => $customer->score !== null ? "{$customer->score}/100" : '—'],
                ],
                'tables' => [[
                    'title' => null,
                    'columns' => ['Téléphone', 'E-mail', 'Quartier', 'Client depuis', 'Dernier achat'],
                    'rows' => [[$customer->phone_e164, $customer->email ?: '—', $customer->district ?: '—', $customer->first_purchase_at?->format('d/m/Y') ?? '—', $customer->last_purchase_at?->format('d/m/Y') ?? '—']],
                ]],
            ],
            [
                'title' => 'Fidélité par enseigne',
                'tables' => [[
                    'title' => null,
                    'columns' => ['Enseigne', 'Programme', 'Statut', 'Points', 'Récompense', 'Expire le'],
                    'rows' => $cards->map(fn (LoyaltyCard $c) => [$c->brand->name, $c->program->name, LoyaltyCard::STATUSES[$c->status] ?? $c->status, $c->points, $c->reward_label ?: '—', $c->expires_on?->format('d/m/Y') ?? '—'])->all(),
                ]],
            ],
            ['title' => 'Derniers achats', 'tables' => [[
                'title' => null,
                'columns' => ['Date', 'Magasin', 'Ticket', 'Montant'],
                'rows' => $tickets->map(fn ($t) => [$t->purchased_at->format('d/m/Y'), $t->store?->name, $t->receipt_number ?: '—', $money((int) $t->amount)])->values()->all(),
            ]]],
            ['title' => 'Historique de fidélité', 'tables' => [[
                'title' => null,
                'columns' => ['Date', 'Mouvement', 'Détail', 'Points'],
                'rows' => $movements->map(fn (LoyaltyMovement $m) => [$m->happened_on->format('d/m/Y'), LoyaltyMovement::TYPES[$m->type] ?? $m->type, $m->note ?: '—', $m->points])->all(),
            ]]],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function directionSections(BiDashboard $dashboard): array
    {
        $direction = $dashboard->tab('direction');
        $clients = $dashboard->tab('clients');
        $network = $dashboard->tab('enseignes');
        $sections = [
            ['title' => 'Chiffres clés', 'kpis' => $this->kpis($direction['kpis'])],
            ['title' => 'Enseignes et familles', 'tables' => array_map(fn ($chart) => $this->chartTable($chart), $direction['charts'])],
            ['title' => 'Réseau', 'tables' => $this->tables($network['lists'])],
            ['title' => 'Clients', 'tables' => [...array_map(fn ($chart) => $this->chartTable($chart), array_slice($clients['charts'], 0, 1)), ...$this->tables(array_slice($clients['lists'], 0, 1))]],
        ];

        if ($this->user->organization->loyaltyPrograms()->exists()) {
            $sections[] = ['title' => 'Fidélité', 'kpis' => $this->kpis($dashboard->tab('fidelite')['kpis'])];
        }

        return $sections;
    }

    /** @return array<int, array<string, mixed>> */
    private function loyaltySections(BiDashboard $dashboard): array
    {
        $data = $dashboard->tab('fidelite', self::LIST_ROWS);

        return [
            ['title' => 'Chiffres clés', 'kpis' => $this->kpis($data['kpis'])],
            ['title' => 'Répartition', 'tables' => array_map(fn ($chart) => $this->chartTable($chart), $data['charts'])],
            ['title' => 'Clients à contacter', 'tables' => $this->tables($data['lists'])],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function storeSections(BiDashboard $dashboard): array
    {
        $direction = $dashboard->tab('direction');
        $clients = $dashboard->tab('clients');

        return [
            ['title' => 'Chiffres clés', 'kpis' => $this->kpis($direction['kpis'])],
            ['title' => 'Familles', 'tables' => [$this->chartTable(collect($direction['charts'])->firstWhere('key', 'families'))]],
            ['title' => 'Segments des clients', 'tables' => [$this->chartTable($clients['charts'][0])]],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function relaunchSections(): array
    {
        $sections = [];

        foreach (self::RELAUNCH_SEGMENTS as $segment) {
            $search = new CustomerSearch($this->user, ['segment' => $segment]);
            $query = $search->baseQuery();
            $total = (clone $query)->count();

            if ($total === 0) {
                continue;
            }

            $sections[] = [
                'title' => RfmCalculator::SEGMENTS[$segment]['label']." ({$total})",
                'note' => RfmCalculator::SEGMENTS[$segment]['action'],
                'tables' => [[
                    'title' => null,
                    'columns' => ['Client', 'Téléphone', 'Dernier achat', 'Achats', 'CA total'],
                    'rows' => $query->orderByDesc('total_spent')->limit(self::LIST_ROWS)->get()
                        ->map(fn (Customer $c) => [$c->name, $c->phone_e164, $c->last_purchase_at?->format('d/m/Y') ?? '—', $c->purchases_count, $this->money((int) $c->total_spent)])->all(),
                ]],
            ];
        }

        return $sections;
    }

    /** @param array<int, array<string, mixed>> $sections */
    private function render(string $title, string $subtitle, array $sections): DomPdfWrapper
    {
        $organization = $this->user->organization;
        $logo = 'data:image/svg+xml;base64,'.base64_encode(str_replace('url(#menentia-gradient)', '#0E7BD6', (string) file_get_contents(public_path('menentia-symbol.svg'))));
        $pdf = Pdf::loadView('reports.pdf', compact('organization', 'title', 'subtitle', 'sections', 'logo'))->setPaper('a4')->setOption('isFontSubsettingEnabled', true);
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $stamp = 'Édité le '.now()->format('d/m/Y');
        $canvas->page_text(40, $canvas->get_height() - 28, $stamp, $font, 8, [0.4, 0.45, 0.5]);
        $canvas->page_text($canvas->get_width() - 95, $canvas->get_height() - 28, 'Page {PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.4, 0.45, 0.5]);

        return $pdf;
    }

    private function dashboard(array $storeIds, Carbon $from, Carbon $to): BiDashboard
    {
        $organization = $this->user->organization;
        $analytics = new RetailAnalytics($organization, $storeIds, $from, $to);

        return new BiDashboard($this->user, $organization, $analytics, $storeIds, $from, $to);
    }

    private function stores()
    {
        $visible = $this->user->visibleStoreIds();

        return Store::where('organization_id', $this->user->organization_id)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->orderBy('name')->get();
    }

    /**
     * @param  list<array<string, mixed>>  $kpis
     * @return list<array{label: string, value: string, change?: string}>
     */
    private function kpis(array $kpis): array
    {
        return array_map(function (array $k) {
            $value = match ($k['unit']) {
                'fcfa' => $this->money((int) $k['value']),
                '%' => "{$k['value']} %",
                'x' => number_format((float) $k['value'], 1, ',', ' '),
                default => number_format((float) $k['value'], 0, ',', ' '),
            };
            $change = fn (?float $base) => $base ? sprintf('%+d %%', round(($k['value'] - $base) / abs($base) * 100)) : '—';

            return ['label' => $k['label'], 'value' => $value, 'change' => $k['previous'] !== null ? 'Préc. '.$change($k['previous']).' · N-1 '.$change($k['last_year']) : null];
        }, $kpis);
    }

    /** @return array<string, mixed> */
    private function chartTable(array $chart): array
    {
        $isMoney = $chart['unit'] === 'fcfa';

        return [
            'title' => $chart['title'],
            'columns' => ['', $isMoney ? 'CA net' : 'Nombre'],
            'rows' => array_map(fn ($item) => [$item['label'], $isMoney ? $this->money($item['value']) : $item['value']], $chart['items']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function tables(array $lists): array
    {
        return array_map(fn ($list) => ['title' => $list['title'], 'columns' => $list['columns'], 'rows' => $list['rows']], $lists);
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ').' '.$this->user->organization->currencySymbol();
    }
}
