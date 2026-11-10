import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, PlusIcon } from '../../components/page/Page';
import AppointmentFormModal, { AppointmentRow, CatalogEntry, StaffOption, formatPrice } from '../../components/appointments/AppointmentFormModal';
import CheckoutModal from '../../components/appointments/CheckoutModal';
import WhatsAppIcon from '../../components/common/WhatsAppIcon';
import { Delta, percentChange } from '../../components/dashboard/Kpi';
import './calendar.css';

/*
 * Agenda en calendrier : vues Mois, Semaine et Jour (grille horaire), à la
 * manière d'Odoo. Clic sur un jour ou un créneau libre : nouveau rendez-vous ;
 * clic sur un rendez-vous : fiche rapide (statut, rappel WhatsApp, modifier).
 * À droite : mini-calendrier et prochains rendez-vous.
 */

type View = 'day' | 'week' | 'month';

/** Séance d'un cours de groupe (module « seances_groupe »), affichée avec les rendez-vous. */
interface ClassSessionRow {
    id: number;
    date: string;
    start_time: string;
    end_time: string;
    name: string;
    coach: string | null;
    store: string | null;
    enrolled: number;
    capacity: number;
    status: 'scheduled' | 'cancelled';
    is_makeup: boolean;
}

interface PageProps {
    rdvEnabled: boolean;
    classSessions: ClassSessionRow[];
    view: View;
    date: string;
    from: string;
    to: string;
    store: number | null;
    stores: { id: number; name: string }[];
    staff: StaffOption[];
    staffFilter: number | null;
    closures: { label: string; starts_on: string; ends_on: string; all_stores: boolean }[];
    breaks: { start: string; end: string }[];
    statuses: Record<string, string>;
    appointments: AppointmentRow[];
    upcoming: AppointmentRow[];
    catalog: CatalogEntry[];
    paymentMethods: Record<string, string>;
    stats: AgendaStats | null;
    bookingUrl: string | null;
    bookingEnabled: boolean;
    reminders: { available: boolean; enabled: boolean; canManage: boolean };
    [key: string]: unknown;
}

/** Chiffres des cartes : 6 derniers mois (mois en cours en dernier). */
interface AgendaStats {
    months: string[];
    total: number[];
    done: number[];
    revenue: number[];
    upcoming: number;
    toConfirm: number;
    upcomingWeeks: number[];
}

const WEEKDAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
const HOUR_HEIGHT = 56;

const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parseDay = (day: string) => new Date(`${day}T12:00:00`);
const addDays = (d: Date, n: number) => {
    const c = new Date(d);
    c.setDate(c.getDate() + n);
    return c;
};
/** Lundi de la semaine de d. */
const mondayOf = (d: Date) => addDays(d, -((d.getDay() + 6) % 7));
const hhmm = (a: AppointmentRow) => a.starts_at.slice(11, 16).replace(':', 'h');
const minutesOf = (a: AppointmentRow) => Number(a.starts_at.slice(11, 13)) * 60 + Number(a.starts_at.slice(14, 16));

/**
 * Rendez-vous qui se chevauchent : côte à côte, chacun dans une colonne
 * (première colonne libre), sur la largeur partagée de son groupe.
 */
function layoutDay(items: AppointmentRow[]): Map<number, { lane: number; lanes: number }> {
    const sorted = [...items].sort((a, b) => minutesOf(a) - minutesOf(b));
    const result = new Map<number, { lane: number; lanes: number }>();
    let group: AppointmentRow[] = [];
    let laneEnds: number[] = [];
    let groupEnd = -1;

    const flush = () => {
        group.forEach((a) => result.set(a.id, { lane: result.get(a.id)!.lane, lanes: laneEnds.length }));
        group = [];
        laneEnds = [];
    };

    for (const a of sorted) {
        const start = minutesOf(a);
        const end = start + a.duration_minutes;
        if (start >= groupEnd) flush();
        let lane = laneEnds.findIndex((e) => e <= start);
        if (lane === -1) {
            lane = laneEnds.length;
            laneEnds.push(end);
        } else {
            laneEnds[lane] = end;
        }
        result.set(a.id, { lane, lanes: 1 });
        group.push(a);
        groupEnd = Math.max(groupEnd, end);
    }
    flush();

    return result;
}

function shortName(name: string | null): string {
    const parts = (name ?? '').trim().split(/\s+/);
    return parts.length > 1 ? `${parts[0]} ${parts[parts.length - 1][0]}.` : (parts[0] ?? '');
}

