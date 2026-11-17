import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel } from '../../components/page/Page';
import { Modal } from '../../components/ui/modal';
import CustomerPicker, { PickedCustomer } from '../../components/common/CustomerPicker';

/*
 * Flotte (module « vehicules ») : planning des véhicules sur 14 jours,
 * contrats de location (départ, retour, encaissement), fiches véhicules
 * avec leurs échéances, et véhicules à vendre.
 */

interface Cell {
    rental_id: number;
    customer: string | null;
    status: string;
    first: boolean;
}

interface Vehicle {
    id: number;
    name: string;
    reference: string | null;
    label: string;
    category: string | null;
    category_label: string | null;
    store_id: number | null;
    store: string | null;
    mileage: number | null;
    price_day: number | null;
    price_week: number | null;
    price_month: number | null;
    status: string;
    status_label: string;
    sale_price: number | null;
    for_sale_since: string | null;
    days_for_sale: number | null;
    service_due_km: number | null;
    service_due_on: string | null;
    insurance_until: string | null;
    inspection_until: string | null;
    deadlines: { label: string; due: string; late: boolean }[];
    active: boolean;
}

interface RentalRow {
    id: number;
    vehicle: string | null;
    customer_id: number;
    customer: string | null;
    driver: string | null;
    kind: string;
    kind_label: string;
    status: string;
    status_label: string;
    starts_at: string;
    ends_at: string;
    amount: number;
    deposit: number | null;
    km_out: number | null;
    paid: boolean;
}

interface PageProps {
    planning: { days: { date: string; label: string }[]; rows: { id: number; name: string; category: string | null; status: string; cells: (Cell | null)[] }[] };
    vehicles: Vehicle[];
    rentals: RentalRow[];
    summary: { fleet: number; out: number; late: number; free_tomorrow: number; safety_stock: number; for_sale: number };
    from: string;
    stores: { id: number; name: string }[];
    drivers: { id: number; name: string; store_id: number }[];
    categories: Record<string, string>;
    statuses: Record<string, string>;
    paymentMethods: Record<string, string>;
    [key: string]: unknown;
}

const field =
    'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
const btn = 'rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50';
const ghost = 'rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300';

const money = (n: number | null) => (n === null ? '—' : `${new Intl.NumberFormat('fr-FR').format(n)} FCFA`);
const shortDate = (s: string) => new Date(s.replace(' ', 'T')).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

const CELL_TONES: Record<string, string> = {
    reserved: 'bg-blue-light-100 text-blue-light-800 dark:bg-blue-light-500/20 dark:text-blue-light-300',
    ongoing: 'bg-brand-500 text-white',
    late: 'bg-error-500 text-white',
};

type Tab = 'planning' | 'contrats' | 'flotte' | 'vente';

