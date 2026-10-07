<?php

use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\MessageTemplateSync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function syncAdmin(Organization $organization): User
{
    return User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Administrateur')->id,
    ]);
}

function wabaStore(Organization $organization, string $waba = '555000111'): Store
{
    return Store::factory()->create([
        'organization_id' => $organization->id,
        'whatsapp_business_account_id' => $waba,
        'whatsapp_phone_number_id' => '123456789',
        'whatsapp_access_token' => 'valid-token',
    ]);
}

function metaTemplate(string $name, string $language, string $status = 'APPROVED', array $extra = []): array
{
    return [
        'id' => 'tpl-'.$name.'-'.$language,
        'name' => $name,
        'language' => $language,
        'category' => 'MARKETING',
        'status' => $status,
        'components' => [
            ['type' => 'HEADER', 'format' => 'IMAGE'],
            ['type' => 'BODY', 'text' => 'Bonjour {{1}}, -{{2}} % sur la collection {{3}} !'],
        ],
        ...$extra,
    ];
}

it('imports name, category, language, status and variables from Meta, following pages', function () {
    Http::fake([
        // Page suivante d'abord : le premier motif correspondant l'emporte.
        'graph.facebook.com/*/555000111/message_templates?after=CURSOR' => Http::response([
            'data' => [metaTemplate('promo_rentree', 'en_US', 'PENDING')],
        ]),
        'graph.facebook.com/*/555000111/message_templates*' => Http::response([
            'data' => [metaTemplate('promo_rentree', 'fr')],
            'paging' => ['next' => 'https://graph.facebook.com/v21.0/555000111/message_templates?after=CURSOR'],
        ]),
    ]);

    $organization = Organization::factory()->create();
    wabaStore($organization);

    $this->actingAs(syncAdmin($organization))->post('/templates/sync')
        ->assertSessionHas('success', fn (string $m) => str_contains($m, '2 modèle(s)'));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/555000111/message_templates')
        && $request->hasHeader('Authorization', 'Bearer valid-token'));

    $fr = MessageTemplate::where('name', 'promo_rentree')->where('language', 'fr')->sole();
    expect($fr->organization_id)->toBe($organization->id)
        ->and($fr->category)->toBe('marketing')
        ->and($fr->status)->toBe('APPROVED')
        ->and($fr->body_variables)->toBe(3)
        ->and($fr->header_format)->toBe('IMAGE')
        ->and($fr->body_text)->toContain('{{3}}')
        ->and($fr->synced_at)->not->toBeNull();

    expect(MessageTemplate::where('language', 'en_US')->value('status'))->toBe('PENDING');
});

it('updates an existing template instead of duplicating it', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [metaTemplate('bienvenue', 'fr', 'REJECTED')]])]);

    $organization = Organization::factory()->create();
    wabaStore($organization);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'bienvenue', 'language' => 'fr', 'category' => 'utility']);

    app(MessageTemplateSync::class)->sync($organization);

    expect(MessageTemplate::where('name', 'bienvenue')->sole()->status)->toBe('REJECTED');
});

it('reads each WhatsApp business account only once', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [metaTemplate('promo', 'fr')]])]);

    $organization = Organization::factory()->create();
    wabaStore($organization, '555000111');
    wabaStore($organization, '555000111');
    wabaStore($organization, '777000222');

    $result = app(MessageTemplateSync::class)->sync($organization);

    expect($result['accounts'])->toBe(2);
    Http::assertSentCount(2);
});

it('marks templates removed at Meta as no longer sendable', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

    $organization = Organization::factory()->create();
    wabaStore($organization);
    $gone = MessageTemplate::factory()->create([
        'organization_id' => $organization->id, 'name' => 'ancienne_promo', 'status' => 'APPROVED', 'meta_template_id' => '42',
    ]);
    $manual = MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'manuel', 'status' => null]);

    app(MessageTemplateSync::class)->sync($organization);

    expect($gone->fresh()->status)->toBe('DELETED')
        ->and($manual->fresh()->status)->toBeNull()
        ->and(MessageTemplate::sendable()->pluck('name')->all())->toBe(['manuel']);
});

it('only offers approved (or manual) templates for campaigns', function () {
    $organization = Organization::factory()->create();
    foreach (['APPROVED' => 'ok', 'PENDING' => 'attente', 'REJECTED' => 'rejete'] as $status => $name) {
        MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => $name, 'status' => $status, 'is_active' => true]);
    }

    $this->actingAs(syncAdmin($organization))->get('/campaigns')
        ->assertInertia(fn ($page) => $page->has('templates', 1)->where('templates.0.name', 'ok'));
});

it('explains a Meta error and keeps the other accounts', function () {
    Http::fake([
        'graph.facebook.com/*/555000111/*' => Http::response(['error' => ['code' => 190, 'message' => 'Expired']], 401),
        'graph.facebook.com/*/777000222/*' => Http::response(['data' => [metaTemplate('promo', 'fr')]]),
    ]);

    $organization = Organization::factory()->create();
    wabaStore($organization, '555000111');
    wabaStore($organization, '777000222');

    $this->actingAs(syncAdmin($organization))->post('/templates/sync')
        ->assertSessionHas('error', fn (string $m) => str_contains($m, '1 modèle(s)') && str_contains($m, 'invalide ou expiré'));

    expect(MessageTemplate::where('name', 'promo')->exists())->toBeTrue();
});

it('asks for a WABA ID before syncing', function () {
    Http::fake();
    $organization = Organization::factory()->create();
    Store::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs(syncAdmin($organization))->post('/templates/sync')
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'WABA ID'));

    Http::assertNothingSent();
});

it('reserves the sync to administrators', function () {
    $organization = Organization::factory()->create();
    $store = wabaStore($organization);
    $manager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de boutique')->id,
        'store_id' => $store->id,
    ]);

    $this->actingAs($manager)->post('/templates/sync')->assertStatus(403);
});

it('counts the variables of a template body', function (?string $text, int $expected) {
    expect(MessageTemplateSync::countVariables($text))->toBe($expected);
})->with([
    [null, 0],
    ['Sans variable', 0],
    ['Bonjour {{1}}', 1],
    ['{{1}} et {{ 2 }} puis {{1}}', 2],
]);
