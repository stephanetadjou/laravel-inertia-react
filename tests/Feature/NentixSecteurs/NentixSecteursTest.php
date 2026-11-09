<?php

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\AdBudget;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\CreditMovement;
use App\Models\Customer;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsAppOutboundMessage;
use App\Services\Acquisition;
use App\Services\ActionLists;
use App\Services\AutoReplies;
use App\Support\SectorPresets;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * NENTIX par secteur : préréglages, fiche auto + source de la pub, réponses
 * automatiques et boutons, rappel de la veille, discussions, sources, crédits.
 */

function sectorOrg(string $sector, array $attributes = []): Organization
{
    $org = Organization::factory()->create(['sector' => $sector, 'whatsapp_api_enabled' => true, 'timezone' => 'Africa/Libreville', ...$attributes]);
    SectorPresets::apply($org);

    return $org->fresh();
}

function sectorStore(Organization $org): Store
{
    // Ouvert tous les jours, toute la journée : le robot accueille (pas de message d'absence).
    return Store::factory()->create([
        'organization_id' => $org->id,
        'whatsapp_phone_number_id' => '555000111',
        'whatsapp_access_token' => 'token-test',
        'booking_hours' => array_fill_keys(range(1, 7), ['open' => '00:00', 'close' => '23:59']),
    ]);
}

function sectorAdmin(Organization $org): User
{
    return User::factory()->create(['organization_id' => $org->id, 'role_id' => defaultRole($org, 'Super administrateur')->id]);
}

function incoming(Store $store, array $message, ?string $profile = 'Awa Ndong'): void
{
    $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp',
        'metadata' => ['phone_number_id' => $store->whatsapp_phone_number_id],
        'contacts' => [['wa_id' => $message['from'], 'profile' => ['name' => $profile]]],
        'messages' => [['timestamp' => (string) time(), ...$message]],
    ]]]]]];

    (new ProcessWhatsAppWebhookJob($store->id, $payload))->handle();
}

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT.'.uniqid()]]])]);
});

it('applies the sector preset without duplicates when replayed', function () {
    $org = sectorOrg('beaute');

    expect($org->enabledModules())->toContain('rdv', 'prospects', 'reponses_auto', 'rappels_rdv', 'relance_cycle')
        ->and($org->hasModule('fidelite'))->toBeFalse()
        ->and(collect($org->moduleSettings('prospects')['stages'])->pluck('label')->all())->toBe(['Nouveau contact', 'RDV réservé', 'RDV confirmé', 'Venue', 'À relancer'])
        ->and($org->autoReplySettings()['enabled'])->toBeTrue();

    // L'entreprise coupe un module et renomme une étape : rejouer ne les écrase pas.
    $org->setModules([...array_diff($org->enabledModules(), ['relance_cycle'])], ['prospects' => ['stages' => [['key' => 'nouveau', 'label' => 'Premier message', 'kind' => 'a_contacter', 'delay_days' => 1]]]]);
    $org->save();
    SectorPresets::apply($org->fresh());
    SectorPresets::apply($org->fresh());

    $org->refresh();
    expect($org->hasModule('relance_cycle'))->toBeFalse()
        ->and($org->moduleSettings('prospects')['stages'][0]['label'])->toBe('Premier message')
        ->and($org->roles()->where('name', 'Commercial')->count())->toBe(1);
});

it('registers with only the sector and prepares the four sectors', function (string $sector, array $modules, string $firstStage) {
    $this->post('/register', [
        'organization_name' => "Entreprise {$sector}", 'sector' => $sector, 'store_name' => 'Principal', 'name' => 'Gérant',
        'email' => "{$sector}@example.com", 'password' => 'Secret-123456', 'password_confirmation' => 'Secret-123456',
    ])->assertRedirect('/dashboard');

    $org = Organization::where('name', "Entreprise {$sector}")->firstOrFail();
    expect($org->enabledModules())->toContain(...$modules)
        ->and($org->message_credits)->toBe(0)
        ->and($org->sector_preset_applied_at)->not->toBeNull();

    if ($firstStage !== '') {
        expect($org->moduleSettings('prospects')['stages'][0]['label'])->toBe($firstStage);
    } else {
        expect($org->hasAgenda())->toBeFalse()->and($org->sector)->toBe('commerce');
    }
})->with([
    'beauté' => ['beaute', ['rdv', 'rappels_rdv'], 'Nouveau contact'],
    'sport' => ['sport', ['abonnements', 'essai', 'seances_groupe'], 'Prospect'],
    'pro' => ['pro', ['prospects', 'relance_devis'], 'À contacter'],
    'boutiques' => ['boutique', ['familles', 'fidelite'], ''],
]);

