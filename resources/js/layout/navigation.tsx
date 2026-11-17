import { BoxCubeIcon, BoxIcon, CalenderIcon, ChatIcon, DollarLineIcon, DownloadIcon, FileIcon, GridIcon, PaperPlaneIcon, PieChartIcon, TaskIcon, UserCircleIcon, UserIcon } from '../icons';

/*
 * Menu principal, à la manière d'Odoo : quelques grandes rubriques dans le
 * menu latéral, et les écrans secondaires d'une rubrique en onglets en haut
 * de page (cf. SectionTabs). Les routes de gestion (Organisation,
 * Utilisateurs…) restent protégées côté serveur ; on n'affiche que ce que
 * le rôle peut ouvrir.
 */

export interface NavTab {
    name: string;
    path: string;
    /** Autres préfixes d'URL qui rendent l'onglet actif. */
    alsoActiveOn?: string[];
}

export interface NavSection {
    key: string;
    name: string;
    icon: React.ReactNode;
    path: string;
    tabs: NavTab[];
}

/** Applications qui ont des automatisations WhatsApp / IA (onglet « Automatisations »). */
export const AUTOMATION_APPS = ['agenda', 'customers', 'discussions', 'invoices'];

export interface NavContext {
    canManageOrganization: boolean;
    whatsappApiEnabled: boolean;
    /** Modules à la carte actifs (cf. Organization::MODULES). */
    modules?: string[];
    /** Commerce : vocabulaire « familles », imports des ventes et analyses Retail. */
    sellsProducts?: boolean;
    /** Droit d'importer des ventes (rubrique Imports de ClientlyX Retail). */
    canImport?: boolean;
    /** Super-admin ClientlyX : rubrique Plateforme (/admin). */
    isPlatformAdmin?: boolean;
    /** Nom des personnes suivies (clients, patients, adherents, eleves). */
    customerLabel?: string;
}

/** Libellés selon le nom choisi : pluriel (« Patients ») et fiches (« Fiches patients »). */
export const CUSTOMER_LABELS: Record<string, { plural: string; lower: string }> = {
    clients: { plural: 'Clients', lower: 'clients' },
    patients: { plural: 'Patients', lower: 'patients' },
    adherents: { plural: 'Adhérents', lower: 'adhérents' },
    eleves: { plural: 'Élèves', lower: 'élèves' },
    apprenants: { plural: 'Apprenants', lower: 'apprenants' },
};
export const customerLabel = (key?: string | null) => CUSTOMER_LABELS[key ?? ''] ?? CUSTOMER_LABELS.clients;

/** Données partagées par HandleInertiaRequests utiles au menu. */
export interface NavAuth {
    user: { can_manage_organization?: boolean; can_import?: boolean; is_platform_admin?: boolean } | null;
    organization?: { whatsapp_api_enabled?: boolean; sells_products?: boolean; modules?: string[]; customer_label?: string; pinned_apps?: string[] } | null;
}

export function navContext(auth: NavAuth | undefined): NavContext {
    return {
        canManageOrganization: auth?.user?.can_manage_organization ?? false,
        whatsappApiEnabled: auth?.organization?.whatsapp_api_enabled ?? true,
        modules: auth?.organization?.modules,
        sellsProducts: auth?.organization?.sells_products ?? false,
        canImport: auth?.user?.can_import ?? false,
        isPlatformAdmin: auth?.user?.is_platform_admin ?? false,
        customerLabel: auth?.organization?.customer_label,
    };
}

/** Modules d'une organisation historique quand la liste n'est pas fournie. */
const LEGACY_MODULES = ['rdv', 'catalogue', 'produits', 'lieux', 'fidelite'];

