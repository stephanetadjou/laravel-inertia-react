<?php

namespace App\Console\Commands;

use App\Models\AdBudget;
use App\Models\Appointment;
use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppOutboundMessage;
use App\Services\Acquisition;
use App\Services\CustomerLifecycle;
use App\Services\ProspectPipeline;
use App\Support\SectorPresets;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Quatre entreprises fictives, une par secteur NENTIX (beauté, sport,
 * services pro, boutiques) : préréglage du secteur, prospects à chaque
 * étape, discussions WhatsApp (dont une « à traiter »), sources de pub,
 * budgets, rendez-vous, crédits. Rejouable : seules ces 4 entreprises de
 * démonstration sont supprimées puis recréées. Données fictives, jamais en
 * production sans --force.
 */
class SeedNentixSectorsDemo extends Command
{
    public const PASSWORD = 'Demo-Nentix-2026';

    protected $signature = 'demo:nentix-secteurs {--force : Autoriser l\'exécution en production}';

    protected $description = 'Crée 4 entreprises de démonstration NENTIX, une par secteur (données de test)';

    /** slug => [secteur, nom, établissement, email, prestations/produits, prospects] */
    private const COMPANIES = [
        'demo-nentix-beaute' => ['beaute', 'Salon Kalia Beauté', 'Kalia Louis', 'beaute@demo-nentix.test',
            [['Coupe femme', 8000, 45, 35], ['Tresses', 15000, 180, 60], ['Manucure', 6000, 40, 21], ['Soin visage', 12000, 60, 30]]],
        'demo-nentix-sport' => ['sport', 'Studio Fit Libreville', 'Fit Glass', 'sport@demo-nentix.test',
            [['Séance d’essai', 0, 60, null], ['Abonnement mensuel', 25000, 60, null], ['Coaching privé', 15000, 60, null]]],
        'demo-nentix-pro' => ['pro', 'Agence Nova Digital', 'Nova Bureau', 'pro@demo-nentix.test', []],
        'demo-nentix-boutique' => ['commerce', 'Boutique Élégance', 'Élégance Mont-Bouët', 'boutique@demo-nentix.test',
            [['Robe wax', 35000, null, null], ['Sac à main', 28000, null, null], ['Parfum 50 ml', 22000, null, null]]],
    ];

    private const NAMES = ['Awa Ndong', 'Inès Mba', 'Lia Obame', 'Paul Nze', 'Marc Ella', 'Sonia Moussavou', 'Yann Bekale', 'Grâce Nguema', 'Junior Mintsa', 'Rita Oyane', 'Chloé Assoumou', 'Kevin Ondo'];

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Données fictives : refusé en production sans --force.');

