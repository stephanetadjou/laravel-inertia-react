import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { Empty, Page, Panel, PlusIcon, Seg, useQueryFilters } from '../../components/page/Page';
import { Modal } from '../../components/ui/modal';
import AppLayout from '../../layout/AppLayout';
import RideModal, { Fare, FaresPanel } from './RideModal';
import { Field, Options, WhatsAppBanner, field, fmt, money } from './shared';

/* Réservations : planning par véhicule (jour / semaine), départs, retours et cautions. */

interface Cell {
    rental_id: number;
    customer: string | null;
    status: string;
    first: boolean;
}

interface BookingRow {
    id: number;
    vehicle: string;
    customer: string;
    status: string;
    status_label: string;
    starts_at: string;
    ends_at: string;
    pickup_place: string | null;
    driver_name: string | null;
    amount: number;
    deposit: number | null;
    deposit_status: string | null;
    deposit_label: string | null;
    balance: number;
    quote_url: string | null;
    confirm_wa: string | null;
    is_ride?: boolean;
    ride_status?: string | null;
    ride?: { from: string | null; to: string | null; passengers: number | null; luggage: number | null; flight: string | null } | null;
}

interface Props {
    view: 'day' | 'week';
    from: string;
    planning: { days: { date: string; label: string }[]; rows: { id: number; name: string; status: string; cells: (Cell | null)[] }[] };
    dayBookings: BookingRow[];
    bookings: BookingRow[];
    vehicles: { id: number; label: string; status: string; price_day: number | null }[];
    stores: { id: number; name: string }[];
    options: { deposit_reasons: Record<string, string>; payment_methods: Record<string, string>; purposes: Record<string, string> };
    whatsappUrl?: string | null;
    fares: Fare[];
    contract: { accessories: string[]; km_per_day: number; extra_km_price: number; conditions: string };
    [key: string]: unknown;
}

const tone: Record<string, string> = { reserved: 'bg-sky-100 text-sky-800', ongoing: 'bg-emerald-100 text-emerald-800', late: 'bg-rose-100 text-rose-800' };

