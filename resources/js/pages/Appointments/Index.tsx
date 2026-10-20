import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, PlusIcon } from '../../components/page/Page';
import AppointmentFormModal, { AppointmentRow } from '../../components/appointments/AppointmentFormModal';
import WhatsAppIcon from '../../components/common/WhatsAppIcon';
import './calendar.css';

/*
 * Agenda en calendrier : vues Mois, Semaine et Jour (grille horaire), à la
 * manière d'Odoo. Clic sur un jour ou un créneau libre : nouveau rendez-vous ;
 * clic sur un rendez-vous : fiche rapide (statut, rappel WhatsApp, modifier).
 * À droite : mini-calendrier et prochains rendez-vous.
 */

type View = 'day' | 'week' | 'month';

interface PageProps {
    view: View;
    date: string;
    from: string;
    to: string;
    store: number | null;
    stores: { id: number; name: string }[];
    staff: { id: number; name: string }[];
    statuses: Record<string, string>;
    appointments: AppointmentRow[];
    upcoming: AppointmentRow[];
    [key: string]: unknown;
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
function EventCard({ appointment: a, statuses, multiStore, onClose, onEdit }: { appointment: AppointmentRow; statuses: Record<string, string>; multiStore: boolean; onClose: () => void; onEdit: () => void }) {
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
        </button>
    );
}

function MonthGrid({ date, from, byDay, today, onOpen, onNew, onDay }: { date: string; from: string; byDay: Record<string, AppointmentRow[]>; today: string; onOpen: (a: AppointmentRow) => void; onNew: (start: string) => void; onDay: (day: string) => void }) {
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
                        return (
                            <div
                                key={day}
                                className={`mcal-cell ${d.getMonth() !== month ? 'out' : ''} ${day === today ? 'today' : ''}`}
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

function TimeGrid({ days, byDay, today, onOpen, onNew, wide }: { days: string[]; byDay: Record<string, AppointmentRow[]>; today: string; onOpen: (a: AppointmentRow) => void; onNew: (start: string) => void; wide: boolean }) {
    const all = days.flatMap((d) => byDay[d] ?? []);
    // Plage horaire : 8 h – 20 h, élargie aux rendez-vous plus tôt ou plus tard.
    const first = Math.min(8, ...all.map((a) => Math.floor(minutesOf(a) / 60)));
    const last = Math.max(20, ...all.map((a) => Math.ceil((minutesOf(a) + a.duration_minutes) / 60)));
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
                        className={`mcal-col ${day === today ? 'today' : ''}`}
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
                        {day === today && nowMinutes >= first * 60 && nowMinutes <= last * 60 && (
                            <em className="mcal-now" style={{ top: ((nowMinutes - first * 60) / 60) * HOUR_HEIGHT }} />
                        )}
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

function AppointmentsIndex() {
    const { view, date, from, store, stores, staff, statuses, appointments, upcoming } = usePage<PageProps>().props;
    const [modal, setModal] = useState<{ appointment: AppointmentRow | null; start?: string } | null>(null);
    const [opened, setOpened] = useState<AppointmentRow | null>(null);
    const today = iso(new Date());

    // Fiche rapide ouverte : suit les mises à jour (changement de statut).
    useEffect(() => {
        if (opened) setOpened(appointments.find((a) => a.id === opened.id) ?? upcoming.find((a) => a.id === opened.id) ?? null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [appointments, upcoming]);

    const byDay = useMemo(
        () =>
            appointments.reduce<Record<string, AppointmentRow[]>>((acc, a) => {
                (acc[a.starts_at.slice(0, 10)] ??= []).push(a);
                return acc;
            }, {}),
        [appointments],
    );

    function go(changes: Partial<{ view: View; date: string; store: number | null }>) {
        const params: Record<string, string | number> = {};
        Object.entries({ view, date, store, ...changes }).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') params[k] = v as string | number;
        });
        router.get('/appointments', params, { preserveScroll: true, preserveState: true, replace: true });
    }

    const weekDays = Array.from({ length: 7 }, (_, i) => iso(addDays(mondayOf(parseDay(date)), i)));
    const active = appointments.filter((a) => a.status !== 'cancelled').length;

    return (
        <Page
            title="Agenda"
            subtitle={`${active} rendez-vous sur la période`}
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setModal({ appointment: null })} disabled={stores.length === 0}>
                    <PlusIcon />
                    Nouveau rendez-vous
                </button>
            }
        >
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
                            <div className="mdash-seg" role="group" aria-label="Affichage">
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

                    {view === 'month' ? (
                        <MonthGrid date={date} from={from} byDay={byDay} today={today} onOpen={setOpened} onNew={(start) => setModal({ appointment: null, start })} onDay={(day) => go({ view: 'day', date: day })} />
                    ) : (
                        <TimeGrid
                            days={view === 'week' ? weekDays : [date]}
                            byDay={byDay}
                            today={today}
                            onOpen={setOpened}
                            onNew={(start) => setModal({ appointment: null, start })}
                            wide={view === 'day'}
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
                />
            )}

            {modal && (
                <AppointmentFormModal
                    key={modal.appointment?.id ?? `new-${modal.start ?? ''}`}
                    onClose={() => setModal(null)}
                    appointment={modal.appointment}
                    stores={stores}
                    staff={staff}
                    statuses={statuses}
                    date={modal.start}
                />
            )}
        </Page>
    );
}

AppointmentsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default AppointmentsIndex;
