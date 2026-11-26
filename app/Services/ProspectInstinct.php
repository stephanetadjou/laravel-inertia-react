<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Task;
use App\Models\WhatsAppInboundMessage;
use Illuminate\Support\Str;

/**
 * Instinct prospect de l'Agent IA : chaque client en discussion est classé
 * Chaud / À suivre / Froid à partir de ce qu'il a dit et fait (RDV, devis,
 * budget, urgence, questions de prix, silence). Passer à « Chaud » ouvre une
 * tâche pour l'équipe et place le prospect dans le pipeline.
 */
class ProspectInstinct
{
    public const LEVELS = ['hot' => 'Chaud', 'warm' => 'À suivre', 'cold' => 'Froid'];

    private const HOT_WORDS = '/\b(urgent\w*|aujourd ?hui|tout de suite|maintenant|des que possible|asap|commander|acheter|je prends|je veux reserver|on y va|ok pour|d accord pour|valide\w*)\b/';

    private const WARM_WORDS = '/\b(prix|tarif\w*|combien|cout\w*|devis|dispo\w*|rdv|rendez|reserv\w*|offre\w*|forfait\w*|interesse\w*|info\w*)\b/';

    private const COLD_WORDS = '/\b(pas interesse\w*|non merci|trop cher|plus tard|pas maintenant|laisse tomber|annule)\b/';

    /** @return array{level: string, reason: string} */
    public static function evaluate(Contact $contact): array
    {
        $customer = $contact->customer;
        $texts = WhatsAppInboundMessage::where('contact_id', $contact->id)
            ->where('received_at', '>=', now()->subDays(14))
            ->whereNotNull('body')
            ->latest('received_at')->limit(30)->pluck('body')
            ->map(fn ($b) => Str::of($b)->ascii()->lower()->replace("'", ' ')->value());
        $last = $texts->first() ?? '';

        if ($customer !== null && Appointment::where('customer_id', $customer->id)->whereIn('status', Appointment::OPEN_STATUSES)->where('starts_at', '>=', now())->exists()) {
            return ['level' => 'hot', 'reason' => 'RDV à venir'];
        }
        if ($last !== '' && preg_match(self::COLD_WORDS, $last)) {
            return ['level' => 'cold', 'reason' => 'A dit ne pas être intéressé pour l’instant'];
        }
        if ($customer?->need_kind !== null && in_array($customer->need_kind, ['devis', 'demo', 'essai', 'rdv'], true) && $customer->need_at?->gte(now()->subDays(7))) {
            return ['level' => 'hot', 'reason' => 'Demande de '.$customer->need_kind.' enregistrée'];
        }
        if ($texts->contains(fn ($t) => preg_match(self::HOT_WORDS, $t))) {
            return ['level' => 'hot', 'reason' => 'Veut avancer vite'];
        }
        if (filled($customer?->budget)) {
            return ['level' => 'hot', 'reason' => 'Budget donné : '.$customer->budget];
        }
        if ($texts->contains(fn ($t) => preg_match(self::WARM_WORDS, $t))) {
            return ['level' => 'warm', 'reason' => 'S’intéresse aux prix ou aux disponibilités'];
        }
        if ($texts->isNotEmpty()) {
            return ['level' => 'warm', 'reason' => 'En discussion'];
        }

        return ['level' => 'cold', 'reason' => 'Pas de message depuis 14 jours'];
    }

    /** Recalcule et enregistre ; un passage à « Chaud » alerte l'équipe. */
    public static function refresh(Contact $contact): ?string
    {
        $customer = $contact->customer;
        if ($customer === null) {
            return null;
        }

        ['level' => $level, 'reason' => $reason] = self::evaluate($contact);
        $was = $customer->temperature;
        $customer->forceFill(['temperature' => $level, 'temperature_reason' => mb_substr($reason, 0, 160), 'temperature_at' => now()])->saveQuietly();

        if ($level === 'hot' && $was !== 'hot') {
            self::onHot($contact, $customer, $reason);
        }

        return $level;
    }

    private static function onHot(Contact $contact, Customer $customer, string $reason): void
    {
        $organization = $contact->store->organization;

        // Un client qui a déjà acheté n'entre pas dans le pipeline des prospects.
        $pipeline = ProspectPipeline::for($organization);
        if ($pipeline->enabled() && $customer->prospect_stage === null && (int) $customer->purchases_count === 0) {
            $pipeline->start($customer);
        }

        $open = Task::whereNull('done_at')->where('contact_id', $contact->id)->where('kind', 'hot_prospect')->exists();
        // Un RDV déjà pris n'a pas besoin d'un rappel de l'équipe.
        if (! $open && $reason !== 'RDV à venir') {
            Task::open([
                'organization_id' => $organization->id,
                'store_id' => $contact->store_id,
                'customer_id' => $customer->id,
                'contact_id' => $contact->id,
                'assigned_user_id' => $contact->assigned_user_id ?? $customer->owner_user_id,
                'kind' => 'hot_prospect',
                'title' => 'Prospect chaud : '.$customer->name,
                'details' => $reason.'. À rappeler vite pour conclure.',
            ]);
        }
    }
}
