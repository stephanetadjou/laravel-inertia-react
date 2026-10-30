<?php

use App\Models\Lead;
use Illuminate\Support\Facades\Mail;

function leadForm(array $overrides = []): array
{
    return [
        'nom' => 'Awa Mba',
        'entreprise' => 'Salon Élégance',
        'whatsapp' => '+241 062 60 50 54',
        'ville' => 'Libreville',
        'activite' => 'beaute',
        'message' => 'Trop de rendez-vous oubliés.',
        'consentement' => 'on',
        ...$overrides,
    ];
}

it('stores a demo request from the website and sends the visitor back to it', function () {
    config(['services.menentia.site_url' => 'https://menentia.com']);

    $this->post('/api/public/v1/leads', leadForm())
        ->assertRedirect('https://menentia.com/contact#merci');

    $lead = Lead::sole();
    expect($lead->name)->toBe('Awa Mba')
        ->and($lead->company)->toBe('Salon Élégance')
        ->and($lead->whatsapp)->toBe('24162605054')
        ->and($lead->activity)->toBe('beaute')
        ->and($lead->consent_at)->not->toBeNull()
        ->and($lead->status)->toBe('new');
});

it('answers 201 in JSON', function () {
    $this->postJson('/api/public/v1/leads', leadForm())->assertCreated()->assertJson(['ok' => true]);

    expect(Lead::count())->toBe(1);
});

it('accepts every activity offered by the website form', function (string $activity) {
    $this->postJson('/api/public/v1/leads', leadForm(['activite' => $activity]))->assertCreated();

    expect(Lead::sole()->activity)->toBe($activity);
})->with(['commerce', 'autre']);

it('refuses a request without consent or required fields', function () {
    $this->postJson('/api/public/v1/leads', leadForm(['consentement' => null, 'entreprise' => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['consentement', 'entreprise']);

    expect(Lead::count())->toBe(0);
});

it('keeps an unrecognised number as typed', function () {
    $this->postJson('/api/public/v1/leads', leadForm(['whatsapp' => '+237 6 99 00 11 22']))->assertCreated();

    expect(Lead::sole())->whatsapp_raw->toBe('+237 6 99 00 11 22');
});

it('silently ignores robots that fill the hidden field', function () {
    $this->postJson('/api/public/v1/leads', leadForm(['site_web' => 'http://spam.example']))->assertOk();

    expect(Lead::count())->toBe(0);
});

it('still stores the request when a notification address is configured', function () {
    Mail::fake();
    config(['services.menentia.leads_email' => 'ventes@menentia.com']);

    $this->postJson('/api/public/v1/leads', leadForm())->assertCreated();

    expect(Lead::count())->toBe(1);
});

it('limits repeated submissions from the same address', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/public/v1/leads', leadForm())->assertCreated();
    }

    $this->postJson('/api/public/v1/leads', leadForm())->assertTooManyRequests();
});
