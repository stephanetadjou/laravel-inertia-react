import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { encodeQr, qrPath } from '../../components/common/qrcode';
import '../Dashboard/dashboard.css';
import './stores.css';
import './qr.css';

interface PageProps {
    store: { id: number; name: string; brand_name: string | null; shared_account: boolean };
    /** Numéro lu chez Meta (chiffres seuls), null s'il faut le saisir. */
    whatsappNumber: string | null;
    message: string;
    simulated: boolean;
    [key: string]: unknown;
}

interface PosterTexts {
    headline: string;
    benefits: string;
    footer: string;
}

const DEFAULT_TEXTS: PosterTexts = {
    headline: 'Scannez pour faire partie du programme de fidélité et profitez de remises sur vos achats',
    benefits: 'Remises réservées aux membres\nVentes privées en avant-première\nNouveautés et arrivages',
    footer: '2 messages par mois maximum · Répondez STOP à tout moment pour arrêter',
};

const QR_MARGIN = 4;

/** Saisie libre → chiffres E.164 sans « + » (numéro gabonais à 8 chiffres complété par 241). */
function normalizeNumber(raw: string): string | null {
    let digits = raw.replace(/\D/g, '');
    if (digits.startsWith('00')) digits = digits.slice(2);
    if (/^0[67]\d{7}$/.test(digits)) digits = digits.slice(1);
    if (/^[67]\d{7}$/.test(digits)) digits = `241${digits}`;
    return digits.length >= 10 && digits.length <= 15 ? digits : null;
}

/** 24177000000 → « +241 77 00 00 00 ». */
function formatNumber(digits: string): string {
    const gabon = digits.match(/^241(\d{2})(\d{2})(\d{2})(\d{2})$/);
    return gabon ? `+241 ${gabon.slice(1).join(' ')}` : `+${digits}`;
}

function readStored<T>(key: string, fallback: T): T {
    try {
        const raw = localStorage.getItem(key);
        return raw ? { ...fallback, ...JSON.parse(raw) } : fallback;
    } catch {
        return fallback;
    }
}

function writeStored(key: string, value: unknown): void {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Stockage indisponible (navigation privée) : réglages non mémorisés.
    }
}

