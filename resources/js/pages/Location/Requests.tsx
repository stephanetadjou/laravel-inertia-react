import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import { Page, PlusIcon } from '../../components/page/Page';
import { Modal } from '../../components/ui/modal';
import AppLayout from '../../layout/AppLayout';
import RideModal, { Fare } from './RideModal';
import { Field, Options, WhatsAppBanner, field, fmt, money } from './shared';

/* Demandes : Nouvelle → Devis envoyé → Réservée / Vendue, ou Perdue (raison obligatoire). */

interface RequestRow {
    id: number;
    status: 'new' | 'quoted' | 'won' | 'lost';
    need: 'rent' | 'buy' | 'ride';
    ride: { from: string | null; to: string | null; passengers: number | null; luggage: number | null; flight: string | null } | null;
    need_label: string;
    summary: string;
    customer: string;
    phone: string | null;
    purpose_label: string | null;
    source_label: string;
    resource_id: number | null;
    starts_at: string | null;
    ends_at: string | null;
    with_driver: boolean;
    budget: number | null;
    notes: string | null;
    quote: { number: string; total: number; status: string; url: string } | null;
    quote_wa: string | null;
    lost_label: string | null;
    created_at: string;
}

interface Vehicle {
    id: number;
    label: string;
    category: string | null;
    status: string;
    price_day: number | null;
    sale_price: number | null;
}

interface Props {
    requests: RequestRow[];
    vehicles: Vehicle[];
    stores: { id: number; name: string }[];
    options: { needs: Record<string, string>; sources: Record<string, string>; purposes: Record<string, string>; lost_reasons: Record<string, string>; categories: Record<string, string> };
    whatsappUrl?: string | null;
    fares: Fare[];
    [key: string]: unknown;
}

const COLUMNS: { key: RequestRow['status']; label: string; tone: string }[] = [
    { key: 'new', label: 'Nouvelles', tone: 'border-sky-400' },
    { key: 'quoted', label: 'Devis envoyé', tone: 'border-amber-400' },
    { key: 'won', label: 'Réservées / vendues', tone: 'border-emerald-500' },
    { key: 'lost', label: 'Perdues', tone: 'border-gray-400' },
];

