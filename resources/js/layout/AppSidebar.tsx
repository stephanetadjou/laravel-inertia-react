import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';

import {
    BoxCubeIcon,
    ChatIcon,
    ChevronDownIcon,
    DocsIcon,
    DollarLineIcon,
    GridIcon,
    GroupIcon,
    HorizontaLDots,
    ListIcon,
    LockIcon,
    PageIcon,
    PaperPlaneIcon,
    PieChartIcon,
    UserCircleIcon,
} from '../icons';
import { useSidebar } from '../context/SidebarContext';

type NavItem = {
    name: string;
    icon: React.ReactNode;
    path?: string;
    subItems?: { name: string; path: string }[];
};

type NavGroup = {
    label: string;
    collapsible?: boolean;
    items: NavItem[];
};

function buildNavGroups(canManageOrganization: boolean): NavGroup[] {
    const businessManagementItems: NavItem[] = [
        { icon: <DollarLineIcon />, name: "Historique d'achat", path: '/purchases' },
        { icon: <BoxCubeIcon />, name: 'Boutiques', path: '/stores' },
        { icon: <ListIcon />, name: 'Catégories', path: '/categories' },
    ];

    if (canManageOrganization) {
        businessManagementItems.push(
            { icon: <UserCircleIcon />, name: 'Users & Rôles', path: '/users' },
            { icon: <LockIcon />, name: 'Rôles', path: '/roles' },
            { icon: <PageIcon />, name: 'Organisation', path: '/organisation' },
        );
    }

    return [
        {
            label: 'Vue d\'ensemble',
            items: [{ icon: <PieChartIcon />, name: 'Tableau de bord', path: '/dashboard' }],
        },
        {
            label: 'Communication',
            collapsible: true,
            items: [
                { icon: <GridIcon />, name: 'Contacts', path: '/contacts' },
                { icon: <GroupIcon />, name: 'Groupes', path: '/groups' },
                { icon: <ChatIcon />, name: 'Chat', path: '/chat' },
                { icon: <PaperPlaneIcon />, name: 'Campagnes', path: '/campaigns' },
                { icon: <DocsIcon />, name: 'Templates', path: '/templates' },
            ],
        },
        {
            label: 'Business Management',
            collapsible: true,
            items: businessManagementItems,
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

    const [openSubmenu, setOpenSubmenu] = useState<string | null>(null);
    const [subMenuHeight, setSubMenuHeight] = useState<Record<string, number>>({});
    const subMenuRefs = useRef<Record<string, HTMLDivElement | null>>({});
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

    const isActive = useCallback((path: string) => url.startsWith(path), [url]);

    useEffect(() => {
        let matched = false;
        navGroups.forEach((group, groupIndex) => {
            group.items.forEach((nav, index) => {
                nav.subItems?.forEach((subItem) => {
                    if (isActive(subItem.path)) {
                        setOpenSubmenu(`${groupIndex}-${index}`);
                        matched = true;
                    }
                });
            });
        });
        if (!matched) {
            setOpenSubmenu(null);
        }
    }, [url, isActive]);

    useEffect(() => {
        if (openSubmenu !== null && subMenuRefs.current[openSubmenu]) {
            setSubMenuHeight((prev) => ({ ...prev, [openSubmenu]: subMenuRefs.current[openSubmenu]?.scrollHeight || 0 }));
        }
    }, [openSubmenu]);

    function handleSubmenuToggle(key: string) {
        setOpenSubmenu((prev) => (prev === key ? null : key));
    }

    const showLabels = isExpanded || isHovered || isMobileOpen;

    return (
        <aside
            className={`fixed top-0 left-0 z-50 mt-16 flex h-screen flex-col border-r border-gray-200 bg-white px-5 text-gray-900 transition-all duration-300 ease-in-out lg:mt-0 dark:border-gray-800 dark:bg-gray-900 ${
                isExpanded || isMobileOpen ? 'w-[290px]' : isHovered ? 'w-[290px]' : 'w-[90px]'
            } ${isMobileOpen ? 'translate-x-0' : '-translate-x-full'} lg:translate-x-0`}
            onMouseEnter={() => !isExpanded && setIsHovered(true)}
            onMouseLeave={() => setIsHovered(false)}
        >
            <div className={`flex py-8 ${!isExpanded && !isHovered ? 'lg:justify-center' : 'justify-start'}`}>
                <Link href="/dashboard" className="text-xl font-bold text-gray-900 dark:text-white">
                    {showLabels ? (
                        <span>
                            RETAIL <span className="text-brand-500">GROUP</span>
                        </span>
                    ) : (
                        <span className="text-brand-500">RG</span>
                    )}
                </Link>
            </div>
            <div className="no-scrollbar flex flex-col overflow-y-auto duration-300 ease-linear">
                <nav className="mb-6">
                    <div className="flex flex-col gap-4">
                        {navGroups.map((group, groupIndex) => {
                            const isCollapsed = group.collapsible && collapsedGroups.has(group.label);

                            return (
                            <div key={group.label}>
                                <h2
                                    onClick={() => group.collapsible && showLabels && toggleGroup(group.label)}
                                    className={`mb-4 flex items-center text-xs leading-[20px] text-gray-400 uppercase ${
                                        !isExpanded && !isHovered ? 'lg:justify-center' : 'justify-start'
                                    } ${group.collapsible && showLabels ? 'cursor-pointer select-none' : ''}`}
                                >
                                    {showLabels ? group.label : <HorizontaLDots className="size-6" />}
                                    {group.collapsible && showLabels && (
                                        <ChevronDownIcon className={`ml-auto h-4 w-4 transition-transform duration-200 ${isCollapsed ? '-rotate-90' : ''}`} />
                                    )}
                                </h2>
                                {!isCollapsed && (
                                <ul className="flex flex-col gap-4">
                                    {group.items.map((nav, index) => {
                                        const key = `${groupIndex}-${index}`;

                                        return (
                                            <li key={nav.name}>
                                                {nav.subItems ? (
                                                    <button
                                                        onClick={() => handleSubmenuToggle(key)}
                                                        className={`menu-item group ${
                                                            openSubmenu === key ? 'menu-item-active' : 'menu-item-inactive'
                                                        } cursor-pointer ${!isExpanded && !isHovered ? 'lg:justify-center' : 'lg:justify-start'}`}
                                                    >
                                                        <span
                                                            className={`menu-item-icon-size ${
                                                                openSubmenu === key ? 'menu-item-icon-active' : 'menu-item-icon-inactive'
                                                            }`}
                                                        >
                                                            {nav.icon}
                                                        </span>
                                                        {showLabels && <span className="menu-item-text">{nav.name}</span>}
                                                        {showLabels && (
                                                            <ChevronDownIcon
                                                                className={`ml-auto h-5 w-5 transition-transform duration-200 ${
                                                                    openSubmenu === key ? 'rotate-180 text-brand-500' : ''
                                                                }`}
                                                            />
                                                        )}
                                                    </button>
                                                ) : (
                                                    nav.path && (
                                                        <Link
                                                            href={nav.path}
                                                            className={`menu-item group ${isActive(nav.path) ? 'menu-item-active' : 'menu-item-inactive'}`}
                                                        >
                                                            <span
                                                                className={`menu-item-icon-size ${
                                                                    isActive(nav.path) ? 'menu-item-icon-active' : 'menu-item-icon-inactive'
                                                                }`}
                                                            >
                                                                {nav.icon}
                                                            </span>
                                                            {showLabels && <span className="menu-item-text">{nav.name}</span>}
                                                        </Link>
                                                    )
                                                )}
                                                {nav.subItems && showLabels && (
                                                    <div
                                                        ref={(el) => {
                                                            subMenuRefs.current[key] = el;
                                                        }}
                                                        className="overflow-hidden transition-all duration-300"
                                                        style={{ height: openSubmenu === key ? `${subMenuHeight[key]}px` : '0px' }}
                                                    >
                                                        <ul className="mt-2 ml-9 space-y-1">
                                                            {nav.subItems.map((subItem) => (
                                                                <li key={subItem.name}>
                                                                    <Link
                                                                        href={subItem.path}
                                                                        className={`menu-dropdown-item ${
                                                                            isActive(subItem.path)
                                                                                ? 'menu-dropdown-item-active'
                                                                                : 'menu-dropdown-item-inactive'
                                                                        }`}
                                                                    >
                                                                        {subItem.name}
                                                                    </Link>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                )}
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