function title(view: View, date: string): string {
    const label = rawTitle(view, date);
    return label.charAt(0).toUpperCase() + label.slice(1);
}

function rawTitle(view: View, date: string): string {
    const d = parseDay(date);
    if (view === 'month') return d.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
    if (view === 'day') return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    const start = mondayOf(d);
    const end = addDays(start, 6);
    const sameMonth = start.getMonth() === end.getMonth();
    return `${start.toLocaleDateString('fr-FR', sameMonth ? { day: 'numeric' } : { day: 'numeric', month: 'short' })} – ${end.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}`;
}

function shift(view: View, date: string, direction: 1 | -1): string {
    const d = parseDay(date);
    if (view === 'month') return iso(new Date(d.getFullYear(), d.getMonth() + direction, 1, 12));
    return iso(addDays(d, view === 'week' ? 7 * direction : direction));
}

/** Fiche rapide d'un rendez-vous : statut, rappel WhatsApp, modifier, supprimer. */
function EventCard({ appointment: a, statuses, multiStore, onClose, onEdit, onCheckout }: { appointment: AppointmentRow; statuses: Record<string, string>; multiStore: boolean; onClose: () => void; onEdit: () => void; onCheckout: () => void }) {
    function setStatus(status: string) {
        router.patch(`/appointments/${a.id}/status`, { status }, { preserveScroll: true, onSuccess: onClose });
    }

    function destroy() {
        if (confirm(`Supprimer le rendez-vous de ${a.customer_name} ?`)) {
            router.delete(`/appointments/${a.id}`, { preserveScroll: true, onSuccess: onClose });
        }
    }

    const day = new Date(a.starts_at).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });

    return (
        <div className="mcal-overlay" onClick={onClose}>
            <div className="mcal-card" role="dialog" aria-label={`Rendez-vous de ${a.customer_name}`} onClick={(e) => e.stopPropagation()}>
                <button type="button" className="mcal-card-x" onClick={onClose} aria-label="Fermer">
                    ×
                </button>
                <span className={`mcal-dot st-${a.status}`} />
                <h3>
                    <Link href={`/customers/${a.customer_id}`}>{a.customer_name}</Link>
                    {a.source === 'online' && <span className="mcal-online">En ligne</span>}
                </h3>
                <p className="mcal-card-when">
                    {day} · {hhmm(a)} ({a.duration_minutes} min)
                </p>
                <dl>
                    {a.service && (
                        <>
                            <dt>Prestation</dt>
                            <dd>{a.service}</dd>
                        </>
                    )}
                    {a.price > 0 && (
                        <>
                            <dt>Prix prévu</dt>
                            <dd>{formatPrice(a.price)}</dd>
                        </>
                    )}
                    {a.paid_amount !== null && (
                        <>
                            <dt>Encaissé</dt>
                            <dd>
                                <b>{formatPrice(a.paid_amount)}</b>
                            </dd>
                        </>
                    )}
                    {a.staff_name && (
                        <>
                            <dt>Reçu par</dt>
                            <dd>{a.staff_name}</dd>
                        </>
                    )}
                    {multiStore && (
                        <>
                            <dt>Établissement</dt>
                            <dd>{a.store_name}</dd>
                        </>
                    )}
                    <dt>Téléphone</dt>
                    <dd className="mono">{a.phone_e164}</dd>
                    {a.notes && (
                        <>
                            <dt>Notes</dt>
                            <dd>{a.notes}</dd>
                        </>
                    )}
                </dl>
                <label className="mcal-card-status">
                    <span>Statut</span>
                    <select value={a.status} onChange={(e) => setStatus(e.target.value)}>
                        {Object.entries(statuses).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                </label>
                <div className="mcal-card-actions">
                    {a.status === 'requested' && (
                        <button type="button" className="mdash-btn brand sm" onClick={() => setStatus('confirmed')}>
                            Confirmer
                        </button>
                    )}
                    {a.purchase_id === null && a.status !== 'cancelled' && a.status !== 'requested' && (
                        <button type="button" className="mdash-btn brand sm" onClick={onCheckout}>
                            Encaisser
                        </button>
                    )}
                    {a.whatsapp_url && ['planned', 'confirmed'].includes(a.status) && (
                        <a href={a.whatsapp_url} target="_blank" rel="noopener noreferrer" className="mdash-btn wa sm">
                            <WhatsAppIcon />
                            Rappeler
                        </a>
                    )}
                    <button type="button" className="mdash-btn ghost sm" onClick={onEdit}>
                        Modifier
                    </button>
                    <button type="button" className="mdash-btn danger sm" onClick={destroy}>
                        Supprimer
                    </button>
                </div>
            </div>
        </div>
    );
}

