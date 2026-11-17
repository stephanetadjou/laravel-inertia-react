import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, ReactNode, useState } from 'react';

import '../Booking/booking.css';

/* Lien public « Que souhaitez-vous ? » (/rdv/{slug}) : un choix, un court formulaire, la réponse sur WhatsApp. */

interface Choice {
    key: string;
    label: string;
    sub: string;
    stage: string;
}

interface Props {
    organization: { name: string; slug: string };
    stores: { id: number; name: string; city: string | null }[];
    choices: Choice[];
    card: { slug: string; name: string } | null;
    whatsapp: string | null;
    fares: { label: string; price: number }[];
    categories: { key: string; label: string; from: number }[];
    forSale: { id: number; label: string; mileage: number | null; price: number | null; url: string | null; reserved: boolean }[];
    services: { name: string; price: number }[];
    depositPercent: number;
    sent: string | null;
}

const money = (n: number | null | undefined) => `${new Intl.NumberFormat('fr-FR').format(n ?? 0)} FCFA`;
const input = 'w-full rounded-xl border border-gray-300 bg-white px-3.5 py-3 text-[15px] text-gray-900 focus:border-orange-400 focus:outline-hidden';

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <label className="block">
            <span className="mb-1 block text-sm font-semibold">{label}</span>
            {children}
            {error && <span className="text-sm text-red-600">{error}</span>}
        </label>
    );
}

