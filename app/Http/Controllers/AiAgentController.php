<?php

namespace App\Http\Controllers;

use App\Models\AiAgent;
use App\Models\Store;
use App\Services\AiAgents;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Agents IA (module agents_ia) : liste, assistant de création en 5 étapes
 * (persona, modèle, connaissances, sécurité, paramètres), essai sans envoi,
 * activation. La clé API n'est jamais renvoyée au navigateur : laissée vide
 * à la modification, l'ancienne est gardée.
 */
class AiAgentController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;
        $stores = $organization->stores()->pluck('name', 'id');

        return Inertia::render('AiAgents/Index', [
            'agents' => AiAgent::where('organization_id', $organization->id)->orderBy('name')->get()->map(fn (AiAgent $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'role' => $a->role,
                'provider' => AiAgent::PROVIDERS[$a->provider]['label'] ?? $a->provider,
                'model' => $a->model,
                'schedule' => AiAgent::SCHEDULES[$a->schedule] ?? $a->schedule,
                'stores' => empty($a->store_ids) ? 'Tous les établissements' : collect($a->store_ids)->map(fn ($id) => $stores[$id] ?? null)->filter()->implode(', '),
                'active' => $a->active,
                'replies_count' => $a->replies_count,
                'last_reply_at' => $a->last_reply_at?->toIso8601String(),
                'last_error' => $a->last_error,
                'last_error_at' => $a->last_error_at?->toIso8601String(),
            ]),
            'apiEnabled' => $organization->usesWhatsAppApi(),
            'autoRepliesEnabled' => $organization->hasModule('reponses_auto'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function edit(Request $request, AiAgent $agent): Response
    {
        $this->authorizeOwn($request, $agent);

        return $this->form($request, $agent);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $agent = AiAgent::create([...$data, 'organization_id' => $request->user()->organization_id]);
        Audit::record('ai_agent.created', $agent, "Agent IA « {$agent->name} » créé", ['provider' => $agent->provider, 'active' => $agent->active]);

        return redirect()->route('ai-agents.index')->with('success', "Agent « {$agent->name} » créé.".($agent->active ? ' Il répond désormais aux messages WhatsApp.' : ''));
    }

    public function update(Request $request, AiAgent $agent): RedirectResponse
    {
        $this->authorizeOwn($request, $agent);
        $data = $this->validated($request, $agent);

        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }

        $agent->update([...$data, 'last_error' => null, 'last_error_at' => null]);
        Audit::record('ai_agent.updated', $agent, "Agent IA « {$agent->name} » modifié", ['provider' => $agent->provider, 'active' => $agent->active]);

        return redirect()->route('ai-agents.index')->with('success', "Agent « {$agent->name} » enregistré.");
    }

    public function toggle(Request $request, AiAgent $agent): RedirectResponse
    {
        $this->authorizeOwn($request, $agent);
        $agent->update(['active' => ! $agent->active]);
        Audit::record('ai_agent.toggled', $agent, $agent->active ? "Agent IA « {$agent->name} » activé" : "Agent IA « {$agent->name} » mis en pause");

        return back()->with('success', $agent->active ? "« {$agent->name} » répond aux messages." : "« {$agent->name} » est en pause.");
    }

    public function destroy(Request $request, AiAgent $agent): RedirectResponse
    {
        $this->authorizeOwn($request, $agent);
        Audit::record('ai_agent.deleted', $agent, "Agent IA « {$agent->name} » supprimé");
        $agent->delete();

        return redirect()->route('ai-agents.index')->with('success', 'Agent supprimé.');
    }

    /**
     * Essai depuis l'assistant : réponse du fournisseur à un message type,
     * avec les réglages en cours de saisie (rien n'est enregistré ni envoyé).
     */
    public function test(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;
        $existing = $request->filled('agent_id')
            ? AiAgent::where('organization_id', $organization->id)->find($request->integer('agent_id'))
            : null;

        $data = $request->validate([
            ...$this->rules($organization->id, $existing, forTest: true),
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $agent = new AiAgent([...collect($data)->except(['message', 'agent_id'])->all(), 'organization_id' => $organization->id]);
        if (blank($data['api_key'] ?? null)) {
            $agent->api_key = $existing?->api_key;
        }

        if (blank($agent->api_key)) {
            return response()->json(['error' => 'Saisissez la clé API à l’étape « Modèle ».'], 422);
        }

        $storeId = collect($data['store_ids'] ?? [])->first();
        $store = Store::where('organization_id', $organization->id)->when($storeId, fn ($q) => $q->whereKey($storeId))->orderBy('id')->first();

        if ($store === null) {
            return response()->json(['error' => 'Créez d’abord un établissement.'], 422);
        }

        try {
            return response()->json(['reply' => AiAgents::test($agent, $store, $data['message'])]);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function form(Request $request, ?AiAgent $agent): Response
    {
        $organization = $request->user()->organization;

        return Inertia::render('AiAgents/Form', [
            'agent' => $agent ? [
                ...$agent->only(['id', 'name', 'role', 'tone', 'instructions', 'provider', 'model', 'use_business_info', 'knowledge', 'forbidden_topics', 'handoff_message', 'max_replies_per_day', 'schedule', 'active']),
                'store_ids' => array_map('intval', $agent->store_ids ?? []),
                'masked_key' => $agent->maskedKey(),
            ] : null,
            'providers' => collect(AiAgent::PROVIDERS)->map(fn ($p, $key) => ['key' => $key, ...$p])->values(),
            'tones' => collect(AiAgent::TONES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'schedules' => collect(AiAgent::SCHEDULES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'stores' => $organization->stores()->orderBy('name')->get(['id', 'name']),
            'defaultHandoff' => AiAgents::defaultHandoff(),
            'hasCatalog' => $organization->hasModule('catalogue') || $organization->hasModule('produits'),
            'apiEnabled' => $organization->usesWhatsAppApi(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?AiAgent $agent): array
    {
        $data = $request->validate($this->rules($request->user()->organization_id, $agent));
        $data['store_ids'] = array_values(array_map('intval', $data['store_ids'] ?? [])) ?: null;

        return $data;
    }

    /** @return array<string, mixed> */
    private function rules(int $organizationId, ?AiAgent $agent, bool $forTest = false): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'role' => ['nullable', 'string', 'max:120'],
            'tone' => ['required', Rule::in(array_keys(AiAgent::TONES))],
            'instructions' => ['nullable', 'string', 'max:4000'],
            'provider' => ['required', Rule::in(array_keys(AiAgent::PROVIDERS))],
            'model' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            // Obligatoire à la création, facultative ensuite (ancienne clé gardée).
            'api_key' => [$agent || $forTest ? 'nullable' : 'required', 'string', 'max:300'],
            'use_business_info' => ['boolean'],
            'knowledge' => ['nullable', 'string', 'max:12000'],
            'forbidden_topics' => ['nullable', 'string', 'max:2000'],
            'handoff_message' => ['nullable', 'string', 'max:500'],
            'max_replies_per_day' => ['required', 'integer', 'min:1', 'max:200'],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['integer', Rule::exists('stores', 'id')->where('organization_id', $organizationId)],
            'schedule' => ['required', Rule::in(array_keys(AiAgent::SCHEDULES))],
            'active' => ['boolean'],
        ];
    }

    private function authorizeOwn(Request $request, AiAgent $agent): void
    {
        if ($agent->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, "Cet agent n'appartient pas à votre entreprise.");
        }
    }
}