            return self::FAILURE;
        }

        $rows = [];
        foreach (self::COMPANIES as $slug => [$sector, $name, $storeName, $email, $catalog]) {
            $org = $this->company($slug, $sector, $name, $storeName, $email, $catalog);
            $rows[] = [Organization::SECTORS[$sector]['label'], $name, $email, $org->customers()->count(), Task::where('organization_id', $org->id)->whereNull('done_at')->count()];
        }

        $this->table(['Secteur', 'Entreprise', 'Connexion', 'Fiches', 'Demandes à traiter'], $rows);
        $this->line('Mot de passe de tous les comptes : '.self::PASSWORD);
        $this->line('Réponses automatiques : WHATSAPP_DRIVER=log dans .env pour tout simuler sans compte Meta.');

        return self::SUCCESS;
    }

    private function company(string $slug, string $sector, string $name, string $storeName, string $email, array $catalog): Organization
    {
        if ($previous = Organization::where('slug', $slug)->first()) {
            User::where('organization_id', $previous->id)->delete();
            $previous->delete();
        }

        $org = Organization::create([
            'name' => $name, 'slug' => $slug, 'sector' => $sector, 'whatsapp_api_enabled' => true,
            'status' => Organization::STATUS_TRIAL, 'trial_ends_at' => now()->addDays(30),
            'timezone' => 'Africa/Libreville', 'currency' => 'XAF',
        ]);
        Role::seedDefaultsFor($org);
        SectorPresets::apply($org);
        $org->refresh();
        $org->moveCredits(50000, 'Crédits offerts (démo)');

        $brand = Brand::create(['organization_id' => $org->id, 'name' => $name]);
        $store = Store::create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => $storeName,
            'whatsapp_phone_number_id' => 'DEMO'.Str::upper(Str::random(8)), 'whatsapp_access_token' => 'demo-token',
        ]);
        $owner = User::create(['name' => 'Gérant '.$name, 'email' => $email, 'password' => self::PASSWORD, 'organization_id' => $org->id,
            'role_id' => $org->roles()->where('name', 'Super administrateur')->value('id')]);
        if ($org->hasModule('prospects')) {
            User::create(['name' => 'Commercial '.$name, 'email' => str_replace('@', '.commercial@', $email), 'password' => self::PASSWORD, 'organization_id' => $org->id,
                'role_id' => Role::ensureSalesRole($org)->id]);
        }

        if ($org->hasAgenda()) {
            $org->update(['booking_settings' => [...$org->bookingSettings(), 'enabled' => true]]);
        }

        $items = collect($catalog)->map(fn ($c) => CatalogItem::create([
            'organization_id' => $org->id, 'type' => $c[2] === null && $sector === 'commerce' ? 'product' : 'service',
            'name' => $c[0], 'price' => $c[1], 'duration_minutes' => $c[2], 'active' => true, 'bookable_online' => $c[2] !== null,
            'return_after_days' => $c[3],
        ]));

        foreach (['facebook_ad' => 45000, 'instagram_ad' => 30000] as $source => $amount) {
            foreach ([0, 1, 2] as $back) {
                AdBudget::create(['organization_id' => $org->id, 'source' => $source, 'month' => now()->startOfMonth()->subMonths($back)->format('Y-m'), 'amount' => $amount]);
            }
        }

        $pipeline = ProspectPipeline::for($org);
        $stages = $pipeline->enabled() ? $pipeline->stages()->values() : collect();
        $sources = [['source_url' => 'https://fb.me/demo', 'source_type' => 'ad', 'headline' => 'Offre de bienvenue'], ['source_url' => 'https://www.instagram.com/p/demo', 'source_type' => 'ad', 'headline' => 'Nouveautés de la saison'], null];

        CustomerLifecycle::batch(function () use ($org, $store, $owner, $items, $stages, $pipeline, $sources, $sector) {
            foreach (self::NAMES as $i => $person) {
                $phone = '2417700'.str_pad((string) (1000 + $i + 100 * crc32($org->slug) % 9000), 5, '0', STR_PAD_LEFT);
                $contact = Contact::create(['store_id' => $store->id, 'phone_e164' => $phone, 'name' => $person, 'consent_status' => $i % 4 === 3 ? 'unknown' : 'opt_in']);
                $customer = $contact->customer;
                $customer->forceFill(['created_at' => now()->subDays(55 - $i * 5)])->saveQuietly();
                Acquisition::record($customer, $sources[$i % 3]);
                $customer->forceFill(['acquired_at' => now()->subDays(55 - $i * 5)])->saveQuietly();

                // Discussions : un premier message récent, la réponse du robot.
                $at = now()->subHours(2 + $i * 7);
                WhatsAppInboundMessage::create(['store_id' => $store->id, 'contact_id' => $contact->id, 'wa_message_id' => 'wamid.DEMO.'.$org->id.'.'.$i,
                    'from_phone' => $phone, 'type' => 'text', 'body' => ['Bonjour, c’est combien ?', 'Vous êtes ouverts samedi ?', 'Je voudrais un rendez-vous', 'Je veux parler à quelqu’un svp'][$i % 4], 'received_at' => $at, 'payload' => []]);
                WhatsAppOutboundMessage::create(['store_id' => $store->id, 'contact_id' => $contact->id, 'type' => 'interactive', 'origin' => 'auto',
                    'body' => 'Bonjour '.strtok($person, ' ').' 👋 Bienvenue chez '.$store->name.' ! Choisissez une option ci-dessous :', 'status' => 'sent', 'sent_at' => $at->copy()->addSecond()]);
                $contact->forceFill(['last_inbound_at' => $at, 'last_greeting_at' => $at])->saveQuietly();

                if ($i % 4 === 3) {
                    $contact->forceFill(['needs_attention' => true, 'bot_paused_until' => $at->copy()->addDay()])->saveQuietly();
                    Task::open(['organization_id' => $org->id, 'store_id' => $store->id, 'customer_id' => $customer->id, 'contact_id' => $contact->id,
                        'kind' => 'talk_to_human', 'title' => 'Parler à quelqu’un', 'details' => 'Le client demande un humain sur WhatsApp']);
                }

                // Prospects répartis sur les étapes du secteur.
                if ($stages->isNotEmpty()) {
                    $stage = $stages[$i % $stages->count()];
                    $pipeline->move($customer, $stage['key'], null, $stage['kind'] === 'perdu' ? 'Prix trop élevé' : null);
                    $customer->forceFill(['prospect_stage_at' => now()->subDays(($i % 5) + 1), 'owner_user_id' => $owner->id])->saveQuietly();
                }

                // Rendez-vous (secteurs avec agenda) et ventes.
                if ($org->hasAgenda() && $items->isNotEmpty()) {
                    $item = $items[$i % $items->count()];
                    $past = $i % 2 === 0;
                    $appointment = Appointment::create(['store_id' => $store->id, 'customer_id' => $customer->id,
                        'starts_at' => $past ? now()->subDays(20 + $i * 3)->setTime(10, 0) : now()->addDays(1 + $i % 3)->setTime(9 + $i % 8, 0),
                        'duration_minutes' => $item->duration_minutes ?: 60, 'service' => $item->name, 'kind' => $sector === 'sport' && $i % 3 === 0 ? 'essai' : 'rdv',
                        'status' => $past ? 'done' : 'planned']);
                    $appointment->items()->create(['catalog_item_id' => $item->id, 'name' => $item->name, 'price' => $item->price, 'duration_minutes' => (int) $item->duration_minutes]);
                    if ($past && $item->price > 0) {
                        $contact->purchases()->create(['store_id' => $store->id, 'amount' => $item->price, 'purchased_at' => $appointment->starts_at->toDateString()]);
                    }
                } elseif ($i % 3 !== 2) {
                    $amount = $items->isNotEmpty() ? $items[$i % $items->count()]->price : 250000;
                    foreach (range(0, $i % 3) as $n) {
                        $contact->purchases()->create(['store_id' => $store->id, 'amount' => $amount, 'purchased_at' => now()->subDays(10 + $n * 25 + $i)->toDateString()]);
                    }
                }
            }
        });

        return $org;
    }
}