it('creates the customer and prospect from the first WhatsApp message with the ad referral', function () {
    $org = sectorOrg('beaute');
    $store = sectorStore($org);

    incoming($store, ['id' => 'wamid.IN1', 'from' => '24177001122', 'type' => 'text', 'text' => ['body' => 'Bonjour, vous faites les tresses ?'],
        'referral' => ['source_url' => 'https://www.instagram.com/p/xyz', 'source_id' => '1203', 'source_type' => 'ad', 'headline' => 'Tresses -20 %', 'ctwa_clid' => 'ARAkLk']]);

    $customer = Customer::where('phone_e164', '24177001122')->firstOrFail();
    expect($customer->name)->toBe('Awa Ndong')
        ->and($customer->acquisition_source)->toBe('instagram_ad')
        ->and($customer->sourceLabel())->toBe('Pub Instagram')
        ->and($customer->acquisition_data['ctwa_clid'])->toBe('ARAkLk')
        ->and($customer->prospect_stage)->toBe('nouveau');

    // Accueil + menu (4 options : liste) envoyés par le robot, une seule fois par 24 h.
    $out = WhatsAppOutboundMessage::where('origin', 'auto')->get();
    expect($out)->toHaveCount(1)->and($out[0]->payload['interactive']['type'])->toBe('list');
    incoming($store, ['id' => 'wamid.IN2', 'from' => '24177001122', 'type' => 'text', 'text' => ['body' => 'Vous êtes là ?']]);
    expect(WhatsAppOutboundMessage::where('origin', 'auto')->count())->toBe(1);

    // Deuxième message sans pub d'un autre numéro : « Direct ».
    incoming($store, ['id' => 'wamid.IN3', 'from' => '24177009988', 'type' => 'text', 'text' => ['body' => 'Bonsoir']], null);
    expect(Customer::where('phone_e164', '24177009988')->value('acquisition_source'))->toBe('direct');
});

it('detects a Facebook ad and keeps the first source', function () {
    expect(Acquisition::sourceOf(['source_url' => 'https://fb.me/abc', 'source_type' => 'ad']))->toBe('facebook_ad')
        ->and(Acquisition::sourceOf(null))->toBe('direct');
});

