<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActionLists;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Rappel du matin à chaque commercial (module « prospects ») : ses
 * prospects à relancer aujourd'hui, avec le lien WhatsApp prérempli, par
 * email (sans API WhatsApp, l'envoi reste manuel depuis son téléphone).
 */
class SendSalesMorningReminders extends Command
{
    protected $signature = 'crm:sales-morning';

    protected $description = 'Envoie à chaque commercial ses relances de prospects du jour';

    public function handle(): int
    {
        $sent = 0;

        Organization::whereNotNull('modules')->get()
            ->filter(fn (Organization $o) => $o->hasModule('prospects') && ! $o->isSuspended())
            ->each(function (Organization $organization) use (&$sent) {
                foreach (User::where('organization_id', $organization->id)->whereNotNull('email')->get() as $user) {
                    $owned = Customer::where('organization_id', $organization->id)->where('owner_user_id', $user->id)->pluck('id')->flip();
                    $rows = collect((new ActionLists($user))->rows('prospect_followup'))->filter(fn ($r) => isset($owned[$r['customer_id']]))->values();

                    if ($rows->isEmpty()) {
                        continue;
                    }

                    Mail::html(view('reports.sales-morning', ['user' => $user, 'organization' => $organization, 'rows' => $rows])->render(), function ($message) use ($user, $rows) {
                        $message->to($user->email)->subject("Vos {$rows->count()} relance(s) du jour · Menentia");
                    });
                    $sent++;
                }
            });

        $this->info("{$sent} rappel(s) envoyé(s).");

        return self::SUCCESS;
    }
}
