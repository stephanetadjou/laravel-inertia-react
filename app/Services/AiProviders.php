<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Appel du fournisseur IA d'un agent (Claude, ChatGPT ou Gemini) par sa
 * propre API HTTP : une consigne système, l'historique de la discussion
 * (rôles user / assistant), une réponse texte. Aucune dépendance de plus,
 * aucun serveur à part : un simple appel HTTP depuis Laravel.
 */
class AiProviders
{
    private const TIMEOUT = 45;

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     *
     * @throws RuntimeException
     */
    public static function reply(string $provider, string $model, string $apiKey, string $system, array $messages): string
    {
        try {
            $text = match ($provider) {
                'anthropic' => self::anthropic($model, $apiKey, $system, $messages),
                'openai' => self::openai($model, $apiKey, $system, $messages),
                'gemini' => self::gemini($model, $apiKey, $system, $messages),
                default => throw new RuntimeException('Fournisseur IA inconnu.'),
            };
        } catch (ConnectionException) {
            throw new RuntimeException('Le fournisseur IA ne répond pas. Réessayez plus tard.');
        }

        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException('Le fournisseur IA a renvoyé une réponse vide.');
        }

        return $text;
    }

    private static function anthropic(string $model, string $apiKey, string $system, array $messages): string
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 2000,
                'system' => $system,
                'messages' => $messages,
                // Réponses de discussion courtes : effort faible, plus rapide et moins cher.
                'output_config' => ['effort' => 'low'],
            ]);

        self::guard($response);

        if ($response->json('stop_reason') === 'refusal') {
            throw new RuntimeException('Le fournisseur IA a refusé de répondre à ce message.');
        }

        return collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");
    }

    private static function openai(string $model, string $apiKey, string $system, array $messages): string
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withToken($apiKey)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => $system], ...$messages],
            ]);

        self::guard($response);

        return (string) $response->json('choices.0.message.content', '');
    }

    private static function gemini(string $model, string $apiKey, string $system, array $messages): string
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => array_map(fn ($m) => ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]], $messages),
            ]);

        self::guard($response);

        return collect($response->json('candidates.0.content.parts', []))->pluck('text')->implode("\n");
    }

    /** Message d'erreur lisible (clé refusée, quota, modèle inconnu…). */
    private static function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $detail = $response->json('error.message') ?? $response->json('0.error.message') ?? '';

        throw new RuntimeException(match (true) {
            in_array($response->status(), [401, 403], true) => 'Clé API refusée par le fournisseur. Vérifiez-la.',
            $response->status() === 429 => 'Quota ou crédit épuisé chez le fournisseur IA.',
            $response->status() === 404 => 'Modèle introuvable chez le fournisseur. Choisissez-en un autre.',
            default => trim('Erreur du fournisseur IA ('.$response->status().'). '.mb_substr((string) $detail, 0, 200)),
        });
    }
}
