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

    public function metaCode(): ?int
    {
        $code = $this->responseBody['error']['code'] ?? null;

        return is_numeric($code) ? (int) $code : null;
    }

    /**
     * Erreur passagère (Meta indisponible, limite de débit) : l'envoi peut
     * être retenté plus tard, contrairement à un numéro invalide ou un modèle
     * refusé.
     */
    public function isTransient(): bool
    {
        return $this->httpStatus >= 500
            || $this->httpStatus === 429
            || in_array($this->metaCode(), [4, 80007, 130429, 131048, 131056, 131000], true);
    }

    /**
     * Explication en français de l'erreur Meta, avec ce qu'il faut corriger,
     * pour l'écran Paramètres → WhatsApp. Le code et le message bruts de Meta
     * restent ajoutés en fin de phrase pour le support.
     */
    public function explanation(): string
    {
        $code = $this->metaCode();
        $subcode = $this->responseBody['error']['error_subcode'] ?? null;

        $hint = match (true) {
            $code === 190 => "Le token d'accès est invalide ou expiré : générez un token permanent (utilisateur système) dans Meta Business et enregistrez-le.",
            $code === 100 && (int) $subcode === 33 => "Le Phone Number ID est introuvable, ou le token n'a pas accès à ce numéro : vérifiez l'identifiant et les droits de l'utilisateur système sur le compte WhatsApp.",
            $code === 100 => 'Un paramètre est refusé par Meta : vérifiez le Phone Number ID (chiffres uniquement, sans le +).',
            $code === 10 || ($code >= 200 && $code <= 299) => "Le token n'a pas les permissions nécessaires : accordez whatsapp_business_management et whatsapp_business_messaging à l'utilisateur système.",
            in_array($code, [4, 80007, 130429], true) => 'Trop de requêtes envoyées à Meta : réessayez dans quelques minutes.',
            $code === 133005 => 'Le PIN ne correspond pas au code de vérification en deux étapes déjà défini pour ce numéro.',
            $code === 133006 => "Le numéro doit d'abord être vérifié (code SMS ou appel) dans le gestionnaire WhatsApp de Meta.",
            $code === 133010 => "Le numéro n'est pas encore enregistré : utilisez « Enregistrer le numéro » avec votre PIN.",
            $code === 133015 => "Trop de tentatives d'enregistrement récentes : attendez quelques minutes avant de réessayer.",
            in_array($code, [131031, 368], true) => 'Le compte WhatsApp Business est restreint ou bloqué par Meta : consultez la qualité du compte dans Meta Business.',
            $this->httpStatus >= 500 => 'Meta ne répond pas correctement pour le moment : réessayez plus tard.',
            default => "Meta a refusé l'appel.",
        };

        $meta = $this->responseBody['error']['message'] ?? null;

        return $meta ? "{$hint} (Meta".($code ? " #{$code}" : '')." : {$meta})" : $hint;
    }
}
