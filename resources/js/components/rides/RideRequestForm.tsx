import { useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

/*
 * Formulaire public de demande de course / transfert, pensé pour le
 * téléphone. Composant réutilisable : il ne dépend que de l'URL d'envoi et
 * des forfaits (page /rdv/{slug}/course aujourd'hui, autre vitrine demain).
 */

export interface PublicFare {
    id: number;
    label: string;
    price: number;
}

const words = (s: string) =>
    s
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .split(/[^a-z0-9]+/)
        .filter((w) => w.length > 2);

/** Forfait dont le libellé contient un mot du départ et un mot de la destination. */
export function matchFare(fares: PublicFare[], from: string, to: string): PublicFare | undefined {
    const f = words(from);
    const t = words(to);
    return fares.find((fare) => {
        const l = words(fare.label);
        return f.some((w) => l.includes(w)) && t.some((w) => l.includes(w));
    });
}

const input = 'w-full rounded-xl border border-gray-300 bg-white px-3.5 py-3 text-[15px] text-gray-900 focus:border-orange-400 focus:outline-hidden';

export default function RideRequestForm({ action, fares, stores = [] }: { action: string; fares: PublicFare[]; stores?: { id: number; name: string; city?: string | null }[] }) {
    const { data, setData, post, processing, errors } = useForm({
        store_id: stores.length === 1 ? String(stores[0].id) : '',
        ride_from: 'Aéroport',
        ride_to: '',
        when: 'now' as 'now' | 'later',
        starts_at: '',
        ride_passengers: '1',
        ride_luggage: '1',
        flight_number: '',
        name: '',
        phone: '',
        website: '',
    });
    const [locating, setLocating] = useState(false);
    const [geoError, setGeoError] = useState<string | null>(null);
    const fare = useMemo(() => matchFare(fares, data.ride_from, data.ride_to), [fares, data.ride_from, data.ride_to]);

    const locate = () => {
        if (!navigator.geolocation) {
            setGeoError('Votre téléphone ne permet pas la localisation : saisissez l’adresse.');
            return;
        }
        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                const { latitude, longitude } = pos.coords;
                setData('ride_from', `Ma position : https://maps.google.com/?q=${latitude.toFixed(6)},${longitude.toFixed(6)}`);
                setGeoError(null);
                setLocating(false);
            },
            () => {
                setGeoError('Position introuvable : saisissez l’adresse ou le lieu.');
                setLocating(false);
            },
            { enableHighAccuracy: true, timeout: 10000 },
        );
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(action, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-4 text-[15px]">
            {stores.length > 1 && (
                <label className="block">
                    <span className="mb-1 block font-semibold">Ville / agence</span>
                    <select className={input} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                        <option value="">Peu importe</option>
                        {stores.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.city ? `${s.city} · ` : ''}
                                {s.name}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            <label className="block">
                <span className="mb-1 block font-semibold">Départ</span>
                <div className="flex gap-2">
                    <input className={input} value={data.ride_from} onChange={(e) => setData('ride_from', e.target.value)} required placeholder="Aéroport, hôtel, quartier…" />
                    <button type="button" onClick={locate} className="shrink-0 rounded-xl border border-gray-300 bg-white px-3 text-sm font-semibold" disabled={locating}>
                        {locating ? '…' : '📍 Ma position'}
                    </button>
                </div>
                {geoError && <span className="text-sm text-amber-700">{geoError}</span>}
                {errors.ride_from && <span className="text-sm text-red-600">{errors.ride_from}</span>}
            </label>
            <label className="block">
                <span className="mb-1 block font-semibold">Destination</span>
                <input className={input} value={data.ride_to} onChange={(e) => setData('ride_to', e.target.value)} required placeholder="Quartier, adresse, hôtel…" />
                {errors.ride_to && <span className="text-sm text-red-600">{errors.ride_to}</span>}
            </label>

            <div className="grid grid-cols-2 gap-2" role="group" aria-label="Quand">
                {(['now', 'later'] as const).map((w) => (
                    <button key={w} type="button" onClick={() => setData('when', w)} className={`rounded-xl border px-3 py-3 font-semibold ${data.when === w ? 'border-orange-500 bg-orange-50 text-orange-800' : 'border-gray-300 bg-white'}`}>
                        {w === 'now' ? 'Maintenant' : 'Plus tard'}
                    </button>
                ))}
            </div>
            {data.when === 'later' && (
                <label className="block">
                    <span className="mb-1 block font-semibold">Date et heure</span>
                    <input type="datetime-local" className={input} value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} required />
                    {errors.starts_at && <span className="text-sm text-red-600">{errors.starts_at}</span>}
                </label>
            )}

            <div className="grid grid-cols-3 gap-2">
                <label className="block">
                    <span className="mb-1 block text-sm font-semibold">Passagers</span>
                    <input type="number" min={1} className={input} value={data.ride_passengers} onChange={(e) => setData('ride_passengers', e.target.value)} />
                </label>
                <label className="block">
                    <span className="mb-1 block text-sm font-semibold">Bagages</span>
                    <input type="number" min={0} className={input} value={data.ride_luggage} onChange={(e) => setData('ride_luggage', e.target.value)} />
                </label>
                <label className="block">
                    <span className="mb-1 block text-sm font-semibold">N° de vol</span>
                    <input className={input} value={data.flight_number} onChange={(e) => setData('flight_number', e.target.value)} placeholder="Facultatif" />
                </label>
            </div>

            <label className="block">
                <span className="mb-1 block font-semibold">Votre nom</span>
                <input className={input} value={data.name} onChange={(e) => setData('name', e.target.value)} required autoComplete="name" />
                {errors.name && <span className="text-sm text-red-600">{errors.name}</span>}
            </label>
            <label className="block">
                <span className="mb-1 block font-semibold">Votre numéro WhatsApp</span>
                <input className={input} value={data.phone} onChange={(e) => setData('phone', e.target.value)} required inputMode="tel" autoComplete="tel" />
                {errors.phone && <span className="text-sm text-red-600">{errors.phone}</span>}
            </label>
            <input type="text" name="website" value={data.website} onChange={(e) => setData('website', e.target.value)} className="hidden" tabIndex={-1} autoComplete="off" aria-hidden="true" />

            <p className="rounded-xl bg-gray-50 px-4 py-3 text-center">
                {fare ? (
                    <>
                        Prix forfaitaire : <b>{new Intl.NumberFormat('fr-FR').format(fare.price)} FCFA</b> ({fare.label})
                    </>
                ) : (
                    'Prix confirmé par WhatsApp'
                )}
            </p>

            <button type="submit" disabled={processing} className="w-full rounded-xl bg-[#E8590C] px-4 py-3.5 text-[16px] font-bold text-white disabled:opacity-60">
                {processing ? 'Envoi…' : 'Demander la course'}
            </button>
        </form>
    );
}
