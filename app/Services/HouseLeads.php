<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Task;

/**
 * Demandes de démo du site vitrine dans le CRM de ClientlyX lui-même
 * (« nous sommes notre propre premier client ») : l'entreprise maison
 * (services.menentia.house_org, Mercure Solutions par défaut) reçoit une fiche
 * prospect avec la source « site », le besoin, l'étape de prospection et une
 * tâche dans « À faire ». Sans entreprise maison configurée, rien ne se passe :
 * la demande reste dans la table leads (back-office).
 */
class HouseLeads
{
    public static function organization(): ?Organization
    {
        $slug = (string) config('services.menentia.house_org');

        return $slug === '' ? null : Organization::where('slug', $slug)->first();
    }

    public static function record(Lead $lead): void
    {
        $organization = self::organization();
        $store = $organization ? Store::where('organization_id', $organization->id)->orderBy('id')->first() : null;

        if ($store === null || $lead->whatsapp === null) {
            return;
        }

        $contact = Contact::where('store_id', $store->id)->where('phone_e164', $lead->whatsapp)->first()
            ?? Contact::create([
                'store_id' => $store->id,
                'phone_e164' => $lead->whatsapp,
                'name' => $lead->name,
                // Case cochée sur le formulaire : accord daté et sourcé.
                'consent_status' => 'opt_in',
                'consent_at' => $lead->consent_at ?? now(),
                'consent_source' => 'site_web',
            ]);

        $customer = $contact->fresh()->customer;
        if ($customer === null) {
            return;
        }

        $need = trim(implode(' · ', array_filter([
            'Démo demandée sur le site',
            $lead->company,
            $lead->activity ? (Organization::SECTORS[$lead->activity]['label'] ?? $lead->activity) : null,
            $lead->city,
        ])));

        $customer->forceFill([
            'acquisition_source' => $customer->acquisition_source ?? 'site',
            'acquisition_data' => $customer->acquisition_data ?? ['lead_id' => $lead->id],
            'acquired_at' => $customer->acquired_at ?? now(),
            'need' => mb_substr($need.($lead->message ? ' : '.$lead->message : ''), 0, 500),
            'need_kind' => 'demo',
            'need_at' => now(),
        ])->save();

        Task::open([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'contact_id' => $contact->id,
            'kind' => 'online_request',
            'title' => 'Démo demandée sur le site',
            'details' => $need.($lead->message ? "\n« ".mb_substr($lead->message, 0, 300).' »' : ''),
        ]);

        if ($organization->hasModule('prospects') && $customer->prospect_stage === null) {
            ProspectPipeline::for($organization)->start($customer);
        }
    }
}
