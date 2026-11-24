<?php

use App\Models\AiUsage;
use App\Models\KnowledgeEntry;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppOutboundMessage;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
 * Notes vocales : téléchargement chez Meta, transcription, puis réponse de
 * l'assistant comme à un message écrit ; sans transcription, le client est
 * invité à écrire.
 */

function voiceNote(): array
{
    return ['type' => 'audio', 'audio' => ['id' => 'MEDIA1', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]];
}

function fakeVoice(string $text = 'Quels moyens de paiement acceptez-vous ?'): void
{
    Http::fake([
        'graph.facebook.com/*/MEDIA1' => Http::response(['url' => 'https://lookaside.fbsbx.com/audio/1', 'mime_type' => 'audio/ogg', 'file_size' => 12000]),
        'lookaside.fbsbx.com/*' => Http::response(str_repeat('a', 12000)),
        'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => $text]),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.out'.uniqid()]]]),
    ]);
}

it('transcrit une note vocale et y répond comme à un message écrit', function () {
    config(['clientlyx_ai.transcription.key' => 'sk-test-voice']);
    $org = asOrg();
    $store = asStore($org);
    KnowledgeEntry::create(['organization_id' => $org->id, 'section' => 'regles', 'title' => 'Moyens de paiement', 'content' => 'Nous acceptons Airtel Money, Moov Money et les espèces.', 'is_active' => true]);
    fakeVoice();

    asMessage($store, voiceNote());

    $in = WhatsAppInboundMessage::latest('id')->first();
    expect($in->type)->toBe('audio')
        ->and($in->body)->toBe('Quels moyens de paiement acceptez-vous ?')
        ->and(WhatsAppOutboundMessage::where('origin', 'assistant')->where('body', 'like', '%Airtel Money%')->exists())->toBeTrue()
        ->and(AiUsage::where('organization_id', $org->id)->value('voice_notes'))->toBe(1);

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'audio/transcriptions') && $r->hasHeader('Authorization', 'Bearer sk-test-voice'));
});

it('invite à écrire quand la transcription n’est pas activée', function () {
    config(['clientlyx_ai.transcription.key' => null]);
    $org = asOrg();
    $store = asStore($org);
    fakeVoice();

    asMessage($store, voiceNote());
    asMessage($store, voiceNote());

    expect(lastOut()->body)->toContain('écrire')
        ->and(WhatsAppOutboundMessage::count())->toBe(1);
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'openai'));
});

it('laisse la note de côté si l’entreprise a décoché les notes vocales', function () {
    config(['clientlyx_ai.transcription.key' => 'sk-test-voice']);
    $org = asOrg(['voice' => false]);
    $store = asStore($org);
    fakeVoice();

    asMessage($store, voiceNote());

    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'openai'));
});