export function buildSections({ canManageOrganization, whatsappApiEnabled, modules = LEGACY_MODULES, sellsProducts = false, canImport = false, isPlatformAdmin = false, customerLabel: labelKey }: NavContext): NavSection[] {
    const people = customerLabel(labelKey);
    const has = (module: string) => modules.includes(module);
    const agenda = has('rdv') || has('seances_groupe');

    const settingsTabs: NavTab[] = [
        ...(canManageOrganization
            ? [
                  { name: 'Entreprise', path: '/organisation' },
                  { name: 'Modules', path: '/settings/modules' },
                  ...(has('reponses_auto') ? [{ name: 'Réponses automatiques', path: '/settings/auto-replies' }] : []),
                  ...(has('agents_ia') ? [{ name: 'Agents IA', path: '/ai-agents' }] : []),
                  ...(has('rdv') ? [{ name: 'Réservation en ligne', path: '/settings/booking' }] : []),
                  { name: 'Ma page en ligne', path: '/settings/online-page' },
                  ...(agenda ? [{ name: 'Intervenants', path: '/settings/staff' }] : []),
                  ...(has('rdv') ? [{ name: 'Fermetures', path: '/settings/closures' }] : []),
                  ...(has('fidelite') ? [{ name: 'Fidélité', path: '/settings/loyalty' }] : []),
                  ...(has('prospects') ? [{ name: 'Prospects', path: '/settings/prospects' }] : []),
                  { name: 'Segments RFM', path: '/settings/rfm' },
                  ...(agenda || has('ressources') || has('vehicules') ? [{ name: 'Occupation', path: '/settings/occupation' }] : []),
                  { name: 'Rapports', path: '/settings/reports' },
              ]
            : []),
        { name: has('lieux') ? 'Marques & boutiques' : 'Établissement', path: '/stores' },
        ...(canManageOrganization ? [{ name: 'Utilisateurs & rôles', path: '/users', alsoActiveOn: ['/roles'] }] : []),
        ...(canManageOrganization && whatsappApiEnabled ? [{ name: 'WhatsApp', path: '/settings/whatsapp' }] : []),
        ...(canManageOrganization ? [{ name: 'Journal', path: '/settings/audit' }] : []),
    ];

    const sections: NavSection[] = [
        { key: 'home', name: 'Accueil', icon: <PieChartIcon />, path: '/dashboard', tabs: [] },
        { key: 'actions', name: 'À faire', icon: <TaskIcon />, path: '/actions', tabs: [] },
        {
            key: 'discussions',
            name: 'Discussions',
            icon: <ChatIcon />,
            path: '/discussions',
            tabs: canManageOrganization && has('agents_ia') ? [{ name: 'Discussions', path: '/discussions' }, { name: 'Agents IA', path: '/ai-agents' }] : [],
        },
        ...(agenda
            ? [
                  {
                      key: 'agenda',
                      name: 'Agenda',
                      icon: <CalenderIcon />,
                      path: '/appointments',
                      tabs:
                          has('seances_groupe') || has('ressources')
                              ? [
                                    { name: 'Agenda', path: '/appointments' },
                                    ...(has('seances_groupe') ? [{ name: 'Cours', path: '/classes', alsoActiveOn: ['/class-sessions'] }] : []),
                                    ...(has('ressources') ? [{ name: 'Véhicules et salles', path: '/resources' }] : []),
                                ]
                              : [],
                  },
              ]
            : []),
        ...(has('location')
            ? [
                  {
                      key: 'rental',
                      name: 'Location',
                      icon: <CalenderIcon />,
                      path: '/location/dashboard',
                      tabs: [
                          { name: 'Tableau de bord', path: '/location/dashboard' },
                          { name: 'Demandes', path: '/location/requests' },
                          { name: 'Réservations', path: '/location/planning', alsoActiveOn: ['/location/bookings'] },
                          { name: 'Ventes', path: '/location/sales' },
                          { name: 'Entreprises', path: '/location/accounts' },
                      ],
                  },
              ]
            : []),
        ...(has('vehicules') ? [{ key: 'fleet', name: has('location') ? 'Véhicules' : 'Flotte', icon: <BoxCubeIcon />, path: '/vehicles', tabs: [] }] : []),
        {
            key: 'customers',
            name: people.plural,
            icon: <UserIcon />,
            path: '/customers',
            tabs: [
                { name: `Fiches ${people.lower}`, path: '/customers', alsoActiveOn: ['/contacts'] },
                ...(has('formation') ? [{ name: 'Suivi des apprenants', path: '/learners' }, { name: 'Formations', path: '/trainings' }] : []),
                ...(has('fidelite') ? [{ name: 'Fidélité', path: '/fidelite' }] : []),
                ...(has('prospects') ? [{ name: 'Prospects', path: '/prospects' }, { name: 'Objectifs', path: '/sales-team', alsoActiveOn: ['/commissions'] }] : []),
                { name: 'Groupes', path: '/groups' },
                ...(sellsProducts && canImport ? [{ name: 'À vérifier', path: '/customers/a-verifier' }] : []),
                // Retail : les imports ont leur propre rubrique.
                ...(sellsProducts ? [] : [{ name: 'Importer', path: '/imports' }]),
            ],
        },
        {
            key: 'sales',
            name: 'Ventes',
            icon: <DollarLineIcon />,
            path: '/purchases',
            tabs: [
                { name: 'Ventes', path: '/purchases' },
                ...(has('tranches') || has('abonnements') ? [{ name: 'Échéances', path: '/installments', alsoActiveOn: ['/payment-schedules'] }] : []),
                ...(has('abonnements') ? [{ name: 'Formules', path: '/plans', alsoActiveOn: ['/subscriptions'] }] : []),
                ...(canManageOrganization && has('catalogue') ? [{ name: 'Catalogue', path: '/catalog' }] : []),
                // Retail : les familles sont dans la rubrique Analyses.
                ...(!sellsProducts && has('catalogue') ? [{ name: 'Catégories', path: '/categories' }] : []),
            ],
        },
    ];

    // Applications de gestion (façon Odoo) : chacune son espace.
    if (has('factures')) {
        sections.push({
            key: 'invoices',
            name: 'Factures',
            icon: <FileIcon />,
            path: '/invoices?type=quote',
            tabs: [
                { name: 'Devis', path: '/invoices?type=quote' },
                { name: 'Factures', path: '/invoices?type=invoice' },
            ],
        });
    }
    if (has('caisse')) {
        sections.push({ key: 'cash', name: 'Caisse', icon: <DollarLineIcon />, path: '/cash', tabs: [] });
    }
    if (has('stock')) {
        sections.push({
            key: 'stock',
            name: 'Stock',
            icon: <BoxIcon />,
            path: '/stock',
            tabs: [
                { name: 'Produits', path: '/stock' },
                { name: 'Mouvements', path: '/stock/movements' },
            ],
        });
    }
    if (has('tunnels')) {
        sections.push({ key: 'funnels', name: 'Tunnels', icon: <PaperPlaneIcon />, path: '/funnels', tabs: [] });
    }
    if (has('depenses')) {
        sections.push({ key: 'expenses', name: 'Dépenses', icon: <DownloadIcon />, path: '/expenses', tabs: [] });
    }
    // Carte digitale : gratuite, cochée d'office dans tous les secteurs (le pack fixe le nombre de cartes).
    if (has('carte_digitale')) {
        sections.push({ key: 'cards', name: 'Carte digitale', icon: <UserCircleIcon />, path: '/cards', tabs: [] });
    }

    // ClientlyX Retail : imports des ventes (fichiers de caisse / ERP) et des clients, puis analyses.
    if (sellsProducts) {
        sections.push({
            key: 'imports',
            name: 'Imports',
            icon: <DownloadIcon />,
            path: canImport ? '/sales-imports' : '/imports',
            tabs: [...(canImport ? [{ name: 'Ventes', path: '/sales-imports' }] : []), { name: 'Clients', path: '/imports' }],
        });
    }

    // Analyses : socle commun ; familles (commerce) et multimarque selon les modules.
    sections.push({
        key: 'analytics',
        name: 'Analyses',
        icon: <PieChartIcon />,
        path: '/analytics',
        tabs: [
            { name: 'Pilotage', path: '/pilotage' },
            { name: 'Rapports', path: '/rapports' },
            { name: 'Tableaux de bord', path: '/analytics' },
            { name: 'Sources', path: '/sources' },
            ...(sellsProducts && (has('familles') || has('catalogue')) ? [{ name: 'Familles', path: '/categories' }] : []),
            ...(has('lieux') ? [{ name: 'Multimarque', path: '/multibrand' }] : []),
        ],
    });

    if (whatsappApiEnabled) {
        sections.push({
            key: 'campaigns',
            name: 'Campagnes',
            icon: <PaperPlaneIcon />,
            path: '/campaigns',
            tabs: [
                { name: 'Campagnes', path: '/campaigns' },
                { name: 'Modèles de messages', path: '/templates' },
                { name: 'Messages envoyés', path: '/chat' },
            ],
        });
    }

    sections.push({
        key: 'settings',
        name: 'Paramètres',
        icon: canManageOrganization ? <GridIcon /> : <BoxCubeIcon />,
        path: settingsTabs[0].path,
        tabs: settingsTabs,
    });

    // Équipe ClientlyX : back-office plateforme.
    if (isPlatformAdmin) {
        sections.push({
            key: 'platform',
            name: 'Plateforme',
            icon: <BoxCubeIcon />,
            path: '/admin/dashboard',
            tabs: [
                { name: 'Tableau de bord', path: '/admin/dashboard' },
                { name: 'Entreprises', path: '/admin/organizations' },
                { name: 'Demandes de démo', path: '/admin/leads' },
            ],
        });
    }

    // Onglet « Automatisations » (WhatsApp, IA) dans les applications concernées.
    for (const section of sections) {
        if (!AUTOMATION_APPS.includes(section.key)) continue;
        if (section.tabs.length === 0) section.tabs = [{ name: section.name, path: section.path }];
        section.tabs.push({ name: 'Automatisations', path: `/apps/automations?app=${section.key}` });
    }

    return sections;
}

