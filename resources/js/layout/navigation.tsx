import { BoxCubeIcon, CalenderIcon, DollarLineIcon, DownloadIcon, GridIcon, PaperPlaneIcon, PieChartIcon, TaskIcon, UserIcon } from '../icons';

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

export interface NavContext {
    canManageOrganization: boolean;
    whatsappApiEnabled: boolean;
    /** Modules à la carte actifs (cf. Organization::MODULES). */
    modules?: string[];
    /** Commerce : vocabulaire « familles », imports des ventes et analyses Retail. */
    sellsProducts?: boolean;
    /** Droit d'importer des ventes (rubrique Imports de Menentia Retail). */
    canImport?: boolean;
    /** Super-admin Menentia : rubrique Plateforme (/admin). */
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
};
export const customerLabel = (key?: string | null) => CUSTOMER_LABELS[key ?? ''] ?? CUSTOMER_LABELS.clients;

/** Données partagées par HandleInertiaRequests utiles au menu. */
export interface NavAuth {
    user: { can_manage_organization?: boolean; can_import?: boolean; is_platform_admin?: boolean } | null;
    organization?: { whatsapp_api_enabled?: boolean; sells_products?: boolean; modules?: string[]; customer_label?: string } | null;
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
                  ...(has('rdv') ? [{ name: 'Réservation en ligne', path: '/settings/booking' }] : []),
                  ...(agenda ? [{ name: 'Intervenants', path: '/settings/staff' }] : []),
                  ...(has('rdv') ? [{ name: 'Fermetures', path: '/settings/closures' }] : []),
                  ...(has('fidelite') ? [{ name: 'Fidélité', path: '/settings/loyalty' }] : []),
                  ...(has('prospects') ? [{ name: 'Prospects', path: '/settings/prospects' }] : []),
                  { name: 'Segments RFM', path: '/settings/rfm' },
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
        ...(agenda
            ? [
                  {
                      key: 'agenda',
                      name: 'Agenda',
                      icon: <CalenderIcon />,
                      path: '/appointments',
                      tabs: has('seances_groupe') ? [{ name: 'Agenda', path: '/appointments' }, { name: 'Cours', path: '/classes', alsoActiveOn: ['/class-sessions'] }] : [],
                  },
              ]
            : []),
        {
            key: 'customers',
            name: people.plural,
            icon: <UserIcon />,
            path: '/customers',
            tabs: [
                { name: `Fiches ${people.lower}`, path: '/customers', alsoActiveOn: ['/contacts'] },
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

    // Menentia Retail : imports des ventes (fichiers de caisse / ERP) et des clients, puis analyses.
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

    // Équipe Menentia : back-office plateforme.
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
    return sections.find((s) => matches(url, s.path) || s.tabs.some((t) => tabIsActive(url, t)));
}
