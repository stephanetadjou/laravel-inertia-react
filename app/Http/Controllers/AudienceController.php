<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Group;
use App\Models\Purchase;
use App\Services\Audit;
use App\Services\CustomerSearch;
use App\Services\Loyalty;
use App\Services\RfmCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Clients d'une analyse, pour les relancer : chaque chiffre des tableaux de
 * bord, de l'écran Multimarque, des constats « À retenir », des segments RFM
 * et des familles renvoie vers ses clients avec les filtres de Fiches
 * clients (CustomerSearch, critères d'analyse compris). Deux sorties :
 * - export CSV / Excel des clients (droit d'export, périmètre du rôle,
 *   journalisé) ;
 * - création d'un groupe, ouvert dans Actions du jour (liens wa.me avec
 *   message prérempli) et utilisable dans une campagne si l'API est active.
 */
class AudienceController extends Controller
{
    public const COLUMNS = ['Nom', 'Type', 'Téléphone', 'Code client', 'Marques', 'Point de vente principal', 'Segment RFM', 'Étape', 'Dernier achat', 'Tickets', 'CA net (FCFA)', 'Consentement WhatsApp', 'Palier fidélité', 'Points'];

    public function export(Request $request): StreamedResponse
    {
        if (! $request->user()->canExport()) {
            throw new HttpException(403, 'Votre rôle ne permet pas d’exporter des données.');
        }

        $search = CustomerSearch::fromRequest($request);
        $label = $this->label($request, $search);
        $query = $search->query();
        $count = (clone $query)->count();

        Audit::export('clients · '.$label, $count, $request->only(CustomerSearch::FILTER_KEYS));

        $name = 'clients-'.str($label)->slug()->limit(60, '').'-'.now()->format('Y-m-d');
        $rows = fn () => $this->rows($search, $query);

        if ($request->query('format') === 'xlsx') {
            return response()->streamDownload(function () use ($rows, $label) {
                $sheet = new Spreadsheet;
                $data = [self::COLUMNS];
                foreach ($rows() as $row) {
                    $data[] = $row;
                }
                $sheet->getActiveSheet()->setTitle('Clients')->fromArray($data, null, 'A1', true);
                $sheet->getProperties()->setTitle($label);
                (new Xlsx($sheet))->save('php://output');
            }, "{$name}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::COLUMNS, ';', '"', '');
            foreach ($rows() as $row) {
                fputcsv($out, $row, ';', '"', '');
            }
            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Crée un groupe avec les clients de l'analyse (leurs contacts dans le
     * périmètre) et l'ouvre dans Actions du jour pour les relancer.
     */
    public function group(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'filters' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $search = new CustomerSearch($user, array_intersect_key($data['filters'] ?? [], array_flip(CustomerSearch::FILTER_KEYS)));
        $contacts = Contact::query()->where($search->contactScope())->whereIn('contacts.customer_id', $search->query()->select('customers.id'))->pluck('contacts.id');

        if ($contacts->isEmpty()) {
            return back()->with('error', 'Aucun client de votre périmètre dans cette analyse.');
        }

        $group = Group::firstOrCreate(['organization_id' => $user->organization_id, 'name' => trim($data['name'])]);
        $group->contacts()->syncWithoutDetaching($contacts->all());
        $customers = Contact::whereKey($contacts)->distinct()->count('customer_id');

        return redirect('/actions?list=group&group='.$group->id)
            ->with('success', "{$customers} client(s) dans le groupe « {$group->name} » : relancez-les ci-dessous ou depuis une campagne.");
    }

    /**
     * Lignes d'export, par paquets : chiffres du périmètre (ou de la marque
     * choisie), point de vente principal, segment, consentement, fidélité.
     *
     * @return \Generator<int, list<string|int>>
     */
    private function rows(CustomerSearch $search, $query): \Generator
    {
        $storeIds = $search->storeIds;
        $brandId = $search->brandFilter['selected'];
        $loyalty = Loyalty::for($search->user->organization);
        $loyaltyOn = $search->user->organization->loyaltySettings()['enabled'];
        $stores = $search->roleStores->keyBy('id');

        foreach ($query->lazyById(500, 'customers.id') as $customer) {
            // Rassemblé par client : la base est petite par client (quelques contacts, quelques tickets).
            $contacts = $customer->contacts()->whereIn('store_id', $storeIds)->get(['id', 'store_id', 'consent_status']);
            $sales = DB::table('purchases')->whereIn('contact_id', $contacts->pluck('id'))->where('status', Purchase::STATUS_VALID)
                ->selectRaw('store_id, '.str_replace('purchases.', '', Purchase::SQL_SALES_COUNT).' as tickets, SUM(amount) as net, MAX(substr(purchased_at, 1, 10)) as last_at')
                ->groupBy('store_id')->get();
            $main = $sales->sortByDesc('net')->first();
            $segment = $brandId !== null
                ? DB::table('customer_brand_metrics')->where('customer_id', $customer->id)->where('brand_id', $brandId)->value('rfm_segment')
                : $customer->rfm_segment;
            $tier = $loyaltyOn ? $loyalty->tier($customer) : null;

            yield [
                $customer->displayName(),
                Customer::TYPES[$customer->type] ?? 'Particulier',
                '+'.$customer->phone_e164,
                (string) $customer->external_code,
                $this->brands($sales, $stores),
                $main ? (string) ($stores[$main->store_id]->name ?? '') : '',
                RfmCalculator::SEGMENTS[$segment]['label'] ?? '',
                $customer->stageLabel(),
                (string) $sales->max('last_at'),
                (int) $sales->sum('tickets'),
                (int) $sales->sum('net'),
                $contacts->contains('consent_status', 'opt_in') ? 'Oui' : ($contacts->contains('consent_status', 'opt_out') ? 'Refus' : 'Inconnu'),
                $tier['current']['name'] ?? '',
                $loyaltyOn ? $loyalty->balance($customer) : '',
            ];
        }
    }

    private function brands(Collection $sales, Collection $stores): string
    {
        return $sales->map(fn ($s) => $stores[$s->store_id]?->brand?->name)->filter()->unique()->sort()->implode(', ');
    }

    private function label(Request $request, CustomerSearch $search): string
    {
        return trim((string) $request->query('label', '')) ?: $search->label();
    }
}
