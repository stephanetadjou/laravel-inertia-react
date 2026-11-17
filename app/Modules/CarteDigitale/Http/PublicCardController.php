<?php

namespace App\Modules\CarteDigitale\Http;

use App\Http\Controllers\Controller;
use App\Modules\CarteDigitale\Models\DigitalCard;
use App\Modules\CarteDigitale\Services\DigitalCards;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Page publique d'une carte digitale (/c/{slug}), sans compte. */
class PublicCardController extends Controller
{
    public function show(Request $request, string $slug): Response
    {
        $card = $this->card($slug);
        $card->record($request->query('src') === 'qr' ? 'scan' : 'view');
        $organization = $card->organization;
        $booking = $card->booking_enabled && $organization->hasModule('rdv') && (bool) ($organization->bookingSettings()['enabled'] ?? false);

        return Inertia::render('Cards/Public', [
            'card' => [
                'slug' => $card->slug,
                'url' => $card->publicUrl(),
                'name' => $card->name,
                'job_title' => $card->job_title,
                'company' => $card->company ?: $organization->name,
                'phone' => $card->phone_e164 ? '+'.$card->phone_e164 : null,
                'whatsapp' => $card->whatsapp_e164,
                'email' => $card->email,
                'website' => $card->website,
                'socials' => $card->socialLinks(),
                'address' => $card->address,
                'bio' => $card->bio,
                'color' => $card->color,
                'photo_url' => $card->photo_path ? url("/c/{$card->slug}/photo") : null,
                'booking_url' => $booking ? url("/rdv/{$organization->slug}") : null,
            ],
            'branding' => DigitalCards::showsBranding($organization),
            'signupUrl' => url('/register?offre=carte'),
        ]);
    }

    public function vcard(string $slug): HttpResponse
    {
        $card = $this->card($slug);
        $card->record('vcard');

        return response($card->vcard(), 200, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$card->slug.'.vcf"',
        ]);
    }

    public function photo(string $slug)
    {
        return DigitalCardController::photo($this->card($slug));
    }

    /** Clic sur WhatsApp, Appeler ou Prendre RDV (envoyé en arrière-plan par la page). */
    public function click(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(['whatsapp', 'call', 'booking', 'social'])]]);
        $this->card($slug)->record($data['type']);

        return response()->json(['ok' => true]);
    }

    public function contact(Request $request, string $slug): RedirectResponse
    {
        $card = $this->card($slug);

        // Champ caché anti-robots : rempli = robot, on fait comme si tout allait bien.
        if (filled($request->input('site_web'))) {
            return back()->with('success', 'Merci, votre message a bien été envoyé.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:1000'],
            'consent' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Indiquez votre nom.',
            'phone.required' => 'Indiquez votre numéro WhatsApp.',
        ]);

        $phone = PhoneNormalizer::normalize($data['phone'])
            ?? throw ValidationException::withMessages(['phone' => 'Numéro invalide : attendu '.PhoneNormalizer::EXPECTED_FORMAT.'.']);

        DigitalCards::receiveContact($card, trim($data['name']), $phone, $data['message'] ?? null, (bool) ($data['consent'] ?? false));

        return back()->with('success', "Merci ! {$card->name} vous recontacte très vite sur WhatsApp.");
    }

    private function card(string $slug): DigitalCard
    {
        $card = DigitalCard::where('slug', $slug)->where('is_active', true)->with('organization')->first();
        abort_if($card === null || $card->organization === null || $card->organization->isSuspended(), 404);

        return $card;
    }
}
