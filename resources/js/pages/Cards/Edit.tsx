import { Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel } from '../../components/page/Page';
import CardQr from './CardQr';
import SocialIcon, { BRAND_COLORS, SocialRow } from './SocialIcon';

/* Création et modification d'une carte digitale, avec l'aperçu en direct. */

interface CardData {
    id: number;
    url: string;
    qr_url: string;
    name: string;
    job_title: string | null;
    company: string | null;
    phone: string | null;
    whatsapp: string | null;
    email: string | null;
    website: string | null;
    socials: Record<string, string>;
    address: string | null;
    bio: string | null;
    color: string;
    photo_url: string | null;
    booking_enabled: boolean;
    is_active: boolean;
    store_id: number | null;
    user_id: number | null;
    staff_member_id: number | null;
}

interface Option {
    id: number;
    name: string;
}

interface PageProps {
    card: CardData | null;
    stores: Option[];
    users: Option[];
    staff: Option[];
    colors: string[];
    socialNetworks: { key: string; label: string; handle: boolean }[];
    organizationName: string;
    bookingAvailable: boolean;
    canPrint: boolean;
    [key: string]: unknown;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

function CardEdit() {
    const { card, stores, users, staff, colors, socialNetworks, organizationName, bookingAvailable, canPrint } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors } = useForm({
        name: card?.name ?? '',
        job_title: card?.job_title ?? '',
        company: card?.company ?? organizationName,
        phone: card?.phone ?? '',
        whatsapp: card?.whatsapp ?? '',
        email: card?.email ?? '',
        website: card?.website ?? '',
        address: card?.address ?? '',
        bio: card?.bio ?? '',
        color: card?.color ?? colors[0],
        booking_enabled: card?.booking_enabled ?? true,
        is_active: card?.is_active ?? true,
        store_id: card?.store_id ? String(card.store_id) : stores.length === 1 ? String(stores[0].id) : '',
        user_id: card?.user_id ? String(card.user_id) : '',
        staff_member_id: card?.staff_member_id ? String(card.staff_member_id) : '',
        socials: (card?.socials ?? {}) as Record<string, string>,
        photo: null as File | null,
        _method: card ? 'put' : 'post',
    });
    const [preview, setPreview] = useState<string | null>(card?.photo_url ?? null);

