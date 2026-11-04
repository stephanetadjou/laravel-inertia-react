<?php

namespace App\Console\Commands;

use App\Mail\MonthlyReportMail;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\PdfReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Envoie les rapports PDF du mois écoulé aux entreprises qui l'ont demandé
 * (Paramètres › Rapports). Planifiée le 1er de chaque mois ; --month choisit
 * le mois, --force envoie même si l'envoi mensuel est désactivé.
 */
class SendMonthlyReports extends Command
{
    protected $signature = 'crm:monthly-report {--organization= : Identifiant d’une seule organisation} {--month= : mois du rapport (AAAA-MM)} {--force : envoyer même si l’envoi mensuel est désactivé}';

    protected $description = 'Envoie les rapports PDF mensuels par e-mail';

    public function handle(): int
    {
        $month = Carbon::parse(($this->option('month') ?: now()->subMonthNoOverflow()->format('Y-m')).'-01');
        $key = $month->format('Y-m');
        $period = $month->locale('fr')->isoFormat('MMMM YYYY');

        $organizations = Organization::query()->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($organizations as $organization) {
            $settings = $organization->reportSettings();

            if ((! $settings['monthly_enabled'] && ! $this->option('force')) || $settings['recipients'] === []) {
                continue;
            }

            // Rapport de l'entreprise entière : calculé avec le périmètre d'un gestionnaire.
            $user = User::where('organization_id', $organization->id)
                ->whereIn('role_id', Role::where('organization_id', $organization->id)->where('can_manage_organization', true)->where('scope', Role::SCOPE_ORGANIZATION)->select('id'))
                ->first();

            if (! $user) {
                $this->warn("{$organization->name} : aucun gestionnaire.");

                continue;
            }

            $report = new PdfReport($user);
            $files = ["rapport-direction-{$key}.pdf" => $report->monthly('direction', $key)->output()];

            if ($organization->hasModule('fidelite') && $organization->loyaltyPrograms()->exists()) {
                $files["rapport-fidelite-{$key}.pdf"] = $report->monthly('fidelite', $key)->output();
            }

            Mail::to($settings['recipients'])->send(new MonthlyReportMail($organization, $period, $files));
            $this->info("{$organization->name} : rapports de {$key} envoyés.");
        }

        return self::SUCCESS;
    }
}