function NewRequest({ onClose }: { onClose: () => void }) {
    const { stores, vehicles, options } = usePage<Props>().props;
    const { data, setData, post, processing, errors } = useForm({
        store_id: stores.length === 1 ? String(stores[0].id) : '',
        name: '',
        phone: '',
        need: 'rent',
        purpose: '',
        category: '',
        resource_id: '',
        starts_at: '',
        ends_at: '',
        with_driver: false,
        budget: '',
        source: 'whatsapp',
        notes: '',
        ride_from: 'Aéroport',
        ride_to: '',
        ride_passengers: '1',
        ride_luggage: '1',
        flight_number: '',
    });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/location/requests', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-xl p-6">
            <form onSubmit={submit} className="space-y-3 text-sm">
                <h2 className="text-lg font-semibold">Nouvelle demande</h2>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Nom du client" error={errors.name}>
                        <input className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    </Field>
                    <Field label="Numéro WhatsApp" error={errors.phone}>
                        <input className={field} value={data.phone} onChange={(e) => setData('phone', e.target.value)} inputMode="tel" required />
                    </Field>
                    <Field label="Besoin">
                        <select className={field} value={data.need} onChange={(e) => setData('need', e.target.value)}>
                            <Options options={options.needs} />
                        </select>
                    </Field>
                    <Field label="Reçue par">
                        <select className={field} value={data.source} onChange={(e) => setData('source', e.target.value)}>
                            <Options options={options.sources} />
                        </select>
                    </Field>
                    <Field label="Type de véhicule">
                        <select className={field} value={data.category} onChange={(e) => setData('category', e.target.value)}>
                            <Options options={options.categories} empty="Peu importe" />
                        </select>
                    </Field>
                    <Field label="Véhicule précis (facultatif)" error={errors.resource_id}>
                        <select className={field} value={data.resource_id} onChange={(e) => setData('resource_id', e.target.value)}>
                            <option value="">—</option>
                            {vehicles
                                .filter((v) => (data.need === 'buy' ? v.status === 'for_sale' : v.status !== 'for_sale'))
                                .map((v) => (
                                    <option key={v.id} value={v.id}>
                                        {v.label}
                                    </option>
                                ))}
                        </select>
                    </Field>
                    {data.need === 'ride' && (
                        <>
                            <Field label="Départ">
                                <input className={field} value={data.ride_from} onChange={(e) => setData('ride_from', e.target.value)} placeholder="Aéroport" />
                            </Field>
                            <Field label="Destination" error={errors.ride_to}>
                                <input className={field} value={data.ride_to} onChange={(e) => setData('ride_to', e.target.value)} />
                            </Field>
                            <Field label="Date et heure">
                                <input type="datetime-local" className={field} value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                            </Field>
                            <Field label="N° de vol">
                                <input className={field} value={data.flight_number} onChange={(e) => setData('flight_number', e.target.value)} />
                            </Field>
                            <Field label="Passagers">
                                <input type="number" min={1} className={field} value={data.ride_passengers} onChange={(e) => setData('ride_passengers', e.target.value)} />
                            </Field>
                            <Field label="Bagages">
                                <input type="number" min={0} className={field} value={data.ride_luggage} onChange={(e) => setData('ride_luggage', e.target.value)} />
                            </Field>
                        </>
                    )}
                    {data.need === 'rent' && (
                        <>
                            <Field label="Début" error={(errors as Record<string, string>).starts_at}>
                                <input type="datetime-local" className={field} value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                            </Field>
                            <Field label="Fin" error={errors.ends_at}>
                                <input type="datetime-local" className={field} value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} />
                            </Field>
                            <label className="col-span-2 flex items-center gap-2">
                                <input type="checkbox" checked={data.with_driver} onChange={(e) => setData('with_driver', e.target.checked)} /> Avec chauffeur
                            </label>
                        </>
                    )}
                    <Field label="Pour">
                        <select className={field} value={data.purpose} onChange={(e) => setData('purpose', e.target.value)}>
                            <Options options={options.purposes} empty="—" />
                        </select>
                    </Field>
                    <Field label="Budget (FCFA)">
                        <input type="number" min={0} className={field} value={data.budget} onChange={(e) => setData('budget', e.target.value)} />
                    </Field>
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
                    <Field label="Note" className="col-span-2">
                        <input className={field} value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="ex. mariage samedi, 3 véhicules" />
                    </Field>
                </div>
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        Enregistrer
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function QuoteModal({ request, onClose }: { request: RequestRow; onClose: () => void }) {
    const { vehicles } = usePage<Props>().props;
    const { data, setData, post, processing, errors } = useForm({ resource_id: request.resource_id ? String(request.resource_id) : '', rate: '', driver_rate: '', deposit: '', price: '', notes: '' });
    const vehicle = vehicles.find((v) => String(v.id) === data.resource_id);

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-md p-6">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/location/requests/${request.id}/quote`, { preserveScroll: true, onSuccess: onClose });
                }}
                className="space-y-3 text-sm"
            >
                <h2 className="text-lg font-semibold">Devis pour {request.customer}</h2>
                <p className="text-gray-500">{request.summary}</p>
                <Field label="Véhicule" error={errors.resource_id}>
                    <select className={field} value={data.resource_id} onChange={(e) => setData('resource_id', e.target.value)}>
                        <option value="">—</option>
                        {vehicles.map((v) => (
                            <option key={v.id} value={v.id}>
                                {v.label}
                            </option>
                        ))}
                    </select>
                </Field>
                {request.need === 'buy' ? (
                    <Field label="Prix (FCFA)">
                        <input type="number" className={field} value={data.price} placeholder={vehicle?.sale_price ? String(vehicle.sale_price) : ''} onChange={(e) => setData('price', e.target.value)} />
                    </Field>
                ) : (
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Prix par jour" error={(errors as Record<string, string>).starts_at}>
                            <input type="number" className={field} value={data.rate} placeholder={vehicle?.price_day ? `${vehicle.price_day} (tarif)` : ''} onChange={(e) => setData('rate', e.target.value)} />
                        </Field>
                        {request.with_driver && (
                            <Field label="Chauffeur par jour">
                                <input type="number" className={field} value={data.driver_rate} onChange={(e) => setData('driver_rate', e.target.value)} />
                            </Field>
                        )}
                        <Field label="Caution (FCFA)">
                            <input type="number" className={field} value={data.deposit} onChange={(e) => setData('deposit', e.target.value)} />
                        </Field>
                    </div>
                )}
                <Field label="Note sur le devis">
                    <input className={field} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                </Field>
                {(errors as Record<string, string>).request && <p className="text-error-500">{(errors as Record<string, string>).request}</p>}
                <div className="flex justify-end gap-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        Créer le devis
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function BookModal({ request, onClose }: { request: RequestRow; onClose: () => void }) {
    const { vehicles } = usePage<Props>().props;
    const { data, setData, post, processing, errors } = useForm({
        resource_id: request.resource_id ? String(request.resource_id) : '',
        starts_at: request.starts_at ?? '',
        ends_at: request.ends_at ?? '',
        deposit: '',
        deposit_percent: '30',
        pickup_place: '',
        driver_name: '',
    });

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-md p-6">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/location/requests/${request.id}/book`, { onSuccess: onClose });
                }}
                className="space-y-3 text-sm"
            >
                <h2 className="text-lg font-semibold">Réserver pour {request.customer}</h2>
                <p className="text-gray-500">Le devis devient le contrat : acompte facturé maintenant, solde ensuite dans Devis et factures.</p>
                <Field label="Véhicule" error={errors.resource_id}>
                    <select className={field} value={data.resource_id} onChange={(e) => setData('resource_id', e.target.value)} required>
                        <option value="">—</option>
                        {vehicles
                            .filter((v) => v.status === 'available')
                            .map((v) => (
                                <option key={v.id} value={v.id}>
                                    {v.label}
                                </option>
                            ))}
                    </select>
                </Field>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Début">
                        <input type="datetime-local" className={field} value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} required />
                    </Field>
                    <Field label="Fin" error={errors.ends_at}>
                        <input type="datetime-local" className={field} value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} required />
                    </Field>
                    <Field label="Caution (FCFA)">
                        <input type="number" className={field} value={data.deposit} onChange={(e) => setData('deposit', e.target.value)} />
                    </Field>
                    <Field label="Acompte (%)">
                        <input type="number" min={0} max={99} className={field} value={data.deposit_percent} onChange={(e) => setData('deposit_percent', e.target.value)} />
                    </Field>
                </div>
                <Field label="Lieu de prise en charge">
                    <input className={field} value={data.pickup_place} onChange={(e) => setData('pickup_place', e.target.value)} placeholder="Agence, aéroport, hôtel…" />
                </Field>
                {request.with_driver && (
                    <Field label="Chauffeur">
                        <input className={field} value={data.driver_name} onChange={(e) => setData('driver_name', e.target.value)} />
                    </Field>
                )}
                {(errors as Record<string, string>).request && <p className="text-error-500">{(errors as Record<string, string>).request}</p>}
                <div className="flex justify-end gap-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        Confirmer la réservation
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function LostModal({ request, onClose }: { request: RequestRow; onClose: () => void }) {
    const { options } = usePage<Props>().props;
    const { data, setData, post, processing, errors } = useForm({ lost_reason: '', lost_note: '' });

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-sm p-6">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/location/requests/${request.id}/lose`, { preserveScroll: true, onSuccess: onClose });
                }}
                className="space-y-3 text-sm"
            >
                <h2 className="text-lg font-semibold">Demande perdue</h2>
                <Field label="Raison" error={errors.lost_reason}>
                    <select className={field} value={data.lost_reason} onChange={(e) => setData('lost_reason', e.target.value)} required>
                        <Options options={options.lost_reasons} empty="Choisir…" />
                    </select>
                </Field>
                <Field label="Précision (facultatif)">
                    <input className={field} value={data.lost_note} onChange={(e) => setData('lost_note', e.target.value)} />
                </Field>
                <div className="flex justify-end gap-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        Classer perdue
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function LocationRequests() {
    const { requests, whatsappUrl, vehicles, stores, fares } = usePage<Props>().props;
    const [modal, setModal] = useState<null | { kind: 'new' } | { kind: 'quote' | 'book' | 'lost' | 'ride'; request: RequestRow }>(null);

    return (
        <Page
            title="Demandes"
            subtitle="Chaque demande WhatsApp, Facebook, téléphone ou agence, jusqu’à la réservation ou la vente"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setModal({ kind: 'new' })}>
                    <PlusIcon />
                    Nouvelle demande
                </button>
            }
        >
            <WhatsAppBanner url={whatsappUrl} />
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                {COLUMNS.map((col) => {
                    const rows = requests.filter((r) => r.status === col.key);
                    return (
                        <section key={col.key} className={`mdash-panel mpage-panel border-t-4 ${col.tone}`}>
                            <h2 className="mb-2 flex justify-between text-sm font-semibold">
                                {col.label} <span className="text-gray-500">{rows.length}</span>
                            </h2>
                            {rows.length === 0 && <p className="text-xs text-gray-500">Aucune.</p>}
                            <ul className="space-y-2">
                                {rows.map((r) => (
                                    <li key={r.id} className="rounded-lg border border-gray-200 p-2.5 text-sm dark:border-white/10">
                                        <span className="font-medium">{r.customer}</span>
                                        <span className="block text-xs text-gray-600 dark:text-gray-400">{r.summary}</span>
                                        <span className="block text-xs text-gray-500">
                                            {[r.source_label, r.purpose_label, r.budget ? `budget ${money(r.budget)}` : null, fmt(r.created_at)].filter(Boolean).join(' · ')}
                                        </span>
                                        {r.quote && (
                                            <span className="block text-xs">
                                                Devis{' '}
                                                <a href={r.quote.url} target="_blank" rel="noreferrer" className="underline">
                                                    {r.quote.number}
                                                </a>{' '}
                                                · {money(r.quote.total)}
                                            </span>
                                        )}
                                        {r.lost_label && <span className="block text-xs text-gray-500">Perdue : {r.lost_label}</span>}
                                        {(r.status === 'new' || r.status === 'quoted') && (
                                            <span className="mpage-rowlinks mt-1.5 flex flex-wrap gap-2">
                                                {r.need === 'ride' && (
                                                    <button type="button" onClick={() => setModal({ kind: 'ride', request: r })}>
                                                        Réserver la course
                                                    </button>
                                                )}
                                                {r.status === 'new' && r.need !== 'ride' && (
                                                    <button type="button" onClick={() => setModal({ kind: 'quote', request: r })}>
                                                        Devis
                                                    </button>
                                                )}
                                                {r.quote_wa && (
                                                    <a href={r.quote_wa} target="_blank" rel="noreferrer">
                                                        Envoyer le devis
                                                    </a>
                                                )}
                                                {r.need === 'rent' && (
                                                    <button type="button" onClick={() => setModal({ kind: 'book', request: r })}>
                                                        Réserver
                                                    </button>
                                                )}
                                                <button type="button" onClick={() => setModal({ kind: 'lost', request: r })}>
                                                    Perdue
                                                </button>
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    );
                })}
            </div>

            {modal?.kind === 'new' && <NewRequest onClose={() => setModal(null)} />}
            {modal?.kind === 'quote' && <QuoteModal request={modal.request} onClose={() => setModal(null)} />}
            {modal?.kind === 'book' && <BookModal request={modal.request} onClose={() => setModal(null)} />}
            {modal?.kind === 'lost' && <LostModal request={modal.request} onClose={() => setModal(null)} />}
            {modal?.kind === 'ride' && (
                <RideModal
                    vehicles={vehicles}
                    stores={stores}
                    fares={fares}
                    prefill={{ requestId: modal.request.id, customer: modal.request.customer, from: modal.request.ride?.from, to: modal.request.ride?.to, startsAt: modal.request.starts_at, passengers: modal.request.ride?.passengers, luggage: modal.request.ride?.luggage, flight: modal.request.ride?.flight }}
                    onClose={() => setModal(null)}
                />
            )}
        </Page>
    );
}

LocationRequests.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default LocationRequests;
