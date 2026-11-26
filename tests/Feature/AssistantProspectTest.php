<?php

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Task;
use App\Models\WhatsAppOutboundMessage;
use App\Services\LeadCapture;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/AssistantHelpers.php';

/*
 * Agent IA : capture des informations, instinct prospect (chaud / à suivre /
 * froid), reprise en main en 1 clic et résumé de la conversation.
 */

it('lit email, ville, quartier, adresse et budget dans une phrase', function () {
    expect(LeadCapture::extract('Bonjour, c’est Aïcha. Mon mail : aicha.n@exemple.com, je suis à Owendo quartier Akournam'))
        ->toMatchArray(['email' => 'aicha.n@exemple.com', 'city' => 'Owendo', 'district' => 'Akournam'])
        ->and(LeadCapture::extract('Mon budget est de 150 000 FCFA')['budget'])->toBe('150 000 FCFA')
        ->and(LeadCapture::extract('Livrez-moi à la cité de la Démocratie, porte 12')['address'])->toBe('la cité de la Démocratie, porte 12')
        ->and(LeadCapture::extract('Vous êtes ouverts demain ?'))->toBe([]);
});

it('range les informations sur la fiche sans écraser la saisie de l’équipe', function () {
    $org = asOrg();
    $store = asStore($org);
    asText($store, 'Bonjour');
    $customer = Customer::first();
    $customer->update(['city' => 'Libreville']);

    asText($store, 'Je suis à Owendo, mon mail est paul.mba@exemple.com');

    expect($customer->fresh()->email)->toBe('paul.mba@exemple.com')
        ->and($customer->fresh()->city)->toBe('Libreville');
});

it('classe le prospect et alerte l’équipe quand il devient chaud', function () {
    $org = asOrg();
    $store = asStore($org);

    asText($store, 'Bonjour, c’est combien la séance ?');
    $customer = Customer::first();
    expect($customer->temperature)->toBe('warm');

    asText($store, 'C’est urgent, je veux commencer aujourd’hui');
    expect($customer->fresh()->temperature)->toBe('hot')
        ->and(Task::where('kind', 'hot_prospect')->whereNull('done_at')->count())->toBe(1);

    asText($store, 'Merci');
    expect(Task::where('kind', 'hot_prospect')->count())->toBe(1);
});

it('prend la main et la rend à l’IA en un clic', function () {
    $org = asOrg();
    $store = asStore($org);
    asText($store, 'Bonjour');
    $contact = Contact::first();
    $admin = asAdmin($org);

    $this->actingAs($admin)->post("/discussions/{$contact->id}/takeover", ['on' => true])->assertRedirect();
    expect($contact->fresh()->bot_paused_until->gt(now()->addDays(20)))->toBeTrue();

    $before = WhatsAppOutboundMessage::count();
    asText($store, 'Vous êtes où ?');
    expect(WhatsAppOutboundMessage::count())->toBe($before);

    $this->actingAs($admin)->post("/discussions/{$contact->id}/takeover", ['on' => false]);
    expect($contact->fresh()->bot_paused_until)->toBeNull();
});

it('résume la conversation avec l’IA, ou à partir des données sans IA', function () {
    $org = asOrg();
    $store = asStore($org);
    asText($store, 'Bonjour, mon budget est de 80 000 FCFA pour un shooting');
    $contact = Contact::first();
    $admin = asAdmin($org);

    $this->actingAs($admin)->post("/discussions/{$contact->id}/summary")->assertRedirect();
    expect(Customer::first()->ai_summary)->toContain('80 000 FCFA')->toContain('Dernier message');

    config(['clientlyx_ai.enabled' => true, 'clientlyx_ai.anthropic_key' => 'sk-ant-test']);
    $org->update(['assistant_settings' => [...$org->assistant_settings ?? [], 'use_ai' => true]]);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "Paul veut un shooting.\nBudget 80 000 FCFA.\nLui proposer samedi."]]])]);

    $this->actingAs($admin)->post("/discussions/{$contact->id}/summary");
    expect(Customer::first()->ai_summary)->toBe("Paul veut un shooting.\nBudget 80 000 FCFA.\nLui proposer samedi.");
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'anthropic') && str_contains($r['messages'][0]['content'], 'Client : Bonjour, mon budget'));
});
