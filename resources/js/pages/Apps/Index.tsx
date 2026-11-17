import { Head, Link, usePage } from '@inertiajs/react';

import AppLayout from '../../layout/AppLayout';
import { AppIcon, appStyle } from '../../layout/apps';
import { buildSections, forMobile, groupByPillar, navContext, NavAuth } from '../../layout/navigation';
import useIsMobile from '../../layout/useIsMobile';
import { formatNumber } from '../../components/dashboard/Charts';

/*
 * « Ma journée » : à gauche, le fil des clients du jour (qui vient, qui
 * attend une réponse, qui doit payer, qui s'éloigne) ; à droite, les
 * applications en nid d'abeilles, chacune avec son chiffre client du jour.
 */

interface DayCustomer {
    id: number;
    name: string;
}

interface Day {
    appointments: { time: string; customer: DayCustomer | null; what: string }[];
    quotes: { id: number; customer: DayCustomer | null; what: string; amount: number; days: number }[];
    balances: { id: number; customer: DayCustomer | null; what: string; amount: number; late: boolean }[];
    drifting: { customer: DayCustomer | null; since: number | null }[];
}

interface Badge {
    value: number;
    label: string;
    money?: boolean;
    tone?: 'bad';
}

interface PageProps {
    auth: NavAuth & { user: { name: string } | null; organization?: { name?: string; pack_label?: string | null; automation?: boolean } | null };
    badges: Record<string, Badge>;
    locked: Record<string, string>;
    greeting: string;
    day: Day;
    [key: string]: unknown;
}

function FeedBlock({ title, dot, empty, children, count }: { title: string; dot: string; empty: string; children: React.ReactNode; count: number }) {
    return (
        <section className={`rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900 ${count === 0 ? 'hidden md:block' : ''}`}>
            <h2 className="mb-2 flex items-center gap-2 text-[13px] font-bold tracking-wide text-gray-500 uppercase">
                <span className={`h-2 w-2 rounded-full ${dot}`} aria-hidden="true" />
                {title}
                {count > 0 && <span className="ml-auto rounded-full bg-gray-100 px-2 text-[12px] text-gray-600 dark:bg-white/10 dark:text-gray-300">{count}</span>}
            </h2>
            {count === 0 ? <p className="py-2 text-[13.5px] text-gray-400">{empty}</p> : <ul className="divide-y divide-gray-100 dark:divide-gray-800">{children}</ul>}
        </section>
    );
}

function Who({ customer }: { customer: DayCustomer | null }) {
    if (!customer) return <span className="text-gray-400">Client anonyme</span>;
    return (
        <Link href={`/customers/${customer.id}`} className="font-semibold text-gray-900 hover:text-blue-700 dark:text-white">
            {customer.name}
        </Link>
    );
}

