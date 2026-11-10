import { Head, Link, usePage } from "@inertiajs/react";

import AppLayout from "../../layout/AppLayout";
import {
    CalenderIcon,
    ChatIcon,
    DollarLineIcon,
    GroupIcon,
    PaperPlaneIcon,
    TaskIcon,
    UserIcon,
} from "../../icons";
import { formatCompact, formatNumber } from "../../components/dashboard/Charts";
import { Kpi } from "../../components/dashboard/Kpi";
import { useHeaderHeight } from "../../components/dashboard/useHeaderHeight";
import WelcomeBanner from "../../components/dashboard/WelcomeBanner";
import OnboardingChecklist, {
    OnboardingData,
} from "../../components/common/OnboardingChecklist";
import "./dashboard.css";

/*
 * Accueil : l'essentiel pour commencer la journée.
 * 1. « Pour bien démarrer » tant qu'il n'est pas terminé ;
 * 2. 4 chiffres clés adaptés au secteur ;
 * 3. les actions du jour (demandes et discussions à traiter, listes de « À faire »,
 *    prochains rendez-vous).
 * Les analyses détaillées sont dans Analyses, les chiffres par établissement
 * dans Paramètres › Établissements.
 */

interface KpiCard {
    key: string;
    label: string;
    value: number;
    money?: boolean;
    foot?: string;
    href: string;
    tone: "blue" | "cyan" | "indigo" | "amber" | "rose" | "emerald";
}

interface ActionCount {
    key: string;
    label: string;
    description: string;
    count: number;
}

interface TodayAppointment {
    id: number;
    customer_id: number | null;
    customer: string | null;
    time: string;
    service: string | null;
    staff: string | null;
    status: string;
}

interface PageProps {
    onboarding: OnboardingData | null;
    kpis: KpiCard[];
    todo: { discussions: number; tasks: number };
    actions: ActionCount[];
    today: TodayAppointment[];
    flash?: { success?: string; error?: string };
    auth?: { organization?: { has_agenda?: boolean } | null };
    [key: string]: unknown;
}

const ICONS: Record<string, React.ReactNode> = {
    revenue: <DollarLineIcon />,
    appointments_today: <CalenderIcon />,
    to_confirm: <CalenderIcon />,
    to_recall: <PaperPlaneIcon />,
    new_contacts: <UserIcon />,
    active_subscriptions: <GroupIcon />,
    overdue: <DollarLineIcon />,
    open_prospects: <GroupIcon />,
    quotes: <TaskIcon />,
    buyers: <GroupIcon />,
    at_risk: <UserIcon />,
};

