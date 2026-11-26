<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\Contact;
use App\Services\Assistant;
use App\Services\WhatsAppBooking;
use App\Support\Locale;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Relance de l'Assistant IA : un client qui s'est intéressé à une prestation
 * (créneaux proposés) sans réserver reçoit une seule relance, avant que la
 * fenêtre WhatsApp de 24 h ne se ferme (message libre, sans modèle Meta).
 */
class AssistantFollowUps extends Command
{
    protected $signature = 'assistant:relances';

    protected $description = 'Relance les prospects intéressés qui n’ont pas réservé (fenêtre WhatsApp de 24 h)';

    public function handle(): int
    {
        $sent = 0;

        Contact::query()
            ->whereNotNull('assistant_state')
            ->where('last_inbound_at', '>=', now()->subHours(23))
            ->where('last_inbound_at', '<=', now()->subHours(3))
            ->with('store.organization', 'customer')
            ->get()
            ->each(function (Contact $contact) use (&$sent) {
                if (self::shouldNudge($contact)) {
                    $sent += (int) self::nudge($contact);
                }
            });

        $this->info("{$sent} relance(s) envoyée(s).");

        return self::SUCCESS;
    }

    public static function shouldNudge(Contact $contact): bool
    {
        $state = $contact->assistant_state ?? [];
        $interest = $state['interest'] ?? null;

        if ($interest === null || isset($interest['nudged_at']) || ! Assistant::active($contact)) {
            return false;
        }
        if ($contact->bot_paused_until !== null && $contact->bot_paused_until->isFuture()) {
            return false;
        }

        // Au moins 3 h après la proposition, et le client n'a rien réservé depuis.
        return Carbon::parse($interest['at'])->lte(now()->subHours(3))
            && (new WhatsAppBooking($contact))->nextAppointment() === null;
    }

    private static function nudge(Contact $contact): bool
    {
        Locale::apply($contact->store->organization->locale ?? Locale::DEFAULT);

        $state = $contact->assistant_state;
        $service = CatalogItem::find($state['interest']['service_id'] ?? 0);
        $first = trim(explode(' ', (string) ($contact->customer?->name ?: $contact->name))[0] ?? '');
        $hello = $first !== '' && ! str_starts_with($first, 'Client') ? __('Bonjour :name', ['name' => $first]) : __('Bonjour');

        $proposed = $service !== null && WhatsAppBooking::available($contact) && (new WhatsAppBooking($contact))->proposeSlots(
            $service,
            $hello.' 👋 '.__('Vous pensiez à « :service » ? Il reste ces créneaux, je vous le réserve en un clic :', ['service' => $service->name]),
        );

        $state['interest']['nudged_at'] = now()->toIso8601String();
        $contact->forceFill(['assistant_state' => $state])->saveQuietly();

        return $proposed;
    }
}
