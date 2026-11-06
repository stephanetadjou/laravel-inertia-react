<?php

namespace App\Models;

use App\Services\ActionLists;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'lifecycle_settings',
        'uses_shared_whatsapp_account',
        'legal_name',
        'privacy_email',
        'privacy_phone',
        'privacy_address',
        'privacy_extra',
        'whatsapp_api_enabled',
        'action_messages',
        'booking_settings',
        'report_settings',
        'loyalty_settings',
        'sector',
        'currency',
        'timezone',
        'product_analysis_level',
        'rfm_settings',
        'plan',
        'status',
        'trial_ends_at',
        'suspended_at',
        'suspension_reason',
        'modules',
        'onboarding_dismissed_at',
        'usage_mode',
        'history_import_closed_at',
        'customer_label',
    ];

    /**
     * Adresse publique de la politique de confidentialité : slug unique tiré
     * du nom, fixé à la création (il ne change pas si l'entreprise est
     * renommée, l'URL étant déjà déclarée chez Meta).
     */
    protected static function booted(): void
    {
        static::creating(function (Organization $organization) {
            if (blank($organization->slug)) {
                $organization->slug = static::uniqueSlug((string) $organization->name);
            }
        });
    }

    /**
     * Slug libre tiré du nom (« retail-group », sinon « retail-group-2 »…).
     * À calculer avant d'ouvrir une transaction : avec SQLite, une transaction
     * qui lit avant d'écrire échoue au lieu d'attendre si un autre processus
     * écrit au même moment (cf. config/database.php).
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'entreprise';
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** Durée de l'essai gratuit d'une nouvelle inscription. */
    public const TRIAL_DAYS = 14;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [
        self::STATUS_TRIAL => 'Essai',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_SUSPENDED => 'Suspendue',
    ];

    /** Offres et prix mensuels (FCFA, Gabon) du cahier de cadrage. */
    public const PLANS = [
        'solo' => ['label' => 'Solo', 'price' => 9900],
        'equipe' => ['label' => 'Équipe', 'price' => 25000],
        'premium' => ['label' => 'Premium', 'price' => 50000],
    ];

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Une seule offre Menentia (CRM des PME de prestations de services) ; le
     * commerce existant y garde le module « Analyse commerciale ».
     */
    public function offerLabel(): string
    {
        return $this->sector === 'commerce' ? 'Menentia · Analyse commerciale' : 'Menentia';
    }

    /** Secteurs proposés à l'inscription publique : le commerce n'y est plus (organisations existantes inchangées). */
    public const PUBLIC_SECTORS = ['beaute', 'sante', 'sport', 'technique', 'pro', 'autre'];

    public function privacyPolicyUrl(): string
    {
        return route('privacy-policy', $this->slug);
    }

    /**
     * Seuils par défaut du parcours client (modifiables dans Organisation).
     * Durées en jours ; VIP = top X % des acheteurs de chaque boutique (CA dans
     * la boutique sur la période), un client pouvant être VIP dans une seule.
     */
    public const LIFECYCLE_DEFAULTS = [
        'new_customer_days' => 90,
        'at_risk_days' => 90,
        'churn_days' => 180,
        'vip_top_percent' => 10,
        'vip_period_days' => 365,
    ];

    protected function casts(): array
    {
        return [
            'lifecycle_settings' => 'array',
            // Repli autorisé sur le compte Meta partagé (.env) : organisations
            // historiques uniquement, jamais une entreprise inscrite.
            'uses_shared_whatsapp_account' => 'boolean',
            // Envoi par l'API WhatsApp Cloud (campagnes, modèles, bienvenue, webhook).
            // Désactivé : le CRM fonctionne avec des relances manuelles par lien wa.me.
            'whatsapp_api_enabled' => 'boolean',
            'action_messages' => 'array',
            'booking_settings' => 'array',
            'report_settings' => 'array',
            'loyalty_settings' => 'array',
            'rfm_settings' => 'array',
            'modules' => 'array',
            'onboarding_dismissed_at' => 'datetime',
            'history_import_closed_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return array{new_customer_days: int, at_risk_days: int, churn_days: int, vip_top_percent: int, vip_period_days: int}
     */
    public function lifecycleSettings(): array
    {
        $saved = array_intersect_key($this->lifecycle_settings ?? [], self::LIFECYCLE_DEFAULTS);

        return array_map('intval', [...self::LIFECYCLE_DEFAULTS, ...$saved]);
    }

    /**
     * Secteurs proposés à l'inscription (mêmes familles que le site vitrine).
     * `modules` : modules cochés d'office pour le secteur (cf. MODULES),
     * `suggested` : modules proposés en plus, à cocher si besoin.
     *
     * @var array<string, array{label: string, description: string, modules: list<string>, suggested: list<string>}>
     */
    public const SECTORS = [
        'beaute' => [
            'label' => 'Beauté et soins',
            'description' => 'Salons, barbiers, onglerie, esthétique, spas : agenda et réservation en ligne, et un rappel quand une cliente dépasse son délai habituel.',
            'modules' => ['rdv', 'catalogue', 'fidelite'],
            'suggested' => ['produits'],
        ],
        'sante' => [
            'label' => 'Santé',
            'description' => 'Cabinets, cliniques, dentistes, optique, kiné : rendez-vous des patients, rappels de contrôle et suivi de chaque dossier client.',
            'modules' => ['rdv', 'catalogue'],
            'suggested' => ['beneficiaires'],
        ],
        'sport' => [
            'label' => 'Sport et bien-être',
            'description' => 'Clubs, écoles de sport, salles, danse, yoga : cours collectifs, abonnements et mensualités, présences, et les membres qui décrochent repérés à temps.',
            'modules' => ['seances_groupe', 'abonnements', 'tranches', 'catalogue', 'fidelite'],
            'suggested' => ['rdv', 'beneficiaires', 'lieux'],
        ],
        'technique' => [
            'label' => 'Services techniques',
            'description' => 'Garages, entretien, réparation, installation : rendez-vous atelier et rappel à l’échéance du prochain entretien.',
            'modules' => ['rdv', 'catalogue'],
            'suggested' => [],
        ],
        'pro' => [
            'label' => 'Services professionnels',
            'description' => 'Conseil, formation, agences, cabinets : rendez-vous clients, suivi de la relation et relances.',
            'modules' => ['rdv', 'catalogue'],
            'suggested' => ['tranches', 'prospects', 'abonnements'],
        ],
        'commerce' => [
            'label' => 'Commerce et distribution',
            'description' => 'Boutiques, supermarchés, quincailleries, grossistes : import des ventes, analyse des achats par famille de produits et campagnes ciblées. Sans agenda.',
            'modules' => ['familles', 'lieux', 'fidelite'],
            'suggested' => ['catalogue', 'tranches'],
        ],
        'autre' => [
            'label' => 'Autre activité',
            'description' => 'Choisissez vous-même vos modules. Vous pourrez changer de secteur plus tard dans Paramètres › Entreprise.',
            'modules' => ['rdv', 'catalogue', 'lieux', 'fidelite'],
            'suggested' => [],
        ],
    ];

    /** Devises proposées dans Paramètres › Entreprise (code ISO => libellé). */
    public const CURRENCIES = [
        'XAF' => 'FCFA (XAF, Afrique centrale)',
        'XOF' => 'FCFA (XOF, Afrique de l’Ouest)',
        'EUR' => 'Euro (EUR)',
        'USD' => 'Dollar US (USD)',
        'CDF' => 'Franc congolais (CDF)',
        'GNF' => 'Franc guinéen (GNF)',
        'MAD' => 'Dirham marocain (MAD)',
    ];

    /** Fuseaux horaires proposés (Afrique francophone d'abord). */
    public const TIMEZONES = [
        'Africa/Libreville', 'Africa/Douala', 'Africa/Brazzaville', 'Africa/Kinshasa', 'Africa/Lubumbashi',
        'Africa/Abidjan', 'Africa/Dakar', 'Africa/Bamako', 'Africa/Ouagadougou', 'Africa/Lome', 'Africa/Porto-Novo',
        'Africa/Niamey', 'Africa/Ndjamena', 'Africa/Bangui', 'Africa/Conakry', 'Africa/Casablanca', 'Africa/Tunis',
        'Africa/Algiers', 'Indian/Antananarivo', 'Europe/Paris', 'UTC',
    ];

    /**
     * Niveau d'analyse des ventes choisi par l'entreprise : par article (libellé
     * vu dans les imports), par famille ou par catégorie (niveau au-dessus des
     * familles, ex. Boissons › Sodas). Analyses, filtres, ciblage et rapports
     * travaillent à ce niveau.
     */
    public const ANALYSIS_LEVELS = [
        'product' => 'Article',
        'family' => 'Famille',
        'category' => 'Catégorie',
    ];

    public function analysisLevel(): string
    {
        return array_key_exists((string) $this->product_analysis_level, self::ANALYSIS_LEVELS) ? $this->product_analysis_level : 'family';
    }

    /**
     * Règles de segmentation RFM (Paramètres › Segments RFM) : période
     * d'analyse (0 = tout l'historique) et seuils des 8 segments, notés de 1
     * à 5 (R = récence, FM = moyenne fréquence / montant). Les valeurs par
     * défaut reproduisent la segmentation historique ; chaque modification
     * incrémente la version des règles, gardée dans l'historique des segments.
     */
    public const RFM_DEFAULTS = [
        'period_months' => 0,
        'active_min_r' => 3,
        'champions_min_r' => 4,
        'champions_min_fm' => 4,
        'loyal_min_fm' => 3,
        'cant_lose_min_fm' => 4,
        'at_risk_min_fm' => 3,
        'version' => 1,
    ];

    /** @return array<string, int> */
    public function rfmSettings(): array
    {
        $saved = array_intersect_key($this->rfm_settings ?? [], self::RFM_DEFAULTS);

        return array_map('intval', [...self::RFM_DEFAULTS, ...$saved]);
    }

    /** Symbole affiché des montants : FCFA pour XAF / XOF, sinon le code. */
    public function currencySymbol(): string
    {
        return in_array($this->currency ?? 'XAF', ['XAF', 'XOF'], true) ? 'FCFA' : (string) $this->currency;
    }

    /**
     * Modules à la carte (Paramètres › Modules). Le socle (clients, ventes,
     * analyses, actions du jour, rapport du soir) est toujours actif ; un
     * module non coché disparaît du menu, des fiches et des réglages.
     * « campagnes » suit l'activation de l'API WhatsApp (whatsapp_api_enabled).
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const MODULES = [
        'rdv' => [
            'label' => 'Rendez-vous individuels',
            'description' => 'Agenda, intervenants, fermetures et réservation en ligne.',
        ],
        'seances_groupe' => [
            'label' => 'Cours et séances de groupe',
            'description' => 'Cours réguliers (jour, heure, lieu, coach, places), inscrits, appel des présences, annulations et rattrapages.',
        ],
        'abonnements' => [
            'label' => 'Formules et abonnements',
            'description' => 'Mensualités, saisons, cartes de séances, frais d’inscription et options ; chaque abonnement prépare son échéancier.',
        ],
        'tranches' => [
            'label' => 'Paiement en plusieurs fois',
            'description' => 'Échéanciers, tranches à payer ou en retard, encaissement et reçu.',
        ],
        'beneficiaires' => [
            'label' => 'Personnes suivies',
            'description' => 'Enfants, élèves ou patients suivis pour un responsable (parent, tuteur, employeur) : les messages partent au responsable.',
        ],
        'prospects' => [
            'label' => 'Suivi des prospects',
            'description' => 'Étapes avant le premier achat (à contacter, démo, essai…), relances à date, Kanban ; avec plusieurs utilisateurs : objectifs, résultats et primes des commerciaux.',
        ],
        'lieux' => [
            'label' => 'Plusieurs lieux',
            'description' => 'Plusieurs établissements, points de vente ou terrains, et plusieurs marques.',
        ],
        'catalogue' => [
            'label' => 'Catalogue',
            'description' => 'Prestations et produits classés par catégories. Ni stock ni variantes.',
        ],
        'produits' => [
            'label' => 'Vente de produits',
            'description' => 'Produits vendus en complément de vos prestations (soins, accessoires, tenues…), classés par catégories. Ni stock ni variantes.',
        ],
        'familles' => [
            'label' => 'Analyse commerciale',
            'description' => 'Analyse des ventes importées par article, famille ou catégorie, et ventes « à classer ».',
        ],
        'fidelite' => [
            'label' => 'Fidélité',
            'description' => 'Points à chaque achat, paliers et récompenses.',
        ],
        'campagnes' => [
            'label' => 'Campagnes WhatsApp (API)',
            'description' => 'Envoi automatique par l’API WhatsApp Cloud : campagnes, modèles de messages, historique.',
        ],
    ];

    /**
     * Questions « Comment travaillez-vous ? » de l'inscription : une réponse
     * « oui » coche le module.
     */
    public const WORK_QUESTIONS = [
        'rdv' => 'Recevez-vous vos clients sur rendez-vous individuel ?',
        'seances_groupe' => 'Donnez-vous des cours ou des séances en groupe ?',
        'abonnements' => 'Vendez-vous des abonnements, forfaits ou cartes de séances ?',
        'tranches' => 'Vos clients paient-ils en plusieurs fois ?',
        'beneficiaires' => 'Suivez-vous des personnes pour le compte d’un responsable (enfants, élèves, salariés) ?',
        'lieux' => 'Travaillez-vous dans plusieurs lieux ?',
        'produits' => 'Vendez-vous aussi des produits (en complément de vos prestations) ?',
        'prospects' => 'Démarchez-vous des prospects avant qu’ils deviennent clients ?',
    ];

    /**
     * Étapes internes fixes du suivi des prospects ; chaque entreprise nomme
     * ses étapes et range chacune sur l'une d'elles.
     */
    public const PROSPECT_KINDS = [
        'a_contacter' => 'À contacter',
        'en_discussion' => 'En discussion',
        'en_essai' => 'En essai',
        'gagne' => 'Client',
        'perdu' => 'Perdu',
    ];

    /**
     * Modèles d'étapes par secteur (key, libellé, étape interne, délai de
     * relance en jours ; 0 = pas de relance). Services professionnels : le
     * parcours de démarchage de Menentia (visite, démo, essai).
     *
     * @var array<string, list<array{key: string, label: string, kind: string, delay_days: int}>>
     */
    public const PROSPECT_TEMPLATES = [
        'pro' => [
            ['key' => 'prospect', 'label' => 'Prospect', 'kind' => 'a_contacter', 'delay_days' => 3],
            ['key' => 'visite', 'label' => 'Visité', 'kind' => 'en_discussion', 'delay_days' => 5],
            ['key' => 'demo', 'label' => 'Démo faite', 'kind' => 'en_discussion', 'delay_days' => 3],
            ['key' => 'essai', 'label' => 'En essai', 'kind' => 'en_essai', 'delay_days' => 7],
            ['key' => 'client', 'label' => 'Client', 'kind' => 'gagne', 'delay_days' => 0],
            ['key' => 'perdu', 'label' => 'Perdu', 'kind' => 'perdu', 'delay_days' => 0],
        ],
        'default' => [
            ['key' => 'a_contacter', 'label' => 'À contacter', 'kind' => 'a_contacter', 'delay_days' => 2],
            ['key' => 'discussion', 'label' => 'En discussion', 'kind' => 'en_discussion', 'delay_days' => 5],
            ['key' => 'essai', 'label' => 'En essai', 'kind' => 'en_essai', 'delay_days' => 7],
            ['key' => 'client', 'label' => 'Client', 'kind' => 'gagne', 'delay_days' => 0],
            ['key' => 'perdu', 'label' => 'Perdu', 'kind' => 'perdu', 'delay_days' => 0],
        ],
    ];

    /** Modules d'une organisation historique (sans secteur) : tout ce qui existait avant les modules. */
    public const LEGACY_MODULES = ['rdv', 'catalogue', 'produits', 'lieux', 'fidelite'];

    /** Libellés du bénéficiaire selon l'activité (singulier, pluriel). */
    public const BENEFICIARY_LABELS = [
        'adherent' => ['Adhérent', 'Adhérents'],
        'eleve' => ['Élève', 'Élèves'],
        'patient' => ['Patient', 'Patients'],
        'beneficiaire' => ['Bénéficiaire', 'Bénéficiaires'],
    ];

    /** Réglages de base des modules, complétés par ceux de l'entreprise. */
    public const MODULE_DEFAULTS = [
        'seances_groupe' => [
            'capacity' => 20,
            // Rattrapage proposé après N séances annulées, le jour choisi (1 = lundi … 7 = dimanche).
            'makeup_after' => 2,
            'makeup_weekday' => 6,
        ],
        'abonnements' => [
            'period' => 'month',
            'season_start' => null,
            'season_end' => null,
        ],
        'tranches' => [
            'due_day' => 5,
            // Relances : jours avant (négatif) ou après l'échéance.
            'reminders' => [-2, 0, 7],
        ],
        'beneficiaires' => [
            'label' => 'adherent',
            'minor_needs_responsible' => true,
        ],
        'fidelite' => [
            // Points gagnés à chaque présence à un cours (0 = aucun).
            'points_per_attendance' => 0,
        ],
        'prospects' => [
            // Étapes de l'entreprise (vide = modèle de son secteur, cf. PROSPECT_TEMPLATES).
            'stages' => [],
            // Prime d'un commercial : % du prix du premier mois de la formule, due au 2e paiement du client apporté.
            'commission_rate' => 50,
            // Montants fixes par formule (nom => FCFA), prioritaires sur le pourcentage.
            'commission_grid' => [],
        ],
    ];

    /** Modules cochés d'office pour un secteur. @return list<string> */
    public static function defaultModulesFor(?string $sector): array
    {
        return self::SECTORS[$sector]['modules'] ?? self::LEGACY_MODULES;
    }

    /** Modules actifs, campagnes comprises si l'API WhatsApp est activée. @return list<string> */
    public function enabledModules(): array
    {
        $enabled = $this->modules['enabled'] ?? self::defaultModulesFor($this->sector);

        return array_values(array_intersect(array_keys(self::MODULES), [...$enabled, ...($this->usesWhatsAppApi() ? ['campagnes'] : [])]));
    }

    public function hasModule(string $module): bool
    {
        if ($module === 'campagnes') {
            return $this->usesWhatsAppApi();
        }

        return in_array($module, $this->enabledModules(), true);
    }

    /** @return array<string, mixed> */
    public function moduleSettings(string $module): array
    {
        $defaults = self::MODULE_DEFAULTS[$module] ?? [];
        $settings = [...$defaults, ...array_intersect_key($this->modules['settings'][$module] ?? [], $defaults)];

        if ($module === 'abonnements') {
            // Saison par défaut : de septembre à juin de l'année scolaire en cours.
            $start = today()->month >= 8 ? today()->year : today()->year - 1;
            $settings['season_start'] = $settings['season_start'] ?: "{$start}-09-01";
            $settings['season_end'] = $settings['season_end'] ?: ($start + 1).'-06-30';
        }

        if ($module === 'prospects' && empty($settings['stages'])) {
            $settings['stages'] = self::PROSPECT_TEMPLATES[$this->sector] ?? self::PROSPECT_TEMPLATES['default'];
        }

        return $settings;
    }

    /**
     * Enregistre les modules actifs et leurs réglages (sans sauvegarder).
     *
     * @param  list<string>  $enabled
     * @param  array<string, array<string, mixed>>  $settings
     */
    public function setModules(array $enabled, array $settings = [], bool $reviewed = false): void
    {
        $current = $this->modules['settings'] ?? [];

        foreach ($settings as $module => $values) {
            if (! isset(self::MODULE_DEFAULTS[$module]) || ! is_array($values)) {
                continue;
            }

            foreach (array_intersect_key($values, self::MODULE_DEFAULTS[$module]) as $name => $value) {
                $default = self::MODULE_DEFAULTS[$module][$name];
                $current[$module][$name] = match (true) {
                    $value === null || $value === '' => $default,
                    is_int($default) => (int) $value,
                    is_bool($default) => (bool) $value,
                    // Listes de nombres (relances) ; structures (étapes, grille) gardées telles quelles, validées en amont.
                    is_array($default) && $module !== 'prospects' => array_values(array_map('intval', (array) $value)),
                    is_array($default) => (array) $value,
                    default => (string) $value,
                };
            }
        }

        $this->modules = [
            'enabled' => array_values(array_intersect(array_keys(self::MODULES), array_diff($enabled, ['campagnes']))),
            'settings' => $current,
            // Modules choisis par l'entreprise (inscription ou Paramètres › Modules) : étape « Pour bien démarrer » faite.
            'reviewed' => $reviewed || ! empty($this->modules['reviewed']),
        ];
    }

    /** Libellé du bénéficiaire (« Adhérent », « Élève »…). */
    public function beneficiaryLabel(bool $plural = false): string
    {
        $labels = self::BENEFICIARY_LABELS[$this->moduleSettings('beneficiaires')['label']] ?? self::BENEFICIARY_LABELS['adherent'];

        return $labels[$plural ? 1 : 0];
    }

    /**
     * Produits au catalogue : le commerce (cœur de son activité) ou un
     * prestataire qui vend aussi des produits en complément (module « produits »).
     */
    public function sellsCatalogProducts(): bool
    {
        return $this->sellsProducts() || $this->hasModule('produits');
    }

    /** Agenda des rendez-vous individuels, intervenants, fermetures, réservation en ligne. */
    public function hasAgenda(): bool
    {
        return $this->hasModule('rdv');
    }

    /** Façon d'utiliser NENTIX : tout gérer, ou seulement analyser les clients (imports réguliers). */
    public const USAGE_MODES = [
        'complete' => 'Gestion complète : les ventes se saisissent dans NENTIX, l’historique s’importe une seule fois',
        'analyse' => 'Analyse client seule : vous importez vos ventes régulièrement depuis votre caisse',
    ];

    /** Nom des personnes suivies, au pluriel ; choisi dans Paramètres › Modules, sinon selon le secteur. */
    public const CUSTOMER_LABELS = ['clients' => 'Clients', 'patients' => 'Patients', 'adherents' => 'Adhérents', 'eleves' => 'Élèves'];

    public function customerLabelKey(): string
    {
        if ($this->customer_label && isset(self::CUSTOMER_LABELS[$this->customer_label])) {
            return $this->customer_label;
        }

        return match ($this->sector) {
            'sante' => 'patients',
            'sport' => 'adherents',
            default => 'clients',
        };
    }

    public function isAnalysisOnly(): bool
    {
        return ($this->usage_mode ?? 'complete') === 'analyse';
    }

    /** Import des ventes possible : toujours en analyse seule, sinon tant que la reprise n'est pas terminée. */
    public function salesImportOpen(): bool
    {
        return $this->isAnalysisOnly() || $this->history_import_closed_at === null;
    }

    /** Le commerce parle de « familles » de produits, les autres de « catégories ». */
    public function sellsProducts(): bool
    {
        return $this->sector === 'commerce';
    }

    /**
     * Envoi par l'API WhatsApp Cloud activé (par défaut pour les organisations
     * existantes). Sans API : listes d'actions et liens wa.me uniquement.
     */
    public function usesWhatsAppApi(): bool
    {
        return $this->whatsapp_api_enabled ?? true;
    }

    /**
     * Messages préremplis des listes d'actions, modifiables dans la page
     * Actions : défauts complétés par ceux enregistrés par l'entreprise.
     *
     * @return array<string, string>
     */
    public function actionMessages(): array
    {
        $saved = array_filter(
            array_intersect_key($this->action_messages ?? [], ActionLists::DEFAULT_MESSAGES),
            fn ($text) => is_string($text) && trim($text) !== '',
        );

        return [...ActionLists::DEFAULT_MESSAGES, ...$saved];
    }

    /**
     * Réglages de la réservation en ligne (Paramètres › Réservation en ligne).
     * Désactivée par défaut ; une réservation arrive « À confirmer » sauf si
     * la confirmation automatique est choisie.
     */
    public const BOOKING_DEFAULTS = [
        'enabled' => false,
        'auto_confirm' => false,
        'slot_step' => 30,
        'min_notice_minutes' => 120,
        'horizon_days' => 30,
        'welcome' => '',
    ];

    /** @return array{enabled: bool, auto_confirm: bool, slot_step: int, min_notice_minutes: int, horizon_days: int, welcome: string} */
    public function bookingSettings(): array
    {
        $saved = array_intersect_key($this->booking_settings ?? [], self::BOOKING_DEFAULTS);
        $settings = [...self::BOOKING_DEFAULTS, ...$saved];

        return [
            'enabled' => (bool) $settings['enabled'],
            'auto_confirm' => (bool) $settings['auto_confirm'],
            'slot_step' => (int) $settings['slot_step'],
            'min_notice_minutes' => (int) $settings['min_notice_minutes'],
            'horizon_days' => (int) $settings['horizon_days'],
            'welcome' => (string) $settings['welcome'],
        ];
    }

    /**
     * Rapport quotidien au dirigeant (Paramètres › Rapports) : désactivé par
     * défaut, envoyé chaque soir à l'heure choisie aux adresses indiquées.
     */
    public const REPORT_DEFAULTS = [
        'enabled' => false,
        'recipients' => [],
        'send_at' => '21:00',
        'monthly_target' => 0,
        'last_sent_on' => null,
        'monthly_enabled' => false,
    ];

    /** @return array{enabled: bool, recipients: list<string>, send_at: string, monthly_target: int, last_sent_on: string|null, monthly_enabled: bool} */
    public function reportSettings(): array
    {
        $settings = [...self::REPORT_DEFAULTS, ...array_intersect_key($this->report_settings ?? [], self::REPORT_DEFAULTS)];

        return [
            'enabled' => (bool) $settings['enabled'],
            'recipients' => array_values(array_filter((array) $settings['recipients'], 'is_string')),
            'send_at' => (string) $settings['send_at'],
            'monthly_target' => (int) $settings['monthly_target'],
            'last_sent_on' => $settings['last_sent_on'] ?: null,
            'monthly_enabled' => (bool) $settings['monthly_enabled'],
        ];
    }

    /**
     * Programme de fidélité (Paramètres › Fidélité) : 1 point par tranche de
     * « fcfa_per_point » FCFA, à partir de « starts_on » ; paliers selon les
     * points gagnés sur 12 mois.
     */
    public const LOYALTY_DEFAULTS = [
        'enabled' => false,
        'fcfa_per_point' => 1000,
        'starts_on' => null,
        'tiers' => [
            ['name' => 'Bronze', 'min_points' => 0],
            ['name' => 'Argent', 'min_points' => 300],
            ['name' => 'Or', 'min_points' => 1000],
        ],
    ];

    /** @return array{enabled: bool, fcfa_per_point: int, starts_on: string|null, tiers: list<array{name: string, min_points: int}>} */
    public function loyaltySettings(): array
    {
        $settings = [...self::LOYALTY_DEFAULTS, ...array_intersect_key($this->loyalty_settings ?? [], self::LOYALTY_DEFAULTS)];

        return [
            // Module Fidélité décoché : le programme est arrêté, les points acquis restent.
            'enabled' => (bool) $settings['enabled'] && $this->hasModule('fidelite'),
            'fcfa_per_point' => max(1, (int) $settings['fcfa_per_point']),
            'starts_on' => $settings['starts_on'] ?: null,
            'tiers' => collect((array) $settings['tiers'])
                ->filter(fn ($t) => is_array($t) && trim((string) ($t['name'] ?? '')) !== '')
                ->map(fn ($t) => ['name' => trim((string) $t['name']), 'min_points' => max(0, (int) ($t['min_points'] ?? 0))])
                ->sortBy('min_points')
                ->values()
                ->all(),
        ];
    }

    public function bookingUrl(): string
    {
        return route('booking.show', $this->slug);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function loyaltyPrograms(): HasMany
    {
        return $this->hasMany(LoyaltyProgram::class);
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
