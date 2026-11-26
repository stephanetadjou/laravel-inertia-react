import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import { MenentiaSymbol } from '../../components/common/MenentiaLogo';
import CardQr from './CardQr';
import { SocialLink, SocialRow } from './SocialIcon';
import { t } from '../../lib/i18n';

/*
 * Page publique d'une carte digitale (/c/{slug}), pensée pour le téléphone :
 * WhatsApp, appel, enregistrement du contact, prise de RDV, formulaire de
 * contact. Chaque clic est compté (statistiques de la carte).
 */

interface PublicCard {
    slug: string;
    url: string;
    name: string;
    job_title: string | null;
    company: string;
    phone: string | null;
    whatsapp: string | null;
    email: string | null;
    website: string | null;
    socials: SocialLink[];
    address: string | null;
    bio: string | null;
    color: string;
    photo_url: string | null;
    booking_url: string | null;
}

interface PageProps {
    card: PublicCard;
    branding: boolean;
    signupUrl: string;
    flash?: { success?: string | null };
    [key: string]: unknown;
}

const initials = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');

function track(slug: string, type: 'whatsapp' | 'call' | 'booking' | 'social') {
    try {
        const body = new Blob([JSON.stringify({ type })], { type: 'application/json' });
        if (!navigator.sendBeacon?.(`/c/${slug}/clic`, body)) {
            fetch(`/c/${slug}/clic`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ type }), keepalive: true });
        }
    } catch {
        // Statistique perdue : sans conséquence pour le visiteur.
    }
}

