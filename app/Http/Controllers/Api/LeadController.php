<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * POST /api/public/v1/leads : formulaire « Demander une démo » du site vitrine.
 *
 * Le site est statique : le navigateur poste le formulaire directement ici,
 * puis revient sur la page Contact du site (#merci). Un appel en JSON
 * (fetch) reçoit une réponse 201. Le champ caché « site_web » piège les robots :
 * rempli, la demande est ignorée sans le dire.
 */
class LeadController extends Controller
{
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        if (filled($request->input('site_web'))) {
            return $this->done($request);
        }

        $data = $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'entreprise' => ['required', 'string', 'max:160'],
            'whatsapp' => ['required', 'string', 'max:32'],
            'ville' => ['nullable', 'string', 'max:80'],
            'activite' => ['nullable', Rule::in(Lead::ACTIVITIES)],
            'message' => ['nullable', 'string', 'max:2000'],
            'consentement' => ['accepted'],
        ]);

        $lead = Lead::create([
            'name' => $data['nom'],
            'company' => $data['entreprise'],
            'whatsapp_raw' => $data['whatsapp'],
            'whatsapp' => PhoneNormalizer::normalize($data['whatsapp']),
            'city' => $data['ville'] ?? null,
            'activity' => $data['activite'] ?? null,
            'message' => $data['message'] ?? null,
            'consent_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        $this->notify($lead);

        return $this->done($request, 201);
    }

    private function done(Request $request, int $status = 200): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true], $status);
        }

        return redirect()->away(rtrim((string) config('services.menentia.site_url'), '/').'/contact#merci');
    }

    /** Prévient l'équipe NENTIX par email, après la réponse au visiteur. */
    private function notify(Lead $lead): void
    {
        $to = config('services.menentia.leads_email');

        if (blank($to)) {
            return;
        }

        dispatch(function () use ($lead, $to) {
            $lines = [
                'Nouvelle demande de démo depuis le site.',
                '',
                "Nom : {$lead->name}",
                "Entreprise : {$lead->company}",
                'WhatsApp : '.($lead->whatsapp ?? $lead->whatsapp_raw),
                'Ville : '.($lead->city ?? '-'),
                'Activité : '.($lead->activity ?? '-'),
                '',
                (string) $lead->message,
            ];

            try {
                Mail::raw(implode("\n", $lines), fn ($m) => $m->to($to)->subject("Demande de démo : {$lead->company}"));
            } catch (\Throwable $e) {
                Log::warning('Email de demande de démo non envoyé', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        })->afterResponse();
    }
}
