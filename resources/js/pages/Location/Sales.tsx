import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { Empty, Page, Panel, PlusIcon } from '../../components/page/Page';
import { Modal } from '../../components/ui/modal';
import AppLayout from '../../layout/AppLayout';
import { Field, Options, WhatsAppBanner, field, fmt, money } from './shared';

/* Véhicules à vendre (prix, photos, statut) et acheteurs suivis : essai, offre, reprise, paiement en plusieurs fois. */

interface Buyer {
    id: number;
    customer: string;
    status: string;
    status_label: string;
    test_drive_at: string | null;
    offer_amount: number | null;
    trade_in_label: string | null;
    trade_in_value: number | null;
    installments: { paid: number; count: number } | null;
    wa_url: string | null;
}

interface SaleVehicle {
    id: number;
    label: string;
    mileage: number | null;
    sale_price: number | null;
    description: string | null;
    days_for_sale: number | null;
    sale_status: 'available' | 'reserved' | 'sold';
    photos: string[];
    listing_url: string | null;
    buyers: Buyer[];
}

interface Props {
    vehicles: SaleVehicle[];
    candidates: { id: number; label: string }[];
    stores: { id: number; name: string }[];
    paymentMethods: Record<string, string>;
    whatsappUrl?: string | null;
    [key: string]: unknown;
}

const STATUS = { available: ['Disponible', 'bg-emerald-100 text-emerald-800'], reserved: ['Réservé', 'bg-amber-100 text-amber-800'], sold: ['Vendu', 'bg-gray-200 text-gray-700'] } as const;