function addDays(date: string, n: number) {
    const d = new Date(date + 'T00:00');
    d.setDate(d.getDate() + n);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function NewBooking({ onClose }: { onClose: () => void }) {
    const { vehicles, stores, options } = usePage<Props>().props;
    const { data, setData, post, processing, errors } = useForm({
        store_id: stores.length === 1 ? String(stores[0].id) : '',
        name: '',
        phone: '',
        resource_id: '',
        starts_at: '',
        ends_at: '',
        with_driver: false,
        driver_name: '',
        pickup_place: '',
        rate: '',
        deposit: '',
        deposit_percent: '30',
        purpose: '',
    });

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-xl p-6">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/location/bookings', { preserveScroll: true, onSuccess: onClose });
                }}
                className="space-y-3 text-sm"
            >
                <h2 className="text-lg font-semibold">Nouvelle réservation</h2>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Nom du client" error={errors.name}>
                        <input className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    </Field>
                    <Field label="Numéro WhatsApp" error={errors.phone}>
                        <input className={field} value={data.phone} onChange={(e) => setData('phone', e.target.value)} inputMode="tel" required />
                    </Field>
                    <Field label="Véhicule" error={errors.resource_id} className="col-span-2">
                        <select className={field} value={data.resource_id} onChange={(e) => setData('resource_id', e.target.value)} required>
                            <option value="">—</option>
                            {vehicles
                                .filter((v) => v.status === 'available')
                                .map((v) => (
                                    <option key={v.id} value={v.id}>
                                        {v.label}
                                        {v.price_day ? ` · ${money(v.price_day)}/jour` : ''}
                                    </option>
                                ))}
                        </select>
                    </Field>
                    <Field label="Début">
                        <input type="datetime-local" className={field} value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} required />
                    </Field>
                    <Field label="Fin" error={errors.ends_at}>
                        <input type="datetime-local" className={field} value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} required />
                    </Field>
                    <Field label="Prix par jour (vide = tarif)">
                        <input type="number" className={field} value={data.rate} onChange={(e) => setData('rate', e.target.value)} />
                    </Field>
                    <Field label="Caution (FCFA)">
                        <input type="number" className={field} value={data.deposit} onChange={(e) => setData('deposit', e.target.value)} />
                    </Field>
                    <Field label="Acompte (%)">
                        <input type="number" min={0} max={99} className={field} value={data.deposit_percent} onChange={(e) => setData('deposit_percent', e.target.value)} />
                    </Field>
                    <Field label="Pour">
                        <select className={field} value={data.purpose} onChange={(e) => setData('purpose', e.target.value)}>
                            <Options options={options.purposes} empty="—" />
                        </select>
                    </Field>
                    <Field label="Lieu de prise en charge" className="col-span-2">
                        <input className={field} value={data.pickup_place} onChange={(e) => setData('pickup_place', e.target.value)} />
                    </Field>
                    <label className="flex items-center gap-2">
                        <input type="checkbox" checked={data.with_driver} onChange={(e) => setData('with_driver', e.target.checked)} /> Avec chauffeur
                    </label>
                    {data.with_driver && (
                        <Field label="Chauffeur">
                            <input className={field} value={data.driver_name} onChange={(e) => setData('driver_name', e.target.value)} />
                        </Field>
                    )}
                    {stores.length > 1 && (
                        <Field label="Agence" className="col-span-2">
                            <select className={field} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} required>
                                <option value="">—</option>
                                {stores.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                </div>
                <div className="flex justify-end gap-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        Réserver
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function DepositModal({ booking, onClose }: { booking: BookingRow; onClose: () => void }) {
    const { options } = usePage<Props>().props;
    const { data, setData, post, processing, errors } = useForm({ retained: '0', reason: '', note: '', payment_method: 'cash' });
    const retained = Number(data.retained) || 0;

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-sm p-6">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/location/bookings/${booking.id}/deposit`, { preserveScroll: true, onSuccess: onClose });
                }}
                className="space-y-3 text-sm"
            >
                <h2 className="text-lg font-semibold">Caution de {money(booking.deposit)}</h2>
                <Field label="Montant retenu (0 = caution rendue en entier)" error={errors.retained}>
                    <input type="number" min={0} max={booking.deposit ?? 0} className={field} value={data.retained} onChange={(e) => setData('retained', e.target.value)} />
                </Field>
                {retained > 0 && (
                    <>
                        <Field label="Motif" error={errors.reason}>
                            <select className={field} value={data.reason} onChange={(e) => setData('reason', e.target.value)} required>
                                <Options options={options.deposit_reasons} empty="Choisir…" />
                            </select>
                        </Field>
                        <Field label="Détail">
                            <input className={field} value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="ex. rayure portière arrière" />
                        </Field>
                    </>
                )}
                <p className="text-gray-600">À rendre au client : {money((booking.deposit ?? 0) - retained)}</p>
                {(errors as Record<string, string>).deposit && <p className="text-error-500">{(errors as Record<string, string>).deposit}</p>}
                <div className="flex justify-end gap-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        Clôturer et préparer le reçu
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function ContractSettings() {
    const { contract } = usePage<Props>().props;
    const { data, setData, post, processing } = useForm({
        accessories: contract.accessories.join('\n'),
        km_per_day: String(contract.km_per_day ?? 0),
        extra_km_price: String(contract.extra_km_price ?? 0),
        conditions: contract.conditions ?? '',
    });

    return (
        <form
            className="grid gap-3 text-sm md:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                post('/location/settings', { preserveScroll: true });
            }}
        >
            <Field label="Accessoires vérifiés (un par ligne)">
                <textarea className={field} rows={6} value={data.accessories} onChange={(e) => setData('accessories', e.target.value)} />
            </Field>
            <div className="space-y-3">
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Km inclus par jour (0 = illimité)">
                        <input type="number" min={0} className={field} value={data.km_per_day} onChange={(e) => setData('km_per_day', e.target.value)} />
                    </Field>
                    <Field label="Prix du km en plus (FCFA)">
                        <input type="number" min={0} className={field} value={data.extra_km_price} onChange={(e) => setData('extra_km_price', e.target.value)} />
                    </Field>
                </div>
                <Field label="Conditions du contrat">
                    <textarea className={field} rows={4} value={data.conditions} onChange={(e) => setData('conditions', e.target.value)} />
                </Field>
                <button type="submit" className="mdash-btn brand" disabled={processing}>
                    Enregistrer
                </button>
            </div>
        </form>
    );
}

