<?php

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\AiUsage;
use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeQuestion;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsAppOutboundMessage;
use App\Services\Assistant;
use App\Services\KnowledgeBase;
use App\Support\SectorPresets;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Assistant IA ClientlyX : base de connaissances, réponses sans IA, prise de
 * RDV à boutons, fiche client (besoin + suite), questions à apprendre et
 * recours facultatif à Haiku.
 */

function asOrg(array $settings = []): Organization
{
    $org = Organization::factory()->create(['sector' => 'pro', 'whatsapp_api_enabled' => true, 'timezone' => 'Africa/Libreville']);
    SectorPresets::apply($org);
    $org->setModules([...$org->enabledModules(), 'assistant', 'rdv', 'catalogue']);
    $org->assistant_settings = $settings ?: null;
    $org->booking_settings = ['enabled' => true, 'auto_confirm' => true, 'min_notice_minutes' => 0];
    $org->save();

    return $org->fresh();
}

function asStore(Organization $org): Store
{
    return Store::factory()->create([
        'organization_id' => $org->id,
        'whatsapp_phone_number_id' => '555000333',
        'whatsapp_access_token' => 'token-test',
        'booking_hours' => array_fill_keys(range(1, 7), ['open' => '08:00', 'close' => '18:00']),
    ]);
}

function asAdmin(Organization $org): User
{
    return User::factory()->create(['organization_id' => $org->id, 'role_id' => defaultRole($org, 'Super administrateur')->id]);
}

function asMessage(Store $store, array $message, string $from = '24166112233'): void
{
    $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp',
        'metadata' => ['phone_number_id' => $store->whatsapp_phone_number_id],
        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Paul Mba']]],
        'messages' => [['timestamp' => (string) time(), 'id' => 'wa'.uniqid(), 'from' => $from, ...$message]],
    ]]]]]];

    (new ProcessWhatsAppWebhookJob($store->id, $payload))->handle();
}

function asText(Store $store, string $body): void
{
    asMessage($store, ['type' => 'text', 'text' => ['body' => $body]]);
}

function asButton(Store $store, string $id): void
{
    asMessage($store, ['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $id, 'title' => 'x']]]);
}

function lastOut(): WhatsAppOutboundMessage
{
    return WhatsAppOutboundMessage::latest('id')->first();
}

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT.'.uniqid()]]])]);
    config(['clientlyx_ai.enabled' => false]);
});

it('answers a free question from the knowledge base without any AI', function () {
    $org = asOrg();
    $store = asStore($org);
    KnowledgeEntry::create(['organization_id' => $org->id, 'section' => 'regles', 'title' => 'Moyens de paiement', 'content' => 'Nous acceptons espèces, Airtel Money et Moov Money.']);
    KnowledgeEntry::create(['organization_id' => $org->id, 'section' => 'faq', 'title' => 'Faites-vous les photos de mariage ?', 'content' => 'Oui, nous couvrons les mariages dans tout Libreville.']);

    asText($store, 'Est-ce que je peux payer par Airtel Money chez vous ?');

    $answer = WhatsAppOutboundMessage::where('origin', 'assistant')->where('type', 'text')->sole();
    expect($answer->body)->toBe('Nous acceptons espèces, Airtel Money et Moov Money.');
    // Puis la suite est proposée une fois (RDV, devis, rappel).
    expect(lastOut()->type)->toBe('interactive')->and(json_encode(lastOut()->payload))->toContain('as:need:devis');
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'anthropic'));
});

