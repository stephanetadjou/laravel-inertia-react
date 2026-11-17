<?php

namespace App\Modules\Tunnels\Services;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Modules\Tunnels\Models\Funnel;
use App\Modules\Tunnels\Models\FunnelLead;
use App\Services\ActionLists;
use App\Services\WhatsAppConversation;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Str;

/**
 * Tunnels de vente : quota par offre, modèles par secteur, statistiques,
 * séquence WhatsApp J0 / J+1 / J+3 (arrêtée dès l'achat ou au STOP).
 */
class Funnels
{
    /** Tunnels actifs permis par l'offre (null = illimité, 0 = aucun). */
    public static function limit(Organization $organization): ?int
    {
        $pack = ModuleRegistry::pack($organization->pack());

        return array_key_exists('funnels', $pack) ? $pack['funnels'] : 1;
    }

    public static function canActivate(Organization $organization, ?Funnel $except = null): bool
    {
        $limit = self::limit($organization);

        return $limit === null || Funnel::where('organization_id', $organization->id)->where('is_active', true)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))->count() < $limit;
    }

    /**
     * Modèles prêts à l'emploi (Location d'abord, puis 1 ou 2 par secteur).
     *
     * @var array<string, array<string, mixed>>
     */
    public const TEMPLATES = [
        'mariage' => ['sector' => 'location', 'title' => 'Voitures de mariage', 'choice' => 'event', 'days' => 45, 'seats' => null, 'fields' => ['date', 'vehicles_count', 'message'],
            'offer' => 'Berlines VIP et minibus pour votre mariage, avec chauffeur en tenue, décoration offerte.',
            'benefits' => ['Chauffeurs en tenue et ponctuels', 'Décoration florale offerte', 'Devis en 1 heure sur WhatsApp'],
            'reviews' => [['name' => 'Mariée de juillet', 'text' => 'Cortège parfait, voitures impeccables.'], ['name' => 'Organisatrice de mariages', 'text' => 'Toujours à l’heure, je recommande.']],
            'message' => 'Bonjour, je souhaite un devis pour les voitures de mon mariage.'],
        'aeroport_diaspora' => ['sector' => 'location', 'title' => 'Transfert aéroport diaspora', 'choice' => 'rent', 'days' => 60, 'seats' => null, 'fields' => ['date', 'message'],
            'offer' => 'Accueil à l’aéroport et véhicule avec chauffeur pendant tout votre séjour.',
            'benefits' => ['Accueil avec pancarte à l’arrivée', 'Suivi de votre vol', 'Tarif séjour dégressif'],
            'reviews' => [['name' => 'Client de Paris', 'text' => 'Chauffeur à l’heure malgré le retard du vol.'], ['name' => 'Cliente de Bruxelles', 'text' => 'Séjour serein, tout était prévu.']],
            'message' => 'Bonjour, j’arrive bientôt et je souhaite un transfert depuis l’aéroport.'],
        'week_end' => ['sector' => 'location', 'title' => 'Location week-end', 'choice' => 'rent', 'days' => 14, 'seats' => 10, 'fields' => ['date', 'message'],
            'offer' => 'Du vendredi soir au lundi matin : le 3e jour offert.',
            'benefits' => ['3e jour offert', 'Véhicules récents et climatisés', 'Réservation en 2 minutes'],
            'reviews' => [['name' => 'Client fidèle', 'text' => 'Simple, rapide, voiture très propre.'], ['name' => 'Famille de Yaoundé', 'text' => 'Parfait pour notre week-end à Kribi.']],
            'message' => 'Bonjour, je souhaite réserver un véhicule pour le week-end.'],
        'vente_flash' => ['sector' => 'location', 'title' => 'Vente flash d’un véhicule', 'choice' => 'test_drive', 'days' => 10, 'seats' => null, 'fields' => ['vehicle', 'date', 'message'],
            'offer' => 'Un véhicule entretenu chez nous, prix spécial pendant 10 jours, paiement en 3 fois.',
            'benefits' => ['Historique d’entretien complet', 'Paiement en 3 fois', 'Reprise de votre véhicule étudiée'],
            'reviews' => [['name' => 'Acheteur satisfait', 'text' => 'Véhicule conforme à l’annonce.'], ['name' => 'Client entreprise', 'text' => 'Paiement en plusieurs fois très pratique.']],
            'message' => 'Bonjour, je suis intéressé(e) par le véhicule en vente flash.'],
        'premiere_visite' => ['sector' => 'beaute', 'title' => 'Première visite -20 %', 'choice' => 'rdv', 'days' => 30, 'seats' => null, 'fields' => ['date', 'message'],
            'offer' => '-20 % sur votre première prestation, sur rendez-vous.',
            'benefits' => ['-20 % dès la première visite', 'Diagnostic offert', 'Rappel la veille sur WhatsApp'],
            'reviews' => [['name' => 'Cliente du quartier', 'text' => 'Accueil chaleureux, résultat superbe.'], ['name' => 'Nouvelle cliente', 'text' => 'Je reviens chaque mois maintenant.']],
            'message' => 'Bonjour, je souhaite profiter de l’offre première visite.'],
        'fetes' => ['sector' => 'beaute', 'title' => 'Beauté des fêtes', 'choice' => 'rdv', 'days' => 45, 'seats' => 40, 'fields' => ['date', 'message'],
            'offer' => 'Créneaux du 20 au 31 décembre, réservés avec acompte. Forfaits fêtes coiffure + maquillage, et cartes cadeaux.',
            'benefits' => ['Forfaits fêtes coiffure + maquillage', 'Créneau garanti avec acompte', 'Carte cadeau à offrir'],
            'reviews' => [['name' => 'Cliente du réveillon', 'text' => 'Prête à temps pour le 24, magnifique.'], ['name' => 'Cliente fidèle', 'text' => 'J’ai offert une carte cadeau à ma mère, elle a adoré.']],
            'message' => 'Bonjour, je souhaite réserver un créneau pour les fêtes (entre le 20 et le 31 décembre).'],
        'essai_gratuit' => ['sector' => 'sport', 'title' => 'Séance d’essai gratuite', 'choice' => 'trial', 'days' => 30, 'seats' => 20, 'fields' => ['date'],
            'offer' => 'Venez essayer une séance gratuitement, sans engagement.',
            'benefits' => ['Séance offerte', 'Coach pour vous accueillir', 'Offre d’inscription réservée aux essais'],
            'reviews' => [['name' => 'Nouvelle adhérente', 'text' => 'Ambiance super, je me suis inscrite le jour même.'], ['name' => 'Adhérent depuis 1 an', 'text' => 'Coachs très à l’écoute.']],
            'message' => 'Bonjour, je souhaite réserver ma séance d’essai gratuite.'],
        'inscriptions' => ['sector' => 'formation', 'title' => 'Inscriptions : places limitées', 'choice' => 'trial', 'days' => 21, 'seats' => 15, 'fields' => ['date', 'message'],
            'offer' => 'Prochaine session : réservez votre place, paiement en plusieurs fois.',
            'benefits' => ['Places limitées par session', 'Paiement en plusieurs fois', 'Séance d’information offerte'],
            'reviews' => [['name' => 'Ancien élève', 'text' => 'Formation claire et formateurs disponibles.'], ['name' => 'Parent d’élève', 'text' => 'Suivi régulier, très rassurant.']],
            'message' => 'Bonjour, je souhaite réserver une place pour la prochaine session.'],
        'diagnostic' => ['sector' => 'pro', 'title' => 'Diagnostic offert', 'choice' => 'quote', 'days' => 30, 'seats' => 10, 'fields' => ['message'],
            'offer' => 'Un premier diagnostic offert pour faire le point sur votre besoin.',
            'benefits' => ['Diagnostic gratuit et sans engagement', 'Proposition chiffrée sous 48 h', 'Devis accepté en un clic sur WhatsApp'],
            'reviews' => [['name' => 'Dirigeant de PME', 'text' => 'Diagnostic précis, mise en place rapide.'], ['name' => 'Commerçante', 'text' => 'Enfin un conseil clair.']],
            'message' => 'Bonjour, je souhaite mon diagnostic offert.'],
        'visites' => ['sector' => 'immobilier', 'title' => 'Visites groupées', 'choice' => 'visit', 'days' => 14, 'seats' => 12, 'fields' => ['date', 'message'],
            'offer' => 'Une journée de visites groupées : inscrivez-vous pour choisir votre créneau.',
            'benefits' => ['Plusieurs biens en une journée', 'Conseiller dédié', 'Dossier préparé sur place'],
            'reviews' => [['name' => 'Locataire', 'text' => 'J’ai trouvé mon appartement en une matinée.'], ['name' => 'Acheteur', 'text' => 'Organisation impeccable.']],
            'message' => 'Bonjour, je souhaite m’inscrire aux visites groupées.'],
        'lunettes_3_fois' => ['sector' => 'optique', 'title' => 'Lunettes en 3 fois', 'choice' => 'quote', 'days' => 30, 'seats' => null, 'fields' => ['date', 'message'],
            'offer' => 'Examen de vue et lunettes payables en 3 fois sans frais.',
            'benefits' => ['Paiement en 3 fois', 'Examen de vue sur rendez-vous', 'Montures pour toute la famille'],
            'reviews' => [['name' => 'Patient', 'text' => 'Le paiement en 3 fois m’a bien aidé.'], ['name' => 'Maman de 2 enfants', 'text' => 'Montures solides et jolies.']],
            'message' => 'Bonjour, je souhaite un devis pour des lunettes en 3 fois.'],
    ];

    /** Modèles du secteur d'abord, puis les autres. @return list<array<string, mixed>> */
    public static function templatesFor(Organization $organization): array
    {
        $sector = in_array($organization->sector, ['vehicules', 'location'], true) ? 'location' : ($organization->sector === 'sante' ? 'optique' : $organization->sector);

        return collect(self::TEMPLATES)->map(fn ($t, $k) => ['key' => $k, ...$t])
            ->sortBy(fn ($t) => $t['sector'] === $sector ? 0 : 1)->values()->all();
    }

    /** @param array<string, mixed> $overrides */
    public static function createFromTemplate(Organization $organization, string $key, array $overrides = []): Funnel
    {
        $t = self::TEMPLATES[$key] ?? self::TEMPLATES['diagnostic'];
        $base = Str::slug($overrides['title'] ?? $t['title']) ?: 'offre';
        $slug = $base;
        for ($i = 2; Funnel::where('organization_id', $organization->id)->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return Funnel::create([
            'organization_id' => $organization->id, 'template' => $key, 'slug' => $slug,
            'title' => $t['title'], 'offer' => $t['offer'], 'benefits' => $t['benefits'], 'reviews' => $t['reviews'], 'fields' => $t['fields'],
            'choice' => $t['choice'], 'ends_at' => now()->addDays($t['days'])->endOfDay(), 'seats' => $t['seats'], 'message' => $t['message'],
            'is_active' => self::canActivate($organization),
            ...$overrides,
        ]);
    }

    /** @return array{visits: int, leads: int, trials: int, sales: int, revenue: int, cost_per_sale: ?int, by_source: array<string, int>} */
    public static function stats(Funnel $funnel): array
    {
        $leads = $funnel->leads()->with('request')->get();
        $sales = 0;
        $revenue = 0;
        $trials = 0;

        foreach ($leads as $lead) {
            $amount = (int) Purchase::whereHas('contact', fn ($q) => $q->where('customer_id', $lead->customer_id))->where('purchased_at', '>=', $lead->created_at)->sum('amount');
            if ($amount > 0 || $lead->request?->status === 'won') {
                $sales++;
                $revenue += $amount;
            }
            if ($lead->request?->status === 'won' || Appointment::where('customer_id', $lead->customer_id)->where('created_at', '>=', $lead->created_at)->exists()) {
                $trials++;
            }
        }

        return [
            'visits' => $funnel->visits,
            'leads' => $leads->count(),
            'trials' => $trials,
            'sales' => $sales,
            'revenue' => $revenue,
            'cost_per_sale' => $funnel->budget && $sales ? (int) round($funnel->budget / $sales) : null,
            'by_source' => $leads->countBy('source')->all(),
        ];
    }

    /** Le client a acheté (demande réservée ou vendue, ou vente) : la séquence s'arrête. */
    public static function converted(FunnelLead $lead): bool
    {
        return $lead->request?->status === 'won'
            || Purchase::whereHas('contact', fn ($q) => $q->where('customer_id', $lead->customer_id))->where('purchased_at', '>=', $lead->created_at)->exists();
    }

    /**
     * Relances dues : J0 récapitulatif, J+1 rappel avec un avis, J+3 dernière chance.
     *
     * @return list<array{lead_id: int, funnel: string, customer: string, step: int, label: string, message: string, wa_url: ?string}>
     */
    public static function due(Organization $organization): array
    {
        $leads = FunnelLead::whereHas('funnel', fn ($q) => $q->where('organization_id', $organization->id))
            ->whereNull('stopped_at')->where('step', '<', 3)->with(['funnel.organization', 'customer', 'request'])->get();
        $items = [];

        foreach ($leads as $lead) {
            if (self::stopIfNeeded($lead)) {
                continue;
            }
            $dueAt = $lead->created_at->copy()->addDays([0, 1, 3][$lead->step]);
            if ($dueAt->isFuture()) {
                continue;
            }
            $message = self::message($lead);
            $phone = $lead->customer?->recipient()->phone_e164;
            $items[] = ['lead_id' => $lead->id, 'funnel' => $lead->funnel->title, 'customer' => (string) $lead->customer?->name, 'step' => $lead->step,
                'label' => ['Récapitulatif (J0)', 'Rappel avec un avis (J+1)', 'Dernière chance (J+3)'][$lead->step], 'message' => $message,
                'wa_url' => $phone ? ActionLists::whatsappUrl($phone, $message) : null];
        }

        return $items;
    }

    public static function message(FunnelLead $lead): string
    {
        $f = $lead->funnel;
        $first = ActionLists::firstNameOf((string) $lead->customer?->name);
        $review = $f->reviews[0]['text'] ?? null;
        $end = $f->ends_at ? ' jusqu’au '.$f->ends_at->locale('fr')->translatedFormat('j F') : '';

        return match ($lead->step) {
            0 => "Bonjour {$first}, merci pour votre demande « {$f->title} » ! {$f->offer} Nous revenons vers vous très vite. Détails : {$f->publicUrl()}",
            1 => "Bonjour {$first}, avez-vous pu réfléchir à « {$f->title} » ?".($review ? " Une cliente nous a dit : « {$review} »" : '')." L’offre est valable{$end}.",
            default => "Bonjour {$first}, dernière chance pour « {$f->title} »{$end}".($f->seatsLeft() !== null ? ' : plus que '.$f->seatsLeft().' place(s)' : '').'. Répondez OUI et on s’occupe de tout !',
        };
    }

    public static function markDone(FunnelLead $lead): void
    {
        $lead->update(['step' => min(3, $lead->step + 1)]);
    }

    /** Arrêt : achat, ou client qui a répondu STOP (plus d'accord WhatsApp). */
    public static function stopIfNeeded(FunnelLead $lead): bool
    {
        $reason = match (true) {
            self::converted($lead) => 'converted',
            Contact::where('customer_id', $lead->customer_id)->where('consent_status', 'opt_out')->exists() => 'stop',
            default => null,
        };
        if ($reason) {
            $lead->update(['stopped_at' => now(), 'stop_reason' => $reason]);
        }

        return $reason !== null;
    }

    /** Envoi automatique (offres payantes avec l'API, fenêtre de 24 h ouverte). */
    public static function sendAutomatic(Organization $organization): int
    {
        $sent = 0;
        foreach (self::due($organization) as $item) {
            $lead = FunnelLead::with('funnel', 'customer')->find($item['lead_id']);
            if (! $organization->automationAllowed() || ! $organization->usesWhatsAppApi()) {
                break;
            }
            $contact = Contact::where('customer_id', $lead->customer_id)->where('consent_status', '!=', 'opt_out')->first();
            if ($contact && WhatsAppConversation::windowOpen($contact) && WhatsAppConversation::text($contact, $item['message'], 'auto')->status === 'sent') {
                self::markDone($lead);
                $sent++;
            }
        }

        return $sent;
    }
}
