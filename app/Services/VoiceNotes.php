<?php

namespace App\Services;

use App\Models\AiUsage;
use App\Models\Contact;
use App\Models\Store;
use App\Models\WhatsAppOutboundMessage;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Notes vocales WhatsApp : le fichier est téléchargé chez Meta, transcrit
 * (OpenAI, clé ClientlyX CLIENTLYX_TRANSCRIPTION_KEY), puis le texte suit le
 * même chemin qu'un message écrit (Assistant, robot à règles). Le fichier
 * audio n'est jamais conservé : seul le texte reste, dans Discussions.
 */
class VoiceNotes
{
    public static function configured(): bool
    {
        return config('clientlyx_ai.transcription.driver') === 'openai' && filled(config('clientlyx_ai.transcription.key'));
    }

    /** L'entreprise veut que l'assistant traite les notes vocales de ce contact. */
    public static function wanted(Contact $contact): bool
    {
        return Assistant::active($contact) && $contact->store->organization->assistantSettings()['voice'];
    }

    /**
     * Texte de la note, ou null si elle ne peut pas être transcrite (pas de
     * clé, fichier trop long, simulation, note vide).
     *
     * @return array{text: string, seconds: int}|null
     */
    public static function transcribe(Store $store, string $mediaId): ?array
    {
        if (! self::configured()) {
            return null;
        }

        $media = $store->whatsappClient()->downloadMedia($mediaId, (int) config('clientlyx_ai.transcription.max_bytes'));
        if ($media === null || $media['content'] === '') {
            return null;
        }

        $response = Http::withToken((string) config('clientlyx_ai.transcription.key'))
            ->timeout(90)
            ->attach('file', $media['content'], 'note.'.self::extension($media['mime']), ['Content-Type' => $media['mime']])
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => (string) config('clientlyx_ai.transcription.model'),
                'response_format' => 'json',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Transcription impossible (HTTP '.$response->status().') : '.($response->json('error.message') ?? ''));
        }

        $text = trim((string) $response->json('text'));
        if ($text === '') {
            return null;
        }

        // La durée n'est pas toujours renvoyée : estimation à ~2 Ko par seconde (opus WhatsApp).
        $seconds = (int) ($response->json('usage.seconds') ?? $response->json('duration') ?? ceil(strlen($media['content']) / 2000));
        AiUsage::countVoiceNote($store->organization, $seconds);

        return ['text' => $text, 'seconds' => $seconds];
    }

    /** Message « écrivez-moi » quand la note ne peut pas être écoutée (une fois par heure au plus). */
    public static function askToWrite(Contact $contact): void
    {
        $reply = $contact->store->organization->assistantSettings()['voice_unavailable_reply'];

        $recent = WhatsAppOutboundMessage::where('contact_id', $contact->id)
            ->where('body', $reply)
            ->where('created_at', '>=', now()->subHour())
            ->exists();

        if (! $recent) {
            WhatsAppConversation::text($contact, $reply, 'assistant');
        }
    }

    private static function extension(string $mime): string
    {
        return match (strtok($mime, ';')) {
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/aac' => 'm4a',
            'audio/amr' => 'amr',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/webm' => 'webm',
            default => 'ogg',
        };
    }
}
