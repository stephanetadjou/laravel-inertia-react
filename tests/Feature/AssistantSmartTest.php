<?php

use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeQuestion;
use App\Models\WhatsAppOutboundMessage;
use App\Services\Assistant;
use App\Support\BookingRequest;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Support/AssistantHelpers.php';

/*
 * Assistant plus intelligent : demande complète (« shooting famille samedi
 * 10 h »), annulation et décalage d'un RDV sur WhatsApp, plusieurs questions
 * dans un même message.
 */

function smartServices(int $orgId): array
{
    return [
        CatalogItem::create(['organization_id' => $orgId, 'type' => 'service', 'name' => 'Shooting famille', 'price' => 45000, 'duration_minutes' => 60, 'active' => true, 'bookable_online' => true]),
        CatalogItem::create(['organization_id' => $orgId, 'type' => 'service', 'name' => 'Portrait professionnel', 'price' => 25000, 'duration_minutes' => 30, 'active' => true, 'bookable_online' => true]),
    ];
}

it('lit la prestation, le jour et l’heure d’une phrase', function () {
    $now = Carbon::parse('2026-11-24 09:00'); // mardi
    $services = collect([new CatalogItem(['name' => 'Shooting famille']), new CatalogItem(['name' => 'Portrait professionnel'])]);

    $r = BookingRequest::parse('Bonjour, shooting famille samedi 10 h svp', $services, $now);
    expect($r->intent)->toBe(BookingRequest::BOOK)
        ->and($r->service->name)->toBe('Shooting famille')
        ->and($r->start()->format('Y-m-d H:i'))->toBe('2026-11-28 10:00');

    expect(BookingRequest::parse('un portrait demain à 15h30 c’est possible ?', $services, $now)->start()->format('Y-m-d H:i'))->toBe('2026-11-25 15:30')
        ->and(BookingRequest::parse('rdv le 3/12 à 9h', $services, $now)->start()->format('Y-m-d H:i'))->toBe('2026-12-03 09:00')
        ->and(BookingRequest::parse('Je dois annuler mon rendez-vous', $services, $now)->intent)->toBe(BookingRequest::CANCEL)
        ->and(BookingRequest::parse('Je peux décaler mon rdv à jeudi 14h ?', $services, $now)->intent)->toBe(BookingRequest::RESCHEDULE)
        ->and(BookingRequest::parse('Vous êtes où exactement ?', $services, $now)->intent)->toBeNull();
});

it('propose directement le créneau demandé dans une phrase', function () {
    $org = asOrg();
    $store = asStore($org);
    [$famille] = smartServices($org->id);
    $day = now()->addDays(2)->locale('fr');
    $weekday = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][$day->dayOfWeek];

    asText($store, "Je voudrais un shooting famille {$weekday} à 10h");

    expect(json_encode(lastOut()->payload))->toContain('bk:ok:'.$famille->id.':'.$day->format('Ymd').'1000')
        ->and(lastOut()->body)->toContain('Shooting famille');

    asButton($store, 'bk:ok:'.$famille->id.':'.$day->format('Ymd').'1000');
    expect(Appointment::where('service', 'Shooting famille')->first()->starts_at->format('Y-m-d H:i'))->toBe($day->format('Y-m-d').' 10:00');
});

it('annule le prochain rendez-vous après confirmation', function () {
    $org = asOrg();
    $store = asStore($org);
    [$famille] = smartServices($org->id);
    asText($store, 'Bonjour');
    $customer = Customer::where('organization_id', $org->id)->first();
    $appointment = Appointment::create(['store_id' => $store->id, 'customer_id' => $customer->id, 'starts_at' => now()->addDays(3)->setTime(11, 0), 'duration_minutes' => 60, 'service' => 'Shooting famille', 'status' => 'confirmed']);

    asText($store, 'Je dois annuler mon rendez-vous');
    expect(json_encode(lastOut()->payload))->toContain('bk:cx:'.$appointment->id);

    asButton($store, 'bk:cx:'.$appointment->id);
    expect($appointment->fresh()->status)->toBe('cancelled')
        ->and(lastOut()->body)->toContain('annulé');
});

it('décale un rendez-vous : le nouveau est pris, l’ancien annulé', function () {
    $org = asOrg();
    $store = asStore($org);
    [$famille] = smartServices($org->id);
    asText($store, 'Bonjour');
    $customer = Customer::where('organization_id', $org->id)->first();
    $old = Appointment::create(['store_id' => $store->id, 'customer_id' => $customer->id, 'starts_at' => now()->addDays(3)->setTime(11, 0), 'duration_minutes' => 60, 'service' => 'Shooting famille', 'status' => 'confirmed']);
    $old->items()->create(['catalog_item_id' => $famille->id, 'name' => 'Shooting famille', 'price' => 45000, 'duration_minutes' => 60]);
    $day = now()->addDays(4)->format('d/m');
    $ymd = now()->addDays(4)->format('Ymd');

    asText($store, "Je voudrais décaler mon rdv au {$day} à 15h");
    $id = 'bk:ok:'.$famille->id.':'.$ymd.'1500:r'.$old->id;
    expect(json_encode(lastOut()->payload))->toContain($id);

    asButton($store, $id);
    expect($old->fresh()->status)->toBe('cancelled')
        ->and(Appointment::where('customer_id', $customer->id)->whereIn('status', Appointment::OPEN_STATUSES)->first()->starts_at->format('Ymd H:i'))->toBe($ymd.' 15:00')
        ->and(lastOut()->body)->toContain('décalé');
});

it('répond à plusieurs questions dans un même message', function () {
    $org = asOrg();
    $store = asStore($org);
    KnowledgeEntry::create(['organization_id' => $org->id, 'section' => 'regles', 'title' => 'Moyens de paiement', 'content' => 'Nous acceptons Airtel Money et les espèces.', 'is_active' => true]);
    KnowledgeEntry::create(['organization_id' => $org->id, 'section' => 'presentation', 'title' => 'Adresse du studio', 'content' => 'Le studio est situé Quartier Louis, Libreville.', 'is_active' => true]);

    $decision = Assistant::decide($org, $store, 'Vous acceptez quels moyens de paiement ? Et votre adresse ?');
    expect($decision['text'])->toContain('Airtel Money')->toContain('Quartier Louis')
        ->and($decision['unanswered'])->toBe([]);

    asText($store, 'Quels moyens de paiement acceptez-vous ? Vous faites des drones ?');
    expect(WhatsAppOutboundMessage::where('origin', 'assistant')->where('body', 'like', '%Airtel Money%Pour le reste%')->exists())->toBeTrue()
        ->and(KnowledgeQuestion::where('organization_id', $org->id)->value('question'))->toContain('drones');
});