it('sends unknown questions to the team and learns the validated human answer', function () {
    $org = asOrg();
    $store = asStore($org);
    $admin = asAdmin($org);

    asText($store, 'Proposez-vous des séances photo sous-marines dans la piscine ?');

    $contact = Contact::where('phone_e164', '24166112233')->firstOrFail();
    $question = KnowledgeQuestion::sole();
    expect($question->status)->toBe('pending')
        ->and(Task::where('contact_id', $contact->id)->where('kind', 'learn_question')->whereNull('done_at')->count())->toBe(1)
        ->and(lastOut()->body)->toContain('Je transmets à l’équipe');

    // Le gérant répond au client dans Discussions : la réponse attend sa validation.
    $this->actingAs($admin)->post("/discussions/{$contact->id}/reply", ['body' => 'Oui, en partenariat avec la piscine du Lycée, 60 000 FCFA la séance.'])->assertRedirect();
    expect($question->fresh()->status)->toBe('answered')->and($question->fresh()->answer)->toContain('60 000 FCFA');

    $this->actingAs($admin)->get('/assistant')->assertInertia(fn (Assert $page) => $page->component('Assistant/Index')->has('questions', 1));
    $this->actingAs($admin)->post("/assistant/questions/{$question->id}/learn", [
        'title' => 'Faites-vous des séances photo sous-marines ?', 'content' => 'Oui, en piscine, 60 000 FCFA la séance.',
    ])->assertRedirect();

    expect(KnowledgeEntry::where('source', 'human')->count())->toBe(1)
        ->and($question->fresh()->status)->toBe('learned')
        ->and(Task::where('kind', 'learn_question')->whereNull('done_at')->count())->toBe(0);

    // La prochaine fois, l'assistant sait répondre.
    asText($store, 'Vous faites des photos sous-marines ?');
    expect(WhatsAppOutboundMessage::where('origin', 'assistant')->where('body', 'Oui, en piscine, 60 000 FCFA la séance.')->exists())->toBeTrue();
});

it('books an appointment through WhatsApp buttons and records the need', function () {
    $org = asOrg();
    $store = asStore($org);
    $service = CatalogItem::create(['organization_id' => $org->id, 'type' => 'service', 'name' => 'Shooting famille', 'price' => 45000, 'duration_minutes' => 60, 'active' => true, 'bookable_online' => true]);

    asText($store, 'Je veux un rendez-vous');
    expect(json_encode(lastOut()->payload))->toContain('bk:s:'.$service->id);

    asButton($store, 'bk:s:'.$service->id);
    $day = now()->addDays(2)->format('Ymd');
    expect(json_encode(lastOut()->payload))->toContain("bk:d:{$service->id}:{$day}");

    asButton($store, "bk:d:{$service->id}:{$day}");
    expect(json_encode(lastOut()->payload))->toContain("bk:t:{$service->id}:{$day}1000");

    asButton($store, "bk:t:{$service->id}:{$day}1000");
    expect(json_encode(lastOut()->payload))->toContain("bk:ok:{$service->id}:{$day}1000");

    asMessage($store, ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "bk:ok:{$service->id}:{$day}1000", 'title' => 'Je confirme']]]);

    $appointment = Appointment::sole();
    expect($appointment->service)->toBe('Shooting famille')
        ->and($appointment->status)->toBe('confirmed')
        ->and($appointment->starts_at->format('YmdHi'))->toBe($day.'1000')
        ->and($appointment->items()->count())->toBe(1)
        ->and(lastOut()->body)->toContain('C’est réservé')
        ->and($appointment->customer->need_kind)->toBe('rdv');

    // Le même créneau n'est plus proposé.
    asButton($store, "bk:d:{$service->id}:{$day}");
    expect(json_encode(lastOut()->payload))->not->toContain("bk:t:{$service->id}:{$day}1000");
});

it('records the need and the follow-up (quote) on the customer card', function () {
    $org = asOrg();
    $store = asStore($org);

    asText($store, 'un devis svp');
    expect(lastOut()->body)->toContain('Décrivez votre besoin');

    asText($store, 'Photos de 40 produits pour mon site e-commerce, livraison avant le 15 décembre');

    $customer = Contact::where('phone_e164', '24166112233')->firstOrFail()->customer;
    expect($customer->need)->toContain('40 produits')
        ->and($customer->need_kind)->toBe('devis')
        ->and(Task::where('customer_id', $customer->id)->where('kind', 'online_request')->value('title'))->toBe('Demande de devis')
        ->and($customer->prospect_stage)->not->toBeNull()
        ->and(lastOut()->body)->toContain('devis');
});