it('answers the menu buttons and hands over to a human for 24 hours', function () {
    $org = sectorOrg('beaute');
    $store = sectorStore($org);
    CatalogItem::create(['organization_id' => $org->id, 'type' => 'service', 'name' => 'Coupe femme', 'price' => 8000, 'duration_minutes' => 45, 'active' => true]);

    incoming($store, ['id' => 'w1', 'from' => '24177112233', 'type' => 'text', 'text' => ['body' => 'Bonjour']]);
    incoming($store, ['id' => 'w2', 'from' => '24177112233', 'type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'menu:prix', 'title' => 'Nos prix']]]);
    expect(WhatsAppOutboundMessage::latest('id')->value('body'))->toContain('Coupe femme : 8 000 FCFA');

    incoming($store, ['id' => 'w3', 'from' => '24177112233', 'type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'menu:humain', 'title' => 'Parler à quelqu’un']]]);
    $contact = Contact::where('phone_e164', '24177112233')->first();
    expect($contact->needs_attention)->toBeTrue()
        ->and($contact->bot_paused_until->isFuture())->toBeTrue()
        ->and(Task::where('contact_id', $contact->id)->where('kind', 'talk_to_human')->whereNull('done_at')->count())->toBe(1);

    // Robot coupé : plus aucune réponse automatique, même à un mot-clé.
    $before = WhatsAppOutboundMessage::count();
    incoming($store, ['id' => 'w4', 'from' => '24177112233', 'type' => 'text', 'text' => ['body' => 'prix ?']]);
    expect(WhatsAppOutboundMessage::count())->toBe($before);

    // La tâche est dans « À faire » › Demandes à traiter.
    $rows = (new ActionLists(sectorAdmin($org)))->rows('tasks');
    expect($rows)->toHaveCount(1)->and($rows[0]['reason'])->toContain('Parler à quelqu’un');
});

it('sends the away message outside opening hours at most once a day', function () {
    $org = sectorOrg('pro');
    $store = sectorStore($org);
    $store->update(['booking_hours' => array_fill_keys(range(1, 7), null)]);

    incoming($store, ['id' => 'a1', 'from' => '24177223344', 'type' => 'text', 'text' => ['body' => 'Bonjour']]);
    incoming($store, ['id' => 'a2', 'from' => '24177223344', 'type' => 'text', 'text' => ['body' => 'Allô ?']]);

    $out = WhatsAppOutboundMessage::all();
    expect($out)->toHaveCount(1)->and($out[0]->body)->toContain('fermé');
});

it('works with the simulated WhatsApp driver', function () {
    config(['services.meta_whatsapp.driver' => 'log']);
    $org = sectorOrg('sport');
    $store = sectorStore($org);

    incoming($store, ['id' => 's1', 'from' => '24177334455', 'type' => 'text', 'text' => ['body' => 'Bonjour']]);

    expect(WhatsAppOutboundMessage::first()->status)->toBe('sent');
    Http::assertNothingSent();
});

it('confirms or reschedules the appointment from the reminder buttons', function () {
    $org = sectorOrg('beaute');
    $store = sectorStore($org);
    $contact = Contact::create(['store_id' => $store->id, 'phone_e164' => '24177445566', 'name' => 'Lia Obame', 'consent_status' => 'opt_in']);
    $contact->forceFill(['last_inbound_at' => now()->subHour()])->save();
    $tomorrow = now('Africa/Libreville')->addDay()->setTime(10, 30);
    $a = Appointment::create(['store_id' => $store->id, 'customer_id' => $contact->customer_id, 'starts_at' => $tomorrow, 'duration_minutes' => 60, 'status' => 'planned', 'service' => 'Tresses']);
    $b = Appointment::create(['store_id' => $store->id, 'customer_id' => $contact->customer_id, 'starts_at' => $tomorrow->copy()->addHours(3), 'duration_minutes' => 30, 'status' => 'planned']);

    expect($contact->customer->fresh()->prospect_stage)->toBe('rdv_reserve');

    Artisan::call('crm:appointment-reminders');
    expect($a->fresh()->reminder_sent_at)->not->toBeNull();
    Http::assertSent(fn (HttpRequest $r) => ($r['type'] ?? null) === 'interactive' && $r['interactive']['action']['buttons'][0]['reply']['id'] === "rdv_ok:{$a->id}");

    incoming($store, ['id' => 'r1', 'from' => '24177445566', 'type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "rdv_ok:{$a->id}", 'title' => 'Je confirme']]]);
    expect($a->fresh()->status)->toBe('confirmed')
        ->and($a->fresh()->client_response)->toBe('confirmed')
        ->and($contact->customer->fresh()->prospect_stage)->toBe('rdv_confirme');

    // Réponse à un bouton de modèle (quick reply) : « Je décale ».
    incoming($store, ['id' => 'r2', 'from' => '24177445566', 'type' => 'button', 'button' => ['payload' => "rdv_move:{$b->id}", 'text' => 'Je décale']]);
    expect($b->fresh()->client_response)->toBe('reschedule')
        ->and(Task::where('appointment_id', $b->id)->where('kind', 'reschedule')->exists())->toBeTrue();
});

it('lists overdue service cycles and quotes to follow up', function () {
    $beauty = sectorOrg('beaute');
    $store = sectorStore($beauty);
    $coupe = CatalogItem::create(['organization_id' => $beauty->id, 'type' => 'service', 'name' => 'Coupe', 'price' => 5000, 'duration_minutes' => 30, 'active' => true, 'return_after_days' => 30]);
    $contact = Contact::create(['store_id' => $store->id, 'phone_e164' => '24177556677', 'name' => 'Inès Mba', 'consent_status' => 'opt_in']);
    $appt = Appointment::create(['store_id' => $store->id, 'customer_id' => $contact->customer_id, 'starts_at' => now()->subDays(35), 'duration_minutes' => 30, 'status' => 'done']);
    $appt->items()->create(['catalog_item_id' => $coupe->id, 'name' => 'Coupe', 'price' => 5000, 'duration_minutes' => 30]);

    $rows = (new ActionLists(sectorAdmin($beauty)))->rows('service_cycle');
    expect($rows)->toHaveCount(1)->and($rows[0]['message'])->toContain('35 jours depuis votre Coupe');

    $pro = sectorOrg('pro');
    $proStore = sectorStore($pro);
    $lead = Contact::create(['store_id' => $proStore->id, 'phone_e164' => '24177667788', 'name' => 'Paul Nze', 'consent_status' => 'unknown'])->customer;
    $lead->forceFill(['prospect_stage' => 'devis_envoye', 'prospect_stage_at' => now()->subDays(4)])->save();

    $rows = (new ActionLists(sectorAdmin($pro)))->rows('quote_followup');
    expect($rows)->toHaveCount(1)->and($rows[0]['message'])->toContain('notre devis');
});

it('shows the inbox, replies within 24 hours and filters what to handle', function () {
    $org = sectorOrg('pro');
    $store = sectorStore($org);
    $admin = sectorAdmin($org);
    incoming($store, ['id' => 'd1', 'from' => '24177778899', 'type' => 'text', 'text' => ['body' => 'je veux parler à un conseiller']]);
    $contact = Contact::where('phone_e164', '24177778899')->first();

    $this->actingAs($admin)->get('/discussions?filter=todo')->assertInertia(fn (Assert $page) => $page
        ->component('Discussions/Index')->has('conversations', 1)->where('counts.todo', 1));

    $this->actingAs($admin)->get("/discussions/{$contact->id}")->assertInertia(fn (Assert $page) => $page
        ->where('selected.window_open', true)->where('selected.source', 'Direct'));

    $this->actingAs($admin)->post("/discussions/{$contact->id}/reply", ['body' => 'Bonjour, je vous appelle dans 5 minutes.'])->assertSessionHas('success');
    expect(WhatsAppOutboundMessage::where('origin', 'manual')->where('user_id', $admin->id)->exists())->toBeTrue();

    $this->actingAs($admin)->put("/discussions/{$contact->id}/assign", ['user_id' => $admin->id]);
    $this->actingAs($admin)->post("/discussions/{$contact->id}/resolve");
    expect($contact->fresh()->needs_attention)->toBeFalse()->and($contact->fresh()->assigned_user_id)->toBe($admin->id)
        ->and(Task::where('contact_id', $contact->id)->whereNull('done_at')->count())->toBe(0);

    // Fenêtre fermée : texte libre refusé.
    $contact->forceFill(['last_inbound_at' => now()->subDays(2)])->save();
    $this->actingAs($admin)->post("/discussions/{$contact->id}/reply", ['body' => 'Relance'])->assertSessionHas('error');
});

it('reports prospects, sales and cost per customer by source', function () {
    $org = sectorOrg('beaute');
    $store = sectorStore($org);
    incoming($store, ['id' => 'f1', 'from' => '24177889900', 'type' => 'text', 'text' => ['body' => 'Bonjour'], 'referral' => ['source_url' => 'https://fb.me/x', 'source_type' => 'ad']]);
    $contact = Contact::where('phone_e164', '24177889900')->first();
    $contact->purchases()->create(['store_id' => $store->id, 'amount' => 20000, 'purchased_at' => today()]);
    AdBudget::create(['organization_id' => $org->id, 'source' => 'facebook_ad', 'month' => now()->format('Y-m'), 'amount' => 30000]);

    $this->actingAs(sectorAdmin($org))->get('/sources')->assertInertia(fn (Assert $page) => $page->component('Analytics/Sources')
        ->where('rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['source'] === 'facebook_ad' && $r['month'] === now()->format('Y-m')
            && $r['prospects'] === 1 && $r['customers'] === 1 && $r['revenue'] === 20000 && $r['cost_per_customer'] === 30000)));
});

it('blocks a marketing campaign without enough credits and debits it otherwise', function () {
    $org = sectorOrg('commerce', ['message_credits' => 100, 'marketing_message_cost' => 60]);
    $store = sectorStore($org);
    $admin = sectorAdmin($org);
    MessageTemplate::create(['organization_id' => $org->id, 'name' => 'promo', 'language' => 'fr', 'category' => 'MARKETING', 'is_active' => true, 'status' => 'APPROVED', 'body_variables' => 0]);
    foreach (['24177100001', '24177100002'] as $phone) {
        Contact::create(['store_id' => $store->id, 'phone_e164' => $phone, 'name' => 'Client', 'consent_status' => 'opt_in']);
    }
    $send = fn () => $this->actingAs($admin)->post('/campaigns', ['name' => 'Soldes', 'contact_source' => 'segment', 'store_ids' => [$store->id], 'message_type' => 'template', 'template_name' => 'promo']);

    $send()->assertSessionHas('error', fn ($m) => str_contains($m, 'Crédits insuffisants'));
    expect(Campaign::count())->toBe(0);

    // Recharge depuis le back-office : 1 000 FCFA, puis la campagne passe (2 × 60).
    $platform = User::factory()->create(['organization_id' => $org->id, 'is_platform_admin' => true]);
    $this->actingAs($platform)->post("/admin/organizations/{$org->id}/credits", ['amount' => 1000, 'reason' => 'Mobile Money', 'cost' => 60])->assertSessionHas('success');
    $send()->assertSessionHas('success');

    expect($org->fresh()->message_credits)->toBe(980)
        ->and(CreditMovement::where('organization_id', $org->id)->pluck('amount')->all())->toBe([1000, -120]);
});

it('keeps session messages free and leaves historical organizations unlimited', function () {
    expect(Organization::factory()->create()->hasCreditLimit())->toBeFalse();

    $org = sectorOrg('sport', ['message_credits' => 0]);
    $store = sectorStore($org);
    incoming($store, ['id' => 'c1', 'from' => '24177990011', 'type' => 'text', 'text' => ['body' => 'Bonjour']]);

    expect(WhatsAppOutboundMessage::count())->toBe(1)->and($org->fresh()->message_credits)->toBe(0);
});

it('renders the auto replies settings and saves them', function () {
    $org = sectorOrg('beaute');
    sectorStore($org);
    $admin = sectorAdmin($org);

    $this->actingAs($admin)->get('/settings/auto-replies')->assertInertia(fn (Assert $page) => $page->component('Settings/AutoReplies')
        ->where('settings.options', ['prix', 'rdv', 'horaires', 'humain'])->where('reminders', true));

    $this->actingAs($admin)->put('/settings/auto-replies', ['enabled' => true, 'options' => ['prix', 'humain'], 'labels' => ['prix' => 'Tarifs'], 'welcome' => 'Salut {prenom} !', 'away' => ''])
        ->assertSessionHas('success');

    $settings = $org->fresh()->autoReplySettings();
    expect($settings['options'])->toBe(['prix', 'humain'])
        ->and($settings['labels']['prix'])->toBe('Tarifs')
        ->and($settings['welcome'])->toBe('Salut {prenom} !')
        ->and($settings['away'])->toContain('fermé');
});

it('reads keywords of short messages', function (string $text, ?string $option) {
    expect(AutoReplies::keywordOf($text))->toBe($option);
})->with([['C’est combien la coupe ?', 'prix'], ['Vous êtes ouverts dimanche ?', 'horaires'], ['Je veux un rdv', 'rdv'], ['Merci beaucoup', null]]);