export function matches(url: string, path: string): boolean {
    return url === path || url.startsWith(path + '/') || url.startsWith(path + '?');
}

export function tabIsActive(url: string, tab: NavTab): boolean {
    return [tab.path, ...(tab.alsoActiveOn ?? [])].some((path) => matches(url, path));
}

/** Rubrique de la page courante : celle dont un onglet (ou le chemin) correspond. */
export function currentSection(sections: NavSection[], url: string): NavSection | undefined {
    const exact = sections.find((s) => matches(url, s.path) || s.tabs.some((t) => tabIsActive(url, t)));
    if (exact) return exact;
    // Écrans de détail (/invoices/12) : l'application dont le chemin, sans paramètres, préfixe l'URL.
    return sections.find((s) => s.key !== 'home' && matches(url, s.path.split('?')[0]));
}

/*
 * Téléphone : le paramétrage se fait sur ordinateur. On retire du menu les
 * rubriques et onglets de configuration ; une URL de réglage ouverte sur
 * téléphone affiche « À faire sur ordinateur » (cf. AppLayout).
 */
const MOBILE_HIDDEN_SECTIONS = ['settings', 'imports', 'platform', 'home', 'analytics', 'campaigns'];
const CONFIG_PREFIXES = [
    '/organisation',
    '/settings',
    '/stores',
    '/users',
    '/roles',
    '/ai-agents',
    '/imports',
    '/sales-imports',
    '/templates',
    '/plans',
    '/catalog',
    '/categories',
    '/trainings',
    '/sales-team',
    '/customers/a-verifier',
    '/apps/automations',
    '/admin',
];

