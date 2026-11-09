<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\AutoReplies;
use App\Services\WhatsAppConversation;
use Illuminate\Console\Command;

/**
 * Rappel de la veille avec confirmation (module « rappels_rdv ») : pour
 * chaque rendez-vous de demain pas encore rappelé, un message WhatsApp avec
 * les boutons « Je confirme » / « Je décale ». Dans la fenêtre de 24 h :
 * message interactif ; sinon le modèle Meta choisi dans Paramètres ›
 * Réponses automatiques (boutons de réponse rapide). Sans modèle ni fenêtre
 * ouverte, le rappel reste dans « À faire » (lien wa.me), comme avant.
 * Messages utilitaires : aucun crédit consommé.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'crm:appointment-reminders {--organization= : ID d\'une seule organisation}';

    protected $description = 'Envoie le rappel WhatsApp de la veille avec boutons de confirmation';

    public function handle(): int
    {
        $sent = 0;

        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get()
            ->filter(fn (Organization $o) => $o->hasModule('rappels_rdv') && $o->usesWhatsAppApi());

        foreach ($organizations as $organization) {
            $settings = $organization->autoReplySettings();
            $tomorrow = now($organization->timezone ?: config('app.timezone'))->addDay();

            $appointments = Appointment::whereIn('store_id', $organization->stores()->pluck('id'))
                ->whereIn('status', ['requested', 'planned'])
                ->whereNull('reminder_sent_at')
                ->whereBetween('starts_at', [$tomorrow->copy()->startOfDay()->setTimezone(config('app.timezone')), $tomorrow->copy()->endOfDay()->setTimezone(config('app.timezone'))])
                ->with(['store', 'customer'])
                ->get();

            foreach ($appointments as $appointment) {
                $contact = Contact::where('store_id', $appointment->store_id)->where('customer_id', $appointment->customer_id)
                    ->where('consent_status', '!=', 'opt_out')->with('store.organization', 'customer')->first();

                if ($contact === null) {
                    continue;
                }

                $bot = new AutoReplies($contact);
                $body = $bot->fill($settings['reminder'], $appointment);
                $buttons = [AutoReplies::CONFIRM.$appointment->id => 'Je confirme', AutoReplies::RESCHEDULE.$appointment->id => 'Je décale'];

                if (WhatsAppConversation::windowOpen($contact)) {
                    $message = WhatsAppConversation::interactive($contact, WhatsAppConversation::menu($body, $buttons), 'reminder');
                } elseif (filled($settings['reminder_template'])) {
                    $first = $bot->fill('{prenom}');
                    $message = WhatsAppConversation::template($contact, $settings['reminder_template'], $settings['reminder_template_language'] ?: 'fr',
                        [$first, $appointment->starts_at->translatedFormat('l j F'), $appointment->starts_at->format('H\hi')], array_keys($buttons), 'reminder', null, $body);
                } else {
                    continue;
                }

                if ($message->status === 'sent') {
                    $appointment->forceFill(['reminder_sent_at' => now()])->saveQuietly();
                    $sent++;
                }
            }
        }

        $this->info("{$sent} rappel(s) envoyé(s).");

        return self::SUCCESS;
    }
}
