<?php

namespace App\Http\Controllers;

use App\Models\SalesTarget;
use App\Models\User;
use App\Services\Audit;
use App\Services\SalesTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Clients › Objectifs (module « prospects ») : objectifs et résultats du mois
 * par commercial, objectif à date, taux de passage, classement ; primes du
 * mois et export Excel pour la paie. Un commercial ne voit que sa ligne.
 */
class SalesTeamController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $team = new SalesTeam($user->organization, $this->month($request));

        return Inertia::render('SalesTeam/Index', [
            'month' => $team->month(),
            'elapsed' => (int) round($team->elapsed() * 100),
            'board' => $team->board($user),
            'indicators' => SalesTeam::INDICATORS,
            'members' => $user->canManageOrganization()
                ? User::where('organization_id', $user->organization_id)->orderBy('name')->get()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'targets' => $team->targets($u)])
                : [],
            'canEdit' => $user->canManageOrganization(),
        ]);
    }

    public function updateTargets(Request $request, User $member): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canManageOrganization() && $member->organization_id === $user->organization_id, 403);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            ...collect(array_keys(SalesTeam::INDICATORS))->mapWithKeys(fn ($k) => [$k => ['required', 'integer', 'min:0']])->all(),
        ]);

        SalesTarget::updateOrCreate(
            ['user_id' => $member->id, 'month' => $data['month']],
            ['organization_id' => $user->organization_id, ...collect($data)->only(array_keys(SalesTeam::INDICATORS))->map(fn ($v) => (int) $v)->all()],
        );
        Audit::record('settings.updated', $member, "Objectifs {$data['month']} de {$member->name}", $data);

        return back()->with('success', "Objectifs de {$member->name} enregistrés.");
    }

    public function commissions(Request $request): Response
    {
        $user = $request->user();
        $team = new SalesTeam($user->organization, $this->month($request));
        $rows = collect($team->commissions())->when($user->ownRecordsOnly(), fn ($c) => $c->where('user_id', $user->id))->values();

        return Inertia::render('SalesTeam/Commissions', [
            'month' => $team->month(),
            'rows' => $rows,
            'totals' => $rows->groupBy('user')->map(fn ($r, $name) => ['user' => $name, 'count' => $r->count(), 'amount' => (int) $r->sum('amount')])->values(),
            'canExport' => $user->canExport(),
        ]);
    }

    /** Export Excel des primes du mois (paie), journalisé. */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->canExport(), 403);
        $team = new SalesTeam($user->organization, $this->month($request));
        $rows = $team->commissions();
        Audit::export('primes des commerciaux '.$team->month(), count($rows));

        return response()->streamDownload(function () use ($rows, $team) {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet()->setTitle('Primes '.$team->month());
            $sheet->fromArray([
                ['Commercial', 'Client apporté', 'Formule', 'Date du 2e paiement', 'Prime (FCFA)'],
                ...array_map(fn ($r) => [$r['user'], $r['customer'], $r['plan'], $r['second_payment_at'], $r['amount']], $rows),
                [],
                ['Total', '', '', '', array_sum(array_column($rows, 'amount'))],
            ], null, 'A1', true);
            $sheet->getStyle('1:1')->getFont()->setBold(true);
            foreach (range('A', 'E') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            (new Xlsx($book))->save('php://output');
        }, 'primes-commerciaux-'.$team->month().'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function month(Request $request): string
    {
        $month = (string) $request->query('month', '');

        return preg_match('/^\d{4}-\d{2}$/', $month) ? $month : today()->format('Y-m');
    }
}