export const isConfigPath = (url: string) => CONFIG_PREFIXES.some((p) => url === p || url.startsWith(`${p}/`) || url.startsWith(`${p}?`));

export function forMobile(sections: NavSection[]): NavSection[] {
    return sections.filter((s) => !MOBILE_HIDDEN_SECTIONS.includes(s.key)).map((s) => ({ ...s, tabs: s.tabs.filter((t) => !isConfigPath(t.path)) }));
}

/*
 * Les 4 piliers ClientlyX (Customer Growth Platform). Les applications du cycle
 * client sont mises en avant ; Caisse, Stock, Dépenses… restent dans « Outils ».
 */
export const PILLARS = [
    { key: 'engage', name: 'Engage', sub: 'WhatsApp, campagnes et IA', apps: ['discussions', 'campaigns'] },
    { key: 'understand', name: 'Understand', sub: 'Clients, RFM et comportement', apps: ['customers', 'analytics', 'home'] },
    { key: 'convert', name: 'Convert', sub: 'Rendez-vous, ventes et paiements', apps: ['agenda', 'sales', 'invoices', 'fleet'] },
    { key: 'retain', name: 'Retain', sub: 'Relances, fidélité et réactivation', apps: ['actions'] },
];

export function groupByPillar(sections: NavSection[]) {
    const groups = PILLARS.map((p) => ({ ...p, sections: p.apps.map((k) => sections.find((s) => s.key === k)).filter(Boolean) as NavSection[] })).filter((g) => g.sections.length);
    const used = PILLARS.flatMap((p) => p.apps);
    const tools = sections.filter((s) => !used.includes(s.key) && s.key !== 'platform');
    return tools.length ? [...groups, { key: 'tools', name: 'Outils', sub: 'Caisse, stock, dépenses et réglages', apps: [], sections: tools }] : groups;
}
