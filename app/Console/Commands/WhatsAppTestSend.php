<?php

namespace App\Console\Commands;

use App\Services\WhatsAppApiException;
use App\Services\WhatsAppClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class WhatsAppTestSend extends Command
{
    protected $signature = 'whatsapp:test-send {numero : Numéro destinataire au format international, ex: 24177123456}';

    protected $description = "Vérifie la connexion à l'API WhatsApp Cloud et envoie le template hello_world à un numéro de test";

    public function handle(): int
    {
        $numero = $this->argument('numero');

        try {
            $client = app(WhatsAppClient::class);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Vérification de la connexion WhatsApp Business...');

        try {
            $info = $client->verifyConnection();
            $this->info(sprintf(
                'Connecté : %s (%s)',
                $info['verified_name'] ?? 'nom non vérifié',
                $info['display_phone_number'] ?? '?',
            ));
        } catch (WhatsAppApiException $e) {
            $this->error($e->getMessage());
            $this->dumpResponseBody($e->responseBody);

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->error("Impossible de joindre l'API Meta : {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Envoi du template hello_world à {$numero}...");

        try {
            $result = $client->sendTemplate($numero, 'hello_world', 'en_US');
        } catch (WhatsAppApiException $e) {
            $this->error($e->getMessage());
            $this->dumpResponseBody($e->responseBody);

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->error("Impossible de joindre l'API Meta : {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Message envoyé avec succès.');
        $this->dumpResponseBody($result);

        return self::SUCCESS;
    }

    private function dumpResponseBody(?array $body): void
    {
        if ($body !== null) {
            $this->line(json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
}
