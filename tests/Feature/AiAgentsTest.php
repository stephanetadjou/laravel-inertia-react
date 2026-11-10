<?php

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\AiAgent;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsAppOutboundMessage;
use App\Services\AiAgents;
use App\Support\SectorPresets;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Module Agents IA : inscription, écran de réglage en 5 étapes, essai,
 * réponse automatique aux messages WhatsApp et passage à l'équipe.
 */

function aiOrg(array $modules = ['agents_ia', 'reponses_auto', 'catalogue']): Organization
{
    $org = Organization::factory()->create(['sector' => 'beaute', 'whatsapp_api_enabled' => true, 'timezone' => 'Africa/Libreville']);
    SectorPresets::apply($org);
    $org->setModules([...$org->enabledModules(), ...$modules]);
    $org->save();

    return $org->fresh();
}

function aiStore(Organization $org): Store
{
    return Store::factory()->create([
        'organization_id' => $org->id,
        'whatsapp_phone_number_id' => '555000222',
        'whatsapp_access_token' => 'token-test',
        'booking_hours' => array_fill_keys(range(1, 7), ['open' => '00:00', 'close' => '23:59']),
    ]);
}

function aiAdmin(Organization $org): User
{
    return User::factory()->create(['organization_id' => $org->id, 'role_id' => defaultRole($org, 'Super administrateur')->id]);
}

function aiAgent(Organization $org, array $attributes = []): AiAgent
{
    return AiAgent::create([
        'organization_id' => $org->id, 'name' => 'Awa', 'role' => 'Conseillère', 'tone' => 'chaleureux',
        'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'api_key' => 'sk-ant-test-123456',
        'use_business_info' => true, 'knowledge' => 'Paiement : Airtel Money.', 'max_replies_per_day' => 20,
        'schedule' => 'always', 'active' => true, ...$attributes,
    ]);
}

function aiIncoming(Store $store, string $id, string $body, string $from = '24177445566'): void
{
    $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp',
        'metadata' => ['phone_number_id' => $store->whatsapp_phone_number_id],
        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Marie Obiang']]],
        'messages' => [['timestamp' => (string) time(), 'id' => $id, 'from' => $from, 'type' => 'text', 'text' => ['body' => $body]]],
    ]]]]]];

    (new ProcessWhatsAppWebhookJob($store->id, $payload))->handle();
}

function fakeClaude(string $text): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT.'.uniqid()]]]),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn']),
    ]);
}

it('enables the AI agents module from the registration checkbox', function () {
    $this->post('/register', [
        'organization_name' => 'Institut Awa', 'sector' => 'beaute', 'store_name' => 'Centre-ville', 'name' => 'Gérante',
        'email' => 'awa@example.com', 'password' => 'Secret-123456', 'password_confirmation' => 'Secret-123456', 'ai_agents' => true,
    ])->assertRedirect('/dashboard');

    $org = Organization::where('name', 'Institut Awa')->firstOrFail();
    expect($org->hasModule('agents_ia'))->toBeTrue()->and($org->hasModule('rdv'))->toBeTrue();
});

it('lists agents, creates one with an encrypted key and hides the key', function () {
    $org = aiOrg();
    $admin = aiAdmin($org);

    $this->actingAs($admin)->get('/ai-agents')->assertInertia(fn (Assert $page) => $page->component('AiAgents/Index')->has('agents', 0));
    $this->actingAs($admin)->get('/ai-agents/create')->assertInertia(fn (Assert $page) => $page->component('AiAgents/Form')->has('providers', 3));

    $this->actingAs($admin)->post('/ai-agents', [
        'name' => 'Awa', 'role' => 'Conseillère', 'tone' => 'professionnel', 'provider' => 'openai', 'model' => 'gpt-4o-mini',
        'api_key' => 'sk-secret-abcdef', 'use_business_info' => true, 'knowledge' => 'Ouvert le dimanche matin.',
        'max_replies_per_day' => 10, 'store_ids' => [], 'schedule' => 'outside_hours', 'active' => true,
    ])->assertRedirect('/ai-agents');

    $agent = AiAgent::firstOrFail();
    expect($agent->api_key)->toBe('sk-secret-abcdef')
        ->and(DB::table('ai_agents')->value('api_key'))->not->toContain('sk-secret')
        ->and($agent->store_ids)->toBeNull();

    // La clé n'est jamais renvoyée ; vide à la modification = ancienne clé gardée.
    $this->actingAs($admin)->get("/ai-agents/{$agent->id}/edit")->assertInertia(fn (Assert $page) => $page->where('agent.masked_key', 'sk-s…cdef')->missing('agent.api_key'));
    $this->actingAs($admin)->put("/ai-agents/{$agent->id}", [
        'name' => 'Awa', 'tone' => 'chaleureux', 'provider' => 'openai', 'model' => 'gpt-4o', 'api_key' => '',
        'max_replies_per_day' => 10, 'schedule' => 'always', 'active' => false,
    ])->assertRedirect('/ai-agents');
    expect($agent->fresh()->api_key)->toBe('sk-secret-abcdef')->and($agent->fresh()->active)->toBeFalse();
});