function Chip({ a, onOpen, compact }: { a: AppointmentRow; onOpen: (a: AppointmentRow) => void; compact?: boolean }) {
    return (
        <button
            type="button"
            className={`mcal-chip st-${a.status}`}
            onClick={(e) => {
                e.stopPropagation();
                onOpen(a);
            }}
            title={`${hhmm(a)} · ${a.customer_name}${a.service ? ` · ${a.service}` : ''}`}
        >
            <b>{hhmm(a)}</b> {compact ? shortName(a.customer_name) : a.customer_name}
            {a.source === 'online' && <i className="mcal-web" title="Réservé en ligne" />}
        </button>
    );
}

function MonthGrid({
    date,
    from,
    byDay,
    today,
    onOpen,
    onNew,
    onDay,
    closedOn,
}: {
    date: string;
    from: string;
    byDay: Record<string, AppointmentRow[]>;
    today: string;
    onOpen: (a: AppointmentRow) => void;
    onNew: (start: string) => void;
    onDay: (day: string) => void;
    closedOn: (day: string) => string | null;
}) {
    const month = parseDay(date).getMonth();
    const start = parseDay(from);
    const days = Array.from({ length: 42 }, (_, i) => addDays(start, i));
    // Ne garder que les semaines qui touchent le mois (5 ou 6 lignes).
    const weeks = Array.from({ length: 6 }, (_, w) => days.slice(w * 7, w * 7 + 7)).filter((week) => week.some((d) => d.getMonth() === month));

    return (
        <div className="mcal-month">
            <div className="mcal-head">
                {WEEKDAYS.map((d) => (
                    <span key={d}>{d}</span>
                ))}
            </div>
            {weeks.map((week) => (
                <div key={iso(week[0])} className="mcal-week">
                    {week.map((d) => {
                        const day = iso(d);
                        const items = byDay[day] ?? [];
                        const closed = closedOn(day);
                        return (
                            <div
                                key={day}
                                className={`mcal-cell ${d.getMonth() !== month ? 'out' : ''} ${day === today ? 'today' : ''} ${closed ? 'closed' : ''}`}
                                onClick={() => onNew(`${day}T10:00`)}
                                role="gridcell"
                                aria-label={d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })}
                            >
                                <button
                                    type="button"
                                    className="mcal-num"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onDay(day);
                                    }}
                                    title="Voir la journée"
                                >
                                    {d.getDate()}
                                </button>
                                {closed && <span className="mcal-closed">{closed}</span>}
                                <div className="mcal-chips">
                                    {items.slice(0, 3).map((a) => (
                                        <Chip key={a.id} a={a} onOpen={onOpen} compact />
                                    ))}
                                    {items.length > 3 && (
                                        <button
                                            type="button"
                                            className="mcal-more"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                onDay(day);
                                            }}
                                        >
                                            + {items.length - 3} autre{items.length - 3 > 1 ? 's' : ''}
                                        </button>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            ))}
        </div>
    );
}

