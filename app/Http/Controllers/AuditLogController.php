<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres › Journal : consultation du journal d'audit de l'entreprise
 * (réservé aux rôles qui gèrent l'organisation, cf. routes).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;
        $action = array_key_exists((string) $request->query('action'), AuditLog::ACTIONS) ? (string) $request->query('action') : null;
        $userId = $request->integer('user') ?: null;

        $logs = AuditLog::where('organization_id', $organizationId)
            ->when($action !== null, fn ($q) => $q->where('action', $action))
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->with('user:id,name')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Settings/Audit', [
            'filters' => ['action' => $action, 'user' => $userId],
            'actions' => collect(AuditLog::ACTIONS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'users' => User::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            'logs' => $logs->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'at' => $log->created_at?->toIso8601String(),
                'user' => $log->user?->name,
                'action' => $log->action,
                'action_label' => AuditLog::ACTIONS[$log->action] ?? $log->action,
                'description' => $log->description,
                'payload' => $log->payload,
                'ip' => $log->ip,
            ]),
        ]);
    }
}
