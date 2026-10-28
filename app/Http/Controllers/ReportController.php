<?php

namespace App\Http\Controllers;

use App\Mail\DailyReportMail;
use App\Services\DailyReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rapport quotidien au dirigeant : consultation (page imprimable en PDF
 * depuis le navigateur), réglages de l'envoi du soir, envoi de test.
 */
class ReportController extends Controller
{
    public function daily(Request $request): View
    {
        $day = rescue(fn () => Carbon::parse((string) $request->query('date', today()->toDateString())), today(), false);

        return view('reports.daily', [
            'report' => (new DailyReport($request->user()->organization, $day))->build(),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    public function settings(Request $request): Response
    {
        $organization = $request->user()->organization;

        return Inertia::render('Settings/Reports', [
            'settings' => $organization->reportSettings(),
            'today' => today()->toDateString(),
            'userEmail' => $request->user()->email,
            'mailConfigured' => config('mail.default') !== 'log' && config('mail.default') !== 'array',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'recipients' => ['nullable', 'array', 'max:10'],
            'recipients.*' => ['email'],
            'send_at' => ['required', 'date_format:H:i'],
            'monthly_target' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
        ], [
            'recipients.*.email' => 'Adresse email invalide.',
        ]);

        if ($data['enabled'] && empty($data['recipients'])) {
            return back()->withErrors(['recipients' => 'Indiquez au moins un destinataire.']);
        }

        $organization = $request->user()->organization;
        $organization->update(['report_settings' => [
            ...$organization->reportSettings(),
            'enabled' => $data['enabled'],
            'recipients' => array_values(array_unique($data['recipients'] ?? [])),
            'send_at' => $data['send_at'],
            'monthly_target' => (int) ($data['monthly_target'] ?? 0),
        ]]);

        return back()->with('success', $data['enabled'] ? "Rapport quotidien activé : envoi chaque soir à {$data['send_at']}." : 'Rapport quotidien enregistré (envoi désactivé).');
    }

    /** Envoie le rapport du jour à l'utilisateur connecté, pour vérifier la mise en forme et la réception. */
    public function test(Request $request): RedirectResponse
    {
        $organization = $request->user()->organization;
        Mail::to($request->user()->email)->send(new DailyReportMail($organization, (new DailyReport($organization, today()))->build()));

        return back()->with('success', "Rapport du jour envoyé à {$request->user()->email}.");
    }
}
