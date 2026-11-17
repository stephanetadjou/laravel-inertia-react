<?php

namespace App\Modules\Tunnels\Http;

use App\Http\Controllers\Controller;
use App\Modules\Tunnels\Models\Funnel;
use App\Modules\Tunnels\Models\FunnelLead;
use App\Modules\Tunnels\Services\Funnels;
use App\Services\Audit;
use App\Support\OnlinePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Tunnels de vente de l'entreprise : liste, modèles, réglages, statistiques et relances. */
class FunnelController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;
        $funnels = Funnel::where('organization_id', $organization->id)->with('organization')->latest()->get();
        $limit = Funnels::limit($organization);

        return Inertia::render('Funnels/Index', [
            'funnels' => $funnels->map(fn (Funnel $f) => [
                'id' => $f->id, 'title' => $f->title, 'slug' => $f->slug, 'offer' => $f->offer, 'benefits' => $f->benefits ?? [], 'reviews' => $f->reviews ?? [],
                'fields' => $f->fields ?? [], 'choice' => $f->choice, 'ends_at' => $f->ends_at?->format('Y-m-d\TH:i'), 'seats' => $f->seats, 'seats_left' => $f->seatsLeft(),
                'message' => $f->message, 'budget' => $f->budget, 'is_active' => $f->is_active, 'open' => $f->isOpen(),
                'url' => $f->publicUrl(), 'image' => $f->image_path ? url("/t/{$organization->slug}/{$f->slug}/image") : null,
                'stats' => Funnels::stats($f),
            ]),
            'templates' => Funnels::templatesFor($organization),
            'followUps' => Funnels::due($organization),
            'limit' => $limit,
            'active' => $funnels->where('is_active', true)->count(),
            'choices' => collect(OnlinePage::CHOICES)->except(['ride', 'prices', 'callback'])->map(fn ($c) => $c['label']),
            'sources' => Funnel::SOURCES,
            'fieldOptions' => Funnel::FIELDS,
            'hasCampaigns' => $organization->hasModule('campagnes'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $request->user()->organization;
        abort_if(Funnels::limit($organization) === 0, 403, 'Les tunnels de vente sont inclus à partir de l’offre Solo.');
        $data = $request->validate(['template' => ['required', Rule::in(array_keys(Funnels::TEMPLATES))]]);
        $funnel = Funnels::createFromTemplate($organization, $data['template']);
        Audit::record('funnel.created', $funnel, "Tunnel « {$funnel->title} » créé");

        return back()->with('success', $funnel->is_active ? 'Tunnel créé : personnalisez-le puis partagez son lien.' : 'Tunnel créé en brouillon : votre offre limite le nombre de tunnels actifs.');
    }

    public function update(Request $request, Funnel $funnel): RedirectResponse
    {
        $this->authorizeFunnel($request, $funnel);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'offer' => ['required', 'string', 'max:500'],
            'benefits' => ['nullable', 'array', 'max:3'],
            'benefits.*' => ['nullable', 'string', 'max:120'],
            'reviews' => ['nullable', 'array', 'max:3'],
            'reviews.*.name' => ['nullable', 'string', 'max:80'],
            'reviews.*.text' => ['nullable', 'string', 'max:300'],
            'fields' => ['nullable', 'array', 'max:4'],
            'fields.*' => [Rule::in(array_keys(Funnel::FIELDS))],
            'choice' => ['required', Rule::in(array_keys(OnlinePage::CHOICES))],
            'ends_at' => ['nullable', 'date'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'message' => ['nullable', 'string', 'max:1000'],
            'budget' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);
        $funnel->update([
            ...collect($data)->except('image')->all(),
            'benefits' => array_values(array_filter($data['benefits'] ?? [])),
            'reviews' => array_values(array_filter($data['reviews'] ?? [], fn ($r) => filled($r['text'] ?? null))),
            ...($request->hasFile('image') ? ['image_path' => $request->file('image')->store("funnels/{$funnel->organization_id}")] : []),
        ]);

        return back()->with('success', 'Tunnel enregistré.');
    }

    public function toggle(Request $request, Funnel $funnel): RedirectResponse
    {
        $this->authorizeFunnel($request, $funnel);
        if (! $funnel->is_active && ! Funnels::canActivate($request->user()->organization, $funnel)) {
            return back()->with('error', 'Votre offre ne permet pas plus de '.Funnels::limit($request->user()->organization).' tunnel(s) actif(s) : désactivez-en un ou passez à l’offre supérieure.');
        }
        $funnel->update(['is_active' => ! $funnel->is_active]);

        return back()->with('success', $funnel->is_active ? 'Tunnel activé.' : 'Tunnel désactivé.');
    }

    public function destroy(Request $request, Funnel $funnel): RedirectResponse
    {
        $this->authorizeFunnel($request, $funnel);
        $funnel->delete();

        return back()->with('success', 'Tunnel supprimé.');
    }

    public function followUpDone(Request $request, FunnelLead $lead): RedirectResponse
    {
        $this->authorizeFunnel($request, $lead->funnel);
        Funnels::markDone($lead);

        return back()->with('success', 'Relance notée comme faite.');
    }

    private function authorizeFunnel(Request $request, Funnel $funnel): void
    {
        abort_unless($funnel->organization_id === $request->user()->organization_id, 404);
    }
}