function RequestForm({ type, organization, card, stores, categories, forSale, depositPercent }: { type: string } & Pick<Props, 'organization' | 'card' | 'stores' | 'categories' | 'forSale' | 'depositPercent'>) {
    const { data, setData, post, processing, errors } = useForm({
        type,
        name: '',
        phone: '',
        website: '',
        carte: card?.slug ?? '',
        store_id: stores.length === 1 ? String(stores[0].id) : '',
        starts_at: '',
        ends_at: '',
        resource_id: '',
        category: '',
        with_driver: false,
        vehicles_count: '',
        message: '',
    });
    const [free, setFree] = useState<null | { days: number; vehicles: { id: number; label: string; category: string | null; price_day: number; estimate: number }[] }>(null);
    const err = errors as Record<string, string>;
    const picked = free?.vehicles.find((v) => String(v.id) === data.resource_id);

    const check = () => {
        if (!data.starts_at || !data.ends_at) return;
        fetch(`/rdv/${organization.slug}/disponibles?from=${encodeURIComponent(data.starts_at)}&to=${encodeURIComponent(data.ends_at)}`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then((json) => json && setFree(json));
    };
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/rdv/${organization.slug}/demande`, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            {stores.length > 1 && (
                <Field label="Ville / agence">
                    <select className={input} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                        <option value="">Peu importe</option>
                        {stores.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.city ? `${s.city} · ` : ''}
                                {s.name}
                            </option>
                        ))}
                    </select>
                </Field>
            )}

            {type === 'rent' && (
                <>
                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Du" error={err.starts_at}>
                            <input type="datetime-local" className={input} value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} onBlur={check} required />
                        </Field>
                        <Field label="Au" error={err.ends_at}>
                            <input type="datetime-local" className={input} value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} onBlur={check} required />
                        </Field>
                    </div>
                    {free && (
                        <Field label={`Véhicules libres (${free.days} jour${free.days > 1 ? 's' : ''})`}>
                            <select className={input} value={data.resource_id} onChange={(e) => setData('resource_id', e.target.value)}>
                                <option value="">{free.vehicles.length ? 'Choisir (ou laisser l’agence proposer)' : 'Aucun véhicule libre : l’agence vous propose une solution'}</option>
                                {free.vehicles.map((v) => (
                                    <option key={v.id} value={v.id}>
                                        {v.label} · {money(v.price_day)} / jour
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                    {!free && categories.length > 0 && (
                        <Field label="Type de véhicule">
                            <select className={input} value={data.category} onChange={(e) => setData('category', e.target.value)}>
                                <option value="">Peu importe</option>
                                {categories.map((c) => (
                                    <option key={c.key} value={c.key}>
                                        {c.label} · dès {money(c.from)} / jour
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                    <label className="flex items-center gap-2 text-sm font-semibold">
                        <input type="checkbox" checked={data.with_driver} onChange={(e) => setData('with_driver', e.target.checked)} /> Avec chauffeur
                    </label>
                    <Field label="Pour quelle occasion ?">
                        <input className={input} value={data.message} onChange={(e) => setData('message', e.target.value)} placeholder="Voyage, mission, visite de famille…" />
                    </Field>
                    {picked && (
                        <p className="rounded-xl bg-orange-50 px-4 py-3 text-center text-sm">
                            Estimation : <b>{money(picked.estimate)}</b> · acompte de {depositPercent} % ({money(Math.round((picked.estimate * depositPercent) / 100))}) à la réservation. Caution au départ.
                        </p>
                    )}
                </>
            )}

            {type === 'event' && (
                <>
                    <Field label="Date de l’événement" error={err.starts_at}>
                        <input type="datetime-local" className={input} value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} required />
                    </Field>
                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Nombre de véhicules">
                            <input type="number" min={1} className={input} value={data.vehicles_count} onChange={(e) => setData('vehicles_count', e.target.value)} />
                        </Field>
                        <Field label="Type">
                            <select className={input} value={data.category} onChange={(e) => setData('category', e.target.value)}>
                                <option value="">Au choix</option>
                                {categories.map((c) => (
                                    <option key={c.key} value={c.key}>
                                        {c.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </div>
                    <Field label="Lieux et programme">
                        <input className={input} value={data.message} onChange={(e) => setData('message', e.target.value)} placeholder="Église, salle, cortège…" />
                    </Field>
                </>
            )}

            {(type === 'buy' || type === 'test_drive') && (
                <>
                    <Field label="Véhicule">
                        <select className={input} value={data.resource_id} onChange={(e) => setData('resource_id', e.target.value)} required={type === 'test_drive'}>
                            <option value="">—</option>
                            {forSale
                                .filter((v) => !v.reserved)
                                .map((v) => (
                                    <option key={v.id} value={v.id}>
                                        {v.label} · {money(v.price)}
                                    </option>
                                ))}
                        </select>
                    </Field>
                    {type === 'test_drive' && (
                        <Field label="Date souhaitée pour l’essai">
                            <input type="datetime-local" className={input} value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                        </Field>
                    )}
                    <Field label="Reprise de votre véhicule, paiement… (facultatif)">
                        <input className={input} value={data.message} onChange={(e) => setData('message', e.target.value)} />
                    </Field>
                </>
            )}

            {(type === 'trial' || type === 'visit') && (
                <Field label="Date souhaitée">
                    <input type="datetime-local" className={input} value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                </Field>
            )}
            {(type === 'quote' || type === 'callback' || type === 'trial' || type === 'visit') && (
                <Field label={type === 'quote' ? 'Votre besoin' : 'Votre message (facultatif)'}>
                    <textarea className={input} rows={3} value={data.message} onChange={(e) => setData('message', e.target.value)} required={type === 'quote'} />
                </Field>
            )}

            <div className="grid grid-cols-2 gap-2">
                <Field label="Votre nom" error={err.name}>
                    <input className={input} value={data.name} onChange={(e) => setData('name', e.target.value)} required autoComplete="name" />
                </Field>
                <Field label="WhatsApp" error={err.phone}>
                    <input className={input} value={data.phone} onChange={(e) => setData('phone', e.target.value)} required inputMode="tel" autoComplete="tel" />
                </Field>
            </div>
            <input type="text" value={data.website} onChange={(e) => setData('website', e.target.value)} className="hidden" tabIndex={-1} autoComplete="off" aria-hidden="true" />
            {err.type && <p className="text-sm text-red-600">{err.type}</p>}
            <button type="submit" disabled={processing} className="w-full rounded-xl bg-[#E8590C] px-4 py-3.5 text-[16px] font-bold text-white disabled:opacity-60">
                {processing ? 'Envoi…' : type === 'callback' ? 'Être rappelé' : 'Envoyer ma demande'}
            </button>
        </form>
    );
}

export default function OnlinePageShow(props: Props) {
    const { organization, choices, card, whatsapp, fares, categories, forSale, services, sent } = props;
    const [active, setActive] = useState<string | null>(choices.length === 1 ? choices[0].key : null);
    const q = card ? `?carte=${card.slug}` : '';

    const panel = () => {
        if (active === 'prices') {
            return (
                <ul className="space-y-1.5 text-[15px]">
                    {fares.map((f) => (
                        <li key={f.label} className="flex justify-between gap-3">
                            <span>{f.label}</span>
                            <b>{money(f.price)}</b>
                        </li>
                    ))}
                    {categories.map((c) => (
                        <li key={c.key} className="flex justify-between gap-3">
                            <span>Location {c.label}</span>
                            <b>dès {money(c.from)} / jour</b>
                        </li>
                    ))}
                    {services.map((s) => (
                        <li key={s.name} className="flex justify-between gap-3">
                            <span>{s.name}</span>
                            <b>{money(s.price)}</b>
                        </li>
                    ))}
                    {fares.length + categories.length + services.length === 0 && <li>Tarifs communiqués sur WhatsApp.</li>}
                </ul>
            );
        }
        if (active === 'buy' && forSale.length > 0) {
            return (
                <div className="space-y-3">
                    <ul className="space-y-1.5 text-[15px]">
                        {forSale.map((v) => (
                            <li key={v.id} className="flex justify-between gap-3">
                                <span>
                                    {v.url ? (
                                        <a href={v.url} className="font-semibold underline">
                                            {v.label}
                                        </a>
                                    ) : (
                                        <b>{v.label}</b>
                                    )}
                                    {v.mileage ? ` · ${new Intl.NumberFormat('fr-FR').format(v.mileage)} km` : ''}
                                </span>
                                <b>{v.reserved ? 'Réservé' : money(v.price)}</b>
                            </li>
                        ))}
                    </ul>
                    <RequestForm type="buy" {...props} />
                </div>
            );
        }
        if (active === 'callback' && whatsapp) return <RequestForm key={active} type={active} {...props} />;
        return active ? <RequestForm key={active} type={active} {...props} /> : null;
    };

    return (
        <div className="mbook">
            <Head title={`${organization.name} · Que souhaitez-vous ?`} />
            <header className="mbook-head">
                <span className="mbook-logo">{organization.name.slice(0, 1).toUpperCase()}</span>
                <div>
                    <h1>{organization.name}</h1>
                    <p>{card ? `Votre conseiller : ${card.name}` : 'Que souhaitez-vous ?'}</p>
                </div>
            </header>
            <div className="mbook-form">
                {sent ? (
                    <section className="mbook-step text-center">
                        <h2>✅ {sent}</h2>
                        <button type="button" className="mt-3 font-semibold underline" onClick={() => window.location.assign(`/rdv/${organization.slug}${q}`)}>
                            Faire une autre demande
                        </button>
                    </section>
                ) : (
                    <>
                        <section className="mbook-step">
                            <h2>Que souhaitez-vous ?</h2>
                            <div className="grid grid-cols-2 gap-2">
                                {choices.map((c) => (
                                    <button
                                        key={c.key}
                                        type="button"
                                        onClick={() =>
                                            c.key === 'ride'
                                                ? window.location.assign(`/rdv/${organization.slug}/course${q}`)
                                                : c.key === 'rdv'
                                                  ? window.location.assign(`/rdv/${organization.slug}?rdv=1${card ? `&carte=${card.slug}` : ''}`)
                                                  : setActive(c.key)
                                        }
                                        aria-pressed={active === c.key}
                                        className={`rounded-xl border p-3 text-left ${active === c.key ? 'border-orange-500 bg-orange-50' : 'border-gray-200 bg-white'}`}
                                    >
                                        <b className="block text-[15px]">{c.label}</b>
                                        <span className="text-[12.5px] text-gray-500">{c.sub}</span>
                                    </button>
                                ))}
                            </div>
                            {active === 'callback' && whatsapp && (
                                <a href={whatsapp} className="mt-3 block rounded-xl bg-green-600 px-4 py-3 text-center font-bold text-white">
                                    Écrire tout de suite sur WhatsApp
                                </a>
                            )}
                        </section>
                        {active && <section className="mbook-step">{panel()}</section>}
                    </>
                )}
            </div>
        </div>
    );
}