function VehiclesIndex() {
    const { planning, vehicles, rentals, summary, from } = usePage<PageProps>().props;
    const [tab, setTab] = useState<Tab>('planning');
    const [vehicleForm, setVehicleForm] = useState<Vehicle | 'new' | null>(null);
    const [rentalFor, setRentalFor] = useState<{ vehicleId?: number; date?: string } | null>(null);
    const [move, setMove] = useState<{ rental: RentalRow; action: 'depart' | 'return' } | null>(null);
    const [selling, setSelling] = useState<Vehicle | null>(null);

    const shift = (days: number) => {
        const d = new Date(from);
        d.setDate(d.getDate() + days);
        router.get('/vehicles', { from: d.toISOString().slice(0, 10) }, { preserveState: true, preserveScroll: true });
    };

    const tabs: [Tab, string][] = [
        ['planning', 'Planning'],
        ['contrats', `Contrats (${rentals.filter((r) => r.status !== 'returned').length})`],
        ['flotte', `Flotte (${vehicles.filter((v) => v.status !== 'sold').length})`],
        ['vente', `À vendre (${summary.for_sale})`],
    ];

    return (
        <Page
            title="Flotte"
            subtitle="Planning, locations courte et longue durée, entretien et vente"
            actions={
                <div className="flex gap-2">
                    <button type="button" className={ghost} onClick={() => setVehicleForm('new')}>
                        Ajouter un véhicule
                    </button>
                    <button type="button" className={btn} onClick={() => setRentalFor({})}>
                        Nouvelle location
                    </button>
                </div>
            }
        >
            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                <Stat label="Véhicules en location" value={summary.fleet} />
                <Stat label="Sortis" value={summary.out} />
                <Stat label="Retours en retard" value={summary.late} tone={summary.late > 0 ? 'bad' : undefined} href="/actions?list=rental_late" />
                <Stat label="Libres demain" value={summary.free_tomorrow} foot={`stock de sécurité : ${summary.safety_stock}`} tone={summary.free_tomorrow < summary.safety_stock ? 'bad' : 'ok'} />
                <Stat label="À vendre" value={summary.for_sale} />
            </div>

            <div className="mb-4 flex flex-wrap gap-2" role="tablist">
                {tabs.map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        aria-selected={tab === key}
                        onClick={() => setTab(key)}
                        className={`rounded-lg px-3 py-1.5 text-sm font-medium ${tab === key ? 'bg-brand-500 text-white' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5'}`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {tab === 'planning' && (
                <Panel title="Planning de la flotte" sub="Cliquez sur une case libre pour louer le véhicule ce jour-là" className="min-w-0 max-w-full overflow-hidden">
                    <div className="mb-3 flex gap-2">
                        <button type="button" className={ghost} onClick={() => shift(-7)}>
                            ← 7 jours
                        </button>
                        <button type="button" className={ghost} onClick={() => router.get('/vehicles')}>
                            Aujourd’hui
                        </button>
                        <button type="button" className={ghost} onClick={() => shift(7)}>
                            7 jours →
                        </button>
                    </div>
                    {planning.rows.length === 0 ? (
                        <Empty title="Aucun véhicule">Ajoutez vos véhicules pour voir le planning.</Empty>
                    ) : (
                        <div className="w-0 min-w-full overflow-x-auto">
                            <table className="w-full border-separate border-spacing-y-1 text-xs">
                                <thead>
                                    <tr>
                                        <th className="sticky left-0 bg-white pr-3 text-left font-medium text-gray-500 dark:bg-gray-900">Véhicule</th>
                                        {planning.days.map((d) => (
                                            <th key={d.date} className="min-w-16 px-0.5 text-center font-medium text-gray-500 first-letter:uppercase">
                                                {d.label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {planning.rows.map((row) => (
                                        <tr key={row.id}>
                                            <th className="sticky left-0 bg-white pr-3 text-left font-medium whitespace-nowrap dark:bg-gray-900">
                                                {row.name}
                                                {row.category && <span className="block text-[11px] font-normal text-gray-400">{row.category}</span>}
                                            </th>
                                            {row.cells.map((cell, i) =>
                                                cell ? (
                                                    <td key={i} className={`h-9 truncate px-1 ${CELL_TONES[cell.status] ?? CELL_TONES.reserved}`} title={cell.customer ?? ''}>
                                                        {cell.first ? cell.customer : ''}
                                                    </td>
                                                ) : row.status === 'available' ? (
                                                    <td key={i} className="h-9 px-0.5">
                                                        <button
                                                            type="button"
                                                            aria-label={`Louer ${row.name} le ${planning.days[i].label}`}
                                                            onClick={() => setRentalFor({ vehicleId: row.id, date: planning.days[i].date })}
                                                            className="h-full w-full rounded bg-gray-50 hover:bg-brand-50 dark:bg-white/5"
                                                        />
                                                    </td>
                                                ) : (
                                                    <td key={i} className="h-9 bg-gray-100 dark:bg-white/10" title={row.status === 'maintenance' ? 'En entretien' : 'À vendre'} />
                                                ),
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <p className="mt-2 text-xs text-gray-500">Bleu clair : réservé · bleu : sorti · rouge : retour en retard · gris : en entretien ou à vendre.</p>
                        </div>
                    )}
                </Panel>
            )}

            {tab === 'contrats' && (
                <Panel title="Contrats de location" sub="Réservés, en cours et rendus ces 7 derniers jours" className="min-w-0 max-w-full overflow-hidden">
                    {rentals.length === 0 ? (
                        <Empty title="Aucun contrat en cours" />
                    ) : (
                        <div className="w-0 min-w-full overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs text-gray-500">
                                    <tr>
                                        <th className="py-2">Véhicule</th>
                                        <th>Client</th>
                                        <th>Période</th>
                                        <th>Montant</th>
                                        <th>Statut</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                    {rentals.map((r) => (
                                        <tr key={r.id}>
                                            <td className="py-2">
                                                {r.vehicle}
                                                <span className="block text-xs text-gray-400">
                                                    {r.kind_label}
                                                    {r.driver && ` · chauffeur ${r.driver}`}
                                                </span>
                                            </td>
                                            <td>
                                                <Link href={`/customers/${r.customer_id}`} className="hover:underline">
                                                    {r.customer}
                                                </Link>
                                            </td>
                                            <td className="text-xs">
                                                {shortDate(r.starts_at)} → {shortDate(r.ends_at)}
                                            </td>
                                            <td>
                                                {money(r.amount)}
                                                {r.deposit ? <span className="block text-xs text-gray-400">caution {money(r.deposit)}</span> : null}
                                            </td>
                                            <td>
                                                <span className={`rounded-full px-2 py-0.5 text-xs ${r.status === 'late' ? 'bg-error-50 text-error-700' : 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300'}`}>{r.status_label}</span>
                                            </td>
                                            <td className="text-right whitespace-nowrap">
                                                {r.status === 'reserved' && (
                                                    <>
                                                        <button type="button" className={ghost} onClick={() => setMove({ rental: r, action: 'depart' })}>
                                                            Départ
                                                        </button>{' '}
                                                        <button
                                                            type="button"
                                                            className={ghost}
                                                            onClick={() => confirm('Annuler ce contrat ?') && router.post(`/rentals/${r.id}/cancel`, {}, { preserveScroll: true })}
                                                        >
                                                            Annuler
                                                        </button>
                                                    </>
                                                )}
                                                {(r.status === 'ongoing' || r.status === 'late') && (
                                                    <button type="button" className={ghost} onClick={() => setMove({ rental: r, action: 'return' })}>
                                                        Retour
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>
            )}

            {tab === 'flotte' && (
                <Panel title="Véhicules" sub="Tarifs, kilométrage et échéances (entretien, assurance, visite technique)">
                    <VehicleTable vehicles={vehicles.filter((v) => v.status !== 'sold')} onEdit={setVehicleForm} onSell={setSelling} />
                </Panel>
            )}

            {tab === 'vente' && (
                <Panel title="Véhicules à vendre" sub="Un véhicule qui reste trop longtemps au parc perd de la valeur chaque mois">
                    <VehicleTable vehicles={vehicles.filter((v) => v.status === 'for_sale' || v.status === 'sold')} onEdit={setVehicleForm} onSell={setSelling} sale />
                </Panel>
            )}

            {vehicleForm && <VehicleForm vehicle={vehicleForm === 'new' ? null : vehicleForm} onClose={() => setVehicleForm(null)} />}
            {rentalFor && <RentalForm preset={rentalFor} onClose={() => setRentalFor(null)} />}
            {move && <MoveForm rental={move.rental} action={move.action} onClose={() => setMove(null)} />}
            {selling && <SellForm vehicle={selling} onClose={() => setSelling(null)} />}
        </Page>
    );
}

function Stat({ label, value, foot, tone, href }: { label: string; value: number; foot?: string; tone?: 'ok' | 'bad'; href?: string }) {
    const body = (
        <div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <p className="text-xs text-gray-500">{label}</p>
            <p className={`mt-1 text-2xl font-bold ${tone === 'bad' ? 'text-error-600' : tone === 'ok' ? 'text-success-600' : 'text-gray-800 dark:text-white/90'}`}>{value}</p>
            {foot && <p className="text-[11px] text-gray-400">{foot}</p>}
        </div>
    );

    return href ? <Link href={href}>{body}</Link> : body;
}

function VehicleTable({ vehicles, onEdit, onSell, sale = false }: { vehicles: Vehicle[]; onEdit: (v: Vehicle) => void; onSell: (v: Vehicle) => void; sale?: boolean }) {
    if (vehicles.length === 0) {
        return <Empty title={sale ? 'Aucun véhicule à vendre' : 'Aucun véhicule pour l’instant'}>{sale ? 'Passez un véhicule au statut « À vendre » depuis l’onglet Flotte.' : 'Ajoutez votre premier véhicule.'}</Empty>;
    }

    return (
        <div className="w-0 min-w-full overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="text-left text-xs text-gray-500">
                    <tr>
                        <th className="py-2">Véhicule</th>
                        <th>Statut</th>
                        <th>{sale ? 'Prix de vente' : 'Tarifs'}</th>
                        <th>Km</th>
                        <th>{sale ? 'Au parc depuis' : 'Échéances'}</th>
                        <th />
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {vehicles.map((v) => (
                        <tr key={v.id}>
                            <td className="py-2">
                                {v.label}
                                <span className="block text-xs text-gray-400">{[v.category_label, v.store].filter(Boolean).join(' · ')}</span>
                            </td>
                            <td>{v.status_label}</td>
                            <td className="text-xs">
                                {sale ? (
                                    money(v.sale_price)
                                ) : (
                                    <>
                                        {money(v.price_day)} / jour
                                        {v.price_month ? <span className="block text-gray-400">{money(v.price_month)} / mois</span> : null}
                                    </>
                                )}
                            </td>
                            <td className="text-xs">{v.mileage !== null ? new Intl.NumberFormat('fr-FR').format(v.mileage) : '—'}</td>
                            <td className="text-xs">
                                {sale ? (
                                    v.days_for_sale !== null ? (
                                        <span className={v.days_for_sale >= 60 ? 'font-medium text-error-600' : ''}>{v.days_for_sale} jours</span>
                                    ) : (
                                        '—'
                                    )
                                ) : v.deadlines.length === 0 ? (
                                    <span className="text-gray-400">À jour</span>
                                ) : (
                                    v.deadlines.map((d) => (
                                        <span key={d.label + d.due} className={`block ${d.late ? 'font-medium text-error-600' : 'text-warning-600'}`}>
                                            {d.label} : {d.due}
                                        </span>
                                    ))
                                )}
                            </td>
                            <td className="text-right whitespace-nowrap">
                                {v.status !== 'sold' && (
                                    <button type="button" className={ghost} onClick={() => onEdit(v)}>
                                        Modifier
                                    </button>
                                )}{' '}
                                {v.status === 'for_sale' && (
                                    <button type="button" className={ghost} onClick={() => onSell(v)}>
                                        Vendre
                                    </button>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <label className="block text-sm">
            <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">{label}</span>
            {children}
            {error && <span className="mt-1 block text-xs text-error-500">{error}</span>}
        </label>
    );
}

function VehicleForm({ vehicle, onClose }: { vehicle: Vehicle | null; onClose: () => void }) {
    const { stores, categories, statuses } = usePage<PageProps>().props;
    const { data, setData, post, put, processing, errors } = useForm({
        name: vehicle?.name ?? '',
        reference: vehicle?.reference ?? '',
        category: vehicle?.category ?? 'citadine',
        store_id: vehicle?.store_id ? String(vehicle.store_id) : stores.length === 1 ? String(stores[0].id) : '',
        mileage: vehicle?.mileage ?? '',
        price_day: vehicle?.price_day ?? '',
        price_week: vehicle?.price_week ?? '',
        price_month: vehicle?.price_month ?? '',
        status: vehicle?.status ?? 'available',
        sale_price: vehicle?.sale_price ?? '',
        service_due_km: vehicle?.service_due_km ?? '',
        service_due_on: vehicle?.service_due_on ?? '',
        insurance_until: vehicle?.insurance_until ?? '',
        inspection_until: vehicle?.inspection_until ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (vehicle) {
            put(`/vehicles/${vehicle.id}`, options);
        } else {
            post('/vehicles', options);
        }
    };

    const num = (key: 'mileage' | 'price_day' | 'price_week' | 'price_month' | 'sale_price' | 'service_due_km', label: string) => (
        <Field label={label} error={errors[key]}>
            <input type="number" min={0} value={data[key]} onChange={(e) => setData(key, e.target.value)} className={field} />
        </Field>
    );

    return (
        <Modal isOpen onClose={onClose} className="m-4 max-w-2xl p-6">
            <form onSubmit={submit} className="space-y-4">
                <h3 className="text-lg font-semibold">{vehicle ? `Modifier ${vehicle.label}` : 'Ajouter un véhicule'}</h3>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label="Modèle" error={errors.name}>
                        <input value={data.name} onChange={(e) => setData('name', e.target.value)} className={field} placeholder="Toyota Hilux" />
                    </Field>
                    <Field label="Immatriculation" error={errors.reference}>
                        <input value={data.reference} onChange={(e) => setData('reference', e.target.value)} className={field} />
                    </Field>
                    <Field label="Catégorie" error={errors.category}>
                        <select value={data.category} onChange={(e) => setData('category', e.target.value)} className={field}>
                            {Object.entries(categories).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Agence" error={errors.store_id}>
                        <select value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} className={field}>
                            <option value="">Toutes</option>
                            {stores.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Statut" error={errors.status}>
                        <select value={data.status} onChange={(e) => setData('status', e.target.value)} className={field}>
                            {Object.entries(statuses)
                                .filter(([k]) => k !== 'sold')
                                .map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                        </select>
                    </Field>
                    {num('mileage', 'Kilométrage')}
                    {num('price_day', 'Prix par jour (FCFA)')}
                    {num('price_week', 'Prix par semaine')}
                    {num('price_month', 'Prix par mois')}
                    {data.status === 'for_sale' && num('sale_price', 'Prix de vente')}
                    {num('service_due_km', 'Prochain entretien (km)')}
                    <Field label="Prochain entretien (date)" error={errors.service_due_on}>
                        <input type="date" value={data.service_due_on} onChange={(e) => setData('service_due_on', e.target.value)} className={field} />
                    </Field>
                    <Field label="Assurance jusqu’au" error={errors.insurance_until}>
                        <input type="date" value={data.insurance_until} onChange={(e) => setData('insurance_until', e.target.value)} className={field} />
                    </Field>
                    <Field label="Visite technique jusqu’au" error={errors.inspection_until}>
                        <input type="date" value={data.inspection_until} onChange={(e) => setData('inspection_until', e.target.value)} className={field} />
                    </Field>
                </div>
                <div className="flex justify-end gap-2">
                    <button type="button" className={ghost} onClick={onClose}>
                        Fermer
                    </button>
                    <button type="submit" className={btn} disabled={processing}>
                        Enregistrer
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function RentalForm({ preset, onClose }: { preset: { vehicleId?: number; date?: string }; onClose: () => void }) {
    const { vehicles, stores, drivers } = usePage<PageProps>().props;
    const rentable = vehicles.filter((v) => v.status === 'available' && v.active);
    const first = rentable.find((v) => v.id === preset.vehicleId) ?? rentable[0];
    const [customer, setCustomer] = useState<PickedCustomer | null>(null);
    const day = preset.date ?? new Date().toISOString().slice(0, 10);
    const { data, setData, post, processing, errors, transform } = useForm({
        resource_id: first ? String(first.id) : '',
        customer_id: '',
        store_id: first?.store_id ? String(first.store_id) : stores.length ? String(stores[0].id) : '',
        driver_id: '',
        kind: 'short',
        starts_at: `${day}T08:00`,
        ends_at: `${day}T18:00`,
        rate: first?.price_day ? String(first.price_day) : '',
        rate_period: 'day',
        deposit: '',
        notes: '',
    });

    const vehicle = vehicles.find((v) => String(v.id) === data.resource_id);
    const priceFor = (period: string) => (period === 'month' ? vehicle?.price_month : period === 'week' ? vehicle?.price_week : vehicle?.price_day) ?? null;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((d) => ({ ...d, customer_id: customer?.id ?? '', starts_at: d.starts_at.replace('T', ' '), ends_at: d.ends_at.replace('T', ' ') }));
        post('/rentals', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal isOpen onClose={onClose} className="m-4 max-w-2xl p-6">
            <form onSubmit={submit} className="space-y-4">
                <h3 className="text-lg font-semibold">Nouvelle location</h3>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Véhicule" error={errors.resource_id}>
                        <select
                            value={data.resource_id}
                            onChange={(e) => {
                                setData('resource_id', e.target.value);
                                const v = vehicles.find((x) => String(x.id) === e.target.value);
                                const p = data.rate_period === 'month' ? v?.price_month : data.rate_period === 'week' ? v?.price_week : v?.price_day;
                                if (p) setData((d) => ({ ...d, resource_id: e.target.value, rate: String(p) }));
                            }}
                            className={field}
                        >
                            {rentable.map((v) => (
                                <option key={v.id} value={v.id}>
                                    {v.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Client" error={errors.customer_id}>
                        <CustomerPicker value={customer} onChange={setCustomer} />
                    </Field>
                    <Field label="Durée" error={errors.kind}>
                        <select
                            value={data.kind}
                            onChange={(e) => {
                                const period = e.target.value === 'long' ? 'month' : 'day';
                                setData((d) => ({ ...d, kind: e.target.value, rate_period: period, rate: String(priceFor(period) ?? d.rate) }));
                            }}
                            className={field}
                        >
                            <option value="short">Courte durée (jour, semaine)</option>
                            <option value="long">Longue durée (mois)</option>
                        </select>
                    </Field>
                    <Field label="Chauffeur" error={errors.driver_id}>
                        <select value={data.driver_id} onChange={(e) => setData('driver_id', e.target.value)} className={field}>
                            <option value="">Sans chauffeur</option>
                            {drivers.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Départ" error={errors.starts_at}>
                        <input type="datetime-local" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} className={field} />
                    </Field>
                    <Field label="Retour prévu" error={errors.ends_at}>
                        <input type="datetime-local" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} className={field} />
                    </Field>
                    <Field label="Tarif (FCFA)" error={errors.rate}>
                        <div className="flex gap-2">
                            <input type="number" min={0} value={data.rate} onChange={(e) => setData('rate', e.target.value)} className={field} />
                            <select
                                value={data.rate_period}
                                onChange={(e) => setData((d) => ({ ...d, rate_period: e.target.value, rate: String(priceFor(e.target.value) ?? d.rate) }))}
                                className={`${field} w-32`}
                            >
                                <option value="day">/ jour</option>
                                <option value="week">/ semaine</option>
                                <option value="month">/ mois</option>
                            </select>
                        </div>
                    </Field>
                    <Field label="Caution (FCFA)" error={errors.deposit}>
                        <input type="number" min={0} value={data.deposit} onChange={(e) => setData('deposit', e.target.value)} className={field} />
                    </Field>
                    <Field label="Agence" error={errors.store_id}>
                        <select value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} className={field}>
                            {stores.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Notes" error={errors.notes}>
                        <input value={data.notes} onChange={(e) => setData('notes', e.target.value)} className={field} placeholder="Destination, état du véhicule…" />
                    </Field>
                </div>
                {data.kind === 'long' && <p className="text-xs text-gray-500">Longue durée : une mensualité par mois est créée dans les échéances, et le renouvellement vous sera proposé 30 jours avant la fin.</p>}
                <div className="flex justify-end gap-2">
                    <button type="button" className={ghost} onClick={onClose}>
                        Fermer
                    </button>
                    <button type="submit" className={btn} disabled={processing || !customer || !data.resource_id}>
                        Enregistrer le contrat
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function MoveForm({ rental, action, onClose }: { rental: RentalRow; action: 'depart' | 'return'; onClose: () => void }) {
    const { paymentMethods } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors } = useForm({ km: rental.km_out ?? '', fuel: '', extra: '', payment_method: 'cash' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/rentals/${rental.id}/${action}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal isOpen onClose={onClose} className="m-4 max-w-md p-6">
            <form onSubmit={submit} className="space-y-4">
                <h3 className="text-lg font-semibold">
                    {action === 'depart' ? 'Départ' : 'Retour'} · {rental.vehicle}
                </h3>
                <p className="text-sm text-gray-500">{rental.customer}</p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Kilométrage" error={errors.km}>
                        <input type="number" min={0} value={data.km} onChange={(e) => setData('km', e.target.value)} className={field} />
                    </Field>
                    <Field label="Carburant (%)" error={errors.fuel}>
                        <input type="number" min={0} max={100} value={data.fuel} onChange={(e) => setData('fuel', e.target.value)} className={field} />
                    </Field>
                    {action === 'return' && rental.kind === 'short' && (
                        <>
                            <Field label="Frais en plus (FCFA)" error={errors.extra}>
                                <input type="number" min={0} value={data.extra} onChange={(e) => setData('extra', e.target.value)} className={field} placeholder="Carburant, dégâts…" />
                            </Field>
                            <Field label="Paiement" error={errors.payment_method}>
                                <select value={data.payment_method} onChange={(e) => setData('payment_method', e.target.value)} className={field}>
                                    {Object.entries(paymentMethods).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </>
                    )}
                </div>
                {action === 'return' && (
                    <p className="text-xs text-gray-500">
                        {rental.kind === 'short'
                            ? `La location est encaissée au retour (${money(rental.amount)}, plus les jours de retard éventuels) et enregistrée dans les ventes.`
                            : 'Longue durée : les mensualités sont suivies dans les échéances.'}
                    </p>
                )}
                {'rental' in errors && <p className="text-xs text-error-500">{(errors as Record<string, string>).rental}</p>}
                <div className="flex justify-end gap-2">
                    <button type="button" className={ghost} onClick={onClose}>
                        Fermer
                    </button>
                    <button type="submit" className={btn} disabled={processing}>
                        {action === 'depart' ? 'Enregistrer le départ' : 'Enregistrer le retour'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function SellForm({ vehicle, onClose }: { vehicle: Vehicle; onClose: () => void }) {
    const { stores, paymentMethods } = usePage<PageProps>().props;
    const [buyer, setBuyer] = useState<PickedCustomer | null>(null);
    const { data, setData, post, processing, errors, transform } = useForm({
        customer_id: '',
        store_id: vehicle.store_id ? String(vehicle.store_id) : stores.length ? String(stores[0].id) : '',
        price: vehicle.sale_price ? String(vehicle.sale_price) : '',
        payment_method: 'transfer',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((d) => ({ ...d, customer_id: buyer?.id ?? '' }));
        post(`/vehicles/${vehicle.id}/sell`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal isOpen onClose={onClose} className="m-4 max-w-md p-6">
            <form onSubmit={submit} className="space-y-4">
                <h3 className="text-lg font-semibold">Vendre {vehicle.label}</h3>
                <Field label="Acheteur" error={errors.customer_id}>
                    <CustomerPicker value={buyer} onChange={setBuyer} />
                </Field>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Prix (FCFA)" error={errors.price}>
                        <input type="number" min={1} value={data.price} onChange={(e) => setData('price', e.target.value)} className={field} />
                    </Field>
                    <Field label="Paiement" error={errors.payment_method}>
                        <select value={data.payment_method} onChange={(e) => setData('payment_method', e.target.value)} className={field}>
                            {Object.entries(paymentMethods).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>
                {'vehicle' in errors && <p className="text-xs text-error-500">{(errors as Record<string, string>).vehicle}</p>}
                <div className="flex justify-end gap-2">
                    <button type="button" className={ghost} onClick={onClose}>
                        Fermer
                    </button>
                    <button type="submit" className={btn} disabled={processing || !buyer}>
                        Enregistrer la vente
                    </button>
                </div>
            </form>
        </Modal>
    );
}

VehiclesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default VehiclesIndex;