function TimeGrid({
    days,
    byDay,
    today,
    onOpen,
    onNew,
    wide,
    closedOn,
    breaks,
    sessions,
}: {
    sessions: ClassSessionRow[];
    days: string[];
    byDay: Record<string, AppointmentRow[]>;
    today: string;
    onOpen: (a: AppointmentRow) => void;
    onNew: (start: string) => void;
    wide: boolean;
    closedOn: (day: string) => string | null;
    breaks: { start: string; end: string }[];
}) {
    const all = days.flatMap((d) => byDay[d] ?? []);
    // Plage horaire : 8 h – 20 h, élargie aux rendez-vous plus tôt ou plus tard.
    const toMinutes = (t: string) => Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5));
    const first = Math.min(8, ...all.map((a) => Math.floor(minutesOf(a) / 60)), ...sessions.map((s) => Math.floor(toMinutes(s.start_time) / 60)));
    const last = Math.max(20, ...all.map((a) => Math.ceil((minutesOf(a) + a.duration_minutes) / 60)), ...sessions.map((s) => Math.ceil(toMinutes(s.end_time) / 60)));
    const hours = Array.from({ length: last - first }, (_, i) => first + i);
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const timer = setInterval(() => setNow(new Date()), 60000);
        return () => clearInterval(timer);
    }, []);

    const nowMinutes = now.getHours() * 60 + now.getMinutes();
    const layouts: Record<string, Map<number, { lane: number; lanes: number }>> = {};

    return (
        <div className="mcal-time" style={{ '--cols': days.length } as React.CSSProperties}>
            <div className="mcal-time-head">
                <span />
                {days.map((day) => {
                    const d = parseDay(day);
                    return (
                        <span key={day} className={day === today ? 'today' : ''}>
                            {d.toLocaleDateString('fr-FR', { weekday: 'short' })} <b>{d.getDate()}</b>
                        </span>
                    );
                })}
            </div>
            <div className="mcal-time-body">
                <div className="mcal-hours">
                    {hours.map((h) => (
                        <span key={h} style={{ height: HOUR_HEIGHT }}>
                            {pad(h)}h
                        </span>
                    ))}
                </div>
                {days.map((day) => (
                    <div
                        key={day}
                        className={`mcal-col ${day === today ? 'today' : ''} ${closedOn(day) ? 'closed' : ''}`}
                        style={{ height: hours.length * HOUR_HEIGHT }}
                        onClick={(e) => {
                            // Créneau cliqué, arrondi à la demi-heure.
                            const y = e.clientY - e.currentTarget.getBoundingClientRect().top;
                            const minutes = first * 60 + Math.floor((y / HOUR_HEIGHT) * 2) * 30;
                            onNew(`${day}T${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}`);
                        }}
                    >
                        {hours.map((h) => (
                            <i key={h} style={{ top: (h - first) * HOUR_HEIGHT }} />
                        ))}
                        {closedOn(day) ? (
                            <span className="mcal-closed-col">{closedOn(day)}</span>
                        ) : (
                            breaks.map((b) => {
                                const toMin = (t: string) => Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5));
                                return (
                                    <span
                                        key={`${b.start}-${b.end}`}
                                        className="mcal-break"
                                        style={{ top: ((toMin(b.start) - first * 60) / 60) * HOUR_HEIGHT, height: ((toMin(b.end) - toMin(b.start)) / 60) * HOUR_HEIGHT }}
                                    >
                                        Pause
                                    </span>
                                );
                            })
                        )}
                        {day === today && nowMinutes >= first * 60 && nowMinutes <= last * 60 && (
                            <em className="mcal-now" style={{ top: ((nowMinutes - first * 60) / 60) * HOUR_HEIGHT }} />
                        )}
                        {sessions
                            .filter((s) => s.date === day)
                            .map((s) => {
                                const top = ((toMinutes(s.start_time) - first * 60) / 60) * HOUR_HEIGHT;
                                const height = Math.max(24, ((toMinutes(s.end_time) - toMinutes(s.start_time)) / 60) * HOUR_HEIGHT - 2);
                                return (
                                    <Link
                                        key={`class-${s.id}`}
                                        href={`/class-sessions/${s.id}`}
                                        className={`mcal-event mcal-class ${s.status === 'cancelled' ? 'st-cancelled' : ''}`}
                                        style={{ top, height }}
                                        onClick={(e) => e.stopPropagation()}
                                        title={`${s.name} · ${s.enrolled}/${s.capacity} inscrits${s.coach ? ` · ${s.coach}` : ''}`}
                                    >
                                        <b>
                                            {s.start_time.replace(':', 'h')} {s.name}
                                            {s.is_makeup ? ' (rattrapage)' : ''}
                                        </b>
                                        {height > 34 && <span>{s.status === 'cancelled' ? 'Annulé' : [`${s.enrolled}/${s.capacity}`, s.coach, wide ? s.store : null].filter(Boolean).join(' · ')}</span>}
                                    </Link>
                                );
                            })}
                        {(byDay[day] ?? []).map((a, _, items) => {
                            const { lane, lanes } = (layouts[day] ??= layoutDay(items)).get(a.id) ?? { lane: 0, lanes: 1 };
                            const top = ((minutesOf(a) - first * 60) / 60) * HOUR_HEIGHT;
                            const height = Math.max(24, (a.duration_minutes / 60) * HOUR_HEIGHT - 2);
                            return (
                                <button
                                    key={a.id}
                                    type="button"
                                    className={`mcal-event st-${a.status}`}
                                    style={{ top, height, left: `calc(${(lane / lanes) * 100}% + 3px)`, width: `calc(${100 / lanes}% - 6px)`, right: 'auto' }}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onOpen(a);
                                    }}
                                >
                                    <b>
                                        {a.source === 'online' && <i className="mcal-web" title="Réservé en ligne" />}
                                        {hhmm(a)} {wide ? a.customer_name : shortName(a.customer_name)}
                                    </b>
                                    {height > 34 && <span>{[a.service, wide ? a.staff_name : null].filter(Boolean).join(' · ')}</span>}
                                </button>
                            );
                        })}
                    </div>
                ))}
            </div>
        </div>
    );
}