function StoreQr() {
    const { store, whatsappNumber, message, simulated } = usePage<PageProps>().props;
    const storageKey = `mercure:qr-poster:${store.id}`;

    // Réglages mémorisés par boutique dans ce navigateur (confort, pas une donnée métier).
    const [texts, setTexts] = useState<PosterTexts>(() => readStored(storageKey, DEFAULT_TEXTS));
    const [manualNumber, setManualNumber] = useState<string>(() => readStored(`${storageKey}:number`, { value: '' }).value);
    const [copied, setCopied] = useState(false);

    useEffect(() => writeStored(storageKey, texts), [storageKey, texts]);
    useEffect(() => writeStored(`${storageKey}:number`, { value: manualNumber }), [storageKey, manualNumber]);

    const number = whatsappNumber ?? normalizeNumber(manualNumber);
    const link = number ? `https://wa.me/${number}?text=${encodeURIComponent(message)}` : null;
    const qr = useMemo(() => (link ? encodeQr(link, 'Q') : null), [link]);
    const benefits = texts.benefits
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean)
        .slice(0, 4);

    function updateText(key: keyof PosterTexts, value: string) {
        setTexts((current) => ({ ...current, [key]: value }));
    }

    async function copyLink() {
        if (!link) return;
        try {
            await navigator.clipboard.writeText(link);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            window.prompt('Copiez le lien :', link);
        }
    }

    function downloadSvg() {
        if (!qr) return;
        const dim = qr.size + QR_MARGIN * 2;
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${dim} ${dim}" shape-rendering="crispEdges"><rect width="${dim}" height="${dim}" fill="#fff"/><path d="${qrPath(qr, QR_MARGIN)}" fill="#000"/></svg>`;
        const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = `qr-whatsapp-${store.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.svg`;
        a.click();
        URL.revokeObjectURL(url);
    }

    const dim = qr ? qr.size + QR_MARGIN * 2 : 0;

    return (
        <div className="mdash mstore mqr">
            <Head title={`QR code · ${store.name}`} />

            <header className="mdash-top mstore-top">
                <div className="mdash-top-in">
                    <div>
                        <Link href="/stores" className="mqr-back">
                            ← Marques & boutiques
                        </Link>
                        <h1>QR code fidélité · {store.name}</h1>
                        <small>Affiche à poser en caisse : le client scanne et envoie « {message} » sur WhatsApp</small>
                    </div>
                    <div className="mstore-actions">
                        <button type="button" className="mdash-btn ghost" disabled={!link} onClick={copyLink}>
                            {copied ? 'Lien copié ✓' : 'Copier le lien'}
                        </button>
                        <button type="button" className="mdash-btn ghost" disabled={!qr} onClick={downloadSvg}>
                            Télécharger le QR (SVG)
                        </button>
                        <button type="button" className="mdash-btn brand" disabled={!qr} onClick={() => window.print()}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                <path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v7H6z" />
                            </svg>
                            Imprimer l'affiche A5
                        </button>
                    </div>
                </div>
            </header>

            <div className="mqr-grid">
                <section className="mdash-panel mqr-settings" aria-label="Réglages de l'affiche">
                    <div className="mdash-ph">
                        <h2>Réglages</h2>
                        <span className="mdash-sub">mémorisés dans ce navigateur</span>
                    </div>

                    <div className="mqr-field">
                        <span className="mqr-label">Numéro WhatsApp de la boutique</span>
                        {whatsappNumber ? (
                            <div className="mqr-number">
                                <b className="mono">{formatNumber(whatsappNumber)}</b>
                                <span className="mdash-pill ok">Lu chez Meta</span>
                            </div>
                        ) : (
                            <>
                                <input
                                    type="tel"
                                    className="mqr-input"
                                    placeholder="ex. 77 00 00 00 ou +241 77 00 00 00"
                                    value={manualNumber}
                                    onChange={(e) => setManualNumber(e.target.value)}
                                />
                                <span className={`mqr-help ${manualNumber && !number ? 'bad' : ''}`}>
                                    {manualNumber && !number
                                        ? 'Numéro invalide : 8 chiffres commençant par 6 ou 7, ou un numéro international.'
                                        : simulated
                                          ? 'Mode simulation : aucun numéro Meta, saisissez celui qui recevra les « OUI ».'
                                          : 'Numéro introuvable chez Meta (boutique non connectée ou test de connexion en échec) : saisissez-le.'}
                                </span>
                            </>
                        )}
                        {store.shared_account && (
                            <span className="mqr-help">
                                Compte partagé : toutes les boutiques ont ce numéro, le message « {message} » indique la boutique.
                            </span>
                        )}
                    </div>

                    <label className="mqr-field">
                        <span className="mqr-label">Titre</span>
                        <textarea className="mqr-input" rows={3} value={texts.headline} onChange={(e) => updateText('headline', e.target.value)} />
                    </label>

                    <label className="mqr-field">
                        <span className="mqr-label">Avantages (un par ligne, 4 au plus)</span>
                        <textarea className="mqr-input" rows={4} value={texts.benefits} onChange={(e) => updateText('benefits', e.target.value)} />
                    </label>

                    <label className="mqr-field">
                        <span className="mqr-label">Engagement</span>
                        <input className="mqr-input" value={texts.footer} onChange={(e) => updateText('footer', e.target.value)} />
                    </label>

                    <button type="button" className="mdash-btn ghost sm" onClick={() => setTexts(DEFAULT_TEXTS)}>
                        Rétablir les textes par défaut
                    </button>

                    <div className="mqr-note">
                        <b>Et ensuite ?</b> Le « {message} » du client arrive dans la conversation WhatsApp de la boutique. Passez alors son consentement
                        à <i>Opt-in</i> dans sa fiche client (ou via la colonne « consentement » d'un import) : sans cela, il ne recevra aucune campagne
                        marketing.
                    </div>
                </section>

                <div className="mqr-preview">
                    <article className="mqr-poster" aria-label="Aperçu de l'affiche A5">
                        <div className="mqr-poster-top">
                            {store.brand_name && store.brand_name !== store.name && <span className="mqr-poster-brand">{store.brand_name}</span>}
                            <span className="mqr-poster-store">{store.name}</span>
                            <span className="mqr-poster-tag">Programme de fidélité</span>
                        </div>

                        <h2 className="mqr-poster-headline">{texts.headline}</h2>

                        <div className="mqr-poster-code">
                            {qr ? (
                                <svg viewBox={`0 0 ${dim} ${dim}`} shapeRendering="crispEdges" role="img" aria-label={`QR code WhatsApp : ${message}`}>
                                    <rect width={dim} height={dim} fill="#fff" />
                                    <path d={qrPath(qr, QR_MARGIN)} fill="#000" />
                                </svg>
                            ) : (
                                <span className="mqr-poster-missing">Renseignez le numéro WhatsApp de la boutique</span>
                            )}
                        </div>

                        <ol className="mqr-poster-steps">
                            <li>
                                <b>1</b>Scannez avec l'appareil photo
                            </li>
                            <li>
                                <b>2</b>Envoyez « {message} »
                            </li>
                            <li>
                                <b>3</b>Profitez de vos avantages
                            </li>
                        </ol>

                        {benefits.length > 0 && (
                            <ul className="mqr-poster-benefits">
                                {benefits.map((benefit) => (
                                    <li key={benefit}>{benefit}</li>
                                ))}
                            </ul>
                        )}

                        <div className="mqr-poster-foot">
                            {number && (
                                <span>
                                    Pas de QR ? Écrivez « {message} » au <b>{formatNumber(number)}</b>
                                </span>
                            )}
                            <small>{texts.footer}</small>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    );
}

StoreQr.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default StoreQr;