export default function CardPublic() {
    const { card, branding, signupUrl, flash } = usePage<PageProps>().props;
    const [showContact, setShowContact] = useState(false);
    const [showQr, setShowQr] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', phone: '', message: '', consent: false, site_web: '' });
    const waText = encodeURIComponent(`Bonjour ${card.name}, je vous contacte depuis votre carte digitale.`);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/c/${card.slug}/contact`, { preserveScroll: true, onSuccess: () => reset() });
    };

    const btn = 'inline-flex h-12 items-center justify-center gap-2 rounded-xl text-[15px] font-semibold transition active:scale-[.98]';
    const input = 'h-12 w-full rounded-xl border border-gray-300 bg-white px-4 text-[16px] text-gray-900 focus:border-gray-500 focus:outline-none';

    return (
        <>
            <Head title={`${card.name}${card.company ? ` · ${card.company}` : ''}`} />
            <div className="min-h-screen bg-[#F5F3F7] px-4 py-6 sm:py-10">
                <main className="mx-auto w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-xl">
                    <div className="h-32" style={{ background: `linear-gradient(135deg, ${card.color}, ${card.color}bb)` }} />
                    <div className="-mt-14 flex flex-col items-center px-6 pb-6 text-center">
                        <div className="grid h-28 w-28 place-items-center overflow-hidden rounded-full border-4 border-white text-3xl font-bold text-white shadow-md" style={{ background: card.color }}>
                            {card.photo_url ? <img src={card.photo_url} alt={card.name} className="h-full w-full object-cover" /> : initials(card.name)}
                        </div>
                        <h1 className="mt-3 text-2xl font-bold leading-tight text-[#2B1A3D]">{card.name}</h1>
                        {card.job_title && <p className="text-[15px] text-gray-600">{card.job_title}</p>}
                        <p className="text-[15px] font-semibold" style={{ color: card.color }}>
                            {card.company}
                        </p>
                        {card.bio && <p className="mt-3 text-[15px] leading-relaxed text-gray-600">{card.bio}</p>}

                        {flash?.success && <p className="mt-4 w-full rounded-xl bg-green-50 px-4 py-3 text-[15px] font-medium text-green-800">{flash.success}</p>}

                        <div className="mt-5 grid w-full grid-cols-2 gap-2.5">
                            {card.whatsapp && (
                                <a href={`https://wa.me/${card.whatsapp}?text=${waText}`} onClick={() => track(card.slug, 'whatsapp')} className={`${btn} bg-[#25D366] text-white`} target="_blank" rel="noreferrer">
                                    WhatsApp
                                </a>
                            )}
                            {card.phone && (
                                <a href={`tel:${card.phone}`} onClick={() => track(card.slug, 'call')} className={`${btn} text-white`} style={{ background: card.color }}>
                                    {t('Appeler')}
                                </a>
                            )}
                            <a href={`/c/${card.slug}/vcard`} className={`${btn} border border-gray-300 text-[#2B1A3D]`}>
                                {t('Enregistrer le contact')}
                            </a>
                            {card.booking_url ? (
                                <a href={card.booking_url} onClick={() => track(card.slug, 'booking')} className={`${btn} border border-gray-300 text-[#2B1A3D]`}>
                                    {t('Prendre RDV')}
                                </a>
                            ) : (
                                <button type="button" onClick={() => setShowContact(true)} className={`${btn} border border-gray-300 text-[#2B1A3D]`}>
                                    {t('Être rappelé')}
                                </button>
                            )}
                        </div>

                        {card.socials.length > 0 && (
                            <div className="mt-5">
                                <SocialRow links={card.socials} color={card.color} onClick={() => track(card.slug, 'social')} />
                            </div>
                        )}

                        <ul className="mt-5 w-full space-y-2 rounded-2xl bg-[#FAFAF9] p-4 text-left text-[15px] text-gray-800">
                            {card.phone && <li className="break-all">📞 {card.phone}</li>}
                            {card.email && (
                                <li className="break-all">
                                    ✉️{' '}
                                    <a href={`mailto:${card.email}`} className="underline-offset-2 hover:underline">
                                        {card.email}
                                    </a>
                                </li>
                            )}
                            {card.website && (
                                <li className="break-all">
                                    🌐{' '}
                                    <a href={card.website} target="_blank" rel="noreferrer" className="underline-offset-2 hover:underline">
                                        {card.website.replace(/^https?:\/\//, '')}
                                    </a>
                                </li>
                            )}
                            {card.address && <li>📍 {card.address}</li>}
                        </ul>

                        {showContact || errors.name || errors.phone ? (
                            <form onSubmit={submit} className="mt-5 w-full space-y-3 text-left">
                                <p className="text-lg font-bold text-[#2B1A3D]">{t('Laissez vos coordonnées')}</p>
                                <input className={input} placeholder={t('Votre nom')} value={data.name} onChange={(e) => setData('name', e.target.value)} autoComplete="name" required />
                                {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                                <input className={input} placeholder={t('Votre numéro WhatsApp')} type="tel" inputMode="tel" value={data.phone} onChange={(e) => setData('phone', e.target.value)} autoComplete="tel" required />
                                {errors.phone && <p className="text-sm text-red-600">{errors.phone}</p>}
                                <textarea className={`${input} h-24 py-3`} placeholder={t('Votre message (facultatif)')} value={data.message} onChange={(e) => setData('message', e.target.value)} />
                                <input type="text" name="site_web" value={data.site_web} onChange={(e) => setData('site_web', e.target.value)} className="hidden" tabIndex={-1} autoComplete="off" aria-hidden="true" />
                                <label className="flex items-start gap-2 text-sm text-gray-600">
                                    <input type="checkbox" className="mt-1" checked={data.consent} onChange={(e) => setData('consent', e.target.checked)} />
                                    {t('J’accepte de recevoir des messages WhatsApp de :name.', { name: card.company ?? card.name })}
                                </label>
                                <button type="submit" disabled={processing} className={`${btn} w-full text-white`} style={{ background: card.color }}>
                                    {processing ? t('Envoi…') : t('Envoyer')}
                                </button>
                            </form>
                        ) : (
                            <button type="button" onClick={() => setShowContact(true)} className="mt-5 text-[15px] font-semibold underline-offset-4 hover:underline" style={{ color: card.color }}>
                                {t('Laisser mes coordonnées')}
                            </button>
                        )}

                        <div className="mt-6 w-full border-t border-gray-100 pt-5">
                            {showQr ? (
                                <div className="flex flex-col items-center gap-2">
                                    <CardQr value={`${card.url}?src=qr`} className="h-44 w-44" />
                                    <p className="text-sm text-gray-500">{t('Faites scanner ce code pour partager la carte.')}</p>
                                </div>
                            ) : (
                                <button type="button" onClick={() => setShowQr(true)} className="text-sm font-semibold text-gray-600 underline-offset-4 hover:underline">
                                    {t('Afficher le QR code pour partager')}
                                </button>
                            )}
                        </div>
                    </div>
                </main>
                {branding && (
                    <p className="mt-5 flex items-center justify-center gap-1.5 text-center text-[13px] text-gray-500">
                        <MenentiaSymbol className="h-4 w-4" />
                        {t('Créé avec')}{' '}
                        <a href={signupUrl} className="font-extrabold text-[#2B1A3D]">
                            Cliently<span className="text-[#E8590C]">X</span>
                        </a>{' '}
                        · {t('Créez votre carte gratuite')}
                    </p>
                )}
            </div>
        </>
    );
}
