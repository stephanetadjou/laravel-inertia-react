<?php

namespace App\Services;

use App\Models\AiAgent;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Store;
use App\Models\Task;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppOutboundMessage;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Agents IA (module agents_ia) : choix de l'agent qui répond à un contact,
 * consigne système (persona, infos de l'entreprise, connaissances, règles),
 * historique récent de la discussion et envoi de la réponse WhatsApp
 * (origine « ai »). Quand le client demande un humain ou que l'agent ne sait
 * pas répondre, la discussion passe à l'équipe comme avec « Parler à
 * quelqu'un » : à traiter, tâche dans « À faire », robot coupé 24 h.
 */
class AiAgents
{
    /** Marqueur que l'agent renvoie pour passer la main à un humain. */
    public const HANDOFF = '[HUMAIN]';

    private const HISTORY = 12;

    /** Agent actif pour ce contact maintenant, ou null. */
    public static function agentFor(Contact $contact): ?AiAgent
    {
        $organization = $contact->store->organization;

        if (! $organization->hasModule('agents_ia')) {
            return null;
        }

        $open = AutoReplies::isOpen($contact->store, now());

        return AiAgent::where('organization_id', $organization->id)
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->first(fn (AiAgent $agent) => $agent->servesStore($contact->store_id) && match ($agent->schedule) {
                'outside_hours' => ! $open,
                'opening_hours' => $open,
                default => true,
            });
    }

    /** Répond au dernier message du contact (appelé en file d'attente). */
    public static function respond(AiAgent $agent, Contact $contact): void
    {
        $repliesToday = WhatsAppOutboundMessage::where('contact_id', $contact->id)
            ->where('origin', 'ai')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($repliesToday >= $agent->max_replies_per_day) {
            self::handOver($agent, $contact, 'Limite de réponses IA atteinte pour ce contact');

            return;
        }

        try {
            $text = AiProviders::reply($agent->provider, $agent->model, (string) $agent->api_key, self::systemPrompt($agent, $contact->store), self::history($contact));
        } catch (RuntimeException $e) {
            $agent->forceFill(['last_error' => $e->getMessage(), 'last_error_at' => now()])->save();
            Log::warning('Agent IA : réponse impossible', ['agent_id' => $agent->id, 'contact_id' => $contact->id, 'error' => $e->getMessage()]);
            // Le client ne reste pas sans suite : l'équipe reprend la discussion.
            $contact->forceFill(['needs_attention' => true])->saveQuietly();

            return;
        }

        if (str_contains($text, self::HANDOFF)) {
            self::handOver($agent, $contact, 'L’agent IA passe la main à l’équipe');

            return;
        }

        WhatsAppConversation::text($contact, $text, 'ai');
        $agent->forceFill(['replies_count' => $agent->replies_count + 1, 'last_reply_at' => now()])->save();
    }

    /** Réponse d'essai depuis l'écran de réglage (rien n'est envoyé). */
    public static function test(AiAgent $agent, Store $store, string $message): string
    {
        $text = AiProviders::reply($agent->provider, $agent->model, (string) $agent->api_key, self::systemPrompt($agent, $store), [['role' => 'user', 'content' => $message]]);

        return str_contains($text, self::HANDOFF) ? ($agent->handoff_message ?: self::defaultHandoff()).' (passage à un humain)' : $text;
    }

    public static function systemPrompt(AiAgent $agent, Store $store): string
    {
        $organization = $store->organization;
        $tone = [
            'chaleureux' => 'chaleureux, poli et rassurant',
            'professionnel' => 'professionnel, précis et courtois',
            'decontracte' => 'simple, amical et décontracté',
        ][$agent->tone] ?? 'chaleureux et poli';

        $parts = [
            'Tu es '.$agent->name.', '.($agent->role ?: 'l’assistant').' de « '.$organization->name.' » ('.$store->name.'). Tu réponds aux clients sur WhatsApp.',
            'Ton : '.$tone.'. Réponds dans la langue du client (français par défaut), en messages courts adaptés à WhatsApp (3 à 5 phrases au plus), sans mise en forme Markdown.',
        ];

        if ($agent->use_business_info) {
            $info = ['Établissement : '.$store->name.($store->city ? ', '.$store->city : ''), 'Horaires : '.AutoReplies::hoursText($store)];

            if ($organization->hasAgenda() && $organization->bookingSettings()['enabled']) {
                $info[] = 'Réservation en ligne : '.$organization->bookingUrl();
            }

            $currency = $organization->currencySymbol();
            $items = CatalogItem::where('organization_id', $organization->id)->where('active', true)->orderBy('type')->orderBy('name')->limit(40)->get(['name', 'price', 'duration_minutes', 'type']);
            if ($items->isNotEmpty()) {
                $info[] = "Prestations et produits :\n".$items->map(fn ($i) => '- '.$i->name
                    .($i->price > 0 ? ' : '.number_format($i->price, 0, ',', ' ').' '.$currency : '')
                    .($i->type === 'service' && $i->duration_minutes ? ' ('.$i->duration_minutes.' min)' : ''))->implode("\n");
            }

            $parts[] = "Informations de l'entreprise :\n".implode("\n", $info);
        }

        if (filled($agent->knowledge)) {
            $parts[] = "Connaissances fournies par l'entreprise (questions fréquentes, politique, offres) :\n".trim($agent->knowledge);
        }

        if (filled($agent->instructions)) {
            $parts[] = "Consignes de l'entreprise :\n".trim($agent->instructions);
        }

        $rules = [
            "N'invente jamais un prix, une disponibilité, une adresse ou une promesse absente des informations ci-dessus.",
            'Tu ne peux pas réserver, encaisser ni modifier un rendez-vous toi-même : oriente vers le lien de réservation s’il existe, sinon vers l’équipe.',
            'Ne demande jamais de mot de passe, de code ni de numéro de carte bancaire.',
            'Si le client demande à parler à une personne, se plaint, ou si tu ne connais pas la réponse, réponds uniquement par '.self::HANDOFF.'.',
        ];
        if (filled($agent->forbidden_topics)) {
            $rules[] = 'Sujets à ne pas aborder (réponds alors par '.self::HANDOFF."):\n".trim($agent->forbidden_topics);
        }
        $parts[] = "Règles :\n- ".implode("\n- ", $rules);

        return implode("\n\n", $parts);
    }

    /**
     * Derniers messages de la discussion, du plus ancien au plus récent,
     * commençant par un message du client.
     *
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public static function history(Contact $contact): array
    {
        $in = WhatsAppInboundMessage::where('contact_id', $contact->id)->latest('received_at')->limit(self::HISTORY)->get(['body', 'received_at'])
            ->map(fn ($m) => ['role' => 'user', 'content' => (string) $m->body, 'at' => $m->received_at]);
        $out = WhatsAppOutboundMessage::where('contact_id', $contact->id)->where('status', '!=', 'failed')->latest('created_at')->limit(self::HISTORY)->get(['body', 'created_at'])
            ->map(fn ($m) => ['role' => 'assistant', 'content' => (string) $m->body, 'at' => $m->created_at]);

        $messages = $in->concat($out)
            ->filter(fn ($m) => trim($m['content']) !== '')
            ->sortBy(fn ($m) => $m['at']?->getTimestamp() ?? 0)
            ->take(-self::HISTORY)
            ->values();

        while ($messages->isNotEmpty() && $messages->first()['role'] !== 'user') {
            $messages->shift();
        }

        return $messages->map(fn ($m) => ['role' => $m['role'], 'content' => mb_substr($m['content'], 0, 2000)])->values()->all();
    }

    public static function defaultHandoff(): string
    {
        return 'Je transmets votre message à un membre de l’équipe, qui vous répond très vite.';
    }

    private static function handOver(AiAgent $agent, Contact $contact, string $details): void
    {
        Task::open([
            'organization_id' => $agent->organization_id,
            'store_id' => $contact->store_id,
            'customer_id' => $contact->customer_id,
            'contact_id' => $contact->id,
            'assigned_user_id' => $contact->assigned_user_id,
            'kind' => 'talk_to_human',
            'title' => 'Parler à quelqu’un',
            'details' => $details,
        ]);
        $contact->forceFill(['needs_attention' => true, 'bot_paused_until' => now()->addDay()])->saveQuietly();
        WhatsAppConversation::text($contact, $agent->handoff_message ?: self::defaultHandoff(), 'ai');
    }
}
