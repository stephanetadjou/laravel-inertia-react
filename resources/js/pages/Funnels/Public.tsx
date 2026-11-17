import { Head, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import '../Booking/booking.css';

/* Page publique d'un tunnel de vente : offre, compte à rebours ou places restantes, avis, formulaire, WhatsApp. */

interface Props {
    organization: { name: string; slug: string };
    funnel: {
        slug: string;
        title: string;
        offer: string;
        benefits: string[];
        reviews: { name: string; text: string }[];
        fields: string[];
        ends_at: string | null;
        seats_left: number | null;
        image: string | null;
    };
    vehicles: { id: number; name: string; sale_price: number | null }[];
    source: string;
    card: { slug: string; name: string } | null;
    whatsapp: string | null;
    sent: boolean;
}

const input = 'w-full rounded-xl border border-gray-300 bg-white px-3.5 py-3 text-[15px] text-gray-900 focus:border-orange-400 focus:outline-hidden';

function Countdown({ end }: { end: string }) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(id);
    }, []);
    const left = Math.max(0, new Date(end).getTime() - now);
    const d = Math.floor(left / 86400000);
    const h = Math.floor((left % 86400000) / 3600000);
    const m = Math.floor((left % 3600000) / 60000);
    const s = Math.floor((left % 60000) / 1000);

    return (
        <div className="grid grid-cols-4 gap-2 text-center" aria-label="Temps restant">
            {[
                [d, 'jours'],
                [h, 'heures'],
                [m, 'min'],
                [s, 's'],
            ].map(([v, l]) => (
                <span key={l as string} className="rounded-xl bg-[#2B1A3D] py-2 text-white">
                    <b className="block text-xl tabular-nums">{String(v).padStart(2, '0')}</b>
                    <span className="text-[11px] opacity-80">{l}</span>
                </span>
            ))}
        </div>
    );
}

export default function FunnelPublic({ organization, funnel, vehicles, source, card, whatsapp, sent }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        phone: '',
        website: '',
        source,
        carte: card?.slug ?? '',
        starts_at: '',
        vehicles_count: '',
        resource_id: '',
        message: '',
    });
    const has = (f: string) => funnel.fields.includes(f);

    return (
        <div className="mbook">
            <Head title={`${funnel.title} · ${organization.name}`} />
            <header className="mbook-head">
                <span className="mbook-logo">{organization.name.slice(0, 1).toUpperCase()}</span>
                <div>
                    <h1>{organization.name}</h1>
                    <p>{card ? `Votre conseiller : ${card.name}` : 'Offre spéciale'}</p>
                </div>
            </header>
            <div className="mbook-form">
                <section className="mbook-step space-y-3">
                    {funnel.image && <img src={funnel.image} alt={funnel.title} className="w-full rounded-xl object-cover" />}
                    <h2 className="text-2xl font-extrabold">{funnel.title}</h2>
                    <p className="text-[15px]">{funnel.offer}</p>
                    <ul className="space-y-1.5">
                        {funnel.benefits.map((b) => (
                            <li key={b} className="flex gap-2 text-[15px]">
                                <span className="font-bold text-[#E8590C]">✓</span>
                                {b}
                            </li>
                        ))}
                    </ul>
                    {funnel.ends_at && <Countdown end={funnel.ends_at} />}
                    {funnel.seats_left !== null && (
                        <p className="rounded-xl bg-orange-50 px-4 py-2 text-center font-semibold text-orange-800">Plus que {funnel.seats_left} place(s) disponible(s)</p>
                    )}
                </section>

                {funnel.reviews.length > 0 && (
                    <section className="mbook-step space-y-2">
                        {funnel.reviews.map((r) => (
                            <blockquote key={r.text} className="rounded-xl bg-gray-50 p-3 text-[14px]">
                                « {r.text} » <span className="block text-xs text-gray-500">{r.name}</span>
                            </blockquote>
                        ))}
                    </section>
                )}

                <section className="mbook-step">
                    {sent ? (
                        <div className="text-center">
                            <h2>✅ Demande reçue</h2>
                            <p>Merci ! {organization.name} vous répond sur WhatsApp très vite.</p>
                        </div>
                    ) : (
                        <form
                            className="space-y-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                post(`/t/${organization.slug}/${funnel.slug}`, { preserveScroll: true });
                            }}
                        >
                            {has('vehicle') && vehicles.length > 0 && (
                                <select className={input} value={data.resource_id} onChange={(e) => setData('resource_id', e.target.value)} aria-label="Véhicule">
                                    <option value="">Véhicule (facultatif)</option>
                                    {vehicles.map((v) => (
                                        <option key={v.id} value={v.id}>
                                            {v.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                            {has('date') && (
                                <label className="block text-sm font-semibold">
                                    Date souhaitée
                                    <input type="datetime-local" className={input} value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                                </label>
                            )}
                            {has('vehicles_count') && <input type="number" min={1} className={input} placeholder="Nombre de véhicules" value={data.vehicles_count} onChange={(e) => setData('vehicles_count', e.target.value)} />}
                            {has('message') && <textarea className={input} rows={2} placeholder="Votre message (facultatif)" value={data.message} onChange={(e) => setData('message', e.target.value)} />}
                            <input className={input} placeholder="Votre nom" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoComplete="name" />
                            {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                            <input className={input} placeholder="Votre numéro WhatsApp" value={data.phone} onChange={(e) => setData('phone', e.target.value)} required inputMode="tel" autoComplete="tel" />
                            {errors.phone && <p className="text-sm text-red-600">{errors.phone}</p>}
                            <input type="text" value={data.website} onChange={(e) => setData('website', e.target.value)} className="hidden" tabIndex={-1} autoComplete="off" aria-hidden="true" />
                            <button type="submit" disabled={processing} className="w-full rounded-xl bg-[#E8590C] px-4 py-3.5 text-[16px] font-bold text-white disabled:opacity-60">
                                {processing ? 'Envoi…' : 'Je profite de l’offre'}
                            </button>
                        </form>
                    )}
                    {whatsapp && (
                        <a href={whatsapp} className="mt-3 block rounded-xl bg-green-600 px-4 py-3 text-center font-bold text-white">
                            Écrire sur WhatsApp
                        </a>
                    )}
                </section>
            </div>
        </div>
    );
}
