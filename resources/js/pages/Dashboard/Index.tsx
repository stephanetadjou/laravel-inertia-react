import { Head, Link, router, usePage } from "@inertiajs/react";

import AppLayout from "../../layout/AppLayout";
import {
    CalenderIcon,
    ChatIcon,
    DollarLineIcon,
    PaperPlaneIcon,
    ShootingStarIcon,
    TaskIcon,
    TimeIcon,
    UserIcon,
} from "../../icons";
import { formatCompact, formatNumber } from "../../components/dashboard/Charts";
import { useHeaderHeight } from "../../components/dashboard/useHeaderHeight";
import OccupationPanel, {
    OccupationData,
} from "../../components/dashboard/OccupationPanel";
import OnboardingChecklist, {
    OnboardingData,
} from "../../components/common/OnboardingChecklist";
import "./dashboard.css";
import "./home.css";

/*
 * Accueil, page d'arrivée de tous les secteurs (App\Services\HomeOverview) :
 * 1. « Mon activité » : 6 chiffres comparés au mois précédent et à N-1 ;
 * 2. « À faire aujourd'hui » et « Recommandations » côte à côte ;
 * 3. « Comparaison des boutiques » (seulement si plusieurs boutiques visibles) ;
 * 4. « Mon secteur » : tuiles propres au secteur, prochains rendez-vous,
 *    occupation de la semaine.
 */

type Unit = "fcfa" | "n" | "%";

interface Tile {
    key: string;
    label: string;
    unit: Unit;
    definition: string;
    foot?: string;
    href: string;
    value: number;
    previous: number;
    last_year: number | null;
}

interface TodoRow {
    key: string;
    label: string;
    detail: string;
    count: number;
    href: string;
    button: string;
    urgent: boolean;
}

interface Recommendation {
    key: string;
    icon: "clock" | "star" | "user";
    count: number;
    title: string;
    text: string;
    stat: { label: string; value: number | string; money: boolean };
    list_href: string;
    campaign_href: string;
    campaign_label: string;
}

interface StoreRow {
    id: number;
    name: string;
    net: number;
    change: number | null;
    active: number;
    basket: number;
    retention: number;
    identification: number;
}

interface SectorTile {
    key: string;
    label: string;
    value: number;
    money?: boolean;
    percent?: boolean;
    foot?: string;
    href: string;
    button?: string;
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
    header: { first_name: string; organization: string; stores_count: number };
    filters: { period: string; store: number | null };
    periods: { value: string; label: string }[];
    stores: { id: number; name: string }[];
    activity: { period: string; from: string; to: string; previous_label: string; tiles: Tile[] };
    todo: TodoRow[];
    recommendations: Recommendation[];
    comparison: { rows: StoreRow[]; active_sum: number; active_total: number; identification: boolean } | null;
    sector: SectorTile[];
    today: TodayAppointment[];
    occupation: OccupationData | null;
    flash?: { success?: string; error?: string };
    auth?: { organization?: { has_agenda?: boolean } | null };
    [key: string]: unknown;
}

const TODO_ICONS: Record<string, React.ReactNode> = {
    discussions: <ChatIcon />,
    tasks: <TaskIcon />,
    recall: <PaperPlaneIcon />,
    reviews: <UserIcon />,
    loyalty_rewards: <ShootingStarIcon />,
    questions: <ChatIcon />,
    imports: <TaskIcon />,
    overdue: <DollarLineIcon />,
    appointments_today: <CalenderIcon />,
    appointments_requested: <CalenderIcon />,
};

const REC_ICONS = { clock: <TimeIcon />, star: <ShootingStarIcon />, user: <UserIcon /> };

function money(n: number): string {
    return `${formatCompact(n)} FCFA`;
}

function display(value: number, unit: Unit): string {
    if (unit === "fcfa") return money(value);
    if (unit === "%") return `${formatNumber(value)} %`;
    return formatNumber(value);
}

