<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\MessageTemplate;
use App\Services\MessageTemplateSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MessageTemplateController extends Controller
{
    private const CATEGORIES = ['utility', 'marketing', 'authentication', 'service'];

    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $templates = MessageTemplate::where('organization_id', $organizationId)
            ->orderBy('name')
            ->get();

        // Utilisation dans les campagnes de l'organisation (par nom de modèle).
        $usage = Campaign::where('message_type', 'template')
            ->whereHas('stores', fn ($q) => $q->where('organization_id', $organizationId))
            ->selectRaw('template_name, COUNT(*) as campaigns, MAX(created_at) as last_used_at')
            ->groupBy('template_name')
            ->get()
            ->keyBy('template_name');

        return Inertia::render('Templates/Index', [
            'templates' => $templates->map(fn (MessageTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'language' => $t->language,
                'category' => $t->category,
                'description' => $t->description,
                'is_active' => (bool) $t->is_active,
                'status' => $t->status,
                'body_text' => $t->body_text,
                'body_variables' => $t->body_variables,
                'header_format' => $t->header_format,
                'synced_at' => $t->synced_at?->toIso8601String(),
                'campaigns_count' => (int) ($usage[$t->name]->campaigns ?? 0),
                'last_used_at' => isset($usage[$t->name]) ? substr((string) $usage[$t->name]->last_used_at, 0, 10) : null,
            ]),
            'canSync' => $request->user()->canManageOrganization(),
        ]);
    }

    /**
     * Importe les modèles des comptes WhatsApp Business des établissements
     * (réservé aux administrateurs, cf. route).
     */
    public function sync(Request $request, MessageTemplateSync $sync): RedirectResponse
    {
        $result = $sync->sync($request->user()->organization);

        if ($result['accounts'] === 0) {
            return back()->with('error', 'Aucun établissement avec un WABA ID et un token : renseignez-les dans Paramètres → WhatsApp.');
        }

        $message = "{$result['synced']} modèle(s) synchronisé(s) depuis {$result['accounts']} compte(s) WhatsApp.";

        return $result['errors'] === []
            ? back()->with('success', $message)
            : back()->with('error', $message.' Erreurs : '.implode(' ; ', $result['errors']));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $request->user()->organization->messageTemplates()->create($data);

        return back()->with('success', "Modèle « {$data['name']} » enregistré.");
    }

    public function update(Request $request, MessageTemplate $template): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $template);

        $data = $this->validated($request, $template->id);

        $template->update($data);

        return back()->with('success', "Modèle « {$template->name} » mis à jour.");
    }

    public function destroy(Request $request, MessageTemplate $template): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $template);

        $template->delete();

        return back()->with('success', 'Modèle supprimé.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $organizationId = $request->user()->organization_id;

        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('message_templates', 'name')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)->where('language', $request->input('language')))
                    ->ignore($ignoreId),
            ],
            'language' => ['required', 'string', 'max:10'],
            'category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }

    private function authorizeSameOrganization(Request $request, MessageTemplate $template): void
    {
        if ($template->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Ce template n\'appartient pas à votre organisation.');
        }
    }
}