function AppsIndex() {
    const { auth, badges, greeting, day, locked } = usePage<PageProps>().props;
    const mobile = useIsMobile();
    const sections = (mobile ? forMobile(buildSections(navContext(auth))) : buildSections(navContext(auth))).filter((s) => s.key !== 'platform');
    const ordered = groupByPillar(sections).flatMap((g) => g.sections);
    const first = (auth.user?.name ?? '').split(' ').find((w) => !['Dr', 'Dr.', 'M.', 'Mme', 'Mlle', 'Pr'].includes(w)) ?? '';
    const canManage = auth.user?.can_manage_organization ?? false;
    const todo = badges.actions?.value ?? 0;
    const today = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
    const li = 'flex items-center gap-3 py-2 text-[14px]';

    return (
        <>
            <Head title="Ma journée" />
            <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p className="text-[13px] font-medium text-cyan-700 capitalize dark:text-cyan-300">{today}</p>
                    <h1 className="text-[22px] leading-tight font-bold text-[#0B1F3A] sm:text-[28px] dark:text-white">
                        {greeting} {first}, voici vos clients du jour.
                    </h1>
                    <p className="mt-1 text-[14.5px] text-gray-500">
                        {todo > 0 ? `${todo} action${todo > 1 ? 's' : ''} client${todo > 1 ? 's' : ''} vous attend${todo > 1 ? 'ent' : ''}.` : 'Tous vos clients sont à jour.'}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {auth.organization?.pack_label && <span className="hidden rounded-full md:inline border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">Pack {auth.organization.pack_label}</span>}
                    {todo > 0 && (
                        <Link href="/actions" className="rounded-full bg-gradient-to-r from-blue-700 to-cyan-500 px-4 py-2 text-sm font-semibold text-white shadow hover:opacity-95">
                            Commencer les relances
                        </Link>
                    )}
                </div>
            </div>

            <div className="nx-pillars mb-5 grid grid-cols-2 gap-2 sm:grid-cols-4" aria-label="Votre cycle client">
                {[
                    { k: 'Engage', v: day.quotes.length, t: 'à relancer', href: '/actions' },
                    { k: 'Understand', v: badges.customers?.value ?? 0, t: 'clients à risque', href: '/customers' },
                    { k: 'Convert', v: day.appointments.length, t: 'RDV aujourd’hui', href: '/appointments' },
                    { k: 'Retain', v: day.drifting.length, t: 'qui s’éloignent', href: '/actions' },
                ].map((p) => (
                    <Link key={p.k} href={p.href} className={`nx-pillar nx-pillar-${p.k.toLowerCase()}`}>
                        <small>{p.k}</small>
                        <b>{p.v}</b>
                        <span>{p.t}</span>
                    </Link>
                ))}
            </div>

            {auth.organization?.automation === false && (
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <span>
                        <b>Offre Gratuit :</b> vos relances partent à la main par lien WhatsApp. Passez à <b>Solo</b> pour les rappels, réponses et relances automatiques.
                    </span>
                    <Link href="/settings/modules" className="font-semibold underline">
                        Voir les offres
                    </Link>
                </div>
            )}

            <div className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
                <div className="flex flex-col gap-3">
                    <FeedBlock title="Ils viennent aujourd’hui" dot="bg-violet-500" empty="Aucun rendez-vous aujourd’hui." count={day.appointments.length}>
                        {day.appointments.map((a, i) => (
                            <li key={i} className={li}>
                                <span className="w-12 shrink-0 font-mono text-[13px] font-semibold text-violet-700">{a.time}</span>
                                <span className="min-w-0 flex-1 truncate">
                                    <Who customer={a.customer} /> <span className="text-gray-500">· {a.what}</span>
                                </span>
                            </li>
                        ))}
                    </FeedBlock>
                    <FeedBlock title="Ils attendent votre relance" dot="bg-amber-500" empty="Aucun devis en attente." count={day.quotes.length}>
                        {day.quotes.map((q) => (
                            <li key={q.id} className={li}>
                                <span className="min-w-0 flex-1 truncate">
                                    <Who customer={q.customer} /> <span className="text-gray-500">· {q.what}</span>
                                </span>
                                <span className="shrink-0 text-[12.5px] text-amber-700">{q.days === 0 ? 'envoyé aujourd’hui' : `depuis ${q.days} j`}</span>
                                <Link href={`/invoices/${q.id}`} className="shrink-0 rounded-full border border-amber-200 px-2.5 py-0.5 text-[12.5px] font-semibold text-amber-800 hover:bg-amber-50">
                                    Relancer
                                </Link>
                            </li>
                        ))}
                    </FeedBlock>
                    <FeedBlock title="Ils doivent encore payer" dot="bg-red-500" empty="Aucun solde à encaisser." count={day.balances.length}>
                        {day.balances.map((b) => (
                            <li key={b.id} className={li}>
                                <span className="min-w-0 flex-1 truncate">
                                    <Who customer={b.customer} /> <span className="text-gray-500">· {b.what}</span>
                                </span>
                                <Link href={`/invoices/${b.id}`} className={`shrink-0 text-[13px] font-semibold ${b.late ? 'text-red-600' : 'text-gray-700 dark:text-gray-200'}`}>
                                    {formatNumber(b.amount)} F{b.late ? ' · en retard' : ''}
                                </Link>
                            </li>
                        ))}
                    </FeedBlock>
                    <FeedBlock title="Ils s’éloignent" dot="bg-blue-500" empty="Aucun client à risque." count={day.drifting.length}>
                        {day.drifting.map((d, i) => (
                            <li key={i} className={li}>
                                <span className="min-w-0 flex-1 truncate">
                                    <Who customer={d.customer} />
                                </span>
                                {d.since !== null && <span className="shrink-0 text-[12.5px] text-gray-500">dernière visite il y a {d.since} j</span>}
                            </li>
                        ))}
                    </FeedBlock>
                </div>

                <section aria-label="Applications">
                    <h2 className="mb-3 text-[13px] font-bold tracking-wide text-gray-500 uppercase">Vos applications</h2>
                    <div className="nx-comb pb-12">
                        {ordered.map((section) => {
                            const badge = badges[section.key];
                            return (
                                <Link key={section.key} href={section.path} className="group flex flex-col items-center gap-1.5 rounded-2xl p-2 text-center transition hover:bg-white dark:hover:bg-white/5" title={appStyle(section.key).tagline}>
                                    <span className="relative transition group-hover:-translate-y-0.5">
                                        <AppIcon section={section} size="lg" />
                                        {badge && badge.value > 0 && (
                                            <span className={`absolute -top-1 -right-3 rounded-full px-1.5 py-0.5 text-[10.5px] font-bold text-white shadow ${badge.tone === 'bad' ? 'bg-red-500' : 'bg-[#0B1F3A]'}`}>
                                                {badge.money ? `${formatNumber(Math.round(badge.value / 1000))}k` : badge.value}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-[13px] leading-tight font-semibold text-gray-800 dark:text-gray-100">{section.name}</span>
                                    {badge && badge.value > 0 && <span className="text-[11px] leading-tight text-gray-500">{badge.label}</span>}
                                </Link>
                            );
                        })}
                        {canManage && !mobile && (
                            <Link href="/settings/modules" className="group flex flex-col items-center gap-1.5 rounded-2xl p-2 text-center text-gray-500 hover:text-blue-700">
                                <span className="nx-hex flex h-16 w-14 items-center justify-center bg-gray-200 text-2xl font-light text-gray-500 transition group-hover:bg-blue-100 dark:bg-white/10">+</span>
                                <span className="text-[13px] leading-tight font-semibold">Ajouter</span>
                                {Object.keys(locked ?? {}).length > 0 && <span className="text-[11px] text-gray-400">{Object.keys(locked).length} en option</span>}
                            </Link>
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}

AppsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default AppsIndex;
