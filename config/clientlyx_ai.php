<?php

/*
 * IA ClientlyX (Assistant IA) : clé IA de la plateforme, comprise dans
 * l'offre, et plafond mensuel de questions IA par offre. Sans clé, l'assistant
 * fonctionne sans modèle externe (menu, RDV à boutons, recherche dans la base).
 */
return [
    // Interrupteur général de l'IA externe (Haiku) : désactivé, l'assistant
    // répond seulement par les boutons et la recherche dans la base.
    'enabled' => (bool) env('CLIENTLYX_AI_ENABLED', false),
    'anthropic_key' => env('CLIENTLYX_ANTHROPIC_KEY'),
    'model' => env('CLIENTLYX_AI_MODEL', 'claude-haiku-5-5'),

    // Recherche par le sens (facultatif) : voyage | openai | none (recherche par mots).
    'embeddings' => [
        'driver' => env('CLIENTLYX_EMBEDDINGS_DRIVER', 'none'),
        'key' => env('CLIENTLYX_EMBEDDINGS_KEY'),
        'model' => env('CLIENTLYX_EMBEDDINGS_MODEL'),
    ],

    // Notes vocales : transcription (openai | none), puis réponse comme à un message écrit.
    'transcription' => [
        'driver' => env('CLIENTLYX_TRANSCRIPTION_DRIVER', 'openai'),
        'key' => env('CLIENTLYX_TRANSCRIPTION_KEY'),
        'model' => env('CLIENTLYX_TRANSCRIPTION_MODEL', 'gpt-4o-mini-transcribe'),
        // Taille maximale d'une note (WhatsApp ne donne pas la durée) : ~5 min.
        'max_bytes' => 5 * 1024 * 1024,
    ],

    // Questions IA par mois et par entreprise (null = illimité).
    'monthly_questions' => [
        'gratuit' => 50,
        'solo' => 300,
        'equipe' => 1000,
        'premium' => 3000,
        'entreprise' => null,
    ],
];