function ForSaleModal({ vehicle, onClose }: { vehicle: SaleVehicle | null; onClose: () => void }) {
    const { candidates } = usePage<Props>().props;
    const [id, setId] = useState(vehicle ? String(vehicle.id) : '');
    const { data, setData, post, processing, errors } = useForm({ sale_price: vehicle?.sale_price ? String(vehicle.sale_price) : '', description: vehicle?.description ?? '', photos: [] as File[] });

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-md p-6">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/location/vehicles/${id}/for-sale`, { forceFormData: true, preserveScroll: true, onSuccess: onClose });
                }}
                className="space-y-3 text-sm"
            >
                <h2 className="text-lg font-semibold">{vehicle ? `Modifier ${vehicle.label}` : 'Mettre un véhicule en vente'}</h2>
                {!vehicle && (
                    <Field label="Véhicule">
                        <select className={field} value={id} onChange={(e) => setId(e.target.value)} required>
                            <option value="">—</option>
                            {candidates.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                )}
                <Field label="Prix (FCFA)" error={errors.sale_price}>
                    <input type="number" className={field} value={data.sale_price} onChange={(e) => setData('sale_price', e.target.value)} required />
                </Field>
                <Field label="Description">
                    <textarea className={field} rows={2} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                </Field>
                <Field label="Photos" error={errors.photos}>
                    <input type="file" accept="image/*" multiple onChange={(e) => setData('photos', Array.from(e.target.files ?? []))} />
                </Field>
                <div className="flex justify-end gap-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing || !id}>
                        Enregistrer
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function BuyerModal({ vehicle, onClose }: { vehicle: SaleVehicle; onClose: () => void }) {
    const { stores } = usePage<Props>().props;
    const { data, setData, post, processing, errors } = useForm({ store_id: String(stores[0]?.id ?? ''), name: '', phone: '', notes: '' });

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-sm p-6">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/location/vehicles/${vehicle.id}/buyers`, { preserveScroll: true, onSuccess: onClose });
                }}
                className="space-y-3 text-sm"
            >
                <h2 className="text-lg font-semibold">Acheteur pour {vehicle.label}</h2>
                <Field label="Nom" error={errors.name}>
                    <input className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                </Field>
                <Field label="Numéro WhatsApp" error={errors.phone}>
                    <input className={field} value={data.phone} onChange={(e) => setData('phone', e.target.value)} required />
                </Field>
                <Field label="Note">
                    <input className={field} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                </Field>
                <div className="flex justify-end gap-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        Suivre
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function StepModal({ buyer, vehicle, onClose }: { buyer: Buyer; vehicle: SaleVehicle; onClose: () => void }) {
    const { paymentMethods } = usePage<Props>().props;
    const step = useForm({ status: 'test_drive', test_drive_at: '', offer_amount: '', trade_in_label: '', trade_in_value: '', lost_reason: '' });
    const sale = useForm({ price: String(buyer.offer_amount ?? vehicle.sale_price ?? ''), installments: '1', payment_method: 'cash' });
    const s = step.data.status;

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-md p-6">
            <div className="space-y-4 text-sm">
                <h2 className="text-lg font-semibold">
                    {buyer.customer} · {vehicle.label}
                </h2>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        step.post(`/location/sales/${buyer.id}/move`, { preserveScroll: true, onSuccess: onClose });
                    }}
                    className="space-y-3"
                >
                    <Field label="Étape" error={step.errors.status}>
                        <select className={field} value={s} onChange={(e) => step.setData('status', e.target.value)}>
                            <Options options={{ test_drive: 'Essai programmé', offer: 'Offre faite', reserved: 'Réservé', lost: 'Perdu' }} />
                        </select>
                    </Field>
                    {s === 'test_drive' && (
                        <Field label="Date de l’essai" error={step.errors.test_drive_at}>
                            <input type="datetime-local" className={field} value={step.data.test_drive_at} onChange={(e) => step.setData('test_drive_at', e.target.value)} required />
                        </Field>
                    )}
                    {s === 'offer' && (
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Offre (FCFA)" error={step.errors.offer_amount}>
                                <input type="number" className={field} value={step.data.offer_amount} onChange={(e) => step.setData('offer_amount', e.target.value)} required />
                            </Field>
                            <Field label="Valeur reprise">
                                <input type="number" className={field} value={step.data.trade_in_value} onChange={(e) => step.setData('trade_in_value', e.target.value)} />
                            </Field>
                            <Field label="Véhicule repris" className="col-span-2">
                                <input className={field} value={step.data.trade_in_label} onChange={(e) => step.setData('trade_in_label', e.target.value)} />
                            </Field>
                        </div>
                    )}
                    {s === 'lost' && (
                        <Field label="Raison" error={step.errors.lost_reason}>
                            <input className={field} value={step.data.lost_reason} onChange={(e) => step.setData('lost_reason', e.target.value)} required maxLength={30} />
                        </Field>
                    )}
                    <button type="submit" className="mdash-btn ghost" disabled={step.processing}>
                        Enregistrer l’étape
                    </button>
                </form>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        sale.post(`/location/sales/${buyer.id}/sell`, { preserveScroll: true, onSuccess: onClose });
                    }}
                    className="space-y-3 border-t border-gray-100 pt-3 dark:border-white/10"
                >
                    <h3 className="font-semibold">Conclure la vente</h3>
                    <div className="grid grid-cols-3 gap-3">
                        <Field label="Prix" error={sale.errors.price}>
                            <input type="number" className={field} value={sale.data.price} onChange={(e) => sale.setData('price', e.target.value)} required />
                        </Field>
                        <Field label="En (fois)">
                            <input type="number" min={1} max={36} className={field} value={sale.data.installments} onChange={(e) => sale.setData('installments', e.target.value)} />
                        </Field>
                        <Field label="Payé en">
                            <select className={field} value={sale.data.payment_method} onChange={(e) => sale.setData('payment_method', e.target.value)}>
                                <Options options={paymentMethods} />
                            </select>
                        </Field>
                    </div>
                    <button type="submit" className="mdash-btn brand" disabled={sale.processing}>
                        Vendu
                    </button>
                </form>
            </div>
        </Modal>
    );
}