/** Écart vs une base : en % pour les montants et nombres, en points pour les taux. */
function Change({ value, base, unit, label }: { value: number; base: number | null; unit: Unit; label: string }) {
    if (base === null) return null;
    let delta: number | null;
    let suffix: string;
    if (unit === "%") {
        delta = value - base;
        suffix = " pt";
    } else {
        delta = base === 0 ? (value === 0 ? 0 : null) : ((value - base) / Math.abs(base)) * 100;
        suffix = " %";
    }
    if (delta === null) {
        return <span className="mhome-delta flat">— <small>{label}</small></span>;
    }
    const rounded = Math.round(delta * 10) / 10;
    const cls = rounded === 0 ? "flat" : rounded > 0 ? "up" : "down";
    return (
        <span className={`mhome-delta ${cls}`} title={`${label} : ${display(base, unit)}`}>
            {rounded > 0 ? "▲" : rounded < 0 ? "▼" : "="} {Math.abs(rounded).toLocaleString("fr-FR", { maximumFractionDigits: 1 })}
            {suffix} <small>{label}</small>
        </span>
    );
}

function Info({ text }: { text: string }) {
    return (
        <span className="mhome-info" title={text} aria-label={`Définition : ${text}`} role="img">
            i
        </span>
    );
}

function DashboardIndex() {
    const props = usePage<PageProps>().props;
    const { onboarding, header, filters, periods, stores, activity, todo, recommendations, comparison, sector, today, occupation, flash, auth } = props;
    const hasAgenda = auth?.organization?.has_agenda ?? true;
    const headerHeight = useHeaderHeight();
    const day = new Date().toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long", year: "numeric" });

    const apply = (next: Partial<{ period: string; store: number | null }>) => {
        const query = { period: filters.period, store: filters.store, ...next };
        router.get(
            "/dashboard",
            { period: query.period === "month" ? undefined : query.period, store: query.store ?? undefined },
            { preserveScroll: true, preserveState: true },
        );
    };

    const hasActivity = activity.tiles.some((t) => t.value !== 0 || t.previous !== 0);
    const maxNet = Math.max(1, ...(comparison?.rows.map((r) => r.net) ?? [1]));

    return (
        <div className="mdash mhome">
            <Head title="Accueil" />

            <header className="mdash-top" style={{ top: headerHeight }}>
                <div className="mdash-top-in">
                    <div>
                        <h1>Bonjour {header.first_name}</h1>
                        <small className="first-letter:uppercase">
                            {day} · {header.organization}
                            {header.stores_count > 1 ? ` · ${header.stores_count} boutiques` : ""}
                        </small>
                    </div>
                    <div className="mdash-filters">
                        <label className="mdash-fgroup">
                            <span className="mhome-flabel">Période</span>
                            <select className="mhome-select" value={filters.period} onChange={(e) => apply({ period: e.target.value })}>
                                {periods.map((p) => (
                                    <option key={p.value} value={p.value}>
                                        {p.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {stores.length > 1 && (
                            <label className="mdash-fgroup">
                                <span className="mhome-flabel">Boutique</span>
                                <select
                                    className="mhome-select"
                                    value={filters.store ?? ""}
                                    onChange={(e) => apply({ store: e.target.value ? Number(e.target.value) : null })}
                                >
                                    <option value="">Toutes les boutiques</option>
                                    {stores.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                    </div>
                </div>
            </header>

            {flash?.success && <div className="mdash-flash ok">{flash.success}</div>}
            {flash?.error && <div className="mdash-flash bad">{flash.error}</div>}

            {/* « Pour bien démarrer » en tête tant qu'il n'y a aucune activité, sinon en bas de page. */}
            {onboarding && !hasActivity && <OnboardingChecklist data={onboarding} />}

            {/* 1. Mon activité */}
            <section className="mhome-section" aria-labelledby="activity-title">
                <div className="mhome-sh">
                    <h2 id="activity-title">Mon activité</h2>
                    <small>Période comparée {activity.previous_label.replace(/^vs /, "à la ")}</small>
                </div>
                <div className={`mhome-tiles n${activity.tiles.length}`}>
                    {activity.tiles.map((t) => (
                        <Link key={t.key} href={t.href} className="mhome-tile">
                            <span className="mhome-tile-label">
                                {t.label}
                                <Info text={t.definition} />
                            </span>
                            <b className="num">{display(t.value, t.unit)}</b>
                            <span className="mhome-tile-foot">
                                <Change value={t.value} base={t.previous} unit={t.unit} label={activity.period === "year" ? "an préc." : "mois préc."} />
                                {activity.period !== "year" && <Change value={t.value} base={t.last_year} unit={t.unit} label="N-1" />}
                            </span>
                            {t.foot && <small className="mhome-tile-note">{t.foot}</small>}
                        </Link>
                    ))}
                </div>
            </section>

            {/* 2. À faire aujourd'hui + Recommandations */}
            <section className="mhome-cols">
                <article className="mdash-panel" aria-labelledby="todo-title">
                    <div className="mdash-ph">
                        <h2 id="todo-title">
                            À faire aujourd’hui
                            {todo.length > 0 && <span className="mhome-count">{todo.length}</span>}
                        </h2>
                        <Link href="/actions" className="mdash-btn ghost sm">
                            Tout voir
                        </Link>
                    </div>
                    {todo.length === 0 ? (
                        <p className="mdash-empty">Rien d’urgent aujourd’hui. Les relances apparaîtront ici dès qu’un client en aura besoin.</p>
                    ) : (
                        <ul className="mhome-todo">
                            {todo.map((row) => (
                                <li key={row.key}>
                                    <span className={`mhome-badge ${row.urgent ? "urgent" : ""}`}>{formatNumber(row.count)}</span>
                                    <span className="mhome-todo-icon" aria-hidden="true">
                                        {TODO_ICONS[row.key] ?? <PaperPlaneIcon />}
                                    </span>
                                    <span className="mhome-todo-text">
                                        <b>{row.label}</b>
                                        <small title={row.detail}>{row.detail}</small>
                                    </span>
                                    <Link href={row.href} className={`mdash-btn sm ${row.key === "discussions" ? "primary" : "ghost"}`}>
                                        {row.button}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </article>

                <article className="mdash-panel" aria-labelledby="reco-title">
                    <div className="mdash-ph">
                        <h2 id="reco-title">Recommandations</h2>
                        <small className="mdash-sub">Calculées à partir des achats</small>
                    </div>
                    <div className="mhome-recos">
                        {recommendations.map((r) => (
                            <div key={r.key} className={`mhome-reco ${r.count === 0 ? "empty" : ""}`}>
                                <span className={`mhome-reco-icon ${r.icon}`} aria-hidden="true">
                                    {REC_ICONS[r.icon]}
                                </span>
                                <div className="mhome-reco-body">
                                    <b>{r.title}</b>
                                    <p>{r.text}</p>
                                    {r.count > 0 && (
                                        <>
                                            <small className="mhome-reco-stat">
                                                {r.stat.label} : <b>{typeof r.stat.value === "number" && r.stat.money ? money(r.stat.value) : r.stat.value}</b>
                                            </small>
                                            <div className="mhome-reco-actions">
                                                <Link href={r.campaign_href} className="mdash-btn primary sm">
                                                    {r.campaign_label}
                                                </Link>
                                                <Link href={r.list_href} className="mdash-btn ghost sm">
                                                    Voir la liste
                                                </Link>
                                            </div>
                                        </>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </article>
            </section>

            {/* 3. Comparaison des boutiques */}
            {comparison && (
                <section className="mdash-panel mhome-section" aria-labelledby="stores-title">
                    <div className="mdash-ph">
                        <h2 id="stores-title">Comparaison des boutiques</h2>
                        <small className="mdash-sub">Triées par CA net de la période</small>
                    </div>
                    <div className="mhome-table-wrap">
                        <table className="mhome-table">
                            <thead>
                                <tr>
                                    <th>Boutique</th>
                                    <th>CA net</th>
                                    <th>Évolution</th>
                                    <th>Clients actifs</th>
                                    <th>Panier moyen</th>
                                    <th>Rétention</th>
                                    {comparison.identification && <th>Identifiés</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {comparison.rows.map((r) => (
                                    <tr key={r.id}>
                                        <td>
                                            <button type="button" className="font-semibold hover:underline" onClick={() => apply({ store: r.id })}>
                                                {r.name}
                                            </button>
                                        </td>
                                        <td className="mhome-bar-cell">
                                            <span className="mhome-bar" style={{ width: `${Math.max(2, (r.net / maxNet) * 100)}%` }} />
                                            <span className="num">{money(r.net)}</span>
                                        </td>
                                        <td>
                                            {r.change === null ? (
                                                "—"
                                            ) : (
                                                <span className={`mhome-delta ${r.change > 0 ? "up" : r.change < 0 ? "down" : "flat"}`}>
                                                    {r.change > 0 ? "▲" : r.change < 0 ? "▼" : "="} {Math.abs(r.change).toLocaleString("fr-FR")} %
                                                </span>
                                            )}
                                        </td>
                                        <td className="num">{formatNumber(r.active)}</td>
                                        <td className="num">{money(r.basket)}</td>
                                        <td>
                                            <span className={`mdash-pill ${r.retention >= 50 ? "ok" : r.retention >= 40 ? "warn" : "bad"}`}>{r.retention} %</span>
                                        </td>
                                        {comparison.identification && (
                                            <td>
                                                <span className={`mdash-pill ${r.identification >= 65 ? "ok" : r.identification >= 55 ? "warn" : "bad"}`}>{r.identification} %</span>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="mhome-note">
                        Un client peut acheter dans plusieurs boutiques : la somme des clients actifs par boutique ({formatNumber(comparison.active_sum)}) dépasse le nombre de clients
                        actifs de l’entreprise ({formatNumber(comparison.active_total)}).
                    </p>
                </section>
            )}

            {/* 4. Mon secteur */}
            {(sector.length > 0 || (hasAgenda && today.length > 0)) && (
                <section className="mhome-section" aria-labelledby="sector-title">
                    <div className="mhome-sh">
                        <h2 id="sector-title">Mon secteur</h2>
                    </div>
                    {sector.length > 0 && (
                        <div className="mhome-sector">
                            {sector.map((s) => (
                                <div key={s.key} className="mhome-sector-tile">
                                    <span className="mhome-tile-label">{s.label}</span>
                                    <b className="num">{s.money ? money(s.value) : s.percent ? `${formatNumber(s.value)} %` : formatNumber(s.value)}</b>
                                    {s.foot && <small>{s.foot}</small>}
                                    <Link href={s.href} className="mdash-btn ghost sm">
                                        {s.button ?? "Ouvrir"}
                                    </Link>
                                </div>
                            ))}
                        </div>
                    )}
                    {hasAgenda && today.length > 0 && (
                        <article className="mdash-panel mt-4">
                            <div className="mdash-ph">
                                <h2>Prochains rendez-vous</h2>
                                <Link href="/appointments" className="text-xs font-medium text-brand-text hover:underline">
                                    Ouvrir l’agenda →
                                </Link>
                            </div>
                            <ul className="mhome-today">
                                {today.map((a) => (
                                    <li key={a.id}>
                                        <span className="mono">{a.time}</span>
                                        <span className="min-w-0">
                                            {a.customer_id ? (
                                                <Link href={`/customers/${a.customer_id}`} className="block truncate font-medium hover:underline">
                                                    {a.customer}
                                                </Link>
                                            ) : (
                                                <span className="block truncate font-medium">{a.customer ?? "—"}</span>
                                            )}
                                            <small className="block truncate">{[a.service, a.staff].filter(Boolean).join(" · ")}</small>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </article>
                    )}
                </section>
            )}

            {occupation && <OccupationPanel data={occupation} />}

            {onboarding && hasActivity && (
                <div className="mhome-section">
                    <OnboardingChecklist data={onboarding} />
                </div>
            )}
        </div>
    );
}

DashboardIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default DashboardIndex;
