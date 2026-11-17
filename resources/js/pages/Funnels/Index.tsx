import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { Empty, Page, Panel, PlusIcon } from '../../components/page/Page';
import ShareLink from '../../components/share/ShareLink';
import { Modal } from '../../components/ui/modal';
import AppLayout from '../../layout/AppLayout';

/* Tunnels de vente : modèles par secteur, réglages, partage par canal, statistiques et relances. */

interface Stats {
    visits: number;
    leads: number;
    trials: number;
    sales: number;
    revenue: number;
    cost_per_sale: number | null;
    by_source: Record<string, number>;
}

interface FunnelRow {
    id: number;
    title: string;
    slug: string;
    offer: string;
    benefits: string[];
    reviews: { name: string; text: string }[];
    fields: string[];
    choice: string;
    ends_at: string | null;
    seats: number | null;
    seats_left: number | null;
    message: string | null;
    budget: number | null;
    is_active: boolean;
    open: boolean;
    url: string;
    image: string | null;
    stats: Stats;
}

interface Props {
    funnels: FunnelRow[];
    templates: { key: string; sector: string; title: string; offer: string }[];
    followUps: { lead_id: number; funnel: string; customer: string; label: string; wa_url: string | null }[];
    limit: number | null;
    active: number;
    choices: Record<string, string>;
    sources: Record<string, string>;
    fieldOptions: Record<string, string>;
    hasCampaigns: boolean;
    [key: string]: unknown;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
const money = (n: number | null | undefined) => `${new Intl.NumberFormat('fr-FR').format(n ?? 0)} F`;

function EditModal({ funnel, onClose }: { funnel: FunnelRow; onClose: () => void }) {
    const { choices, fieldOptions } = usePage<Props>().props;
    const pad = <T,>(list: T[], n: number, empty: T) => [...list, ...Array(Math.max(0, n - list.length)).fill(empty)].slice(0, n);
    const { data, setData, post, processing, errors } = useForm({
        title: funnel.title,
        offer: funnel.offer,
        benefits: pad(funnel.benefits, 3, ''),
        reviews: pad(funnel.reviews, 3, { name: '', text: '' }),
        fields: funnel.fields,
        choice: funnel.choice,
        ends_at: funnel.ends_at ?? '',
        seats: funnel.seats ? String(funnel.seats) : '',
        message: funnel.message ?? '',
        budget: funnel.budget ? String(funnel.budget) : '',
        image: null as File | null,
    });

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-2xl p-6">
            <form
                className="grid gap-3 text-sm sm:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/funnels/${funnel.id}`, { forceFormData: true, preserveScroll: true, onSuccess: onClose });
                }}
            >
                <h2 className="text-lg font-semibold sm:col-span-2">Modifier le tunnel</h2>
                <label className="sm:col-span-2">
                    Titre
                    <input className={field} value={data.title} onChange={(e) => setData('title', e.target.value)} required />
                </label>
                <label className="sm:col-span-2">
                    Offre
                    <textarea className={field} rows={2} value={data.offer} onChange={(e) => setData('offer', e.target.value)} required />
                </label>
                {data.benefits.map((b, i) => (
                    <label key={i} className={i === 2 ? 'sm:col-span-2' : ''}>
                        Avantage {i + 1}
                        <input className={field} value={b} onChange={(e) => setData('benefits', data.benefits.map((x, j) => (j === i ? e.target.value : x)))} />
                    </label>
                ))}
                {data.reviews.map((r, i) => (
                    <label key={i} className="sm:col-span-2">
                        Avis {i + 1}
                        <div className="grid grid-cols-[1fr_2fr] gap-2">
                            <input className={field} placeholder="Nom" value={r.name} onChange={(e) => setData('reviews', data.reviews.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} />
                            <input className={field} placeholder="Avis" value={r.text} onChange={(e) => setData('reviews', data.reviews.map((x, j) => (j === i ? { ...x, text: e.target.value } : x)))} />
                        </div>
                    </label>
                ))}
                <label>
                    Fin de l’offre
                    <input type="datetime-local" className={field} value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} />
                </label>
                <label>
                    Places limitées (facultatif)
                    <input type="number" min={1} className={field} value={data.seats} onChange={(e) => setData('seats', e.target.value)} />
                </label>
                <label>
                    Demande créée
                    <select className={field} value={data.choice} onChange={(e) => setData('choice', e.target.value)}>
                        {Object.entries(choices).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    Budget pub (FCFA, pour le coût par vente)
                    <input type="number" min={0} className={field} value={data.budget} onChange={(e) => setData('budget', e.target.value)} />
                </label>
                <fieldset className="sm:col-span-2">
                    <legend>Champs du formulaire (nom et WhatsApp toujours demandés)</legend>
                    <div className="flex flex-wrap gap-3">
                        {Object.entries(fieldOptions).map(([k, l]) => (
                            <label key={k} className="flex items-center gap-1.5">
                                <input type="checkbox" checked={data.fields.includes(k)} onChange={(e) => setData('fields', e.target.checked ? [...data.fields, k] : data.fields.filter((x) => x !== k))} />
                                {l}
                            </label>
                        ))}
                    </div>
                </fieldset>
                <label className="sm:col-span-2">
                    Message WhatsApp prérempli
                    <input className={field} value={data.message} onChange={(e) => setData('message', e.target.value)} />
                </label>
                <label className="sm:col-span-2">
                    Visuel
                    <input type="file" accept="image/*" onChange={(e) => setData('image', e.target.files?.[0] ?? null)} />
                </label>
                {Object.values(errors).length > 0 && <p className="text-error-500 sm:col-span-2">{Object.values(errors)[0]}</p>}
                <div className="flex justify-end gap-2 sm:col-span-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        Enregistrer
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function FunnelsIndex() {
    const { funnels, templates, followUps, limit, active, sources, hasCampaigns } = usePage<Props>().props;
    const [picking, setPicking] = useState(false);
    const [editing, setEditing] = useState<FunnelRow | null>(null);
    const [shareSource, setShareSource] = useState<Record<number, string>>({});
    const locked = limit === 0;

    return (
        <Page
            title="Tunnels de vente"
            subtitle={locked ? 'Inclus à partir de l’offre Solo' : `${active} tunnel(s) actif(s)${limit !== null ? ` sur ${limit} inclus dans votre offre` : ''}`}
            actions={
                !locked && (
                    <button type="button" className="mdash-btn brand" onClick={() => setPicking(true)}>
                        <PlusIcon />
                        Nouveau tunnel
                    </button>
                )
            }
        >
            {followUps.length > 0 && (
                <Panel title="Relances WhatsApp à faire" sub="Récapitulatif, rappel avec un avis, dernière chance : la séquence s’arrête dès l’achat ou au STOP">
                    <ul className="divide-y divide-gray-100 text-sm dark:divide-white/5">
                        {followUps.map((f) => (
                            <li key={`${f.lead_id}-${f.label}`} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>
                                    <b>{f.customer}</b> · {f.funnel} · <span className="text-gray-500">{f.label}</span>
                                </span>
                                <span className="flex gap-2">
                                    {f.wa_url && (
                                        <a className="mdash-btn brand sm" href={f.wa_url} target="_blank" rel="noreferrer">
                                            WhatsApp
                                        </a>
                                    )}
                                    <button type="button" className="mdash-btn ghost sm" onClick={() => router.post(`/funnels/leads/${f.lead_id}/done`, {}, { preserveScroll: true })}>
                                        Fait
                                    </button>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}

            {funnels.length === 0 && <Empty title="Aucun tunnel">{locked ? 'Passez à l’offre Solo pour créer vos pages d’offre.' : 'Partez d’un modèle de votre secteur : il est prêt en 1 minute.'}</Empty>}

            {funnels.map((f) => {
                const src = shareSource[f.id] ?? 'statut';
                const url = `${f.url}?src=${src}`;
                return (
                    <Panel
                        key={f.id}
                        title={f.title}
                        sub={`${f.open ? 'En ligne' : f.is_active ? 'Terminé : renvoie vers votre page en ligne' : 'Désactivé'}${f.ends_at ? ` · jusqu’au ${new Date(f.ends_at).toLocaleDateString('fr-FR')}` : ''}${f.seats_left !== null ? ` · ${f.seats_left} place(s) restante(s)` : ''}`}
                        actions={
                            <span className="mpage-rowlinks flex gap-3 text-sm">
                                <button type="button" onClick={() => setEditing(f)}>
                                    Modifier
                                </button>
                                <button type="button" onClick={() => router.post(`/funnels/${f.id}/toggle`, {}, { preserveScroll: true })}>
                                    {f.is_active ? 'Désactiver' : 'Activer'}
                                </button>
                            </span>
                        }
                    >
                        <div className="grid gap-4 lg:grid-cols-2">
                            <div className="space-y-3 text-sm">
                                <div className="grid grid-cols-3 gap-2 text-center sm:grid-cols-6">
                                    {[
                                        ['Visites', f.stats.visits],
                                        ['Demandes', f.stats.leads],
                                        ['RDV / essais', f.stats.trials],
                                        ['Ventes', f.stats.sales],
                                        ['CA', money(f.stats.revenue)],
                                        ['Coût / vente', f.stats.cost_per_sale !== null ? money(f.stats.cost_per_sale) : '—'],
                                    ].map(([l, v]) => (
                                        <span key={l as string} className="rounded-lg bg-gray-50 p-2 dark:bg-white/5">
                                            <b className="block">{v}</b>
                                            <span className="text-xs text-gray-500">{l}</span>
                                        </span>
                                    ))}
                                </div>
                                {Object.keys(f.stats.by_source).length > 0 && (
                                    <p className="text-xs text-gray-500">Demandes par canal : {Object.entries(f.stats.by_source).map(([k, n]) => `${sources[k] ?? k} ${n}`).join(' · ')}</p>
                                )}
                                <p className="text-gray-600">{f.offer}</p>
                                {hasCampaigns && (
                                    <a className="mdash-btn ghost sm" href={`/campaigns?texte=${encodeURIComponent(`${f.title} : ${f.offer} Réservez ici : ${f.url}?src=campagne`)}`}>
                                        Envoyer à un segment
                                    </a>
                                )}
                            </div>
                            <div className="space-y-2">
                                <label className="text-xs text-gray-500">
                                    Canal du lien à partager :{' '}
                                    <select className="rounded border border-gray-300 px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-900" value={src} onChange={(e) => setShareSource({ ...shareSource, [f.id]: e.target.value })}>
                                        {Object.entries(sources)
                                            .filter(([k]) => !['carte', 'direct', 'campagne'].includes(k))
                                            .map(([k, l]) => (
                                                <option key={k} value={k}>
                                                    {l}
                                                </option>
                                            ))}
                                    </select>
                                </label>
                                <ShareLink url={url} label="Lien du tunnel" message={`${f.title} : ${f.offer}`} filename={`tunnel-${f.slug}.png`} />
                                <p className="text-xs text-gray-500">Sur une carte digitale, ajoutez ?carte=… pour attribuer les demandes au collaborateur.</p>
                            </div>
                        </div>
                    </Panel>
                );
            })}

            {picking && (
                <Modal isOpen={true} onClose={() => setPicking(false)} className="max-w-2xl p-6">
                    <h2 className="mb-3 text-lg font-semibold">Choisir un modèle</h2>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {templates.map((t) => (
                            <button
                                key={t.key}
                                type="button"
                                className="rounded-xl border border-gray-200 p-3 text-left text-sm hover:border-brand-300 dark:border-white/10"
                                onClick={() => router.post('/funnels', { template: t.key }, { onSuccess: () => setPicking(false) })}
                            >
                                <b className="block">{t.title}</b>
                                <span className="text-xs text-gray-500">{t.offer}</span>
                            </button>
                        ))}
                    </div>
                </Modal>
            )}
            {editing && <EditModal funnel={editing} onClose={() => setEditing(null)} />}
        </Page>
    );
}

FunnelsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default FunnelsIndex;