function LocationSales() {
    const { vehicles, whatsappUrl } = usePage<Props>().props;
    const [modal, setModal] = useState<null | { kind: 'sale'; vehicle: SaleVehicle | null } | { kind: 'buyer'; vehicle: SaleVehicle } | { kind: 'step'; vehicle: SaleVehicle; buyer: Buyer }>(null);

    return (
        <Page
            title="Ventes de véhicules"
            subtitle="Véhicules à vendre et acheteurs suivis jusqu’au paiement"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setModal({ kind: 'sale', vehicle: null })}>
                    <PlusIcon />
                    Mettre en vente
                </button>
            }
        >
            <WhatsAppBanner url={whatsappUrl} />
            {vehicles.length === 0 && <Empty title="Aucun véhicule à vendre">Mettez un véhicule en vente avec son prix et ses photos.</Empty>}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                {vehicles.map((v) => (
                    <Panel
                        key={v.id}
                        title={v.label}
                        sub={[money(v.sale_price), v.mileage ? `${new Intl.NumberFormat('fr-FR').format(v.mileage)} km` : null, v.days_for_sale !== null ? `en vente depuis ${v.days_for_sale} j` : null].filter(Boolean).join(' · ')}
                        actions={<span className={`rounded px-2 py-0.5 text-xs ${STATUS[v.sale_status][1]}`}>{STATUS[v.sale_status][0]}</span>}
                    >
                        {v.photos.length > 0 && (
                            <div className="mb-3 flex gap-2 overflow-x-auto">
                                {v.photos.map((p) => (
                                    <img key={p} src={p} alt={v.label} className="h-20 w-28 shrink-0 rounded object-cover" />
                                ))}
                            </div>
                        )}
                        {v.description && <p className="mb-2 text-sm text-gray-600">{v.description}</p>}
                        <ul className="divide-y divide-gray-100 text-sm dark:divide-white/5">
                            {v.buyers.map((b) => (
                                <li key={b.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span>
                                        <span className="font-medium">{b.customer}</span> · {b.status_label}
                                        <span className="block text-xs text-gray-500">
                                            {[b.test_drive_at ? `essai ${fmt(b.test_drive_at)}` : null, b.offer_amount ? `offre ${money(b.offer_amount)}` : null, b.trade_in_label ? `reprise ${b.trade_in_label}` : null, b.installments ? `${b.installments.paid}/${b.installments.count} échéances payées` : null]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </span>
                                    <span className="mpage-rowlinks flex gap-2">
                                        {b.wa_url && (
                                            <a href={b.wa_url} target="_blank" rel="noreferrer">
                                                WhatsApp
                                            </a>
                                        )}
                                        {!['sold', 'lost'].includes(b.status) && v.sale_status !== 'sold' && (
                                            <button type="button" onClick={() => setModal({ kind: 'step', vehicle: v, buyer: b })}>
                                                Suivre
                                            </button>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        {v.sale_status !== 'sold' && (
                            <span className="mpage-rowlinks mt-2 flex gap-3 text-sm">
                                <button type="button" onClick={() => setModal({ kind: 'buyer', vehicle: v })}>
                                    + Acheteur
                                </button>
                                <button type="button" onClick={() => setModal({ kind: 'sale', vehicle: v })}>
                                    Prix et photos
                                </button>
                                {v.listing_url && (
                                    <>
                                        <a href={v.listing_url} target="_blank" rel="noreferrer">
                                            Voir l’annonce
                                        </a>
                                        <a href={`https://wa.me/?text=${encodeURIComponent(`${v.label} à vendre : ${money(v.sale_price)}. Photos et détails : ${v.listing_url}`)}`} target="_blank" rel="noreferrer">
                                            Partager sur WhatsApp
                                        </a>
                                    </>
                                )}
                            </span>
                        )}
                    </Panel>
                ))}
            </div>
            {modal?.kind === 'sale' && <ForSaleModal vehicle={modal.vehicle} onClose={() => setModal(null)} />}
            {modal?.kind === 'buyer' && <BuyerModal vehicle={modal.vehicle} onClose={() => setModal(null)} />}
            {modal?.kind === 'step' && <StepModal vehicle={modal.vehicle} buyer={modal.buyer} onClose={() => setModal(null)} />}
        </Page>
    );
}

LocationSales.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default LocationSales;
