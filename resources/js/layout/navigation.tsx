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
    /** Secteur sur rendez-vous : agenda, réservation, intervenants, fermetures. */
    hasAgenda?: boolean;
    /** Commerce : ni prestations ni catalogue, l'analyse se fait par familles de produits. */
    sellsProducts?: boolean;
    /** Droit d'importer des ventes (rubrique Imports de Menentia Retail). */
    canImport?: boolean;
    /** Super-admin Menentia : rubrique Plateforme (/admin). */
    isPlatformAdmin?: boolean;
}

export function buildSections({ canManageOrganization, whatsappApiEnabled, hasAgenda = true, sellsProducts = false, canImport = false, isPlatformAdmin = false }: NavContext): NavSection[] {
    const settingsTabs: NavTab[] = [
        ...(canManageOrganization
            ? [
                  { name: 'Entreprise', path: '/organisation' },
                  ...(hasAgenda
                      ? [
                            { name: 'Réservation en ligne', path: '/settings/booking' },
                            { name: 'Intervenants', path: '/settings/staff' },
                            { name: 'Fermetures', path: '/settings/closures' },
                        ]
                      : []),
                  { name: 'Fidélité', path: '/settings/loyalty' },
                  { name: 'Segments RFM', path: '/settings/rfm' },
                  { name: 'Rapports', path: '/settings/reports' },
              ]
            : []),
        { name: 'Marques & boutiques', path: '/stores' },
        ...(canManageOrganization ? [{ name: 'Utilisateurs & rôles', path: '/users', alsoActiveOn: ['/roles'] }] : []),
        ...(canManageOrganization && whatsappApiEnabled ? [{ name: 'WhatsApp', path: '/settings/whatsapp' }] : []),
        ...(canManageOrganization ? [{ name: 'Journal', path: '/settings/audit' }] : []),
    ];

    const sections: NavSection[] = [
        { key: 'home', name: 'Accueil', icon: <PieChartIcon />, path: '/dashboard', tabs: [] },
        { key: 'actions', name: 'À faire', icon: <TaskIcon />, path: '/actions', tabs: [] },
        ...(hasAgenda ? [{ key: 'agenda', name: 'Agenda', icon: <CalenderIcon />, path: '/appointments', tabs: [] }] : []),
        {
            key: 'customers',
            name: 'Clients',
            icon: <UserIcon />,
            path: '/customers',
            tabs: [
                { name: 'Fiches clients', path: '/customers', alsoActiveOn: ['/contacts'] },
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
                ...(canManageOrganization && !sellsProducts ? [{ name: 'Catalogue', path: '/catalog' }] : []),
                // Retail : les familles sont dans la rubrique Analyses.
                ...(sellsProducts ? [] : [{ name: 'Catégories', path: '/categories' }]),
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
        sections.push({
            key: 'analytics',
            name: 'Analyses',
            icon: <PieChartIcon />,
            path: '/analytics',
            tabs: [
                { name: 'Tableaux de bord', path: '/analytics' },
                { name: 'Familles', path: '/categories' },
                { name: 'Multimarque', path: '/multibrand' },
            ],
        });
    }

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
