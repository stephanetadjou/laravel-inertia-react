import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon, StatStrip } from '../../components/page/Page';
import CardQr, { downloadQr } from './CardQr';

/*
 * Application « Carte digitale » : les cartes de l'équipe, leur lien, leur QR
 * code et ce qu'elles rapportent (visites, scans, clics WhatsApp, contacts).
 */

export interface CardRow {
    id: number;
    slug: string;
    url: string;
    qr_url: string;
    name: string;
    job_title: string | null;
    company: string | null;
    color: string;
    photo_url: string | null;
    is_active: boolean;
    user_name: string | null;
    store_name: string | null;
    stats: { views: number; scans: number; whatsapp: number; calls: number; vcard: number; booking: number; contacts: number; customers: number; social: number };
}

interface PageProps {
    cards: CardRow[];
    days: number;
    periods: { days: number; allowed: boolean; pack: string }[];
    canPrint: boolean;
    printPack: string;
    limit: number | null;
    canCreate: boolean;
    nextPack: { label: string; cards: number | null } | null;
    branding: boolean;
    [key: string]: unknown;
}

const PERIOD_LABELS: Record<number, string> = { 7: '7 jours', 30: '30 jours', 90: '90 jours', 365: '12 mois' };

function CardsIndex() {
    const { cards, days, periods, canPrint, printPack, limit, canCreate, nextPack, branding } = usePage<PageProps>().props;
    const [copied, setCopied] = useState<number | null>(null);
    const total = (k: keyof CardRow['stats']) => cards.reduce((n, c) => n + c.stats[k], 0);
    const views = total('views');
    const contacts = total('whatsapp') + total('calls') + total('contacts') + total('booking');

    const copy = (card: CardRow) => {
        navigator.clipboard?.writeText(card.url);
        setCopied(card.id);
        setTimeout(() => setCopied(null), 1500);
    };

    return (
        <Page
            title="Carte digitale"
            subtitle={`${cards.length} carte(s)${limit !== null ? ` sur ${limit} comprises dans votre offre` : ' · illimitées'}`}
            actions={
                canCreate ? (
                    <Link href="/cards/create" className="mdash-btn brand">
                        <PlusIcon />
                        Nouvelle carte
                    </Link>
                ) : (
                    <span className="text-sm text-gray-500">
                        Limite de l’offre atteinte
                        {nextPack && ` · passez à l’offre ${nextPack.label} pour ${nextPack.cards === null ? 'des cartes illimitées' : `${nextPack.cards} cartes`}`}
                    </span>
                )
            }
            filters={
                <div className="mdash-fgroup">
                    <span>Période</span>
                    <div className="mdash-seg" role="group" aria-label="Période">
                        {periods.map((p) => (
                            <button
                                key={p.days}
                                type="button"
                                aria-pressed={days === p.days}
                                disabled={!p.allowed}
                                title={p.allowed ? undefined : `Disponible en offre ${p.pack}`}
                                className={p.allowed ? undefined : 'cursor-not-allowed opacity-50'}
                                onClick={() => router.get('/cards', { days: p.days }, { preserveScroll: true, preserveState: true })}
                            >
                                {PERIOD_LABELS[p.days]}
                                {!p.allowed && <span className="ml-1 text-[10px]">· {p.pack}</span>}
                            </button>
                        ))}
                    </div>
                </div>
            }
        >
            <StatStrip
                items={[
                    { label: 'Visites des cartes', value: String(views), hint: `dont ${total('scans')} scan(s) de QR code` },
                    { label: 'Prises de contact', value: String(contacts), hint: `${total('whatsapp')} WhatsApp · ${total('calls')} appels · ${total('booking')} RDV · ${total('social')} réseaux` },
                    { label: 'Fiches clients créées', value: String(total('customers')), hint: 'Source « Carte digitale », depuis le début' },
                    { label: 'Taux de contact', value: views ? `${Math.round((contacts * 100) / views)} %` : '—' },
                ]}
            />

            {branding && (
                <p className="rounded-xl bg-[#FFF4E6] px-4 py-3 text-sm text-[#B8460A]">
                    Vos cartes affichent la mention « Créé avec <b>Cliently<span className="text-[#E8590C]">X</span></b> ». Elle disparaît avec l’offre Équipe, qui ajoute aussi 30 cartes.
                </p>
            )}

            {cards.length === 0 ? (
                <Panel>
                    <Empty title="Aucune carte pour l’instant">Créez la carte de chaque collaborateur : un lien et un QR code à partager, et chaque contact reçu devient une fiche client.</Empty>
                </Panel>
            ) : (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {cards.map((card) => (
                        <Panel key={card.id}>
                            <div className="flex gap-4">
                                <div className="grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-full text-lg font-bold text-white" style={{ background: card.color }}>
                                    {card.photo_url ? <img src={card.photo_url} alt="" className="h-full w-full object-cover" /> : card.name.slice(0, 1).toUpperCase()}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-semibold text-gray-900 dark:text-white">
                                        {card.name} {!card.is_active && <span className="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">Désactivée</span>}
                                    </p>
                                    <p className="truncate text-sm text-gray-500">{[card.job_title, card.company].filter(Boolean).join(' · ')}</p>
                                    {card.user_name && <p className="truncate text-xs text-gray-500">Fiches reçues : {card.user_name}</p>}
                                </div>
                                <button type="button" onClick={() => downloadQr(card.qr_url, `qr-${card.slug}.svg`)} title="Télécharger le QR code">
                                    <CardQr value={card.qr_url} className="h-16 w-16" />
                                </button>
                            </div>
                            <dl className="mt-4 grid grid-cols-5 gap-1.5 text-center text-xs text-gray-500">
                                {(
                                    [
                                        ['Visites', card.stats.views],
                                        ['Scans', card.stats.scans],
                                        ['WhatsApp', card.stats.whatsapp],
                                        ['Réseaux', card.stats.social],
                                        ['Contacts', card.stats.contacts + card.stats.calls + card.stats.booking],
                                    ] as const
                                ).map(([label, n]) => (
                                    <div key={label} className="rounded-lg bg-gray-50 py-2 dark:bg-white/5">
                                        <dd className="text-lg font-bold text-gray-900 dark:text-white">{n}</dd>
                                        <dt>{label}</dt>
                                    </div>
                                ))}
                            </dl>
                            <div className="mpage-rowlinks mt-4 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                                <a href={card.url} target="_blank" rel="noreferrer">
                                    Voir
                                </a>
                                <button type="button" onClick={() => copy(card)}>
                                    {copied === card.id ? 'Lien copié ✓' : 'Copier le lien'}
                                </button>
                                <a href={`https://wa.me/?text=${encodeURIComponent(`Ma carte digitale : ${card.url}`)}`} target="_blank" rel="noreferrer">
                                    Partager sur WhatsApp
                                </a>
                                {canPrint ? (
                                    <a href={`/cards/${card.id}/print`} target="_blank" rel="noreferrer">
                                        Imprimer
                                    </a>
                                ) : (
                                    <span className="cursor-not-allowed text-gray-400" title={`Impression 85 × 55 mm disponible en offre ${printPack}`}>
                                        Imprimer · {printPack}
                                    </span>
                                )}
                                <Link href={`/cards/${card.id}/edit`}>Modifier</Link>
                                <button type="button" onClick={() => confirm(`Supprimer la carte de ${card.name} ? Le QR code imprimé ne fonctionnera plus.`) && router.delete(`/cards/${card.id}`)}>
                                    Supprimer
                                </button>
                            </div>
                        </Panel>
                    ))}
                </div>
            )}
        </Page>
    );
}

CardsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default CardsIndex;
