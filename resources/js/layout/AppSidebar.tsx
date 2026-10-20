import { Link, usePage } from '@inertiajs/react';

import { useSidebar } from '../context/SidebarContext';
import { MenentiaSymbol, MenentiaWordmark } from '../components/common/MenentiaLogo';
import { buildSections, currentSection } from './navigation';

interface SidebarProps {
    auth?: {
        user: { can_manage_organization?: boolean } | null;
        organization?: { whatsapp_api_enabled?: boolean } | null;
    };
    [key: string]: unknown;
}

/** Menu latéral clair et court : une entrée par grande rubrique (cf. navigation.tsx). */
const AppSidebar: React.FC = () => {
    const { isExpanded, isMobileOpen, isHovered, setIsHovered } = useSidebar();
    const { url, props } = usePage<SidebarProps>();

    const sections = buildSections({
        canManageOrganization: props.auth?.user?.can_manage_organization ?? false,
        whatsappApiEnabled: props.auth?.organization?.whatsapp_api_enabled ?? true,
    });
    const active = currentSection(sections, url);
    const showLabels = isExpanded || isHovered || isMobileOpen;

    return (
        <aside
            className={`fixed top-0 left-0 z-50 mt-14 flex h-screen flex-col border-r border-gray-200 bg-white px-3 text-gray-700 transition-all duration-300 ease-in-out lg:mt-0 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 ${
                showLabels ? 'w-[232px]' : 'w-[72px]'
            } ${isMobileOpen ? 'translate-x-0' : '-translate-x-full'} lg:translate-x-0`}
            onMouseEnter={() => !isExpanded && setIsHovered(true)}
            onMouseLeave={() => setIsHovered(false)}
        >
            <div className={`flex h-14 items-center ${showLabels ? 'px-2' : 'justify-center'}`}>
                <Link href="/dashboard" className="flex items-center gap-2" aria-label="Menentia, accueil">
                    <MenentiaSymbol className="h-8 w-8" />
                    {showLabels && <MenentiaWordmark className="text-[20px]" />}
                </Link>
            </div>

            <nav className="no-scrollbar mt-2 flex flex-col gap-0.5 overflow-y-auto pb-20" aria-label="Menu principal">
                {sections.map((section) => {
                    const isActive = active?.key === section.key;

                    return (
                        <Link
                            key={section.key}
                            href={section.path}
                            aria-current={isActive ? 'page' : undefined}
                            title={showLabels ? undefined : section.name}
                            className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-[14.5px] whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 ${
                                isActive
                                    ? 'bg-brand-50 font-semibold text-brand-700 dark:bg-white/10 dark:text-white'
                                    : 'hover:bg-gray-100 dark:hover:bg-white/5'
                            } ${showLabels ? '' : 'justify-center'} ${section.key === 'settings' ? 'mt-4' : ''}`}
                        >
                            <span className={`shrink-0 [&_svg]:size-5 ${isActive ? 'text-brand-600 dark:text-white' : 'text-gray-500 dark:text-gray-400'}`}>{section.icon}</span>
                            {showLabels && <span>{section.name}</span>}
                        </Link>
                    );
                })}
            </nav>
        </aside>
    );
};

export default AppSidebar;
