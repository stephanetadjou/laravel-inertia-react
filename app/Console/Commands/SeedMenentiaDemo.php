<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Organization;
use App\Services\SalesTeam;
use Database\Seeders\MenentiaDemoSeeder;
use Illuminate\Console\Command;

/**
 * Charge (ou recharge) l'entreprise fictive « Menentia Solutions digitales »
 * (suivi des prospects et des commerciaux). Seule cette entreprise est
 * supprimée puis recréée.
 */
class SeedMenentiaDemo extends Command
{
    protected $signature = 'demo:menentia {--force : Autoriser l\'exécution en production}';

    protected $description = 'Charge l’entreprise fictive « Menentia Solutions digitales » (prospects, commerciaux)';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Données fictives : refusé en production sans --force.');

            return self::FAILURE;
        }

        $this->callSilent('db:seed', ['--class' => MenentiaDemoSeeder::class, '--force' => true]);
        $organization = Organization::where('slug', MenentiaDemoSeeder::SLUG)->firstOrFail();

        $this->table(['Étape', 'Fiches'], Customer::where('organization_id', $organization->id)->whereNotNull('prospect_stage')
            ->selectRaw('prospect_stage, count(*) as n')->groupBy('prospect_stage')->get()->map(fn ($r) => [$r->prospect_stage, $r->n])->all());
        $this->line('Primes du mois : '.number_format(collect((new SalesTeam($organization))->commissions())->sum('amount'), 0, ',', ' ').' FCFA.');
        $this->line('Connexion : directeur@demo-menentia.test, commercial1@demo-menentia.test, commercial2@demo-menentia.test.');
        $this->line('Mot de passe : variable DEMO_PASSWORD, sinon '.MenentiaDemoSeeder::DEFAULT_PASSWORD.'.');

        return self::SUCCESS;
    }
}
