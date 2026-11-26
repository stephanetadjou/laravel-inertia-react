import { locale, switchLocale, t } from '../lib/i18n';
import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';

import { useTheme } from '../context/ThemeContext';
import { NotificationBell } from '../components/notifications/Notifications';
import DemoSwitcher from '../components/common/DemoSwitcher';
import { MenentiaSymbol } from '../components/common/MenentiaLogo';
import { buildSections, currentSection, forMobile, groupByPillar, navContext, NavSection, tabIsActive } from './navigation';
import useIsMobile from './useIsMobile';
import { AppIcon } from './apps';
import CommandPalette from './CommandPalette';

interface SharedAuthProps {
    auth?: {
        user: { name: string; email: string; role_name: string | null; can_manage_organization: boolean; can_import?: boolean } | null;
        organization: { name: string; whatsapp_api_enabled?: boolean; sells_products?: boolean; modules?: string[]; pack_label?: string | null; pinned_apps?: string[] } | null;
    };
    [key: string]: unknown;
}

interface Pulse {
    tone: 'bad' | 'warn' | 'good';
    text: string;
    href: string;
}

/** Applications essentielles du rail, dans l'ordre (le reste est dans « Toutes les apps »). */
const RAIL_PRIORITY = ['discussions', 'customers', 'agenda', 'sales', 'actions', 'campaigns', 'analytics', 'invoices', 'fleet', 'cash', 'stock', 'expenses'];
const RAIL_MAX = 7;

/** Rail d'hexagones (écran large), sans défilement : l'essentiel du secteur, Paramètres, puis toutes les apps. */
const RAIL_PINNED = 4;

/**
 * Ordre du rail : les 4 applications fixes du secteur (module phare en tête,
 * Organization::SECTOR_PINNED_APPS), puis les places variables habituelles.
 */
export function railOrder(sections: NavSection[], pinned: string[] = []): NavSection[] {
    const find = (k: string) => sections.find((s) => s.key === k);
    const fixed = pinned.map(find).filter(Boolean).slice(0, RAIL_PINNED) as NavSection[];
    const rest = RAIL_PRIORITY.map(find).filter((s): s is NavSection => !!s && !fixed.includes(s));

    return [...fixed, ...rest].slice(0, RAIL_MAX);
}

function AppRail({ sections, current, pinned }: { sections: NavSection[]; current?: string; pinned?: string[] }) {
    const essentials = railOrder(sections, pinned);
    const settings = sections.find((s) => s.key === 'settings');
    const elsewhere = current && !essentials.some((s) => s.key === current) && current !== 'settings' ? sections.find((s) => s.key === current) : undefined;
    const item = (s: NavSection) => (
        <Link key={s.key} href={s.path} className="nx-rail-item" aria-current={current === s.key ? 'page' : undefined} title={s.name}>
            <AppIcon section={s} size="sm" active={current === s.key} />
            <span className="max-w-full truncate">{s.name}</span>
        </Link>
    );

    return (
        <aside className="nx-rail fixed inset-y-0 left-0 z-99999 hidden w-[84px] flex-col items-stretch px-2 py-3 md:flex">
            <Link href="/apps" className="mb-2 flex flex-col items-center gap-1 text-[10px] font-semibold tracking-wider text-cyan-200" aria-label="Ma journée">
                <MenentiaSymbol className="h-8 w-8" onDark />
                MA JOURNÉE
            </Link>
            <nav className="flex flex-1 flex-col gap-0.5 overflow-hidden" aria-label="Applications">
                {essentials.map(item)}
                {elsewhere && item(elsewhere)}
            </nav>
            <div className="mt-1 flex flex-col gap-0.5 border-t border-white/10 pt-2">
                {settings && item(settings)}
                <Link href="/apps/toutes" className="nx-rail-item" aria-current={current === 'all' ? 'page' : undefined} title="Toutes les applications">
                    <span className="flex h-8 w-7 items-center justify-center" aria-hidden="true">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
                            {[5, 12, 19].flatMap((y) => [5, 12, 19].map((x) => <circle key={`${x}-${y}`} cx={x} cy={y} r="2.1" />))}
                        </svg>
                    </span>
                    <span>Toutes</span>
                </Link>
            </div>
        </aside>
    );
}

