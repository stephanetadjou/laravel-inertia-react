<?php

/*
 * Aides partagées des tests de l'Assistant IA (entreprise, établissement,
 * messages WhatsApp entrants simulés).
 */

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsAppOutboundMessage;
use App\Support\SectorPresets;

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
