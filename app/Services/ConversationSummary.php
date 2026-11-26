<?php

namespace App\Services;

use App\Models\AiUsage;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppOutboundMessage;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Résumé d'une conversation en 3 lignes, gardé sur la fiche client
 * (customers.ai_summary). Avec l'IA ClientlyX : les 80 derniers messages
 * résumés par Claude Haiku. Sans IA : besoin, informations captées, dernière
 * demande et RDV, lus dans les données.
 */
class ConversationSummary
{
    public static function generate(Contact $contact): string
    {
        $organization = $contact->store->organization;
        $summary = null;

        if (Assistant::aiAllowed($organization)) {
            try {
                $summary = trim(AiProviders::reply('anthropic', (string) config('clientlyx_ai.model'), (string) config('clientlyx_ai.anthropic_key'),
                    'Tu résumes une conversation WhatsApp entre un client et « '.$organization->name.' » pour l’équipe. '
                    .'Réponds en français, en 3 lignes courtes, sans Markdown : 1) qui est le client et ce qu’il veut, '
                    .'2) ce qui a été dit ou proposé (prix, créneaux, devis), 3) la prochaine action pour l’équipe. N’invente rien.',
                    [['role' => 'user', 'content' => self::transcript($contact)]]));
                AiUsage::countQuestion($organization);
            } catch (RuntimeException $e) {
                Log::warning('Résumé IA impossible', ['contact_id' => $contact->id, 'error' => $e->getMessage()]);
            }
        }

        $summary = $summary ?: self::fromData($contact);
        $contact->customer?->forceFill(['ai_summary' => mb_substr($summary, 0, 2000), 'ai_summary_at' => now()])->saveQuietly();

        return $summary;
    }

    /** Les 80 derniers messages, du plus ancien au plus récent. */
    public static function transcript(Contact $contact): string
    {
        $in = WhatsAppInboundMessage::where('contact_id', $contact->id)->latest('received_at')->limit(80)->get(['body', 'received_at'])
            ->map(fn ($m) => ['at' => $m->received_at, 'line' => 'Client : '.($m->body ?? '[pièce jointe]')]);
        $out = WhatsAppOutboundMessage::where('contact_id', $contact->id)->latest('created_at')->limit(80)->get(['body', 'origin', 'created_at'])
            ->map(fn ($m) => ['at' => $m->created_at, 'line' => ($m->origin === 'manual' ? 'Équipe' : 'Assistant').' : '.$m->body]);

        return $in->concat($out)->sortBy('at')->take(-80)->pluck('line')->implode("\n");
    }

    private static function fromData(Contact $contact): string
    {
        $customer = $contact->customer;
        $facts = array_filter([
            $customer?->name,
            $customer?->city ?? $customer?->district,
            filled($customer?->budget) ? 'budget '.$customer->budget : null,
            $customer?->email,
        ]);
        $lastQuestion = WhatsAppInboundMessage::where('contact_id', $contact->id)->whereNotNull('body')->latest('received_at')->value('body');
        $appointment = $customer ? Appointment::where('customer_id', $customer->id)->whereIn('status', Appointment::OPEN_STATUSES)
            ->where('starts_at', '>=', now())->orderBy('starts_at')->first() : null;

        return implode("\n", array_filter([
            implode(' · ', $facts).($customer?->need ? ' — besoin : '.$customer->need : ''),
            $lastQuestion ? 'Dernier message : « '.mb_strimwidth($lastQuestion, 0, 120, '…').' »' : null,
            $appointment
                ? 'RDV prévu le '.$appointment->starts_at->format('d/m à H\hi').($appointment->service ? ' ('.$appointment->service.')' : '').'.'
                : 'Prochaine action : '.($customer?->temperature === 'hot' ? 'rappeler vite pour conclure.' : 'proposer un créneau ou un devis.'),
        ]));
    }
}
