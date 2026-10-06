import { useCallback, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';

import {
    BoxCubeIcon,
    ChatIcon,
    ChevronDownIcon,
    DocsIcon,
    DollarLineIcon,
    GroupIcon,
    HorizontaLDots,
    ListIcon,
    PageIcon,
    PaperPlaneIcon,
    PieChartIcon,
    UserCircleIcon,
    UserIcon,
} from '../icons';
import { useSidebar } from '../context/SidebarContext';

type NavItem = {
    name: string;
    icon: React.ReactNode;
    path: string;
    /** Autres préfixes d'URL qui rendent l'entrée active (ex. /roles pour « Utilisateurs & rôles »). */
    alsoActiveOn?: string[];
};

type NavGroup = {
    label: string;
    collapsible?: boolean;
    items: NavItem[];
};

/**
 * Menu principal. Les routes /organisation, /users et /roles sont protégées
 * côté serveur par le middleware can-manage-organization : on n'affiche ces
 * entrées qu'aux rôles qui y ont accès. /stores et /categories restent
 * accessibles à tous les rôles, elles sont donc toujours visibles.
 */
function buildNavGroups(canManageOrganization: boolean): NavGroup[] {
    const administrationItems: NavItem[] = [];

    if (canManageOrganization) {
        administrationItems.push({ icon: <PageIcon />, name: 'Organisation', path: '/organisation' });
    }

    administrationItems.push(
        { icon: <BoxCubeIcon />, name: 'Marques & boutiques', path: '/stores' },
        { icon: <ListIcon />, name: 'Catégories', path: '/categories' },
    );

    if (canManageOrganization) {
        administrationItems.push(
            { icon: <ChatIcon />, name: 'Paramètres WhatsApp', path: '/settings/whatsapp' },
            { icon: <UserCircleIcon />, name: 'Utilisateurs & rôles', path: '/users', alsoActiveOn: ['/roles'] },
        );
    }

    return [
        {
            label: "Vue d'ensemble",
            items: [{ icon: <PieChartIcon />, name: 'Tableau de bord', path: '/dashboard' }],
        },
        {
            label: 'Communication',
            collapsible: true,
            items: [
                { icon: <PaperPlaneIcon />, name: 'Campagnes', path: '/campaigns' },
                { icon: <DocsIcon />, name: 'Modèles de messages', path: '/templates' },
                { icon: <ChatIcon />, name: 'Historique messages', path: '/chat' },
            ],
        },
        {
            label: 'Clients',
            collapsible: true,
            items: [
                // Une fiche par personne : filtres, groupes, import et campagnes depuis la sélection.
                { icon: <UserIcon />, name: 'Fiches clients', path: '/customers', alsoActiveOn: ['/imports'] },
                { icon: <GroupIcon />, name: 'Groupes', path: '/groups' },
                { icon: <DollarLineIcon />, name: "Historique d'achat", path: '/purchases' },
            ],
        },
        {
            label: 'Administration',
            collapsible: true,
            items: administrationItems,
        },
    ];
}

interface SidebarAuthProps {
    auth?: {
        user: { can_manage_organization?: boolean } | null;
    };
    [key: string]: unknown;
}

const AppSidebar: React.FC = () => {
    const { isExpanded, isMobileOpen, isHovered, setIsHovered } = useSidebar();
    const { url, props } = usePage<SidebarAuthProps>();

    const navGroups = buildNavGroups(props.auth?.user?.can_manage_organization ?? false);

    const [collapsedGroups, setCollapsedGroups] = useState<Set<string>>(new Set());

    function toggleGroup(label: string) {
        setCollapsedGroups((prev) => {
            const next = new Set(prev);
            if (next.has(label)) {
                next.delete(label);
            } else {
                next.add(label);
            }
            return next;
        });
    }

    const isActive = useCallback(
        (nav: NavItem) => [nav.path, ...(nav.alsoActiveOn ?? [])].some((path) => url === path || url.startsWith(path + '/') || url.startsWith(path + '?')),
        [url],
    );

    const showLabels = isExpanded || isHovered || isMobileOpen;

    return (
        <aside
            className={`fixed top-0 left-0 z-50 mt-16 flex h-screen flex-col bg-[#0a5a41] px-4 text-[#effaf4] transition-all duration-300 ease-in-out lg:mt-0 ${
                isExpanded || isMobileOpen ? 'w-[290px]' : isHovered ? 'w-[290px]' : 'w-[90px]'
            } ${isMobileOpen ? 'translate-x-0' : '-translate-x-full'} lg:translate-x-0`}
            onMouseEnter={() => !isExpanded && setIsHovered(true)}
            onMouseLeave={() => setIsHovered(false)}
        >
            <div className={`flex py-6 ${!isExpanded && !isHovered ? 'lg:justify-center' : 'justify-start'}`}>
                <Link href="/dashboard" className="flex items-center gap-2.5">
                    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-[10px] bg-[#25d366]" aria-hidden="true">
                        <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="#05331f" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M4 20l1.3-3.9A8 8 0 1 1 8 19z" />
                            <path d="M9 10h6M9 13.5h4" />
                        </svg>
                    </span>
                    {showLabels && (
                        <span className="leading-tight">
                            <span className="block text-lg font-bold tracking-tight text-white">MERCURE CRM</span>
                            <span className="block text-[11px] text-[#a9dcc3]">CRM WhatsApp retail</span>
                        </span>
                    )}
                </Link>
            </div>
            <div className="no-scrollbar flex flex-col overflow-y-auto pb-20 duration-300 ease-linear">
                <nav className="mb-6">
                    <div className="flex flex-col gap-3">
                        {navGroups.map((group) => {
                            const isCollapsed = group.collapsible && collapsedGroups.has(group.label);

                            return (
                                <div key={group.label}>
                                    <h2
                                        onClick={() => group.collapsible && showLabels && toggleGroup(group.label)}
                                        className={`mb-1.5 flex items-center px-3 pt-1 text-[10.5px] leading-[20px] tracking-[0.08em] text-[#a9dcc3] uppercase ${
                                            !isExpanded && !isHovered ? 'lg:justify-center' : 'justify-start'
                                        } ${group.collapsible && showLabels ? 'cursor-pointer select-none' : ''}`}
                                    >
                                        {showLabels ? group.label : <HorizontaLDots className="size-6" />}
                                        {group.collapsible && showLabels && (
                                            <ChevronDownIcon
                                                className={`ml-auto h-4 w-4 transition-transform duration-200 ${isCollapsed ? '-rotate-90' : ''}`}
                                            />
                                        )}
                                    </h2>
                                    {!isCollapsed && (
                                        <ul className="flex flex-col gap-0.5">
                                            {group.items.map((nav) => {
                                                const active = isActive(nav);

                                                return (
                                                    <li key={nav.name}>
                                                        <Link
                                                            href={nav.path}
                                                            aria-current={active ? 'page' : undefined}
                                                            className={`flex w-full items-center gap-3 rounded-[9px] px-3 py-2 text-sm whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#25d366] ${
                                                                active
                                                                    ? 'bg-[#25d366] font-semibold text-[#05331f]'
                                                                    : 'text-[#effaf4] hover:bg-[#0e6b4e]'
                                                            } ${!isExpanded && !isHovered ? 'lg:justify-center' : ''}`}
                                                        >
                                                            <span className={`shrink-0 [&_svg]:size-5 ${active ? '' : 'opacity-85'}`}>{nav.icon}</span>
                                                            {showLabels && <span>{nav.name}</span>}
                                                        </Link>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </nav>
            </div>
        </aside>
    );
};

export default AppSidebar;
