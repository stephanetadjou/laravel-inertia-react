import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';

import { useSidebar } from '../context/SidebarContext';
import { useTheme } from '../context/ThemeContext';
import { NotificationBell } from '../components/notifications/Notifications';
import MenentiaLogo from '../components/common/MenentiaLogo';
import { buildSections, currentSection, tabIsActive } from './navigation';

interface SharedAuthProps {
    auth?: {
        user: { name: string; email: string; role_name: string | null; can_manage_organization: boolean } | null;
        organization: { name: string; whatsapp_api_enabled?: boolean } | null;
    };
    [key: string]: unknown;
}

/**
 * En-tête : nom de la rubrique, ses onglets (écrans secondaires, à la manière
 * d'Odoo), notifications et menu du compte (thème, déconnexion).
 */
const AppHeader: React.FC = () => {
    const { isMobileOpen, toggleSidebar, toggleMobileSidebar } = useSidebar();
    const { theme, toggleTheme } = useTheme();
    const { url, props } = usePage<SharedAuthProps>();
    const { auth } = props;
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);

    const sections = buildSections({
        canManageOrganization: auth?.user?.can_manage_organization ?? false,
        whatsappApiEnabled: auth?.organization?.whatsapp_api_enabled ?? true,
    });
    const section = currentSection(sections, url);
    const tabs = section?.tabs ?? [];

    useEffect(() => {
        if (!userMenuOpen) return;
        const close = (e: MouseEvent) => {
            if (!menuRef.current?.contains(e.target as Node)) setUserMenuOpen(false);
        };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [userMenuOpen]);

    function handleToggle() {
        if (window.innerWidth >= 1024) {
            toggleSidebar();
        } else {
            toggleMobileSidebar();
        }
    }

    return (
        <header className="sticky top-0 z-99999 w-full border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <div className="flex h-14 items-center gap-3 px-3 lg:px-6">
                <button
                    className="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5"
                    onClick={handleToggle}
                    aria-label="Afficher ou masquer le menu"
                >
                    {isMobileOpen ? (
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
                            <path d="M6 6l12 12M18 6L6 18" />
                        </svg>
                    ) : (
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
                            <path d="M4 6h16M4 12h16M4 18h10" />
                        </svg>
                    )}
                </button>

                <Link href="/dashboard" className="lg:hidden" aria-label="Menentia, accueil">
                    <MenentiaLogo symbolClassName="h-7 w-7" wordmarkClassName="text-lg" />
                </Link>

                <div className="hidden min-w-0 items-baseline gap-2 lg:flex">
                    {section && <span className="text-[15px] font-semibold text-gray-900 dark:text-white">{section.name}</span>}
                    {auth?.organization && <span className="truncate text-sm text-gray-400">· {auth.organization.name}</span>}
                </div>

                <div className="ml-auto flex items-center gap-2">
                    <NotificationBell />

                    {auth?.user && (
                        <div className="relative" ref={menuRef}>
                            <button
                                onClick={() => setUserMenuOpen((prev) => !prev)}
                                className="flex items-center gap-2 rounded-lg px-1.5 py-1 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5"
                                aria-expanded={userMenuOpen}
                            >
                                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-gray-800 text-[13px] text-white dark:bg-gray-700">
                                    {auth.user.name.charAt(0).toUpperCase()}
                                </span>
                                <span className="hidden sm:block">{auth.user.name}</span>
                            </button>
                            {userMenuOpen && (
                                <div className="absolute right-0 mt-2 w-56 rounded-lg border border-gray-200 bg-white py-1.5 shadow-theme-lg dark:border-gray-800 dark:bg-gray-900">
                                    <div className="border-b border-gray-100 px-4 py-2 dark:border-gray-800">
                                        <p className="truncate text-xs text-gray-500 dark:text-gray-400">{auth.user.email}</p>
                                        {auth.user.role_name && <p className="mt-0.5 text-xs font-medium text-gray-700 dark:text-gray-300">{auth.user.role_name}</p>}
                                    </div>
                                    <button
                                        onClick={toggleTheme}
                                        className="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5"
                                    >
                                        {theme === 'dark' ? 'Thème clair' : 'Thème sombre'}
                                    </button>
                                    <button
                                        onClick={() => router.post('/logout')}
                                        className="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5"
                                    >
                                        Se déconnecter
                                    </button>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {tabs.length > 1 && (
                <nav className="no-scrollbar flex gap-1 overflow-x-auto px-3 lg:px-6" aria-label={`Onglets ${section?.name ?? ''}`}>
                    {tabs.map((tab) => {
                        const active = tabIsActive(url, tab);
                        return (
                            <Link
                                key={tab.path}
                                href={tab.path}
                                aria-current={active ? 'page' : undefined}
                                className={`border-b-2 px-3 pt-1 pb-2.5 text-sm whitespace-nowrap ${
                                    active
                                        ? 'border-brand-500 font-semibold text-gray-900 dark:text-white'
                                        : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'
                                }`}
                            >
                                {tab.name}
                            </Link>
                        );
                    })}
                </nav>
            )}
        </header>
    );
};

export default AppHeader;
