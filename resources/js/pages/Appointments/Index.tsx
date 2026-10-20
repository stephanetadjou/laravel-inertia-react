import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon, Seg, SelectFilter, StatStrip } from '../../components/page/Page';
import AppointmentFormModal, { APPOINTMENT_PILL, AppointmentRow } from '../../components/appointments/AppointmentFormModal';
import WhatsAppIcon from '../../components/common/WhatsAppIcon';
import '../Actions/actions.css';

interface PageProps {
    view: 'day' | 'week';
    date: string;
    from: string;
    to: string;
    store: number | null;
    stores: { id: number; name: string }[];
    staff: { id: number; name: string }[];
    statuses: Record<string, string>;
    appointments: AppointmentRow[];
    counts: { total: number; confirmed: number; done: number; no_show: number };
    [key: string]: unknown;
}

function shift(date: string, days: number): string {
    const d = new Date(`${date}T12:00:00`);
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
}

function dayLabel(date: string): string {
    return new Date(`${date}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
}

function AppointmentsIndex() {
    const { view, date, from, to, store, stores, staff, statuses, appointments, counts } = usePage<PageProps>().props;
    const [modal, setModal] = useState<AppointmentRow | null | undefined>(undefined);

    function go(changes: Record<string, string | number | null>) {
        const params: Record<string, string | number> = {};
        Object.entries({ view, date, store, ...changes }).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') params[k] = v;
        });
        router.get('/appointments', params, { preserveScroll: true, preserveState: true, replace: true });
    }

    function setStatus(a: AppointmentRow, status: string) {
        router.patch(`/appointments/${a.id}/status`, { status }, { preserveScroll: true });
    }

    function destroy(a: AppointmentRow) {
        if (confirm(`Supprimer le rendez-vous de ${a.customer_name} ?`)) {
            router.delete(`/appointments/${a.id}`, { preserveScroll: true });
        }
    }

    const byDay = appointments.reduce<Record<string, AppointmentRow[]>>((acc, a) => {
        (acc[a.starts_at.slice(0, 10)] ??= []).push(a);
        return acc;
    }, {});
    const step = view === 'week' ? 7 : 1;
    const today = new Date().toISOString().slice(0, 10);

    return (
        <Page
            title="Rendez-vous"
            subtitle={view === 'week' ? `Semaine du ${dayLabel(from)} au ${dayLabel(to)}` : dayLabel(date)}
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setModal(null)} disabled={stores.length === 0}>
                    <PlusIcon />
                    Nouveau rendez-vous
                </button>
            }
            filters={
                <>
                    <Seg
                        label="Affichage"
                        value={view}
                        options={[
                            { key: 'day', label: 'Jour' },
                            { key: 'week', label: 'Semaine' },
                        ]}
                        onChange={(v) => go({ view: v })}
                    />
                    <div className="mdash-fgroup">
                        <span>Période</span>
                        <div className="mappt-nav">
                            <button type="button" className="mdash-btn ghost sm" onClick={() => go({ date: shift(date, -step) })} aria-label="Période précédente">
                                ←
                            </button>
                            <button type="button" className="mdash-btn ghost sm" onClick={() => go({ date: today })}>
                                Aujourd'hui
                            </button>
                            <button type="button" className="mdash-btn ghost sm" onClick={() => go({ date: shift(date, step) })} aria-label="Période suivante">
                                →
                            </button>
                            <input type="date" aria-label="Aller à la date" value={date} onChange={(e) => e.target.value && go({ date: e.target.value })} />
                        </div>
                    </div>
                    {stores.length > 1 && (
                        <SelectFilter label="Établissement" value={store} allLabel="Tous les établissements" options={stores.map((s) => ({ value: s.id, label: s.name }))} onChange={(v) => go({ store: v })} />
                    )}
                </>
            }
        >
            <StatStrip
                items={[
                    { label: 'Rendez-vous', value: counts.total, hint: 'hors annulés' },
                    { label: 'Confirmés', value: counts.confirmed, tone: 'ok' },
                    { label: 'Honorés', value: counts.done },
                    { label: 'Absents', value: counts.no_show, tone: counts.no_show > 0 ? 'bad' : undefined, hint: 'à reprogrammer depuis Actions du jour' },
                ]}
            />

            <Panel>
                {appointments.length === 0 ? (
                    <Empty title="Aucun rendez-vous sur cette période">Prenez un rendez-vous ici ou depuis la fiche d'un client.</Empty>
                ) : (
                    Object.entries(byDay).map(([day, items]) => (
                        <div key={day} className="mappt-day">
                            <h3>{dayLabel(day)}</h3>
                            <ul className="mappt-list">
                                {items.map((a) => (
                                    <li key={a.id} className={a.status}>
                                        <span className="mappt-time">
                                            {a.starts_at.slice(11, 16).replace(':', 'h')}
                                            <small>{a.duration_minutes} min</small>
                                        </span>
                                        <span className="mappt-who">
                                            <Link href={`/customers/${a.customer_id}`}>
                                                <b>{a.customer_name}</b>
                                            </Link>
                                            <span>
                                                {[a.service, a.staff_name ? `avec ${a.staff_name}` : null, stores.length > 1 ? a.store_name : null].filter(Boolean).join(' · ') || a.phone_e164}
                                            </span>
                                        </span>
                                        <span className={`mdash-pill ${APPOINTMENT_PILL[a.status] ?? 'idle'}`}>{statuses[a.status] ?? a.status}</span>
                                        <span className="mappt-do">
                                            {a.whatsapp_url && ['planned', 'confirmed'].includes(a.status) && (
                                                <a href={a.whatsapp_url} target="_blank" rel="noopener noreferrer" className="mdash-btn wa sm" title="Rappel prérempli sur WhatsApp">
                                                    <WhatsAppIcon />
                                                    Rappeler
                                                </a>
                                            )}
                                            <select aria-label="Statut du rendez-vous" value={a.status} onChange={(e) => setStatus(a, e.target.value)}>
                                                {Object.entries(statuses).map(([key, label]) => (
                                                    <option key={key} value={key}>
                                                        {label}
                                                    </option>
                                                ))}
                                            </select>
                                            <span className="mpage-rowlinks">
                                                <button type="button" onClick={() => setModal(a)}>
                                                    Modifier
                                                </button>
                                                <button type="button" className="danger" onClick={() => destroy(a)}>
                                                    Supprimer
                                                </button>
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))
                )}
            </Panel>

            {modal !== undefined && (
                <AppointmentFormModal key={modal?.id ?? 'new'} onClose={() => setModal(undefined)} appointment={modal} stores={stores} staff={staff} statuses={statuses} date={view === 'day' ? date : undefined} />
            )}
        </Page>
    );
}

AppointmentsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default AppointmentsIndex;
