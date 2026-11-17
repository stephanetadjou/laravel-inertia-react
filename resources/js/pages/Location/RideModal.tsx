import { router, useForm } from '@inertiajs/react';

import { Modal } from '../../components/ui/modal';
import { Field, field, money } from './shared';

/* Course / transfert : réservation immédiate (depuis le planning) ou depuis une demande. */

export interface Fare {
    id: number;
    label: string;
    price: number;
    minutes: number;
}

export interface RidePrefill {
    requestId?: number;
    customer?: string;
    from?: string | null;
    to?: string | null;
    startsAt?: string | null;
    passengers?: number | null;
    luggage?: number | null;
    flight?: string | null;
}

const nowLocal = () => {
    const d = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
    return d.toISOString().slice(0, 16);
};

export default function RideModal({
    vehicles,
    stores,
    fares,
    prefill,
    onClose,
}: {
    vehicles: { id: number; label: string; status: string }[];
    stores: { id: number; name: string }[];
    fares: Fare[];
    prefill?: RidePrefill;
    onClose: () => void;
}) {
    const fromRequest = !!prefill?.requestId;
    const { data, setData, post, processing, errors } = useForm({
        store_id: stores.length === 1 ? String(stores[0].id) : '',
        name: '',
        phone: '',
        ride_from: prefill?.from ?? 'Aéroport',
        ride_to: prefill?.to ?? '',
        starts_at: prefill?.startsAt ?? nowLocal(),
        ride_passengers: String(prefill?.passengers ?? 1),
        ride_luggage: String(prefill?.luggage ?? 1),
        flight_number: prefill?.flight ?? '',
        fare_id: '',
        price: '',
        minutes: '60',
        resource_id: '',
        driver_name: '',
    });
    const err = errors as Record<string, string>;

    const pickFare = (id: string) => {
        const fare = fares.find((f) => String(f.id) === id);
        setData((d) => ({ ...d, fare_id: id, price: fare ? String(fare.price) : d.price, minutes: fare ? String(fare.minutes) : d.minutes }));
    };

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-xl p-6">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(fromRequest ? `/location/requests/${prefill!.requestId}/ride` : '/location/rides', { preserveScroll: true, onSuccess: onClose });
                }}
                className="space-y-3 text-sm"
            >
                <h2 className="text-lg font-semibold">{fromRequest ? `Course pour ${prefill?.customer}` : 'Nouvelle course'}</h2>
                <div className="grid grid-cols-2 gap-3">
                    {!fromRequest && (
                        <>
                            <Field label="Nom du passager" error={err.name}>
                                <input className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                            </Field>
                            <Field label="Numéro WhatsApp" error={err.phone}>
                                <input className={field} value={data.phone} onChange={(e) => setData('phone', e.target.value)} inputMode="tel" required />
                            </Field>
                        </>
                    )}
                    <Field label="Départ">
                        <input className={field} value={data.ride_from} onChange={(e) => setData('ride_from', e.target.value)} />
                    </Field>
                    <Field label="Destination" error={err.ride_to}>
                        <input className={field} value={data.ride_to} onChange={(e) => setData('ride_to', e.target.value)} required placeholder="Quartier, hôtel, adresse" />
                    </Field>
                    <Field label="Date et heure">
                        <input type="datetime-local" className={field} value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} required />
                    </Field>
                    <Field label="N° de vol (facultatif)">
                        <input className={field} value={data.flight_number} onChange={(e) => setData('flight_number', e.target.value)} />
                    </Field>
                    <Field label="Passagers">
                        <input type="number" min={1} className={field} value={data.ride_passengers} onChange={(e) => setData('ride_passengers', e.target.value)} />
                    </Field>
                    <Field label="Bagages">
                        <input type="number" min={0} className={field} value={data.ride_luggage} onChange={(e) => setData('ride_luggage', e.target.value)} />
                    </Field>
                    <Field label="Forfait">
                        <select className={field} value={data.fare_id} onChange={(e) => pickFare(e.target.value)}>
                            <option value="">Prix libre</option>
                            {fares.map((f) => (
                                <option key={f.id} value={f.id}>
                                    {f.label} · {money(f.price)}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Prix (FCFA)" error={err.price}>
                        <input type="number" min={0} className={field} value={data.price} onChange={(e) => setData('price', e.target.value)} />
                    </Field>
                    <Field label="Véhicule" error={err.resource_id}>
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
                    <Field label="Chauffeur">
                        <input className={field} value={data.driver_name} onChange={(e) => setData('driver_name', e.target.value)} />
                    </Field>
                    <Field label="Durée estimée (min)">
                        <input type="number" min={15} className={field} value={data.minutes} onChange={(e) => setData('minutes', e.target.value)} />
                    </Field>
                    {!fromRequest && stores.length > 1 && (
                        <Field label="Agence">
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
                        Réserver la course
                    </button>
                </div>
            </form>
        </Modal>
    );
}

/** Forfaits des courses (ajout, suppression). */
export function FaresPanel({ fares }: { fares: Fare[] }) {
    const { data, setData, post, processing, reset } = useForm({ label: '', price: '', minutes: '60' });

    return (
        <div className="space-y-2 text-sm">
            {fares.length === 0 && <p className="text-gray-500">Aucun forfait. Exemple : « Aéroport ↔ Centre-ville », 15 000 F.</p>}
            <ul className="divide-y divide-gray-100 dark:divide-white/5">
                {fares.map((f) => (
                    <li key={f.id} className="flex items-center justify-between py-1.5">
                        <span>
                            {f.label} · <b>{money(f.price)}</b> · {f.minutes} min
                        </span>
                        <button type="button" className="text-xs text-error-600" onClick={() => router.delete(`/location/ride-fares/${f.id}`, { preserveScroll: true })}>
                            Supprimer
                        </button>
                    </li>
                ))}
            </ul>
            <form
                className="flex flex-wrap gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/location/ride-fares', { preserveScroll: true, onSuccess: () => reset() });
                }}
            >
                <input className={`${field} flex-1`} placeholder="Trajet (ex. Aéroport ↔ Centre-ville)" value={data.label} onChange={(e) => setData('label', e.target.value)} required />
                <input className={`${field} w-28`} type="number" placeholder="Prix" value={data.price} onChange={(e) => setData('price', e.target.value)} required />
                <input className={`${field} w-24`} type="number" placeholder="Min" value={data.minutes} onChange={(e) => setData('minutes', e.target.value)} />
                <button type="submit" className="mdash-btn ghost" disabled={processing}>
                    Ajouter
                </button>
            </form>
        </div>
    );
}
