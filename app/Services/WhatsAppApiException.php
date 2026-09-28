<?php

namespace App\Services;

use RuntimeException;

class WhatsAppApiException extends RuntimeException
{
    public function __construct(
        public readonly string $recipient,
        public readonly int $httpStatus,
        public readonly ?array $responseBody,
    ) {
        $metaMessage = $responseBody['error']['message'] ?? 'Erreur inconnue';

        parent::__construct(
            "Échec de l'appel WhatsApp API pour {$recipient} (HTTP {$httpStatus}) : {$metaMessage}"
        );
    }
}
