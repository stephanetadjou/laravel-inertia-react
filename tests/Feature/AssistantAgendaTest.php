<?php

use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\WhatsAppOutboundMessage;
use App\Services\AssistantStats;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/../Support/AssistantHelpers.php';

/*
 * L'assistant qui remplit l'agenda : 2 créneaux proposés dès qu'un client
 * montre de l'intérêt, puis une relance avant la fin de la fenêtre de 24 h.
 */

function agendaService(int $orgId): CatalogItem
{
    return CatalogItem::create(['organization_id' => $orgId, 'type' => 'service', 'name' => 'Shooting famille', 'price' => 45000, 'duration_minutes' => 60, 'active' => true, 'bookable_online' => true]);
}

it('propose 2 créneaux en boutons après une question de prix', function () {
    $org = asOrg();
    $store = asStore($org);
    $service = agendaService($org->id);

    asText($store, 'Combien coûte le shooting famille ?');

    $out = lastOut();
    $buttons = $out->payload['interactive']['action']['buttons'];
    expect($out->body)->toContain('Shooting famille')
        ->and($buttons)->toHaveCount(3)
        ->and($buttons[0]['reply']['id'])->toStartWith('bk:t:'.$service->id.':')
        ->and($buttons[1]['reply']['id'])->toStartWith('bk:t:'.$service->id.':')
        ->and($buttons[0]['reply']['id'])->not->toBe($buttons[1]['reply']['id'])
        ->and($buttons[2]['reply']['id'])->toBe('bk:s:'.$service->id)
        ->and(WhatsAppOutboundMessage::where('body', 'like', '%45 000%')->exists())->toBeTrue();

    // Un clic sur un créneau mène au récapitulatif.
    asButton($store, $buttons[0]['reply']['id']);
    expect(json_encode(lastOut()->payload))->toContain('bk:ok:'.$service->id);
});

it('relance une seule fois un prospect intéressé qui n’a pas réservé', function () {
    $org = asOrg();
    $store = asStore($org);
    $service = agendaService($org->id);
    asText($store, 'Combien coûte le shooting famille ?');
    $contact = Contact::first();

    $this->travel(4)->hours();
    $before = WhatsAppOutboundMessage::count();
    Artisan::call('assistant:relances');

    expect(WhatsAppOutboundMessage::count())->toBe($before + 1)
        ->and(lastOut()->body)->toContain('Vous pensiez à « Shooting famille »')
        ->and($contact->fresh()->assistant_state['interest']['nudged_at'])->not->toBeNull();

    Artisan::call('assistant:relances');
    expect(WhatsAppOutboundMessage::count())->toBe($before + 1);
});

it('mesure l’agenda rempli : conversion, CA, temps de réponse, heures et fil en direct', function () {
    $org = asOrg();
    $store = asStore($org);
    $service = agendaService($org->id);
    asText($store, 'Combien coûte le shooting famille ?');
    $slot = lastOut()->payload['interactive']['action']['buttons'][0]['reply']['id'];
    asButton($store, $slot);
    asButton($store, str_replace('bk:t:', 'bk:ok:', $slot));

    $stats = AssistantStats::for($org);
    expect($stats['current']['appointments'])->toBe(1)
        ->and($stats['current']['conversion'])->toBe(100)
        ->and($stats['current']['revenue'])->toBe(45000)
        ->and($stats['current']['response_seconds'])->toBeLessThan(60)
        ->and($stats['hourly'])->toHaveCount(24)
        ->and(array_sum($stats['hourly']))->toBe(3)
        ->and(collect($stats['live'])->pluck('kind'))->toContain('rdv');
});