function MiniMonth({ date, view, marked, today, onPick }: { date: string; view: View; marked: Set<string>; today: string; onPick: (day: string) => void }) {
    const [month, setMonth] = useState(() => {
        const d = parseDay(date);
        return new Date(d.getFullYear(), d.getMonth(), 1, 12);
    });

    useEffect(() => {
        const d = parseDay(date);
        setMonth(new Date(d.getFullYear(), d.getMonth(), 1, 12));
    }, [date]);

    const start = mondayOf(month);
    const days = Array.from({ length: 42 }, (_, i) => addDays(start, i));
    const selected = parseDay(date);
    const inSelection = (d: Date) => {
        if (view === 'day') return iso(d) === date;
        if (view === 'week') return iso(mondayOf(d)) === iso(mondayOf(selected));
        return d.getMonth() === selected.getMonth() && d.getFullYear() === selected.getFullYear();
    };

    return (
        <div className="mcal-mini">
            <div className="mcal-mini-top">
                <b>{month.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })}</b>
                <span>
                    <button type="button" aria-label="Mois précédent" onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() - 1, 1, 12))}>
                        ‹
                    </button>
                    <button type="button" aria-label="Mois suivant" onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() + 1, 1, 12))}>
                        ›
                    </button>
                </span>
            </div>
            <div className="mcal-mini-grid">
                {WEEKDAYS.map((d) => (
                    <span key={d} className="wd">
                        {d[0]}
                    </span>
                ))}
                {days.map((d) => {
                    const day = iso(d);
                    return (
                        <button
                            key={day}
                            type="button"
                            className={`${d.getMonth() !== month.getMonth() ? 'out' : ''} ${day === today ? 'today' : ''} ${view !== 'month' && inSelection(d) ? 'sel' : ''} ${marked.has(day) ? 'has' : ''}`}
                            onClick={() => onPick(day)}
                        >
                            {d.getDate()}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/** Vue Tableau de l'agenda : les rendez-vous de la période, triés par date. */
function AgendaTable({ rows, statuses, onOpen }: { rows: AppointmentRow[]; statuses: Record<string, string>; onOpen: (a: AppointmentRow) => void }) {
    const sorted = [...rows].sort((a, b) => a.starts_at.localeCompare(b.starts_at));
    if (sorted.length === 0) {
        return <p className="mcal-empty-table">Aucun rendez-vous sur la période.</p>;
    }
    return (
        <div className="mdash-tbl mcal-table">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Heure</th>
                        <th>Client</th>
                        <th>Prestation</th>
                        <th>Intervenant</th>
                        <th>Durée</th>
                        <th>Statut</th>
                        <th className="num">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    {sorted.map((a) => (
                        <tr key={a.id} onClick={() => onOpen(a)} style={{ cursor: 'pointer' }}>
                            <td data-label="Date">
                                {parseDay(a.starts_at.slice(0, 10)).toLocaleDateString('fr-FR', {
                                    weekday: 'short',
                                    day: 'numeric',
                                    month: 'short',
                                })}
                            </td>
                            <td data-label="Heure" className="mono">{hhmm(a)}</td>
                            <td data-label="Client" className="cell-main">
                                <b>{a.customer_name ?? '—'}</b>
                                {a.phone_e164 && <span className="mono">{a.phone_e164}</span>}
                            </td>
                            <td data-label="Prestation">{a.items.length ? a.items.map((i) => i.name).join(', ') : (a.service ?? '—')}</td>
                            <td data-label="Intervenant">
                                {a.staff_color && (
                                    <span
                                        aria-hidden="true"
                                        style={{
                                            display: 'inline-block',
                                            width: 8,
                                            height: 8,
                                            borderRadius: 999,
                                            background: a.staff_color,
                                            marginRight: 6,
                                        }}
                                    />
                                )}
                                {a.staff_name ?? '—'}
                            </td>
                            <td data-label="Durée">{a.duration_minutes} min</td>
                            <td data-label="Statut">
                                <span className={`mcal-status s-${a.status}`}>{statuses[a.status] ?? a.status}</span>
                            </td>
                            <td data-label="Montant" className="num">{a.price ? a.price.toLocaleString('fr-FR') + ' F' : '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** Petite courbe d'évolution (6 points) à côté du chiffre d'une carte. */
function Sparkline({ values }: { values: number[] }) {
    const w = 96;
    const h = 34;
    const max = Math.max(...values, 1);
    const step = w / Math.max(values.length - 1, 1);
    const line = values.map((v, i) => `${i ? 'L' : 'M'}${(i * step).toFixed(1)} ${(h - 3 - (v / max) * (h - 8)).toFixed(1)}`).join(' ');
    return (
        <svg className="mag-spark" viewBox={`0 0 ${w} ${h}`} width={w} height={h} aria-hidden="true">
            <path d={`${line} L${w} ${h} L0 ${h} Z`} className="area" />
            <path d={line} className="line" />
        </svg>
    );
}

function StatCard({ tone, label, value, foot, delta, series, icon }: { tone: string; label: string; value: string; foot: string; delta?: number | null; series: number[]; icon: React.ReactNode }) {
    return (
        <div className={`mag-card t-${tone}`}>
            <div className="mag-card-top">
                <span className="mag-card-label">{label}</span>
                <span className="mag-card-icon" aria-hidden="true">
                    {icon}
                </span>
            </div>
            <div className="mag-card-mid">
                <b className="num">{value}</b>
                <Sparkline values={series} />
            </div>
            <div className="mag-card-foot">
                {delta !== undefined && <Delta value={delta} missing="Aucun chiffre le mois dernier" />}
                <span>{foot}</span>
            </div>
        </div>
    );
}

const icon = (d: string) => (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d={d} />
    </svg>
);
const ICONS = {
    calendar: icon('M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z'),
    clock: icon('M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2'),
    check: icon('M20 6 9 17l-5-5'),
    money: icon('M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6'),
    link: icon('M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'),
    search: icon('M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35'),
    list: icon('M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01'),
};

/** Les 4 cartes du haut : mois en cours comparé au mois précédent. */
function AgendaStatsRow({ stats }: { stats: AgendaStats }) {
    const last = (a: number[]) => a[a.length - 1] ?? 0;
    const prev = (a: number[]) => a[a.length - 2] ?? 0;
    const trend = (a: number[]) => percentChange(last(a), prev(a));
    return (
        <div className="mag-cards">
            <StatCard tone="blue" label="Rendez-vous du mois" value={last(stats.total).toLocaleString('fr-FR')} foot="vs mois dernier" delta={trend(stats.total)} series={stats.total} icon={ICONS.calendar} />
            <StatCard
                tone="cyan"
                label="À venir"
                value={stats.upcoming.toLocaleString('fr-FR')}
                foot={stats.toConfirm > 0 ? `dont ${stats.toConfirm} à confirmer` : 'sur les 6 prochaines semaines'}
                series={stats.upcomingWeeks}
                icon={ICONS.clock}
            />
            <StatCard tone="green" label="Terminés ce mois" value={last(stats.done).toLocaleString('fr-FR')} foot="vs mois dernier" delta={trend(stats.done)} series={stats.done} icon={ICONS.check} />
            <StatCard tone="navy" label="CA encaissé ce mois" value={formatPrice(last(stats.revenue))} foot="vs mois dernier" delta={trend(stats.revenue)} series={stats.revenue} icon={ICONS.money} />
        </div>
    );
}

function AppointmentsIndex() {
    const { view, date, from, store, stores, staff, staffFilter, closures, breaks, statuses, appointments: allAppointments, upcoming, catalog, paymentMethods, rdvEnabled, classSessions, stats, bookingUrl, bookingEnabled, reminders } =
        usePage<PageProps>().props;
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [copied, setCopied] = useState(false);
    // Recherche (client, numéro, prestation, intervenant) et statut : sur les rendez-vous de la période.
    const appointments = useMemo(() => {
        const q = search.trim().toLowerCase();
        return allAppointments.filter(
            (a) => (!statusFilter || a.status === statusFilter) && (!q || [a.customer_name, a.phone_e164, a.service, a.staff_name].some((v) => (v ?? '').toLowerCase().includes(q))),
        );
    }, [allAppointments, search, statusFilter]);
    // Jour fermé (férié, congés) : libellé, sinon null.
    const closedOn = (day: string) => closures.find((c) => c.starts_on <= day && c.ends_on >= day)?.label ?? null;
    const [checkout, setCheckout] = useState<AppointmentRow | null>(null);
    const [modal, setModal] = useState<{ appointment: AppointmentRow | null; start?: string } | null>(null);
    const [opened, setOpened] = useState<AppointmentRow | null>(null);
    const today = iso(new Date());
    // Affichage Calendrier ou Tableau, mémorisé sur ce navigateur.
    const [display, setDisplay] = useState<'calendar' | 'table'>(() => {
        try {
            return localStorage.getItem('agenda-display') === 'table' ? 'table' : 'calendar';
        } catch {
            return 'calendar';
        }
    });
    function chooseDisplay(d: 'calendar' | 'table') {
        setDisplay(d);
        try {
            localStorage.setItem('agenda-display', d);
        } catch {
            /* stockage indisponible */
        }
    }

    // Fiche rapide ouverte : suit les mises à jour (changement de statut).
    useEffect(() => {
        if (opened) setOpened(allAppointments.find((a) => a.id === opened.id) ?? upcoming.find((a) => a.id === opened.id) ?? null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [allAppointments, upcoming]);

    function copyBookingLink() {
        if (!bookingUrl) return;
        const fallback = () => window.prompt('Lien de réservation à partager', bookingUrl);
        if (!navigator.clipboard) return fallback();
        navigator.clipboard.writeText(bookingUrl).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }, fallback);
    }

    function toggleReminders(enabled: boolean) {
        router.put('/appointments/settings/reminders', { enabled }, { preserveScroll: true });
    }

    const byDay = useMemo(
        () =>
            appointments.reduce<Record<string, AppointmentRow[]>>((acc, a) => {
                (acc[a.starts_at.slice(0, 10)] ??= []).push(a);
                return acc;
            }, {}),
        [appointments],
    );

    function go(changes: Partial<{ view: View; date: string; store: number | null; staff: number | null }>) {
        const params: Record<string, string | number> = {};
        Object.entries({ view, date, store, staff: staffFilter, ...changes }).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') params[k] = v as string | number;
        });
        router.get('/appointments', params, { preserveScroll: true, preserveState: true, replace: true });
    }

    const weekDays = Array.from({ length: 7 }, (_, i) => iso(addDays(mondayOf(parseDay(date)), i)));

    return (
        <Page
            title="Agenda"
            subtitle={rdvEnabled ? 'Consultez et organisez vos rendez-vous.' : `${classSessions.filter((s) => s.status !== 'cancelled').length} séance(s) de cours sur la période`}
            actions={
                rdvEnabled ? (
                    <>
                        {bookingUrl && bookingEnabled && (
                            <button type="button" className="mdash-btn ghost" onClick={copyBookingLink} title={bookingUrl}>
                                {ICONS.link}
                                {copied ? 'Lien copié' : 'Lien de réservation'}
                            </button>
                        )}
                        {bookingUrl && !bookingEnabled && reminders.canManage && (
                            <Link href="/settings/booking" className="mdash-btn ghost" title="Activez la réservation en ligne pour partager votre lien">
                                {ICONS.link}
                                Lien de réservation
                            </Link>
                        )}
                        <button type="button" className="mdash-btn brand" onClick={() => setModal({ appointment: null })} disabled={stores.length === 0}>
                            <PlusIcon />
                            Ajouter un RDV
                        </button>
                    </>
                ) : (
                    <Link href="/classes" className="mdash-btn brand">
                        Cours et appel
                    </Link>
                )
            }
        >
            {stats && <AgendaStatsRow stats={stats} />}

            {rdvEnabled && (
                <div className="mag-bar">
                    <div className="mdash-seg mag-display" role="group" aria-label="Type d'affichage">
                        <button type="button" aria-pressed={display === 'table'} onClick={() => chooseDisplay('table')}>
                            {ICONS.list}
                            Liste
                        </button>
                        <button type="button" aria-pressed={display === 'calendar'} onClick={() => chooseDisplay('calendar')}>
                            {ICONS.calendar}
                            Calendrier
                        </button>
                    </div>
                    <label className="mag-search">
                        {ICONS.search}
                        <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Rechercher un client, un numéro, une prestation…" aria-label="Rechercher" />
                    </label>
                    <select aria-label="Statut" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                        <option value="">Tous les statuts</option>
                        {Object.entries(statuses).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                    {reminders.available && (
                        <label className="mag-switch" title="La veille, chaque client reçoit un message WhatsApp pour confirmer ou décaler">
                            <input type="checkbox" role="switch" checked={reminders.enabled} disabled={!reminders.canManage} onChange={(e) => toggleReminders(e.target.checked)} />
                            <i aria-hidden="true" />
                            Rappels automatiques
                        </label>
                    )}
                </div>
            )}

            <div className="mcal">
                <section className="mcal-main mdash-panel">
                    <div className="mcal-toolbar">
                        <button type="button" className="mdash-btn ghost sm" onClick={() => go({ date: today })}>
                            Aujourd'hui
                        </button>
                        <div className="mcal-nav">
                            <button type="button" aria-label="Précédent" onClick={() => go({ date: shift(view, date, -1) })}>
                                ‹
                            </button>
                            <h2>{title(view, date)}</h2>
                            <button type="button" aria-label="Suivant" onClick={() => go({ date: shift(view, date, 1) })}>
                                ›
                            </button>
                        </div>
                        <div className="mcal-tools">
                            {staff.length > 0 && (
                                <select aria-label="Intervenant" value={staffFilter ?? ''} onChange={(e) => go({ staff: e.target.value ? Number(e.target.value) : null })}>
                                    <option value="">Tous les intervenants</option>
                                    {staff.map((m) => (
                                        <option key={m.id} value={m.id}>
                                            {m.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                            {stores.length > 1 && (
                                <select aria-label="Établissement" value={store ?? ''} onChange={(e) => go({ store: e.target.value ? Number(e.target.value) : null })}>
                                    <option value="">Tous les établissements</option>
                                    {stores.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                            <div className="mdash-seg" role="group" aria-label="Période affichée">
                                {(
                                    [
                                        ['day', 'Jour'],
                                        ['week', 'Semaine'],
                                        ['month', 'Mois'],
                                    ] as [View, string][]
                                ).map(([key, label]) => (
                                    <button key={key} type="button" aria-pressed={view === key} onClick={() => go({ view: key })}>
                                        {label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>

                    {display === 'table' ? (
                        <AgendaTable rows={appointments} statuses={statuses} onOpen={setOpened} />
                    ) : view === 'month' ? (
                        <MonthGrid date={date} from={from} byDay={byDay} today={today} onOpen={setOpened} onNew={(start) => setModal({ appointment: null, start })} onDay={(day) => go({ view: 'day', date: day })} closedOn={closedOn} />
                    ) : (
                        <TimeGrid
                            days={view === 'week' ? weekDays : [date]}
                            byDay={byDay}
                            today={today}
                            onOpen={setOpened}
                            onNew={(start) => rdvEnabled && setModal({ appointment: null, start })}
                            wide={view === 'day'}
                            closedOn={closedOn}
                            breaks={breaks}
                            sessions={classSessions}
                        />
                    )}
                </section>

                <aside className="mcal-side">
                    <div className="mdash-panel">
                        <MiniMonth date={date} view={view} marked={new Set(Object.keys(byDay))} today={today} onPick={(day) => go({ date: day, view: view === 'month' ? 'day' : view })} />
                        <div className="mcal-legend">
                            {Object.entries(statuses).map(([key, label]) => (
                                <span key={key}>
                                    <i className={`mcal-dot st-${key}`} />
                                    {label}
                                </span>
                            ))}
                        </div>
                    </div>
                    <div className="mdash-panel">
                        <h3 className="mcal-side-title">À venir</h3>
                        {upcoming.length === 0 ? (
                            <p className="mpage-muted">Aucun rendez-vous prévu.</p>
                        ) : (
                            <ul className="mcal-upcoming">
                                {upcoming.map((a) => (
                                    <li key={a.id}>
                                        <button type="button" onClick={() => setOpened(a)}>
                                            <i className={`mcal-dot st-${a.status}`} />
                                            <span>
                                                <b>{a.customer_name}</b>
                                                <small>
                                                    {new Date(a.starts_at).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' })} · {hhmm(a)}
                                                    {a.service ? ` · ${a.service}` : ''}
                                                </small>
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </aside>
            </div>

            {opened && (
                <EventCard
                    appointment={opened}
                    statuses={statuses}
                    multiStore={stores.length > 1}
                    onClose={() => setOpened(null)}
                    onEdit={() => {
                        setModal({ appointment: opened });
                        setOpened(null);
                    }}
                    onCheckout={() => {
                        setCheckout(opened);
                        setOpened(null);
                    }}
                />
            )}

            {checkout && <CheckoutModal appointment={checkout} catalog={catalog} paymentMethods={paymentMethods} onClose={() => setCheckout(null)} />}

            {modal && (
                <AppointmentFormModal
                    key={modal.appointment?.id ?? `new-${modal.start ?? ''}`}
                    onClose={() => setModal(null)}
                    appointment={modal.appointment}
                    stores={stores}
                    staff={staff}
                    statuses={statuses}
                    date={modal.start}
                    catalog={catalog}
                />
            )}
        </Page>
    );
}

AppointmentsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default AppointmentsIndex;