/** Barre du bas (téléphone) : 4 applications + Ma journée. */
function MobileDock({ sections, current, fixed }: { sections: NavSection[]; current?: string; fixed?: string[] }) {
    const pinned = railOrder(sections, fixed).slice(0, 4);
    return (
        <nav className="fixed inset-x-0 bottom-0 z-99999 flex items-end justify-around border-t border-gray-200 bg-white/95 px-1 pt-1.5 pb-[max(6px,env(safe-area-inset-bottom))] backdrop-blur md:hidden dark:border-gray-800 dark:bg-gray-900/95" aria-label="Applications">
            {pinned.map((s) => (
                <Link key={s.key} href={s.path} className={`flex w-16 flex-col items-center gap-0.5 text-[10.5px] ${current === s.key ? 'font-semibold text-blue-700' : 'text-gray-500'}`}>
                    <AppIcon section={s} size="sm" active={current === s.key} />
                    <span className="max-w-full truncate">{s.name}</span>
                </Link>
            ))}
            <Link href="/apps" className="flex w-16 flex-col items-center gap-0.5 text-[10.5px] text-gray-500">
                <MenentiaSymbol className="h-8 w-7" />
                <span>Tout</span>
            </Link>
        </nav>
    );
}

/** Pouls client : 1 à 3 signaux clients propres à l'application ouverte. */
function PulseStrip({ app }: { app?: string }) {
    const [items, setItems] = useState<Pulse[]>([]);
    useEffect(() => {
        setItems([]);
        if (!app) return;
        const ctrl = new AbortController();
        fetch(`/apps/pulse?app=${app}`, { headers: { Accept: 'application/json' }, signal: ctrl.signal })
            .then((r) => (r.ok ? r.json() : { items: [] }))
            .then((d) => setItems(d.items ?? []))
            .catch(() => undefined);
        return () => ctrl.abort();
    }, [app]);

    if (items.length === 0) return null;
    return (
        <div className="no-scrollbar flex items-center gap-2 overflow-x-auto px-4 pb-3 md:px-6">
            <span className="nx-pulse-label shrink-0 text-[11px] font-bold tracking-wider text-gray-400 uppercase">Pouls client</span>
            {items.map((p) => (
                <Link key={p.text} href={p.href} className={`nx-pulse shrink-0 ${p.tone}`}>
                    <i aria-hidden="true" />
                    {p.text}
                </Link>
            ))}
        </div>
    );
}

/**
 * En-tête ClientlyX : rail d'hexagones à gauche (une application = un espace),
 * en-tête clair avec l'application en cours et ses onglets, puis le « pouls
 * client » de cette application.
 */
/** Tiroir du téléphone : compte, applications et quelques réglages rapides. Le reste se fait sur PC. */
function MobileDrawer({ auth, sections, current, onClose, theme, toggleTheme }: {
    auth: NonNullable<SharedAuthProps['auth']>; sections: NavSection[]; current?: string; onClose: () => void; theme: string; toggleTheme: () => void;
}) {
    const org = auth.organization;
    const apps = sections.filter((s) => s.key !== 'platform');
    return (
        <div className="fixed inset-0 z-[100000] md:hidden" role="dialog" aria-modal="true" aria-label="Menu">
            <button type="button" className="absolute inset-0 bg-[#0B1F3A]/40" aria-label="Fermer" onClick={onClose} />
            <div className="nx-drawer absolute inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-t-3xl bg-white px-4 pt-2 pb-[max(16px,env(safe-area-inset-bottom))] text-gray-700 dark:bg-gray-900 dark:text-gray-200">
                <span className="mx-auto mb-3 block h-1.5 w-10 rounded-full bg-gray-200" />
                <div className="flex items-center gap-3">
                    <span className="nx-hex flex h-11 w-10 items-center justify-center bg-gradient-to-br from-cyan-400 to-blue-600 font-bold text-white">{auth.user?.name.charAt(0).toUpperCase()}</span>
                    <div className="min-w-0 flex-1 leading-tight">
                        <b className="block truncate text-[15px] text-[#0B1F3A] dark:text-white">{auth.user?.name}</b>
                        <span className="block truncate text-xs text-gray-500">{org?.name}{auth.user?.role_name ? ` · ${auth.user.role_name}` : ''}</span>
                    </div>
                    {org?.pack_label && <span className="rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700">{org.pack_label}</span>}
                </div>

                <div className="mt-3 grid grid-cols-2 gap-2 text-[12.5px]">
                    <span className="rounded-2xl bg-gray-50 px-3 py-2 dark:bg-white/5">
                        <small className="block text-[11px] text-gray-500">WhatsApp</small>
                        <b className={org?.whatsapp_api_enabled ? 'text-emerald-600' : 'text-amber-600'}>{org?.whatsapp_api_enabled ? 'Automatique' : 'Liens manuels'}</b>
                    </span>
                    <span className="rounded-2xl bg-gray-50 px-3 py-2 dark:bg-white/5">
                        <small className="block text-[11px] text-gray-500">Applications</small>
                        <b>{apps.length} actives</b>
                    </span>
                </div>

                {groupByPillar(apps).map((g) => (
                    <div key={g.key}>
                        <p className="mt-4 mb-2 text-[11px] font-semibold tracking-wide text-gray-400 uppercase">{g.name} <span className="font-normal normal-case">· {g.sub}</span></p>
                        <div className="grid grid-cols-4 gap-y-3">
                            {g.sections.map((s) => (
                                <Link key={s.key} href={s.path} onClick={onClose} className={`flex flex-col items-center gap-1 text-[11px] ${current === s.key ? 'font-semibold text-blue-700' : ''}`}>
                                    <AppIcon section={s} size="md" active={current === s.key} />
                                    <span className="max-w-full truncate px-1">{s.name}</span>
                                </Link>
                            ))}
                        </div>
                    </div>
                ))}

                <div className="mt-4 divide-y divide-gray-100 rounded-2xl border border-gray-100 text-[14px] dark:divide-gray-800 dark:border-gray-800">
                    <button type="button" onClick={toggleTheme} className="flex w-full justify-between px-4 py-3">
                        <span>Thème</span>
                        <span className="text-gray-500">{theme === 'dark' ? 'Sombre' : 'Clair'}</span>
                    </button>
                    <Link href="/aide" onClick={onClose} className="block px-4 py-3">Besoin d’aide ?</Link>
                    <button type="button" onClick={() => router.post('/logout')} className="block w-full px-4 py-3 text-left text-rose-600">{t('Se déconnecter')}</button>
                </div>
                <p className="mt-3 text-center text-[11.5px] text-gray-400">Réglages, imports et configuration : sur ordinateur.</p>
            </div>
        </div>
    );
}