it('protects the screens when the module is off and between organizations', function () {
    $org = aiOrg([]);
    $org->setModules(array_diff($org->enabledModules(), ['agents_ia']));
    $org->save();
    $this->actingAs(aiAdmin($org))->get('/ai-agents')->assertRedirect('/dashboard');

    $other = aiOrg();
    $agent = aiAgent($other);
    $intruder = aiAdmin(aiOrg());
    $this->actingAs($intruder)->post("/ai-agents/{$agent->id}/toggle")->assertForbidden();
});

it('tests an agent without sending anything', function () {
    $org = aiOrg();
    aiStore($org);
    fakeClaude('Bonjour ! Nous sommes ouverts tous les jours.');

    $this->actingAs(aiAdmin($org))->postJson('/ai-agents/test', [
        'name' => 'Awa', 'tone' => 'chaleureux', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'api_key' => 'sk-ant-xyz',
        'max_replies_per_day' => 20, 'schedule' => 'always', 'message' => 'Vous êtes ouverts ?',
    ])->assertOk()->assertJson(['reply' => 'Bonjour ! Nous sommes ouverts tous les jours.']);

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.anthropic.com/v1/messages')
        && $r->header('x-api-key')[0] === 'sk-ant-xyz'
        && $r['model'] === 'claude-opus-5-5'
        && str_contains($r['system'], 'Tu es Awa'));
    expect(WhatsAppOutboundMessage::count())->toBe(0);
});

it('answers an incoming WhatsApp message with the business information', function () {
    $org = aiOrg();
    $store = aiStore($org);
    CatalogItem::create(['organization_id' => $org->id, 'type' => 'service', 'name' => 'Tresses', 'price' => 15000, 'duration_minutes' => 120, 'active' => true]);
    $agent = aiAgent($org);
    fakeClaude('Les tresses sont à 15 000 FCFA, comptez 2 heures.');

    aiIncoming($store, 'wa1', 'Combien pour des tresses ?');

    $reply = WhatsAppOutboundMessage::where('origin', 'ai')->sole();
    expect($reply->body)->toBe('Les tresses sont à 15 000 FCFA, comptez 2 heures.')
        ->and($agent->fresh()->replies_count)->toBe(1);

    // Le robot à règles ne répond pas en plus (pas de menu d'accueil).
    expect(WhatsAppOutboundMessage::count())->toBe(1);

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.anthropic.com')
        && str_contains($r['system'], 'Tresses : 15 000')
        && str_contains($r['system'], 'Airtel Money')
        && end($r->data()['messages'])['content'] === 'Combien pour des tresses ?');
});

it('hands the conversation over to the team when the agent asks for a human', function () {
    $org = aiOrg();
    $store = aiStore($org);
    aiAgent($org, ['handoff_message' => 'Une conseillère vous répond tout de suite.']);
    fakeClaude('[HUMAIN]');

    aiIncoming($store, 'wa1', 'Je veux parler au gérant, je ne suis pas contente');

    $contact = Contact::where('phone_e164', '24177445566')->firstOrFail();
    expect($contact->needs_attention)->toBeTrue()
        ->and($contact->bot_paused_until->isFuture())->toBeTrue()
        ->and(Task::where('contact_id', $contact->id)->where('kind', 'talk_to_human')->count())->toBe(1)
        ->and(WhatsAppOutboundMessage::latest('id')->value('body'))->toBe('Une conseillère vous répond tout de suite.');

    // Agent coupé 24 h pour ce client.
    $before = WhatsAppOutboundMessage::count();
    aiIncoming($store, 'wa2', 'Allô ?');
    expect(WhatsAppOutboundMessage::count())->toBe($before)
        ->and(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.anthropic.com')))->toHaveCount(1);
});

it('falls back to the rules bot when no agent matches the schedule, and records provider errors', function () {
    $org = aiOrg();
    $store = aiStore($org);
    $agent = aiAgent($org, ['schedule' => 'outside_hours']);
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]]),
        'api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401),
    ]);

    // Ouvert 24 h/24 : l'agent « hors horaires » ne répond pas, le robot à règles accueille.
    aiIncoming($store, 'wa1', 'Bonjour');
    expect(WhatsAppOutboundMessage::where('origin', 'ai')->count())->toBe(0)
        ->and(WhatsAppOutboundMessage::where('origin', 'auto')->count())->toBe(1);

    $agent->update(['schedule' => 'always']);
    aiIncoming($store, 'wa2', 'Vous faites les ongles ?');

    expect($agent->fresh()->last_error)->toContain('Clé API refusée')
        ->and(Contact::where('phone_e164', '24177445566')->value('needs_attention'))->toBeTruthy();
});

it('calls OpenAI and Gemini with their own formats', function () {
    $org = aiOrg();
    $store = aiStore($org);
    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Réponse OpenAI']]]]),
        'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Réponse Gemini']]]]]]),
    ]);

    expect(AiAgents::test(aiAgent($org, ['provider' => 'openai', 'model' => 'gpt-4o-mini']), $store, 'Bonjour'))->toBe('Réponse OpenAI')
        ->and(AiAgents::test(aiAgent($org, ['provider' => 'gemini', 'model' => 'gemini-2.5-flash']), $store, 'Bonjour'))->toBe('Réponse Gemini');

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.openai.com') && $r['messages'][0]['role'] === 'system' && $r->hasHeader('Authorization', 'Bearer sk-ant-test-123456'));
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'gemini-2.5-flash:generateContent') && isset($r['systemInstruction']) && $r['contents'][0]['role'] === 'user');
});
