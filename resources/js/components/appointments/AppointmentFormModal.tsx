import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import Button from '../ui/button/Button';
import { Modal } from '../ui/modal';

/*
 * Prise ou modification d'un rendez-vous. Depuis une fiche client, le client
 * est fixé (customerId) ; depuis l'agenda, on saisit son numéro (un numéro
 * inconnu crée le client dans l'établissement choisi).
 */

export interface AppointmentRow {
    id: number;
    starts_at: string;
    duration_minutes: number;
    service: string | null;
    status: string;
    notes: string | null;
    store_id: number;
    store_name: string | null;
    staff_user_id: number | null;
    staff_name: string | null;
    customer_id: number;
    customer_name: string | null;
    phone_e164: string | null;
    whatsapp_url: string | null;
    items: { catalog_item_id: number | null; name: string; price: number; duration_minutes: number }[];
    price: number;
    purchase_id: number | null;
    paid_amount: number | null;
}

/** Article du catalogue (cf. CatalogItem::present()). */
export interface CatalogEntry {
    id: number;
    type: 'service' | 'product';
    name: string;
    category: string | null;
    price: number;
    duration_minutes: number | null;
    store_ids: number[];
}

/** Articles proposés dans un établissement (aucun établissement choisi = tous). */
export function availableIn(catalog: CatalogEntry[], storeId: number): CatalogEntry[] {
    return catalog.filter((c) => c.store_ids.length === 0 || c.store_ids.includes(storeId));
}

export const formatPrice = (n: number) => `${n.toLocaleString('fr-FR')} F`;

export const APPOINTMENT_PILL: Record<string, string> = {
    planned: 'info',
    confirmed: 'ok',
    done: 'ok',
    no_show: 'bad',
    cancelled: 'idle',
};

interface Option {
    id: number;
    name: string;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function Label({ htmlFor, children }: { htmlFor: string; children: React.ReactNode }) {
    return (
        <label htmlFor={htmlFor} className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
            {children}
        </label>
    );
}

function Err({ message }: { message?: string }) {
    return message ? <p className="mt-1.5 text-sm text-error-500">{message}</p> : null;
}

/** Valeur par défaut : demain à 10 h, au format d'un champ datetime-local. */
function defaultStart(date?: string): string {
    // Créneau précis cliqué dans le calendrier (AAAA-MM-JJTHH:MM).
    if (date && date.length > 10) return date;
    const d = date ? new Date(`${date}T10:00:00`) : new Date(Date.now() + 86400000);
    if (!date) d.setHours(10, 0, 0, 0);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export default function AppointmentFormModal({
    onClose,
    appointment,
    customerId,
    customerName,
    stores,
    staff,
    statuses,
    date,
    catalog = [],
}: {
    onClose: () => void;
    appointment: AppointmentRow | null;
    customerId?: number;
    customerName?: string;
    stores: Option[];
    staff: Option[];
    statuses: Record<string, string>;
    date?: string;
    catalog?: CatalogEntry[];
}) {
    const isEditing = appointment !== null;
    const withCustomer = isEditing || customerId !== undefined;

    const { data, setData, post, put, transform, processing, errors } = useForm({
        store_id: String(appointment?.store_id ?? stores[0]?.id ?? ''),
        customer_id: customerId ? String(customerId) : '',
        phone: '',
        name: '',
        starts_at: appointment?.starts_at ?? defaultStart(date),
        duration_minutes: String(appointment?.duration_minutes ?? 30),
        service: appointment?.service ?? '',
        staff_user_id: appointment?.staff_user_id ? String(appointment.staff_user_id) : '',
        status: appointment?.status ?? 'planned',
        notes: appointment?.notes ?? '',
        item_ids: (appointment?.items ?? []).map((i) => i.catalog_item_id).filter((id): id is number => id !== null),
    });

    const services = availableIn(catalog, Number(data.store_id)).filter((c) => c.type === 'service');
    const chosen = data.item_ids.map((id) => catalog.find((c) => c.id === id)).filter((c): c is CatalogEntry => c !== undefined);
    const total = chosen.reduce((sum, c) => sum + c.price, 0);

    // Choisir ou retirer une prestation recalcule la durée (modifiable ensuite).
    function setItems(ids: number[]) {
        const duration = ids.reduce((sum, id) => sum + (catalog.find((c) => c.id === id)?.duration_minutes ?? 0), 0);
        setData((d) => ({ ...d, item_ids: ids, duration_minutes: duration > 0 ? String(duration) : d.duration_minutes }));
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };
        if (isEditing) {
            // Le client d'un rendez-vous existant ne change pas.
            transform(({ customer_id: _c, phone: _p, name: _n, ...rest }) => rest);
            put(`/appointments/${appointment.id}`, options);
        } else {
            transform((values) => (withCustomer ? { ...values, phone: '' } : { ...values, customer_id: '' }));
            post('/appointments', options);
        }
    };

    const title = isEditing ? 'Modifier le rendez-vous' : 'Nouveau rendez-vous';
    const who = appointment?.customer_name ?? customerName;

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-lg p-6">
            <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">{title}</h3>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                {who ? `Client : ${who}.` : 'Un numéro inconnu crée le client dans l’établissement choisi.'} Le rappel se fait depuis Actions du jour, la veille.
            </p>

            <form onSubmit={submit} className="space-y-4">
                {!withCustomer && (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="appt-phone">Numéro WhatsApp</Label>
                            <input id="appt-phone" type="tel" value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="077 12 34 56" className={fieldClasses()} autoFocus />
                            <Err message={errors.phone} />
                        </div>
                        <div>
                            <Label htmlFor="appt-name">Nom du client</Label>
                            <input id="appt-name" type="text" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="si nouveau client" className={fieldClasses()} />
                            <Err message={errors.name} />
                        </div>
                    </div>
                )}

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="appt-start">Date et heure</Label>
                        <input id="appt-start" type="datetime-local" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} className={fieldClasses()} />
                        <Err message={errors.starts_at} />
                    </div>
                    <div>
                        <Label htmlFor="appt-duration">Durée (minutes)</Label>
                        <input id="appt-duration" type="number" min={5} max={600} step={5} value={data.duration_minutes} onChange={(e) => setData('duration_minutes', e.target.value)} className={fieldClasses()} />
                        <Err message={errors.duration_minutes} />
                    </div>
                </div>

