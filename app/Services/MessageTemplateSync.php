<?php

namespace App\Services;

use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Http\Client\ConnectionException;

/**
 * Synchronise les modèles de message d'une organisation avec Meta : chaque
 * compte WhatsApp Business (WABA) des établissements est lu une fois, et ses
 * modèles sont créés ou mis à jour par (nom, langue). Les modèles qui ne
 * sont plus chez Meta ne sont pas supprimés (les campagnes passées y
 * renvoient) mais perdent leur statut d'approbation.
 */
class MessageTemplateSync
{
    /**
     * @return array{synced: int, accounts: int, errors: array<int, string>}
     */
    public function sync(Organization $organization): array
    {
        $stores = $organization->stores()
            ->whereNotNull('whatsapp_business_account_id')
            ->get()
            ->filter(fn (Store $store) => $store->canSendWhatsApp())
            ->unique('whatsapp_business_account_id');

        $synced = 0;
        $errors = [];
        $seen = [];

        foreach ($stores as $store) {
            try {
                $templates = $store->whatsappClient()->listTemplates($store->whatsapp_business_account_id);
            } catch (WhatsAppApiException $e) {
                $errors[] = "{$store->name} : {$e->explanation()}";

                continue;
            } catch (ConnectionException) {
                $errors[] = "{$store->name} : impossible de joindre Meta.";

                continue;
            }

            foreach ($templates as $template) {
                $model = $this->upsert($organization, $template);
                $seen[] = $model->id;
                $synced++;
            }
        }

        // Modèles synchronisés auparavant mais absents de Meta : plus envoyables.
        if ($errors === [] && $stores->isNotEmpty()) {
            $organization->messageTemplates()
                ->whereNotNull('meta_template_id')
                ->whereNotIn('id', $seen)
                ->update(['status' => 'DELETED']);
        }

        return ['synced' => $synced, 'accounts' => $stores->count(), 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function upsert(Organization $organization, array $template): MessageTemplate
    {
        $components = $template['components'] ?? [];
        $body = collect($components)->firstWhere('type', 'BODY');
        $header = collect($components)->firstWhere('type', 'HEADER');
        $bodyText = $body['text'] ?? null;

        return MessageTemplate::updateOrCreate(
            [
                'organization_id' => $organization->id,
                'name' => (string) $template['name'],
                'language' => (string) ($template['language'] ?? MessageTemplate::DEFAULT_LANGUAGE),
            ],
            [
                'category' => strtolower((string) ($template['category'] ?? 'utility')),
                'status' => strtoupper((string) ($template['status'] ?? '')) ?: null,
                'meta_template_id' => isset($template['id']) ? (string) $template['id'] : null,
                'body_text' => $bodyText,
                'body_variables' => self::countVariables($bodyText),
                'header_format' => isset($header['format']) ? strtoupper((string) $header['format']) : null,
                'components' => $components,
                'synced_at' => now(),
            ],
        );
    }

    /**
     * Nombre de variables du corps : le plus grand {{n}} présent.
     */
    public static function countVariables(?string $text): int
    {
        if (! $text || ! preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches)) {
            return 0;
        }

        return (int) max($matches[1]);
    }
}