    useEffect(() => {
        if (!data.photo) return;
        const url = URL.createObjectURL(data.photo);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [data.photo]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(card ? `/cards/${card.id}` : '/cards', { forceFormData: true });
    };

    const text = (key: 'name' | 'job_title' | 'company' | 'phone' | 'whatsapp' | 'email' | 'website' | 'address', label: string, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
        <label className="block">
            <span className="mb-1 block text-sm font-medium">{label}</span>
            <input className={field} value={data[key]} onChange={(e) => setData(key, e.target.value)} {...props} />
            {errors[key] && <span className="text-xs text-error-500">{errors[key]}</span>}
        </label>
    );

    return (
        <Page
            title={card ? `Carte de ${card.name}` : 'Nouvelle carte digitale'}
            subtitle={card ? card.url : 'Un lien et un QR code par collaborateur'}
            actions={
                <Link href="/cards" className="mdash-btn ghost">
                    Retour aux cartes
                </Link>
            }
        >
            <form onSubmit={submit} className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_340px]">
                <Panel title="Informations">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {text('name', 'Nom et prénom', { required: true, maxLength: 120 })}
                        {text('job_title', 'Poste', { placeholder: 'ex. Gérante, Coiffeuse, Commercial' })}
                        {text('company', 'Entreprise')}
                        {text('whatsapp', 'Numéro WhatsApp', { type: 'tel', placeholder: '+241 77 12 34 56' })}
                        {text('phone', 'Téléphone pour les appels', { type: 'tel', placeholder: 'Le même si vide' })}
                        {text('email', 'E-mail', { type: 'email' })}
                        {text('website', 'Site web', { type: 'url', placeholder: 'https://' })}
                        {text('address', 'Adresse', { placeholder: 'Quartier, ville' })}
                        <label className="block sm:col-span-2">
                            <span className="mb-1 block text-sm font-medium">Présentation courte (facultatif)</span>
                            <textarea className={`${field} h-20`} maxLength={500} value={data.bio} onChange={(e) => setData('bio', e.target.value)} />
                        </label>
                        <label className="block">
                            <span className="mb-1 block text-sm font-medium">Photo ou logo</span>
                            <input type="file" accept="image/*" onChange={(e) => setData('photo', e.target.files?.[0] ?? null)} />
                            {errors.photo && <span className="block text-xs text-error-500">{errors.photo}</span>}
                        </label>
                        <div>
                            <span className="mb-1 block text-sm font-medium">Couleur</span>
                            <div className="flex flex-wrap gap-2">
                                {colors.map((c) => (
                                    <button key={c} type="button" aria-label={c} aria-pressed={data.color === c} onClick={() => setData('color', c)} className={`h-8 w-8 rounded-full ring-offset-2 ${data.color === c ? 'ring-2 ring-gray-800' : 'ring-1 ring-gray-200'}`} style={{ background: c }} />
                                ))}
                            </div>
                        </div>
                        <label className="block">
                            <span className="mb-1 block text-sm font-medium">Collaborateur (reçoit les fiches et les relances)</span>
                            <select className={field} value={data.user_id} onChange={(e) => setData('user_id', e.target.value)}>
                                <option value="">—</option>
                                {users.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {staff.length > 0 && (
                            <label className="block">
                                <span className="mb-1 block text-sm font-medium">Intervenant de l’agenda</span>
                                <select className={field} value={data.staff_member_id} onChange={(e) => setData('staff_member_id', e.target.value)}>
                                    <option value="">—</option>
                                    {staff.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        {stores.length > 1 && (
                            <label className="block">
                                <span className="mb-1 block text-sm font-medium">Établissement des contacts reçus</span>
                                <select className={field} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                                    <option value="">Le premier</option>
                                    {stores.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        <fieldset className="sm:col-span-2">
                            <legend className="mb-2 text-sm font-semibold">Réseaux sociaux (facultatif)</legend>
                            <p className="mb-2 text-xs text-gray-500">Collez l’adresse complète ou tapez simplement votre @pseudo.</p>
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {socialNetworks.map((n) => (
                                    <label key={n.key} className="flex items-center gap-2">
                                        <span className={`grid h-8 w-8 shrink-0 place-items-center rounded-full ${n.key === 'snapchat' ? 'text-black' : 'text-white'}`} style={{ background: BRAND_COLORS[n.key] ?? data.color }}>
                                            <SocialIcon name={n.key} className="h-4 w-4" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <input className={field} placeholder={n.handle ? `${n.label} : @pseudo ou lien` : 'https://…'} value={data.socials[n.key] ?? ''} onChange={(e) => setData('socials', { ...data.socials, [n.key]: e.target.value })} />
                                            {errors[`socials.${n.key}` as keyof typeof errors] && <span className="text-xs text-error-500">{errors[`socials.${n.key}` as keyof typeof errors]}</span>}
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <div className="space-y-2 sm:col-span-2">
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={data.booking_enabled} onChange={(e) => setData('booking_enabled', e.target.checked)} />
                                Bouton « Prendre RDV » vers la réservation en ligne
                                {!bookingAvailable && <span className="text-xs text-gray-500">(activez la réservation en ligne dans Paramètres ; sinon « Être rappelé » s’affiche)</span>}
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
                                Carte en ligne
                            </label>
                        </div>
                    </div>
                    <div className="mt-5 flex justify-end">
                        <button type="submit" className="mdash-btn brand" disabled={processing}>
                            {processing ? 'Enregistrement…' : card ? 'Enregistrer' : 'Créer la carte'}
                        </button>
                    </div>
                </Panel>

                <div className="space-y-4 lg:sticky lg:top-24 lg:self-start">
                    <div className="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-gray-800">
                        <div className="h-20" style={{ background: data.color }} />
                        <div className="-mt-10 flex flex-col items-center px-5 pb-5 text-center">
                            <div className="grid h-20 w-20 place-items-center overflow-hidden rounded-full border-4 border-white text-2xl font-bold text-white" style={{ background: data.color }}>
                                {preview ? <img src={preview} alt="" className="h-full w-full object-cover" /> : (data.name || '?').slice(0, 1).toUpperCase()}
                            </div>
                            <p className="mt-2 text-lg font-bold text-[#2B1A3D]">{data.name || 'Votre nom'}</p>
                            <p className="text-sm text-gray-500">{data.job_title}</p>
                            <p className="text-sm font-semibold" style={{ color: data.color }}>
                                {data.company}
                            </p>
                            <div className="mt-3 grid w-full grid-cols-2 gap-2 text-xs font-semibold">
                                <span className="rounded-lg bg-[#25D366] py-2 text-white">WhatsApp</span>
                                <span className="rounded-lg py-2 text-white" style={{ background: data.color }}>
                                    Appeler
                                </span>
                                <span className="rounded-lg border border-gray-200 py-2 text-[#2B1A3D]">Enregistrer</span>
                                <span className="rounded-lg border border-gray-200 py-2 text-[#2B1A3D]">{data.booking_enabled && bookingAvailable ? 'Prendre RDV' : 'Être rappelé'}</span>
                            </div>
                            <div className="mt-3">
                                <SocialRow
                                    links={socialNetworks.filter((n) => (data.socials[n.key] ?? '').trim() !== '').map((n) => ({ key: n.key, label: n.label, url: '#' }))}
                                    color={data.color}
                                    size="h-8 w-8"
                                    onClick={() => undefined}
                                />
                            </div>
                        </div>
                    </div>
                    {card && (
                        <Panel title="QR code">
                            <div className="flex items-center gap-3">
                                <CardQr value={card.qr_url} className="h-24 w-24" />
                                <div className="space-y-1 text-sm">
                                    <a href={card.url} target="_blank" rel="noreferrer" className="block font-medium text-brand-500">
                                        Ouvrir la carte
                                    </a>
                                    {canPrint ? (
                                        <a href={`/cards/${card.id}/print`} target="_blank" rel="noreferrer" className="block font-medium text-brand-500">
                                            Imprimer en 85 × 55 mm
                                        </a>
                                    ) : (
                                        <span className="block text-gray-400">Impression 85 × 55 mm : offre Équipe</span>
                                    )}
                                </div>
                            </div>
                        </Panel>
                    )}
                </div>
            </form>
        </Page>
    );
}

CardEdit.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default CardEdit;