it('uses Haiku only when switched on, within the monthly quota, and learns when it does not know', function () {
    config(['clientlyx_ai.enabled' => true, 'clientlyx_ai.anthropic_key' => 'sk-ant-clientlyx']);
    $org = asOrg(['use_ai' => true]);
    $store = asStore($org);
    KnowledgeEntry::create(['organization_id' => $org->id, 'section' => 'presentation', 'title' => 'Adresse', 'content' => 'Quartier Louis, face à la pharmacie.']);
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => 'Nous sommes au quartier Louis, face à la pharmacie.']], 'stop_reason' => 'end_turn'])
            ->push(['content' => [['type' => 'text', 'text' => '[INCONNU]']], 'stop_reason' => 'end_turn']),
    ]);

    asText($store, 'Comment je fais pour venir chez vous depuis Akanda ?');
    expect(WhatsAppOutboundMessage::where('origin', 'assistant')->where('type', 'text')->value('body'))->toBe('Nous sommes au quartier Louis, face à la pharmacie.');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.anthropic.com') && $r['model'] === 'claude-haiku-5-5'
        && $r->header('x-api-key')[0] === 'sk-ant-clientlyx' && str_contains($r['system'], 'Quartier Louis'));

    asText($store, 'Avez-vous une salle pour les anniversaires de 50 personnes ?');
    expect(KnowledgeQuestion::count())->toBe(1)->and(AiUsage::questionsThisMonth($org))->toBe(2);

    // Plafond atteint : plus d'appel à l'IA.
    config(['clientlyx_ai.monthly_questions' => array_fill_keys(['gratuit', 'solo', 'equipe', 'premium', 'entreprise'], 2)]);
    asText($store, 'Et pour 80 personnes, c’est possible ?');
    expect(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'anthropic')))->toHaveCount(2);
});

it('imports the structured document from the Excel template', function () {
    $org = asOrg();
    asStore($org);
    $admin = asAdmin($org);

    $response = $this->actingAs($admin)->get('/assistant/modele');
    $response->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'kb').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    $this->actingAs($admin)->post('/assistant/import', ['file' => new UploadedFile($path, 'document.xlsx', null, null, true), 'replace' => true])->assertRedirect();

    expect(KnowledgeEntry::where('organization_id', $org->id)->count())->toBe(8)
        ->and(KnowledgeEntry::where('section', 'interdit')->count())->toBe(1)
        ->and(KnowledgeBase::sectionOf('À ne pas dire'))->toBe('interdit');

    // Les consignes « à ne pas dire » ne servent jamais de réponse.
    $this->actingAs($admin)->postJson('/assistant/test', ['message' => 'Vous faites des remises ?'])->assertOk()->assertJson(['via' => 'inconnu']);
    $this->actingAs($admin)->postJson('/assistant/test', ['message' => 'Quels moyens de paiement acceptez-vous ?'])->assertOk()->assertJson(['via' => 'base']);
});

it('can be switched off, and is protected when the module is off', function () {
    $org = asOrg();
    $store = asStore($org);
    $admin = asAdmin($org);

    $this->actingAs($admin)->put('/assistant/settings', ['enabled' => false, 'use_ai' => false, 'voice' => true, 'unknown_reply' => 'Je transmets.', 'outcomes' => ['devis']])->assertRedirect();
    asText($store, 'Proposez-vous des séances photo sous-marines ?');
    expect(KnowledgeQuestion::count())->toBe(0)->and(WhatsAppOutboundMessage::where('origin', 'assistant')->count())->toBe(0);

    $org->setModules(array_diff($org->enabledModules(), ['assistant']));
    $org->save();
    $this->actingAs($admin->fresh())->get('/assistant')->assertRedirect('/dashboard');
});

