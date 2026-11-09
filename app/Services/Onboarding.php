<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\CustomerFollowUp;
use App\Models\GroupClass;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\StaffMember;
use App\Models\Store;

/**
 * « Pour bien démarrer » : les premières étapes d'une entreprise, selon ses
 * modules, cochées toutes seules quand la donnée existe. Menentia se met en
 * place sans l'équipe technique ; chaque étape mène à l'écran qui la fait.
 */
class Onboarding
{
    public function __construct(private Organization $organization) {}

    /**
     * @return list<array{key: string, label: string, help: string, url: string, done: bool}>
     */
    public function steps(): array
    {
        $org = $this->organization;
        $id = $org->id;
        $storeIds = Store::where('organization_id', $id)->pluck('id');
        $has = fn (string $module) => $org->hasModule($module);
        $words = $org->hasModule('beneficiaires') ? mb_strtolower($org->beneficiaryLabel(true)) : 'clients';
        $steps = [];

        // Secteur NENTIX : le choix du secteur suffit, ses modules sont déjà en place.
        if ($org->sector_preset_applied_at === null) {
            $steps[] = ['key' => 'modules', 'label' => 'Vérifier vos modules', 'help' => 'Cochez ce que vous utilisez (rendez-vous, cours, abonnements…) : le menu s’adapte.', 'url' => '/settings/modules',
                'done' => ! empty($org->modules['reviewed'])];
        }

        // Secteurs NENTIX : WhatsApp d'abord (fiche auto, source de la pub, réponses automatiques).
        if ($org->sector_preset_applied_at !== null || $has('reponses_auto')) {
            $steps[] = ['key' => 'whatsapp', 'label' => 'Connecter WhatsApp', 'help' => 'Le numéro de votre établissement : chaque client qui écrit a sa fiche, avec la pub qui l’a fait venir.', 'url' => $org->usesWhatsAppApi() ? '/settings/whatsapp' : '/organisation',
                'done' => $org->usesWhatsAppApi() && Store::where('organization_id', $id)->whereNotNull('whatsapp_phone_number_id')->exists()];
        }
        if ($has('reponses_auto')) {
            $steps[] = ['key' => 'auto_replies', 'label' => 'Vérifier les réponses automatiques', 'help' => 'Accueil, hors horaires et menu à boutons : relisez les textes préparés pour votre secteur.', 'url' => '/settings/auto-replies',
                'done' => ! empty($org->auto_replies['welcome'])];
        }
        if ($has('prospects') && $org->sector_preset_applied_at !== null) {
            $steps[] = ['key' => 'pipeline', 'label' => 'Suivre vos premiers prospects', 'help' => 'Les étapes de votre secteur sont prêtes (renommables dans Paramètres › Prospects) : faites glisser chaque prospect d’une étape à l’autre.', 'url' => '/prospects',
                'done' => Customer::where('organization_id', $id)->whereNotNull('prospect_stage')->exists()];
        }

        if ($has('lieux')) {
            $steps[] = ['key' => 'places', 'label' => 'Ajouter vos lieux', 'help' => 'Chaque établissement, terrain ou point de vente où vous recevez vos clients.', 'url' => '/stores', 'done' => $storeIds->count() > 1];
        }
        if ($has('rdv') || $has('seances_groupe')) {
            $steps[] = ['key' => 'staff', 'label' => $has('seances_groupe') ? 'Ajouter vos coachs' : 'Ajouter vos intervenants', 'help' => 'Les personnes qui reçoivent les clients ou animent les cours.', 'url' => '/settings/staff',
                'done' => StaffMember::where('organization_id', $id)->exists()];
        }
        if ($has('catalogue')) {
            $steps[] = ['key' => 'catalog', 'label' => $has('seances_groupe') ? 'Lister vos activités' : 'Créer votre catalogue', 'help' => 'Vos prestations, activités et produits, avec leur prix.', 'url' => '/catalog',
                'done' => CatalogItem::where('organization_id', $id)->exists()];
        }
        if ($has('seances_groupe')) {
            $steps[] = ['key' => 'classes', 'label' => 'Créer vos cours', 'help' => 'Jour, heure, lieu, coach et nombre de places : les séances arrivent dans l’agenda.', 'url' => '/classes',
                'done' => GroupClass::where('organization_id', $id)->exists()];
        }
        if ($has('abonnements')) {
            $steps[] = ['key' => 'plans', 'label' => 'Créer vos formules', 'help' => 'Mensualités, saison, carte de séances, frais d’inscription et options.', 'url' => '/plans',
                'done' => Plan::where('organization_id', $id)->exists()];
        }
        if ($has('rdv')) {
            $steps[] = ['key' => 'booking', 'label' => 'Ouvrir la réservation en ligne', 'help' => 'Vos horaires, puis un lien à partager pour que vos clients réservent seuls.', 'url' => '/settings/booking',
                'done' => $org->bookingSettings()['enabled']];
        }

        $steps[] = ['key' => 'customers', 'label' => "Importer vos {$words}", 'help' => 'Un fichier Excel suffit : téléchargez le modèle, remplissez-le, déposez-le.', 'url' => '/imports',
            'done' => Customer::where('organization_id', $id)->count() >= 5];

        if ($org->sellsProducts()) {
            $steps[] = ['key' => 'sales', 'label' => 'Importer vos ventes', 'help' => 'L’export de votre caisse : chaque vente rejoint la bonne fiche client.', 'url' => '/sales-imports',
                'done' => Purchase::whereIn('store_id', $storeIds)->exists()];
        }
        if ($has('fidelite')) {
            $steps[] = ['key' => 'loyalty', 'label' => 'Lancer la fidélité', 'help' => 'Des points à chaque achat, des paliers et des récompenses.', 'url' => '/settings/loyalty', 'done' => $org->loyaltySettings()['enabled']];
        }

        $steps[] = ['key' => 'report', 'label' => 'Recevoir le rapport du soir', 'help' => 'Chaque soir par email : la journée, les impayés, les clients à relancer.', 'url' => '/settings/reports', 'done' => $org->reportSettings()['enabled']];
        $steps[] = ['key' => 'follow_up', 'label' => 'Faire votre première relance', 'help' => '« À faire » prépare le message WhatsApp : un clic l’ouvre sur votre téléphone.', 'url' => '/actions',
            'done' => CustomerFollowUp::whereIn('store_id', $storeIds)->exists()];

        return $steps;
    }

    /** null quand la liste est masquée (terminée ou fermée par l'entreprise). */
    public function forDashboard(): ?array
    {
        if ($this->organization->onboarding_dismissed_at !== null) {
            return null;
        }

        $steps = $this->steps();
        $done = count(array_filter($steps, fn ($s) => $s['done']));

        return $done === count($steps) ? null : ['steps' => $steps, 'done' => $done, 'total' => count($steps)];
    }
}
