<?php

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function paramsSetup(array $templateAttributes = []): array
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id, 'name' => 'Mango Glass']);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $template = MessageTemplate::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'promo_rentree',
        'language' => 'fr',
        'category' => 'marketing',
        'status' => 'APPROVED',
        'is_active' => true,
        'body_text' => 'Bonjour {{1}}, -{{2}} % chez {{3}} !',
        'body_variables' => 3,
        'header_format' => null,
        ...$templateAttributes,
    ]);
    Contact::factory()->create(['store_id' => $store->id, 'name' => 'Awa Mba', 'phone_e164' => '24177451288', 'consent_status' => 'opt_in']);

    return [$organization, $store, $user, $template];
}

function campaignPayload(Store $store, MessageTemplate $template, array $overrides = []): array
{
    return [
        'name' => 'Rentrée',
        'contact_source' => 'segment',
        'store_ids' => [$store->id],
        'message_type' => 'template',
        'template_name' => $template->name,
        'template_id' => $template->id,
        'template_variables' => ['{prenom}', '20', '{boutique}'],
        ...$overrides,
    ];
}

it('stores the template language and variables on the campaign', function () {
    Queue::fake();
    [, $store, $user, $template] = paramsSetup(['language' => 'en_US']);

    $this->actingAs($user)->post('/campaigns', campaignPayload($store, $template))->assertSessionHas('success');

    $campaign = Campaign::sole();
    expect($campaign->template_language)->toBe('en_US')
        ->and($campaign->template_variables)->toBe(['{prenom}', '20', '{boutique}'])
        ->and($campaign->header_image_url)->toBeNull();
});

it('requires a value for every variable of the template', function () {
    Queue::fake();
    [, $store, $user, $template] = paramsSetup();

    $this->actingAs($user)->post('/campaigns', campaignPayload($store, $template, ['template_variables' => ['{prenom}', '', '']]))
        ->assertSessionHasErrors('template_variables');

    expect(Campaign::count())->toBe(0);
});

it('requires an image for a template with an image header, uploaded or by https link', function () {
    Queue::fake();
    Storage::fake('public');
    [, $store, $user, $template] = paramsSetup(['header_format' => 'IMAGE']);

    $this->actingAs($user)->post('/campaigns', campaignPayload($store, $template))->assertSessionHasErrors('template_variables');

    $this->actingAs($user)->post('/campaigns', campaignPayload($store, $template, [
        'header_image' => UploadedFile::fake()->image('affiche.jpg', 800, 800),
    ]))->assertSessionHas('success');

    $campaign = Campaign::sole();
    expect($campaign->header_image_url)->toContain('/storage/campaign-images/');
    Storage::disk('public')->assertExists(str_replace('/storage/', '', parse_url($campaign->header_image_url, PHP_URL_PATH)));
});

it('refuses a non https image link', function () {
    Queue::fake();
    [, $store, $user, $template] = paramsSetup(['header_format' => 'IMAGE']);

    $this->actingAs($user)->post('/campaigns', campaignPayload($store, $template, ['header_image_url' => 'http://exemple.com/a.jpg']))
        ->assertSessionHasErrors('header_image_url');
});

it('refuses a template that Meta has not approved', function () {
    Queue::fake();
    [, $store, $user, $template] = paramsSetup(['status' => 'PENDING']);

    $this->actingAs($user)->post('/campaigns', campaignPayload($store, $template));

    expect(Campaign::count())->toBe(0);
});

it('sends the personalised variables, the header image and the template language to Meta', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);
    // File synchrone (phpunit.xml) : la campagne part dès sa création, lot compris.
    [, $store, $user, $template] = paramsSetup(['header_format' => 'IMAGE', 'language' => 'fr']);

    $this->actingAs($user)->post('/campaigns', campaignPayload($store, $template, ['header_image_url' => 'https://cdn.exemple.com/rentree.jpg']));

    expect(Campaign::sole()->status)->toBe('completed');

    Http::assertSent(function (Request $request) {
        $template = $request['template'];

        return $template['name'] === 'promo_rentree'
            && $template['language']['code'] === 'fr'
            && $template['components'][0] === ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://cdn.exemple.com/rentree.jpg']]]]
            && array_column($template['components'][1]['parameters'], 'text') === ['Awa', '20', 'Mango Glass'];
    });
});

it('replaces unknown contact fields so Meta never receives an empty parameter', function () {
    $campaign = new Campaign(['template_variables' => ['{prenom}', '{nom}', 'Fixe']]);
    $contact = Contact::factory()->make(['name' => '']);

    expect($campaign->variablesFor($contact))->toBe(['Client', 'Client', 'Fixe']);
});
