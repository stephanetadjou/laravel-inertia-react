import { BoxCubeIcon, CalenderIcon, DollarLineIcon, GridIcon, PaperPlaneIcon, PieChartIcon, TaskIcon, UserIcon } from '../icons';

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
}

export function buildSections({ canManageOrganization, whatsappApiEnabled }: NavContext): NavSection[] {
    const settingsTabs: NavTab[] = [
        ...(canManageOrganization
            ? [
                  { name: 'Entreprise', path: '/organisation' },
                  { name: 'Réservation en ligne', path: '/settings/booking' },
                  { name: 'Intervenants', path: '/settings/staff' },
                  { name: 'Fermetures', path: '/settings/closures' },
              ]
            : []),
        { name: 'Marques & boutiques', path: '/stores' },
        ...(canManageOrganization ? [{ name: 'Utilisateurs & rôles', path: '/users', alsoActiveOn: ['/roles'] }] : []),
        ...(canManageOrganization && whatsappApiEnabled ? [{ name: 'WhatsApp', path: '/settings/whatsapp' }] : []),
    ];

    const sections: NavSection[] = [
        { key: 'home', name: 'Accueil', icon: <PieChartIcon />, path: '/dashboard', tabs: [] },
        { key: 'actions', name: 'À faire', icon: <TaskIcon />, path: '/actions', tabs: [] },
        { key: 'agenda', name: 'Agenda', icon: <CalenderIcon />, path: '/appointments', tabs: [] },
        {
            key: 'customers',
            name: 'Clients',
            icon: <UserIcon />,
            path: '/customers',
            tabs: [
                { name: 'Fiches clients', path: '/customers', alsoActiveOn: ['/contacts'] },
                { name: 'Groupes', path: '/groups' },
                { name: 'Importer', path: '/imports' },
            ],
        },
        {
            key: 'sales',
            name: 'Ventes',
            icon: <DollarLineIcon />,
            path: '/purchases',
            tabs: [
                { name: 'Ventes', path: '/purchases' },
                ...(canManageOrganization ? [{ name: 'Catalogue', path: '/catalog' }] : []),
                { name: 'Catégories', path: '/categories' },
            ],
        },
    ];

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
