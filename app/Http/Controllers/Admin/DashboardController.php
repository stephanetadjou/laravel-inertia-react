<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\PlatformLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Back-office plateforme › Tableau de bord : entreprises par statut et par
 * secteur, inscriptions des 30 derniers jours, essais qui se terminent.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $byStatus = Organization::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $trialExpired = Organization::where('status', Organization::STATUS_TRIAL)->where('trial_ends_at', '<', now())->count();

        $since = today()->subDays(29);
        $signups = Organization::where('created_at', '>=', $since)
            ->selectRaw('substr(created_at, 1, 10) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return Inertia::render('Admin/Dashboard', [
            'statuses' => collect(Organization::STATUSES)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'total' => (int) ($byStatus[$key] ?? 0),
            ])->values(),
            'trialExpired' => $trialExpired,
            'total' => (int) $byStatus->sum(),
            'sectors' => Organization::query()->selectRaw('sector, count(*) as total')->groupBy('sector')->orderByDesc('total')->get()
                ->map(fn ($row) => [
                    'sector' => $row->sector,
                    'label' => Organization::SECTORS[$row->sector]['label'] ?? 'Non renseigné',
                    'total' => (int) $row->total,
                ]),
            'signups' => collect(range(0, 29))->map(fn ($i) => [
                'day' => $since->copy()->addDays($i)->toDateString(),
                'total' => (int) ($signups[$since->copy()->addDays($i)->toDateString()] ?? 0),
            ]),
            'signupsTotal' => (int) $signups->sum(),
            'trialsEnding' => OrganizationController::listQuery()
                ->where('status', Organization::STATUS_TRIAL)
                ->whereBetween('trial_ends_at', [now(), now()->addDays(7)])
                ->orderBy('trial_ends_at')
                ->get()
                ->map(fn (Organization $o) => OrganizationController::present($o)),
            'newLeads' => Lead::where('status', 'new')->count(),
            'logs' => PlatformLog::with('user:id,name', 'organization:id,name')->latest('id')->take(15)->get()
                ->map(fn (PlatformLog $log) => [...OrganizationController::presentLog($log), 'organization' => $log->organization?->name, 'organization_id' => $log->organization_id]),
        ]);
    }
}
