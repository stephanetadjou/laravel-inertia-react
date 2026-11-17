<?php

namespace Database\Seeders;

use App\Models\BookableResource;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\ProspectStageChange;
use App\Models\Role;
use App\Models\SalesTarget;
use App\Models\User;
use App\Modules\CarteDigitale\Models\DigitalCard;
use App\Modules\CarteDigitale\Services\DigitalCards;
use App\Modules\Location\Services\RentalDesk;
use App\Services\CustomerLifecycle;
use App\Services\ProspectPipeline;
use Illuminate\Support\Carbon;

/**
 * Pipeline commercial de la démo « Yamoo Drive » (appelé par YamooDriveDemoSeeder) :
 * étapes du métier, 2 à 3 commerciaux par agence avec objectifs et cartes
 * digitales, ~40 prospects (pub WhatsApp, carte digitale, site, recommandation,
 * salon auto) reliés aux demandes du module Location, ~25 clients avec un
 * historique (nouveaux, fidèles, à risque, perdus). Données fictives.
 */
class YamooDrivePipelineSeeder
{
    public const STAGES = [
        ['key' => 'demande', 'label' => 'Demande reçue', 'kind' => 'a_contacter', 'delay_days' => 1],
        ['key' => 'devis', 'label' => 'Devis envoyé', 'kind' => 'en_discussion', 'delay_days' => 2],
        ['key' => 'essai', 'label' => 'Essai / visite', 'kind' => 'en_essai', 'delay_days' => 3],
        ['key' => 'gagne', 'label' => 'Gagné', 'kind' => 'gagne', 'delay_days' => 0],
        ['key' => 'perdu', 'label' => 'Perdu', 'kind' => 'perdu', 'delay_days' => 0],
    ];

    private const FIRST = ['Arnaud', 'Brice', 'Carine', 'Diane', 'Éric', 'Fabrice', 'Gisèle', 'Hervé', 'Inès', 'Joël', 'Karine', 'Landry', 'Mireille', 'Nadège', 'Olivier', 'Patrice', 'Rosine', 'Serge', 'Thierry', 'Valérie', 'Wilfried', 'Yannick', 'Sandrine', 'Christian'];

    private const LAST = ['Mbarga', 'Nkoulou', 'Fotso', 'Ngono', 'Tchoua', 'Kamga', 'Essomba', 'Ndjock', 'Atangana', 'Djoumessi', 'Mballa', 'Tagne', 'Owona', 'Nana', 'Ekambi', 'Bilong'];

    /** Besoins réalistes : [libellé, besoin, motif, catégorie, avec chauffeur, budget]. */
    private const NEEDS = [
        ['Location VIP avec chauffeur (arrivée aéroport)', 'rent', 'diaspora', 'berline', true, 450000],
        ['Cortège de mariage, 3 berlines avec chauffeurs', 'rent', 'event', 'berline', true, 900000],
        ['Minibus pour un séminaire d’entreprise', 'rent', 'event', 'minibus', true, 600000],
        ['Location longue durée, 2 pick-up pour une société', 'rent', 'business', '4x4', false, 2400000],
        ['Achat d’un SUV d’occasion', 'buy', 'personal', 'suv', false, 15000000],
        ['Achat d’un 4x4 pour la flotte', 'buy', 'business', '4x4', false, 19500000],
        ['SUV pour un week-end à Kribi', 'rent', 'tourism', 'suv', false, 200000],
        ['Obsèques : minibus et berline pour la famille', 'rent', 'event', 'minibus', true, 500000],
    ];

    /** Source de la fiche => source de la demande Location. */
    private const SOURCES = ['facebook_ad' => 'whatsapp', 'carte' => 'agency', 'site' => 'other', 'recommandation' => 'referral', 'salon' => 'agency'];

    private int $seq = 0;

