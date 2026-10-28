<?php

namespace App\Console\Commands;

use App\Mail\DailyReportMail;
use App\Models\Organization;
use App\Services\DailyReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Envoie le rapport quotidien des entreprises dont l'heure d'envoi est
 * passée et qui ne l'ont pas encore reçu aujourd'hui. Planifiée toutes les
 * 15 minutes (routes/console.php) ; --force renvoie, --date choisit le jour.
 */
class SendDailyReports extends Command
{
    protected $signature = 'crm:daily-report {--organization= : ID d\'une seule organisation} {--date= : jour du rapport (AAAA-MM-JJ)} {--force : envoyer même si déjà envoyé ou avant l\'heure}';

    protected $description = 'Envoie le rapport quotidien au dirigeant (email)';

    public function handle(): int
    {
        $day = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : today();
        $force = (bool) $this->option('force');

        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($organizations as $organization) {
            $settings = $organization->reportSettings();

            if (! $force && (! $settings['enabled'] || $settings['recipients'] === [] || $settings['last_sent_on'] === $day->toDateString()
                || now()->format('H:i') < $settings['send_at'])) {
                continue;
            }

            if ($settings['recipients'] === []) {
                $this->warn("{$organization->name} : aucun destinataire.");

                continue;
            }

            Mail::to($settings['recipients'])->send(new DailyReportMail($organization, (new DailyReport($organization, $day))->build()));
            $organization->update(['report_settings' => [...$settings, 'last_sent_on' => $day->toDateString()]]);
            $this->info("{$organization->name} : rapport du {$day->toDateString()} envoyé à ".implode(', ', $settings['recipients']).'.');
        }

        return self::SUCCESS;
    }
}
