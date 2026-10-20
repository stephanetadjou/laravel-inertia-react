import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';

import { MenentiaSymbol } from '../../components/common/MenentiaLogo';
import './booking.css';

/*
 * Page publique de réservation (/rdv/{entreprise}), pensée pour le
 * téléphone : prestations, jour et créneau libre, coordonnées. Aucun compte.
 */

interface Service {
    id: number;
    name: string;
    category: string | null;
    price: number;
    duration_minutes: number;
    store_ids: number[];
}

interface PageProps {
    organization: { name: string; slug: string };
    welcome: string;
    stores: { id: number; name: string }[];
    services: Service[];
    privacyUrl: string;
}

const price = (n: number) => (n > 0 ? `${n.toLocaleString('fr-FR')} F` : 'Gratuit');
const duration = (m: number) => (m >= 60 ? `${Math.floor(m / 60)} h${m % 60 ? ` ${String(m % 60).padStart(2, '0')}` : ''}` : `${m} min`);
const dayLabel = (day: string) => {
    const label = new Date(`${day}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' });
    return label.charAt(0).toUpperCase() + label.slice(1);
};

export default function BookingShow({ organization, welcome, stores, services, privacyUrl }: PageProps) {
    const { data, setData, post, processing, errors, transform } = useForm({
        store: stores[0]?.id ?? 0,
        items: [] as number[],
        date: '',
        time: '',
        name: '',
        phone: '',
        consent: false,
        notes: '',
        website: '',
    });
    const [days, setDays] = useState<string[]>([]);
    const [slots, setSlots] = useState<string[]>([]);
    const [loading, setLoading] = useState(false);
    const [refresh, setRefresh] = useState(0);

    const offered = useMemo(() => services.filter((s) => s.store_ids.length === 0 || s.store_ids.includes(data.store)), [services, data.store]);
    const chosen = offered.filter((s) => data.items.includes(s.id));
    const total = chosen.reduce((n, s) => n + s.price, 0);
    const minutes = chosen.reduce((n, s) => n + s.duration_minutes, 0);

    // Créneaux libres : rechargés à chaque changement de prestations, de jour ou après un « créneau pris ».
    useEffect(() => {
        if (data.items.length === 0) {
            setDays([]);
            setSlots([]);
            return;
        }
        const params = new URLSearchParams({ store: String(data.store) });
        data.items.forEach((id) => params.append('items[]', String(id)));
        if (data.date) params.set('date', data.date);
        setLoading(true);
        fetch(`/rdv/${organization.slug}/creneaux?${params}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((json: { days: string[]; date: string; slots: string[] }) => {
                setDays(json.days);
                setSlots(json.slots);
                if (json.date !== data.date) setData((d) => ({ ...d, date: json.days.includes(json.date) ? json.date : (json.days[0] ?? '') }));
                if (!json.slots.includes(data.time)) setData((d) => ({ ...d, time: '' }));
            })
            .finally(() => setLoading(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.store, data.items.join(','), data.date, refresh]);

    function toggle(id: number) {
        setData((d) => ({ ...d, items: d.items.includes(id) ? d.items.filter((i) => i !== id) : [...d.items, id], time: '' }));
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((d) => ({ ...d, consent: d.consent ? 1 : 0 }));
        post(`/rdv/${organization.slug}`, {
            preserveScroll: true,
            onError: (errs) => {
                if (errs.time) setRefresh((n) => n + 1);
            },
        });
    };

    const ready = data.items.length > 0 && data.date && data.time && data.name.trim() && data.phone.trim();

    return (
        <div className="mbook">
            <Head title={`Prendre rendez-vous · ${organization.name}`} />

            <header className="mbook-head">
                <span className="mbook-logo">{organization.name.slice(0, 1).toUpperCase()}</span>
                <div>
                    <h1>{organization.name}</h1>
                    <p>{welcome || 'Prenez rendez-vous en ligne, en moins d’une minute.'}</p>
                </div>
            </header>

            <form onSubmit={submit} className="mbook-form">
                <section className="mbook-step">
                    <h2>
                        <span>1</span> Votre prestation
                    </h2>
                    {stores.length > 1 && (
                        <div className="mbook-pills" role="group" aria-label="Établissement">
                            {stores.map((s) => (
                                <button key={s.id} type="button" aria-pressed={data.store === s.id} onClick={() => setData((d) => ({ ...d, store: s.id, items: [], date: '', time: '' }))}>
                                    {s.name}
                                </button>
                            ))}
                        </div>
                    )}
                    {offered.length === 0 ? (
                        <p className="mbook-muted">Aucune prestation n’est réservable en ligne pour le moment.</p>
                    ) : (
                        <ul className="mbook-services">
                            {offered.map((s) => (
                                <li key={s.id}>
                                    <label className={data.items.includes(s.id) ? 'on' : ''}>
                                        <input type="checkbox" checked={data.items.includes(s.id)} onChange={() => toggle(s.id)} />
                                        <span className="name">
                                            <b>{s.name}</b>
                                            <small>{[s.category, duration(s.duration_minutes)].filter(Boolean).join(' · ')}</small>
                                        </span>
                                        <span className="price">{price(s.price)}</span>
                                    </label>
                                </li>
                            ))}
                        </ul>
                    )}
                    {errors.items && <p className="mbook-err">{errors.items}</p>}
                    {chosen.length > 0 && (
                        <p className="mbook-total">
                            {duration(minutes)} · <b>{price(total)}</b>
                        </p>
                    )}
                </section>

                {data.items.length > 0 && (
                    <section className="mbook-step">
                        <h2>
                            <span>2</span> Le jour et l’heure
                        </h2>
                        {days.length === 0 && !loading ? (
                            <p className="mbook-muted">Aucun créneau libre dans les prochains jours. Contactez directement l’établissement.</p>
                        ) : (
                            <>
                                <div className="mbook-days" role="group" aria-label="Jour">
                                    {days.map((d) => (
                                        <button key={d} type="button" aria-pressed={data.date === d} onClick={() => setData((v) => ({ ...v, date: d, time: '' }))}>
                                            {dayLabel(d)}
                                        </button>
                                    ))}
                                </div>
                                <div className="mbook-slots" role="group" aria-label="Heure" aria-busy={loading}>
                                    {slots.map((t) => (
                                        <button key={t} type="button" aria-pressed={data.time === t} onClick={() => setData('time', t)}>
                                            {t.replace(':', 'h')}
                                        </button>
                                    ))}
                                    {!loading && slots.length === 0 && <p className="mbook-muted">Plus de créneau libre ce jour-là.</p>}
                                </div>
                            </>
                        )}
                        {errors.time && <p className="mbook-err">{errors.time}</p>}
                    </section>
                )}

                {data.time && (
                    <section className="mbook-step">
                        <h2>
                            <span>3</span> Vos coordonnées
                        </h2>
                        <label className="mbook-field">
                            <span>Nom et prénom</span>
                            <input value={data.name} onChange={(e) => setData('name', e.target.value)} autoComplete="name" />
                            {errors.name && <em className="mbook-err">{errors.name}</em>}
                        </label>
                        <label className="mbook-field">
                            <span>Numéro WhatsApp</span>
                            <input type="tel" value={data.phone} onChange={(e) => setData('phone', e.target.value)} autoComplete="tel" placeholder="077 12 34 56" />
                            {errors.phone && <em className="mbook-err">{errors.phone}</em>}
                        </label>
                        <label className="mbook-field">
                            <span>Un message pour l’établissement (facultatif)</span>
                            <textarea rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                        </label>
                        <label className="mbook-check">
                            <input type="checkbox" checked={data.consent} onChange={(e) => setData('consent', e.target.checked)} />
                            <span>J’accepte de recevoir les informations et offres de {organization.name} sur WhatsApp.</span>
                        </label>
                        <input className="mbook-trap" tabIndex={-1} autoComplete="off" aria-hidden="true" value={data.website} onChange={(e) => setData('website', e.target.value)} name="website" />
                    </section>
                )}

                <div className="mbook-submit">
                    {data.time && (
                        <p>
                            {dayLabel(data.date)} à <b>{data.time.replace(':', 'h')}</b> · {chosen.map((s) => s.name).join(' + ')}
                        </p>
                    )}
                    <button type="submit" disabled={!ready || processing}>
                        {processing ? 'Envoi…' : 'Confirmer le rendez-vous'}
                    </button>
                    <small>
                        Vos coordonnées servent uniquement à gérer ce rendez-vous.{' '}
                        <a href={privacyUrl} target="_blank" rel="noreferrer">
                            Confidentialité
                        </a>
                    </small>
                </div>
            </form>

            <footer className="mbook-foot">
                <MenentiaSymbol className="h-4 w-4" /> Réservation par Menentia
            </footer>
        </div>
    );
}
