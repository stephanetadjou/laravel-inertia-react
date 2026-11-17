import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { AppIcon, appStyle } from '../../layout/apps';
import { buildSections, forMobile, groupByPillar, navContext, NavAuth } from '../../layout/navigation';
import useIsMobile from '../../layout/useIsMobile';
import { formatNumber } from '../../components/dashboard/Charts';

/*
 * Toutes les applications : celles qui sont actives (en nid d'abeilles),
 * puis les modules qu'on peut ajouter, avec le pack qui les comprend.
 */

interface Badge {
    value: number;
    label: string;
    money?: boolean;
    tone?: 'bad';
}

interface Available {
    key: string;
    label: string;
    description: string;
    pack: string;
    allowed: boolean;
}

interface PageProps {
    auth: NavAuth;
    badges: Record<string, Badge>;
    available: Available[];
    canManage: boolean;
    [key: string]: unknown;
}

/** Recherche sans accents ni casse. */
const fold = (text: string) => text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

function AppsAll() {
    const { auth, badges, available: allAvailable, canManage } = usePage<PageProps>().props;
    const mobile = useIsMobile();
    const [query, setQuery] = useState('');
    const input = useRef<HTMLInputElement>(null);
    const q = fold(query.trim());
    const matches = (...texts: string[]) => q === '' || texts.some((t) => fold(t).includes(q));
    const sections = (mobile ? forMobile(buildSections(navContext(auth))) : buildSections(navContext(auth))).filter((s) => s.key !== 'platform');
    const groups = groupByPillar(sections)
        .map((g) => ({ ...g, sections: g.sections.filter((s) => matches(s.name, appStyle(s.key).tagline)) }))
        .filter((g) => g.sections.length > 0);
    const available = allAvailable.filter((m) => matches(m.label, m.description));

    // Focus au chargement, « / » pour revenir dans le champ.
    useEffect(() => {
        if (!mobile) input.current?.focus();
        const onKey = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement;
            if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) && !target.isContentEditable) {
                e.preventDefault();
                input.current?.focus();
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [mobile]);

    return (
        <>
            <Head title="Toutes les applications" />
            <div className="relative mb-6 max-w-xl">
                <svg aria-hidden viewBox="0 0 24 24" className="pointer-events-none absolute top-1/2 left-3.5 h-5 w-5 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" strokeWidth="2">
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.5-3.5" strokeLinecap="round" />
                </svg>
                <input
                    ref={input}
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    onKeyDown={(e) => e.key === 'Escape' && setQuery('')}
                    placeholder="Rechercher un module…"
                    aria-label="Rechercher un module"
                    className="w-full rounded-xl border border-gray-300 bg-white py-2.5 pr-12 pl-11 text-sm text-gray-800 shadow-xs focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90"
                />
                <kbd className="absolute top-1/2 right-3 hidden -translate-y-1/2 rounded border border-gray-200 px-1.5 text-[11px] text-gray-400 sm:block">/</kbd>
            </div>
            {q !== '' && groups.length === 0 && available.length === 0 && <p className="mb-8 text-sm text-gray-500">Aucun module trouvé pour « {query.trim()} ».</p>}
            {groups.map((g) => (
                <section key={g.key} aria-label={g.name} className="mb-8">
                    <h2 className="mb-3 flex items-baseline gap-2 text-[13px] font-bold tracking-wide text-gray-500 uppercase">
                        {g.name} <span className="text-[12px] font-normal normal-case">{g.sub}</span>
                    </h2>
                    <div className="grid grid-cols-3 gap-x-2 gap-y-5 sm:grid-cols-5 lg:grid-cols-8">
                        {g.sections.map((section) => {
                        const badge = badges[section.key];
                        return (
                            <Link key={section.key} href={section.path} className="group flex flex-col items-center gap-1.5 rounded-2xl p-2 text-center transition hover:bg-white dark:hover:bg-white/5">
                                <span className="relative transition group-hover:-translate-y-0.5">
                                    <AppIcon section={section} size="lg" />
                                    {badge && badge.value > 0 && (
                                        <span className={`absolute -top-1 -right-3 rounded-full px-1.5 py-0.5 text-[10.5px] font-bold text-white shadow ${badge.tone === 'bad' ? 'bg-red-500' : 'bg-[#0B1F3A]'}`}>
                                            {badge.money ? `${formatNumber(Math.round(badge.value / 1000))}k` : badge.value}
                                        </span>
                                    )}
                                </span>
                                <span className="text-[13px] leading-tight font-semibold text-gray-800 dark:text-gray-100">{section.name}</span>
                                <span className="text-[11px] leading-tight text-gray-500">{appStyle(section.key).tagline}</span>
                                {q !== '' && <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10.5px] font-semibold text-emerald-700">Installé</span>}
                            </Link>
                        );
                    })}
                    </div>
                </section>
            ))}

            {available.length > 0 && (!mobile || q !== '') && (
                <section aria-label="Modules à ajouter">
                    <div className="mb-4 flex flex-wrap items-end justify-between gap-2">
                        <h2 className="text-[13px] font-bold tracking-wide text-gray-500 uppercase">À ajouter selon vos besoins</h2>
                        {canManage && !mobile && (
                            <Link href="/settings/modules" className="text-sm font-semibold text-blue-700 hover:underline">
                                Gérer les modules →
                            </Link>
                        )}
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {available.map((m) => (
                            <div key={m.key} className="flex flex-col gap-1 rounded-2xl border border-dashed border-gray-300 bg-white/60 p-4 dark:border-gray-700 dark:bg-white/5">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-[14.5px] font-semibold text-gray-900 dark:text-white">{m.label}</span>
                                    <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ${m.allowed ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600'}`}>
                                        {m.allowed ? 'Inclus' : `Pack ${m.pack}`}
                                    </span>
                                </div>
                                <p className="text-[13px] text-gray-500">{m.description}</p>
                                <div className="mt-1 flex items-center justify-between gap-2">
                                    <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600">Disponible</span>
                                    {canManage && (
                                        <Link href="/settings/modules" className="text-[13px] font-semibold text-brand-text hover:underline">
                                            Ajouter →
                                        </Link>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </>
    );
}

AppsAll.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default AppsAll;