function LocationPlanning() {
    const { view, from, planning, dayBookings, bookings, whatsappUrl, vehicles, stores, fares } = usePage<Props>().props;
    const [ride, setRide] = useState(false);
    const rideMove = (b: BookingRow, status: string) => router.post(`/location/rides/${b.id}/status`, { status }, { preserveScroll: true });
    const NEXT: Record<string, [string, string]> = { booked: ['en_route', 'Chauffeur en route'], en_route: ['on_board', 'Client à bord'], on_board: ['done', 'Terminer et encaisser'] };
    const visit = useQueryFilters('/location/planning', { view, from });
    const [modal, setModal] = useState<null | 'new' | BookingRow>(null);
    const step = view === 'week' ? 7 : 1;

    return (
        <Page
            title="Réservations"
            subtitle="Un véhicule ne peut jamais être réservé deux fois sur la même période"
            actions={
                <>
                    <button type="button" className="mdash-btn ghost" onClick={() => setRide(true)}>
                        <PlusIcon />
                        Nouvelle course
                    </button>
                    <button type="button" className="mdash-btn brand" onClick={() => setModal('new')}>
                        <PlusIcon />
                        Nouvelle réservation
                    </button>
                </>
            }
            filters={
                <>
                    <Seg label="Vue" value={view} options={[{ key: 'day', label: 'Jour' }, { key: 'week', label: 'Semaine' }]} onChange={(v) => visit({ view: v })} />
                    <span className="inline-flex items-center gap-1">
                        <button type="button" className="mdash-btn ghost sm" onClick={() => visit({ from: addDays(from, -step) })}>
                            ‹
                        </button>
                        <input type="date" className="rounded-lg border border-gray-300 px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-900" value={from} onChange={(e) => visit({ from: e.target.value })} />
                        <button type="button" className="mdash-btn ghost sm" onClick={() => visit({ from: addDays(from, step) })}>
                            ›
                        </button>
                    </span>
                </>
            }
        >
            <WhatsAppBanner url={whatsappUrl} />

            {view === 'week' ? (
                <Panel title="Planning de la semaine">
                    <div className="mdash-tbl">
                        <table className="mpage-table">
                            <thead>
                                <tr>
                                    <th>Véhicule</th>
                                    {planning.days.map((d) => (
                                        <th key={d.date}>{d.label}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {planning.rows.map((row) => (
                                    <tr key={row.id}>
                                        <td className="font-medium">
                                            {row.name}
                                            {row.status !== 'available' && <span className="block text-xs text-amber-600">indisponible</span>}
                                        </td>
                                        {row.cells.map((c, i) => (
                                            <td key={i} className={c ? tone[c.status] ?? 'bg-gray-100' : row.status !== 'available' ? 'bg-gray-50' : ''}>
                                                {c?.first ? <span className="text-xs">{c.customer}</span> : null}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            ) : (
                <Panel title={`Le ${new Date(from + 'T00:00').toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })}`}>
                    {planning.rows.length === 0 ? (
                        <Empty title="Aucun véhicule" />
                    ) : (
                        <ul className="divide-y divide-gray-100 text-sm dark:divide-white/5">
                            {planning.rows.map((row) => {
                                const own = dayBookings.filter((b) => b.vehicle === row.name);
                                return (
                                    <li key={row.id} className="flex flex-wrap justify-between gap-2 py-2">
                                        <span className="font-medium">{row.name}</span>
                                        <span>{own.length === 0 ? <span className="text-emerald-600">{row.status === 'available' ? 'Libre' : 'Indisponible'}</span> : own.map((b) => `${b.customer} (${fmt(b.starts_at)} → ${fmt(b.ends_at)})`).join(' · ')}</span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Panel>
            )}

            <Panel title="À suivre" sub="Départs, véhicules sortis, retours et cautions à clôturer">
                {bookings.length === 0 ? (
                    <Empty title="Aucune réservation en cours" />
                ) : (
                    <ul className="divide-y divide-gray-100 dark:divide-white/5">
                        {bookings.map((b) => (
                            <li key={b.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                                <span>
                                    {b.is_ride && <span className="mr-1 rounded bg-amber-100 px-1.5 text-xs text-amber-800">Course</span>}
                                    {b.is_ride && b.ride && <span className="font-medium">{b.ride.from} → {b.ride.to} · </span>}
                                    <span className="font-medium">{b.vehicle}</span> · {b.customer} · <span className={`rounded px-1.5 text-xs ${tone[b.status] ?? 'bg-gray-100'}`}>{b.status_label}</span>
                                    {b.is_ride && b.ride && (
                                        <span className="block text-xs text-gray-500">
                                            {[b.ride.passengers ? `${b.ride.passengers} passager(s)` : null, b.ride.luggage !== null ? `${b.ride.luggage} bagage(s)` : null, b.ride.flight ? `vol ${b.ride.flight}` : null].filter(Boolean).join(' · ')}
                                        </span>
                                    )}
                                    <span className="block text-xs text-gray-500">
                                        {fmt(b.starts_at)} → {fmt(b.ends_at)}
                                        {b.pickup_place ? ` · ${b.pickup_place}` : ''}
                                        {b.driver_name ? ` · chauffeur ${b.driver_name}` : ''} · {money(b.amount)}
                                        {b.balance > 0 ? ` · reste ${money(b.balance)}` : ''}
                                        {b.deposit_label ? ` · ${b.deposit_label}` : ''}
                                    </span>
                                </span>
                                <span className="mpage-rowlinks flex flex-wrap gap-2">
                                    {b.confirm_wa && (
                                        <a href={b.confirm_wa} target="_blank" rel="noreferrer">
                                            Confirmation WhatsApp
                                        </a>
                                    )}
                                    {b.is_ride && b.ride_status && NEXT[b.ride_status] && (
                                        <button type="button" onClick={() => rideMove(b, NEXT[b.ride_status!][0])}>
                                            {NEXT[b.ride_status][1]}
                                        </button>
                                    )}
                                    {b.is_ride && (b.ride_status === 'booked' || b.ride_status === 'en_route') && (
                                        <button type="button" onClick={() => confirm('Annuler cette course ?') && rideMove(b, 'cancelled')}>
                                            Annuler la course
                                        </button>
                                    )}
                                    {!b.is_ride && b.status === 'reserved' && <Link href={`/location/bookings/${b.id}/inspection/out`}>État des lieux départ</Link>}
                                    {!b.is_ride && (b.status === 'ongoing' || b.status === 'late') && <Link href={`/location/bookings/${b.id}/inspection/in`}>État des lieux retour</Link>}
                                    {b.status === 'returned' && b.deposit_status === 'held' && (
                                        <button type="button" onClick={() => setModal(b)}>
                                            Clôturer la caution
                                        </button>
                                    )}
                                    {!b.is_ride && (
                                        <a href={`/location/bookings/${b.id}/contract.pdf`} target="_blank" rel="noreferrer">
                                            Contrat PDF
                                        </a>
                                    )}
                                    {b.quote_url && (
                                        <a href={b.quote_url} target="_blank" rel="noreferrer">
                                            Devis
                                        </a>
                                    )}
                                    {!b.is_ride && b.status === 'reserved' && (
                                        <button type="button" onClick={() => confirm('Annuler cette réservation ?') && router.post(`/location/bookings/${b.id}/cancel`, {}, { preserveScroll: true })}>
                                            Annuler
                                        </button>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <Panel title="Forfaits des courses et transferts" sub="Prix par trajet proposés à la réservation d’une course">
                <FaresPanel fares={fares} />
            </Panel>

            <Panel title="Contrat et état des lieux" sub="Repris dans le contrat PDF et dans l’état des lieux">
                <ContractSettings />
            </Panel>

            {ride && <RideModal vehicles={vehicles} stores={stores} fares={fares} onClose={() => setRide(false)} />}
            {modal === 'new' && <NewBooking onClose={() => setModal(null)} />}
            {modal && modal !== 'new' && <DepositModal booking={modal} onClose={() => setModal(null)} />}
        </Page>
    );
}

LocationPlanning.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default LocationPlanning;