const AppHeader: React.FC = () => {
    const { theme, toggleTheme } = useTheme();
    const { url, props } = usePage<SharedAuthProps>();
    const { auth } = props;
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const [paletteOpen, setPaletteOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);

    const mobile = useIsMobile();
    const allSections = buildSections(navContext(auth));
    const sections = mobile ? forMobile(allSections) : allSections;
    const onAll = url.startsWith('/apps/toutes');
    const onLauncher = url === '/apps' || url.startsWith('/apps?') || onAll;
    const section = onLauncher ? undefined : currentSection(sections, url);
    const tabs = section?.tabs ?? [];

    useEffect(() => {
        if (!userMenuOpen) return;
        const close = (e: MouseEvent) => {
            if (!menuRef.current?.contains(e.target as Node) && !(e.target as Element).closest?.('.nx-drawer')) setUserMenuOpen(false);
        };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [userMenuOpen]);

    useEffect(() => {
        const key = (e: KeyboardEvent) => {
            const typing = e.target instanceof HTMLElement && (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable);
            if ((e.key === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !typing)) {
                e.preventDefault();
                setPaletteOpen(true);
            }
        };
        document.addEventListener('keydown', key);
        return () => document.removeEventListener('keydown', key);
    }, []);

    return (
        <>
            <AppRail sections={sections.filter((s) => s.key !== 'platform')} current={onAll ? 'all' : section?.key} pinned={auth?.organization?.pinned_apps} />
            <MobileDock sections={sections} current={section?.key} fixed={auth?.organization?.pinned_apps} />
            <CommandPalette sections={sections} open={paletteOpen} onClose={() => setPaletteOpen(false)} />

            <header className="sticky top-0 z-9999 w-full border-b border-gray-200 bg-white/90 backdrop-blur dark:border-gray-800 dark:bg-gray-900/90">
                <div className="flex h-14 items-center gap-3 px-4 md:px-6">
                    <Link href="/apps" className="md:hidden" aria-label="Ma journée">
                        <MenentiaSymbol className="h-7 w-7" />
                    </Link>
                    <h1 className="truncate text-[17px] font-bold text-[#0B1F3A] dark:text-white">{section?.name ?? (onAll ? 'Toutes les applications' : 'Ma journée')}</h1>

                    {tabs.length > 1 && (
                        <nav className="no-scrollbar ml-4 hidden h-14 min-w-0 items-end gap-5 overflow-x-auto md:flex" aria-label={`Menu ${section?.name ?? ''}`}>
                            {tabs.map((tab) => (
                                <Link key={tab.path} href={tab.path} aria-current={tabIsActive(url, tab) ? 'page' : undefined} className="nx-tab">
                                    {tab.name === 'Automatisations' ? '⚡ Automatisations' : tab.name}
                                </Link>
                            ))}
                        </nav>
                    )}

                    <div className="ml-auto flex items-center gap-1.5">
                        <button
                            type="button"
                            onClick={() => setPaletteOpen(true)}
                            className="hidden h-9 items-center gap-2 rounded-full border border-gray-200 bg-gray-50 px-3 text-[13px] text-gray-500 hover:border-blue-300 lg:flex dark:border-gray-700 dark:bg-white/5"
                        >
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="7" />
                                <path d="m20 20-3.5-3.5" />
                            </svg>
                            Chercher un client
                            <kbd className="rounded bg-white px-1.5 text-[11px] text-gray-400 dark:bg-gray-800">Ctrl K</kbd>
                        </button>
                        <button type="button" onClick={() => setPaletteOpen(true)} className="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 lg:hidden" aria-label="Recherche rapide">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="7" />
                                <path d="m20 20-3.5-3.5" />
                            </svg>
                        </button>
                        <DemoSwitcher />
                        <NotificationBell />

                        {auth?.user && (
                            <div className="relative" ref={menuRef}>
                                <button
                                    onClick={() => setUserMenuOpen((prev) => !prev)}
                                    className="flex items-center gap-2 rounded-full py-1 pr-2 pl-1 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5"
                                    aria-expanded={userMenuOpen}
                                >
                                    <span className="nx-hex flex h-9 w-8 items-center justify-center bg-gradient-to-br from-cyan-400 to-blue-600 text-[13px] font-bold text-white">
                                        {auth.user.name.charAt(0).toUpperCase()}
                                    </span>
                                    <span className="hidden text-left leading-tight xl:block">
                                        <span className="block">{auth.user.name}</span>
                                        {auth.organization && <span className="block max-w-[180px] truncate text-[11px] font-normal text-gray-500">{auth.organization.name}</span>}
                                    </span>
                                </button>
                                {userMenuOpen && !mobile && (
                                    <div className="absolute right-0 mt-2 w-60 rounded-xl border border-gray-200 bg-white py-1.5 text-gray-700 shadow-theme-lg dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                        <div className="border-b border-gray-100 px-4 py-2 dark:border-gray-800">
                                            <p className="truncate text-xs text-gray-500 dark:text-gray-400">{auth.user.email}</p>
                                            {auth.user.role_name && <p className="mt-0.5 text-xs font-medium">{auth.user.role_name}</p>}
                                            {auth.organization?.pack_label && <p className="mt-1 inline-block rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">Pack {auth.organization.pack_label}</p>}
                                        </div>
                                        {allSections.find((s) => s.key === 'platform') && !mobile && (
                                            <Link href="/admin" className="block w-full px-4 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                                Back-office ClientlyX
                                            </Link>
                                        )}
                                        <div className="flex items-center justify-between px-4 py-2 text-sm">
                                            <span>{t('Langue')}</span>
                                            <span className="inline-flex rounded-full border border-gray-200 p-0.5 text-[12px] font-bold dark:border-gray-700">
                                                {(['fr', 'en'] as const).map((l) => (
                                                    <button
                                                        key={l}
                                                        type="button"
                                                        onClick={() => switchLocale(l)}
                                                        aria-pressed={locale() === l}
                                                        className={`rounded-full px-2.5 py-0.5 ${locale() === l ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'text-gray-500'}`}
                                                    >
                                                        {l.toUpperCase()}
                                                    </button>
                                                ))}
                                            </span>
                                        </div>
                                        <button onClick={toggleTheme} className="block w-full px-4 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                            {theme === 'dark' ? t('Thème clair') : t('Thème sombre')}
                                        </button>
                                        <Link href="/aide" className="block w-full px-4 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                            {t('Besoin d’aide ?')}
                                        </Link>
                                        <button onClick={() => router.post('/logout')} className="block w-full px-4 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                            {t('Se déconnecter')}
                                        </button>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>

                {tabs.length > 1 && (
                    <nav className="no-scrollbar -mt-2 flex gap-5 overflow-x-auto px-4 md:hidden" aria-label={`Menu ${section?.name ?? ''}`}>
                        {tabs.map((tab) => (
                            <Link key={tab.path} href={tab.path} aria-current={tabIsActive(url, tab) ? 'page' : undefined} className="nx-tab">
                                {tab.name === 'Automatisations' ? '⚡ Automatisations' : tab.name}
                            </Link>
                        ))}
                    </nav>
                )}
                <PulseStrip app={section?.key} />
            </header>
            {mobile && userMenuOpen && auth?.user && (
                <MobileDrawer auth={auth} sections={sections} current={section?.key} onClose={() => setUserMenuOpen(false)} theme={theme} toggleTheme={toggleTheme} />
            )}
        </>
    );
};

export default AppHeader;