                {stores.length > 1 && (
                    <div>
                        <Label htmlFor="appt-store">Établissement</Label>
                        <select id="appt-store" value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} className={fieldClasses()}>
                            {stores.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                        <Err message={errors.store_id} />
                    </div>
                )}

                <div>
                    <Label htmlFor="appt-service">Prestations</Label>
                    {services.length > 0 && (
                        <>
                            {chosen.length > 0 && (
                                <div className="mb-2 flex flex-wrap gap-1.5">
                                    {chosen.map((c, index) => (
                                        <span key={`${c.id}-${index}`} className="inline-flex items-center gap-1.5 rounded-full border border-gray-300 py-1 pr-1.5 pl-3 text-sm dark:border-gray-700">
                                            {c.name}
                                            <span className="text-xs text-gray-500">{formatPrice(c.price)}</span>
                                            <button
                                                type="button"
                                                aria-label={`Retirer ${c.name}`}
                                                className="flex h-5 w-5 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10"
                                                onClick={() => setItems(data.item_ids.filter((_, i) => i !== index))}
                                            >
                                                ×
                                            </button>
                                        </span>
                                    ))}
                                </div>
                            )}
                            <select
                                id="appt-service"
                                value=""
                                onChange={(e) => e.target.value && setItems([...data.item_ids, Number(e.target.value)])}
                                className={fieldClasses()}
                            >
                                <option value="">{chosen.length ? '+ Ajouter une prestation' : 'Choisir une prestation du catalogue'}</option>
                                {services.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name} · {c.duration_minutes} min · {formatPrice(c.price)}
                                    </option>
                                ))}
                            </select>
                            {chosen.length > 0 && (
                                <p className="mt-1.5 text-sm text-gray-600 dark:text-gray-400">
                                    Total prévu : <b>{formatPrice(total)}</b>
                                </p>
                            )}
                        </>
                    )}
                    {chosen.length === 0 && (
                        <input
                            id={services.length > 0 ? 'appt-service-free' : 'appt-service'}
                            type="text"
                            value={data.service}
                            onChange={(e) => setData('service', e.target.value)}
                            placeholder={services.length > 0 ? 'ou en texte libre' : 'ex. Coupe, Essayage, Consultation'}
                            aria-label="Prestation en texte libre"
                            className={`${fieldClasses()} ${services.length > 0 ? 'mt-2' : ''}`}
                        />
                    )}
                    <Err message={errors.service} />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="appt-staff">Reçu par</Label>
                        <select id="appt-staff" value={data.staff_user_id} onChange={(e) => setData('staff_user_id', e.target.value)} className={fieldClasses()}>
                            <option value="">Non attribué</option>
                            {staff.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <Label htmlFor="appt-status">Statut</Label>
                        <select id="appt-status" value={data.status} onChange={(e) => setData('status', e.target.value)} className={fieldClasses()}>
                            {Object.entries(statuses).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <div>
                    <Label htmlFor="appt-notes">Notes</Label>
                    <textarea id="appt-notes" rows={3} value={data.notes} onChange={(e) => setData('notes', e.target.value)} className={fieldClasses()} />
                    <Err message={errors.notes} />
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing}>{processing ? 'Enregistrement…' : isEditing ? 'Enregistrer' : 'Prendre le rendez-vous'}</Button>
                </div>
            </form>
        </Modal>
    );
}