it('imports a JSON knowledge base, keeps drafts inactive and internal rules as instructions', function () {
    $org = asOrg();
    $store = asStore($org);
    $file = tempnam(sys_get_temp_dir(), 'kb');
    file_put_contents($file, json_encode(['fiches' => [
        ['id' => 'p1', 'partie' => '1. Présentation', 'titre' => 'Qui êtes-vous', 'questions' => ['Vous faites quoi ?'], 'reponse' => 'Un studio photo.', 'statut' => 'disponible', 'mots_cles' => ['studio']],
        ['id' => 'p2', 'partie' => '1. Présentation', 'titre' => 'Adresse', 'questions' => ['Vous êtes où ?'], 'reponse' => '[À COMPLÉTER : adresse]', 'statut' => 'à compléter', 'a_completer' => true],
        ['id' => 'r1', 'partie' => '7. Limites', 'titre' => 'Ne jamais inventer', 'questions' => [], 'reponse' => 'Ne répondre qu’à partir des fiches.', 'statut' => 'règle', 'interne' => true],
    ]]));

    $this->artisan('assistant:import', ['organization' => $org->id, 'file' => $file])->assertSuccessful();
    $this->artisan('assistant:import', ['organization' => $org->id, 'file' => $file])->assertSuccessful();

    expect(KnowledgeEntry::where('organization_id', $org->id)->count())->toBe(3)
        ->and(KnowledgeEntry::where('external_id', 'p2')->value('active'))->toBeFalse()
        ->and(KnowledgeEntry::where('external_id', 'r1')->value('section'))->toBe('consignes')
        ->and((new KnowledgeBase($org))->instructions())->toHaveCount(1);

    expect(Assistant::decide($org, $store, 'Vous faites quoi exactement ?')['text'])->toBe('Un studio photo.')
        ->and(Assistant::decide($org, $store, 'Vous êtes où ?')['via'])->toBe('inconnu')
        ->and(Assistant::decide($org, $store, 'Ne jamais inventer')['via'])->toBe('inconnu');
});

it('reads the configuration and completes it with the guided questionnaire', function () {
    $org = asOrg();
    $store = asStore($org);
    $store->update(['booking_phone' => '24177000111', 'city' => 'Libreville']);
    CatalogItem::create(['organization_id' => $org->id, 'type' => 'service', 'name' => 'Shooting famille', 'price' => 45000, 'duration_minutes' => 60, 'active' => true, 'bookable_online' => true]);
    $admin = asAdmin($org);

    // Lu dans la configuration, sans rien saisir.
    expect(Assistant::decide($org, $store, 'Combien coûte le shooting famille ?')['text'])->toContain('45 000')
        ->and(Assistant::decide($org, $store, 'Quel est votre numéro de téléphone ?')['text'])->toContain('+24177000111')
        ->and(Assistant::decide($org, $store, 'Vous êtes ouverts le dimanche ?')['text'])->toContain('ouvert');

    $before = Assistant::readiness($org)['percent'];
    $this->actingAs($admin)->get('/assistant?tab=start')->assertInertia(fn (Assert $page) => $page->where('initialTab', 'start')
        ->has('questionnaire.0', fn (Assert $q) => $q->where('key', 'adresse')->etc())->where('readiness.percent', $before));

    $this->actingAs($admin)->put('/assistant/questionnaire/paiements', ['choices' => ['Espèces', 'Airtel Money', 'Inventé'], 'text' => ''])->assertRedirect();
    $this->actingAs($admin)->put('/assistant/questionnaire/adresse', ['choices' => [], 'text' => 'Quartier Louis, face à la pharmacie'])->assertRedirect();

    expect(KnowledgeEntry::where('external_id', 'q:paiements')->value('content'))->toBe('Moyens de paiement acceptés : Espèces, Airtel Money.')
        ->and(Assistant::decide($org->fresh(), $store, 'Je peux payer par Airtel Money ?')['text'])->toContain('Airtel Money')
        ->and(Assistant::decide($org->fresh(), $store, 'Vous êtes situés où ?')['text'])->toContain('Quartier Louis')
        ->and(Assistant::readiness($org->fresh())['percent'])->toBeGreaterThan($before);

    // Les réglages n'effacent pas les réponses ; une réponse vidée retire sa fiche.
    $this->actingAs($admin)->put('/assistant/settings', ['enabled' => true, 'use_ai' => false, 'voice' => true, 'unknown_reply' => 'Je transmets.', 'outcomes' => ['rdv']]);
    expect($org->fresh()->assistant_settings['questionnaire'])->toHaveKey('paiements');
    $this->actingAs($admin)->put('/assistant/questionnaire/adresse', ['choices' => [], 'text' => '']);
    expect(KnowledgeEntry::where('external_id', 'q:adresse')->exists())->toBeFalse();
});