    public function run(Organization $organization, array $stores, User $manager, string $password): array
    {
        // Suivi des prospects avec les étapes du métier.
        $organization->setModules([...$organization->enabledModules(), 'prospects'], ['prospects' => ['stages' => self::STAGES]]);
        $organization->save();
        $pipeline = ProspectPipeline::for($organization->fresh());
        $salesRole = Role::ensureSalesRole($organization);

        // Commerciaux : 3 à Douala, 2 à Yaoundé, 2 à Bafoussam ; objectifs du mois et carte digitale.
        $sellers = [];
        foreach (['Douala' => ['Steve Ekotto', 'Linda Moukoko', 'Junior Eyango'], 'Yaoundé' => ['Prisca Abena', 'Franck Onana'], 'Bafoussam' => ['Aline Tchinda', 'Roger Kenfack']] as $city => $names) {
            foreach ($names as $i => $name) {
                $user = User::create(['name' => $name, 'email' => 'commercial'.(count($sellers) + 1).'@yamoo-drive.test', 'password' => $password,
                    'organization_id' => $organization->id, 'store_id' => $stores[$city]->id, 'role_id' => $salesRole->id]);
                SalesTarget::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'month' => now()->format('Y-m'),
                    'visits' => 20, 'demos' => 10, 'trials' => 5, 'customers' => $city === 'Douala' ? 4 : 3, 'revenue' => $city === 'Douala' ? 3000000 : 2000000]);
                $card = DigitalCard::create([
                    'organization_id' => $organization->id, 'store_id' => $stores[$city]->id, 'user_id' => $user->id, 'slug' => DigitalCard::uniqueSlug($name),
                    'name' => $name, 'job_title' => 'Conseiller location et vente', 'company' => 'Yamoo Drive '.$city,
                    'phone_e164' => '23769'.str_pad((string) (1000000 + count($sellers) * 1111), 7, '0', STR_PAD_LEFT), 'whatsapp_e164' => '23769'.str_pad((string) (1000000 + count($sellers) * 1111), 7, '0', STR_PAD_LEFT),
                    'color' => $i % 2 ? '#2B1A3D' : '#E8590C',
                ]);
                foreach (['view' => 30 + $i * 9, 'scan' => 14 + $i * 4, 'whatsapp' => 8 + $i * 2, 'call' => 3 + $i, 'vcard' => 4, 'booking' => 2] as $type => $count) {
                    foreach (range(1, $count) as $n) {
                        $card->events()->create(['type' => $type, 'created_at' => now()->subDays(($n * 3 + $i) % 60)->subHours($n % 9)]);
                    }
                }
                // Réseaux sociaux sur les cartes de Douala (démo).
                if ($city === 'Douala') {
                    $card->update(['socials' => array_filter([
                        'facebook' => 'https://www.facebook.com/yamoodrive',
                        'instagram' => 'https://www.instagram.com/yamoodrive',
                        'tiktok' => $i === 0 ? 'https://www.tiktok.com/@yamoodrive' : null,
                        'linkedin' => $i === 1 ? 'https://www.linkedin.com/company/yamoo-drive' : null,
                    ])]);
                    foreach (range(1, 6 + $i * 3) as $n) {
                        $card->events()->create(['type' => 'social', 'created_at' => now()->subDays($n * 4)]);
                    }
                }
                $sellers[] = ['user' => $user, 'city' => $city, 'card' => $card];
            }
        }

        $vehicles = BookableResource::where('organization_id', $organization->id)->where('kind', 'vehicule')->get();
        $desk = app(RentalDesk::class);

        // ~40 prospects répartis dans les étapes : [étape, jours dans l'étape, nombre].
        // Délais : demande 1 j, devis 2 j, essai 3 j : une partie est due aujourd'hui, une autre en retard.
        $plan = [['demande', 0, 4], ['demande', 1, 3], ['demande', 3, 3], ['devis', 1, 3], ['devis', 2, 3], ['devis', 5, 3], ['essai', 2, 2], ['essai', 3, 2], ['essai', 6, 2],
            ['gagne', 4, 6], ['perdu', 9, 5]];
        $sources = array_keys(self::SOURCES);
        $lostReasons = ['price' => 'Trop cher pour le budget', 'competitor' => 'Parti chez un concurrent', 'cancelled' => 'Événement annulé', 'no_answer' => 'Sans réponse après 3 relances', 'unavailable' => 'Aucun véhicule libre à la date'];
        $counts = ['prospects' => 0, 'won' => 0, 'card' => 0];

        foreach ($plan as [$stageKey, $days, $count]) {
            foreach (range(1, $count) as $n) {
                $seller = $sellers[$this->seq % count($sellers)];
                $source = $sources[$this->seq % count($sources)];
                $need = self::NEEDS[$this->seq % count(self::NEEDS)];
                $name = $this->name();
                $phone = '2376'.str_pad((string) (80000000 + $this->seq * 2711), 8, '0', STR_PAD_LEFT);

                if ($source === 'carte') {
                    // Contact laissé sur la carte du commercial : fiche, prospect et tâche, comme en vrai.
                    $customer = DigitalCards::receiveContact($seller['card'], $name, $phone, $need[0], true)->customer;
                    $counts['card']++;
                } else {
                    $customer = Contact::create(['store_id' => $stores[$seller['city']]->id, 'name' => $name, 'phone_e164' => $phone, 'consent_status' => 'opt_in', 'consent_source' => 'manuel'])->customer;
                    $customer->forceFill(['acquisition_source' => $source, 'acquired_at' => now()->subDays($days + 2), 'owner_user_id' => $seller['user']->id])->save();
                    $pipeline->start($customer, $seller['user']);
                }

                // Demande du module Location, cohérente avec l'étape du prospect.
                $vehicle = $vehicles->first(fn ($v) => $v->category === $need[3] && $v->store_id === $stores[$seller['city']]->id) ?? $vehicles->firstWhere('category', $need[3]);
                $start = now()->addDays(5 + $this->seq % 20)->setTime(8, 0);
                $request = $desk->createRequest($seller['user'], [
                    'store_id' => $stores[$seller['city']]->id, 'customer_id' => $customer->id, 'need' => $need[1], 'purpose' => $need[2], 'category' => $need[3],
                    'resource_id' => $vehicle?->id, 'with_driver' => $need[4], 'budget' => $need[5], 'source' => self::SOURCES[$source], 'notes' => $need[0],
                    'starts_at' => $need[1] === 'rent' ? $start : null, 'ends_at' => $need[1] === 'rent' ? $start->copy()->addDays(2 + $this->seq % 5) : null,
                ]);
                $request->forceFill(['created_at' => now()->subDays($days + 1)])->save();

                $by = $seller['user'];
                if (in_array($stageKey, ['devis', 'essai'], true) && $vehicle !== null) {
                    $desk->quote($request->fresh(), $by, ['resource_id' => $vehicle->id, 'deposit' => $need[1] === 'rent' ? 200000 : 1000000, 'driver_rate' => $need[4] ? 20000 : null]);
                    $request->forceFill(['quoted_at' => now()->subDays($days)])->save();
                }
                if ($stageKey === 'gagne') {
                    // Gagné ce mois-ci : première vente, la fiche passe d'elle-même à « Gagné ».
                    $pipeline->move($customer, 'devis', $by);
                    $customer->contacts()->first()->purchases()->create(['store_id' => $stores[$seller['city']]->id, 'amount' => $need[1] === 'buy' ? (int) ($need[5] * 0.95) : $need[5],
                        'purchased_at' => now()->subDays($days)->toDateString(), 'payment_method' => 'orange_money']);
                    $pipeline->onFirstSale($customer->fresh());
                    $request->forceFill(['status' => 'won', 'closed_at' => now()->subDays($days)])->save();
                    $counts['won']++;
                } elseif ($stageKey === 'perdu') {
                    $reason = array_keys($lostReasons)[$this->seq % count($lostReasons)];
                    $pipeline->move($customer, 'perdu', $by, $lostReasons[$reason]);
                    $desk->lose($request->fresh(), $by, $reason);
                } elseif ($stageKey !== 'demande') {
                    $pipeline->move($customer, $stageKey, $by);
                }

                // Ancienneté dans l'étape : relances dues aujourd'hui ou en retard.
                $since = now()->subDays($days)->subHours(1);
                $customer->forceFill(['prospect_stage_at' => $since])->saveQuietly();
                ProspectStageChange::where('customer_id', $customer->id)->latest('id')->first()?->forceFill(['changed_at' => $since])->save();

                $counts['prospects']++;
                $this->seq++;
            }
        }

        $counts['clients'] = $this->clients($stores, $sellers);

        return $counts + ['sellers' => count($sellers)];
    }

    /** Clients avec un historique : nouveaux, fidèles, à risque, perdus (montants en XAF). */
    private function clients(array $stores, array $sellers): int
    {
        $profiles = [
            // [nombre, ventes : liste de jours dans le passé, montant moyen]
            ['new', 4, [[12]], 180000],
            ['loyal', 7, [[8, 45, 90, 150, 240]], 320000],
            ['risk', 5, [[130, 260]], 250000],
            ['lost', 3, [[420, 560]], 150000],
        ];
        $created = 0;
        CustomerLifecycle::batch(function () use ($profiles, $stores, $sellers, &$created) {
            foreach ($profiles as [$profile, $count, [$sales], $amount]) {
                foreach (range(1, $count) as $n) {
                    $seller = $sellers[$this->seq % count($sellers)];
                    $contact = Contact::create(['store_id' => $stores[$seller['city']]->id, 'name' => $this->name(), 'consent_status' => 'opt_in', 'consent_source' => 'manuel',
                        'phone_e164' => '2376'.str_pad((string) (85000000 + $this->seq * 1931), 8, '0', STR_PAD_LEFT)]);
                    foreach ($sales as $k => $daysAgo) {
                        $contact->purchases()->create(['store_id' => $contact->store_id, 'amount' => (int) round($amount * (0.7 + (($this->seq + $k) % 7) / 10), -3),
                            'purchased_at' => Carbon::today()->subDays($daysAgo + $n)->toDateString(), 'payment_method' => $k % 2 ? 'mtn_momo' : 'orange_money']);
                    }
                    $contact->customer?->forceFill(['acquisition_source' => ['recommandation', 'facebook_ad', 'site', 'salon'][$this->seq % 4], 'owner_user_id' => $seller['user']->id])->saveQuietly();
                    $created++;
                    $this->seq++;
                }
            }
        });

        return $created;
    }

    private function name(): string
    {
        return self::FIRST[($this->seq * 7) % count(self::FIRST)].' '.self::LAST[($this->seq * 5 + intdiv($this->seq, 16)) % count(self::LAST)];
    }
}