function DashboardIndex() {
    const { onboarding, kpis, todo, actions, today, flash, auth } =
        usePage<PageProps>().props;
    const hasAgenda = auth?.organization?.has_agenda ?? true;
    const headerHeight = useHeaderHeight();
    const nothingToDo =
        todo.discussions === 0 && todo.tasks === 0 && actions.length === 0;
    const day = new Date().toLocaleDateString("fr-FR", {
        weekday: "long",
        day: "numeric",
        month: "long",
    });

    return (
        <div className="mdash">
            <Head title="Accueil" />

            <header className="mdash-top" style={{ top: headerHeight }}>
                <div className="mdash-top-in">
                    <div>
                        <h1>Accueil</h1>
                        <small className="first-letter:uppercase">{day}</small>
                    </div>
                </div>
            </header>

            {flash?.success && (
                <div className="mdash-flash ok">{flash.success}</div>
            )}
            {flash?.error && (
                <div className="mdash-flash bad">{flash.error}</div>
            )}

            <WelcomeBanner />

            {onboarding && <OnboardingChecklist data={onboarding} />}

            <section className="mdash-grid" aria-label="Chiffres clés">
                <div className="c12 mdash-kpis">
                    {kpis.map((k, i) => (
                        <Kpi
                            key={k.key}
                            hero={i === 0}
                            label={k.label}
                            icon={ICONS[k.key]}
                            tone={k.tone}
                            href={k.href}
                            value={
                                k.money
                                    ? formatCompact(k.value)
                                    : formatNumber(k.value)
                            }
                            unit={k.money ? "FCFA" : undefined}
                            delta={null}
                            foot={k.foot}
                        />
                    ))}
                </div>
            </section>

            <section className="mdash-grid mt-4" aria-label="Actions du jour">
                <article className={`mdash-panel ${hasAgenda ? "c8" : "c12"}`}>
                    <div className="mdash-ph">
                        <div>
                            <h2>Actions du jour</h2>
                            <small className="mdash-sub">
                                Ce qui attend une réponse ou une relance
                            </small>
                        </div>
                        <Link href="/actions" className="mdash-btn ghost sm">
                            Tout voir
                        </Link>
                    </div>
                    {nothingToDo ? (
                        <p className="py-8 text-center text-sm text-gray-500">
                            Rien à traiter pour l’instant. Les relances
                            apparaîtront ici dès qu’un client en aura besoin.
                        </p>
                    ) : (
                        <ul className="-mx-2 divide-y divide-gray-100 dark:divide-gray-800">
                            {todo.discussions > 0 && (
                                <TodoRow
                                    href="/discussions?filter=todo"
                                    icon={<ChatIcon />}
                                    label="Discussions à traiter"
                                    detail="Clients qui attendent une réponse sur WhatsApp"
                                    count={todo.discussions}
                                    urgent
                                />
                            )}
                            {todo.tasks > 0 && (
                                <TodoRow
                                    href="/actions?list=tasks"
                                    icon={<TaskIcon />}
                                    label="Demandes à traiter"
                                    detail="Parler à quelqu’un, être rappelé, rendez-vous à recaser"
                                    count={todo.tasks}
                                    urgent
                                />
                            )}
                            {actions.map((a) => (
                                <TodoRow
                                    key={a.key}
                                    href={`/actions?list=${a.key}`}
                                    icon={<PaperPlaneIcon />}
                                    label={a.label}
                                    detail={a.description}
                                    count={a.count}
                                />
                            ))}
                        </ul>
                    )}
                </article>

                {hasAgenda && (
                    <article className="mdash-panel c4">
                        <div className="mdash-ph">
                            <div>
                                <h2>Aujourd’hui</h2>
                                <small className="mdash-sub">
                                    Prochains rendez-vous
                                </small>
                            </div>
                        </div>
                        {today.length === 0 ? (
                            <p className="py-8 text-center text-sm text-gray-500">
                                Aucun rendez-vous à venir aujourd’hui.
                            </p>
                        ) : (
                            <ul className="-mx-2 divide-y divide-gray-100 dark:divide-gray-800">
                                {today.map((a) => (
                                    <li
                                        key={a.id}
                                        className="flex items-start gap-3 px-2 py-3 text-sm"
                                    >
                                        <span className="mono w-12 shrink-0 font-semibold text-brand-text">
                                            {a.time}
                                        </span>
                                        <span className="min-w-0">
                                            {a.customer_id ? (
                                                <Link
                                                    href={`/customers/${a.customer_id}`}
                                                    className="block truncate font-medium hover:underline"
                                                >
                                                    {a.customer}
                                                </Link>
                                            ) : (
                                                <span className="block truncate font-medium">
                                                    {a.customer ?? "—"}
                                                </span>
                                            )}
                                            <span className="block truncate text-xs text-gray-500">
                                                {[a.service, a.staff]
                                                    .filter(Boolean)
                                                    .join(" · ")}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <div className="pt-2">
                            <Link
                                href="/appointments"
                                className="text-xs font-medium text-brand-text hover:underline"
                            >
                                Ouvrir l’agenda →
                            </Link>
                        </div>
                    </article>
                )}
            </section>
        </div>
    );
}

function TodoRow({
    href,
    icon,
    label,
    detail,
    count,
    urgent = false,
}: {
    href: string;
    icon: React.ReactNode;
    label: string;
    detail: string;
    count: number;
    urgent?: boolean;
}) {
    return (
        <li>
            <Link
                href={href}
                className="flex items-center gap-3 rounded-lg px-2 py-3 text-sm hover:bg-gray-50 dark:hover:bg-white/5"
            >
                <span
                    className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${urgent ? "bg-warning-50 text-warning-600" : "bg-brand-50 text-brand-text"} [&_svg]:h-5 [&_svg]:w-5`}
                    aria-hidden="true"
                >
                    {icon}
                </span>
                <span className="min-w-0 flex-1">
                    <b className="block truncate">{label}</b>
                    <span className="block truncate text-xs text-gray-500">
                        {detail}
                    </span>
                </span>
                <span className={`mdash-pill ${urgent ? "warn" : "info"}`}>
                    {formatNumber(count)}
                </span>
            </Link>
        </li>
    );
}

DashboardIndex.layout = (page: React.ReactNode) => (
    <AppLayout>{page}</AppLayout>
);

export default DashboardIndex;
