import { useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/*
 * Fidélité par enseigne sur la fiche client : un bloc par enseigne, avec le
 * statut, les points, la progression vers les paliers, la remise disponible,
 * l'expiration, le journal et le bouton « Remise utilisée ».
 */

export interface LoyaltyCardData {
    id: number;
    brand: string;
    program: string;
    status: 'none' | 'active' | 'expired';
    status_label: string;
    points: number;
    cycle_amount: number;
    threshold: number | null;
    amount_to_threshold: number;
    tiers: { points: number; percent: number }[];
    discount_percent: number | null;
    next_tier_points: number | null;
    points_to_next_tier: number | null;
    activated_on: string | null;
    expires_on: string | null;
    discounts_used: number;
    stores: { id: number; name: string }[];
    history: { id: number; type: string; points: number; balance: number; note: string | null; on: string }[];
}

const money = (n: number) => `${n.toLocaleString('fr-FR')} FCFA`;
const day = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR');
const today = () => new Date().toISOString().slice(0, 10);

function Redeem({ customerId, card, onDone }: { customerId: number; card: LoyaltyCardData; onDone: () => void }) {
    const { data, setData, post, processing, errors } = useForm({
        store_id: card.stores.length === 1 ? String(card.stores[0].id) : '',
        discount_percent: String(card.discount_percent ?? ''),
        amount: '',
        used_on: today(),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/customers/${customerId}/loyalty-cards/${card.id}/redeem`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mloy-adjust">
            <select aria-label="Magasin" value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                <option value="">Magasin</option>
                {card.stores.map((s) => (
                    <option key={s.id} value={s.id}>
                        {s.name}
                    </option>
                ))}
            </select>
            <select aria-label="Palier" value={data.discount_percent} onChange={(e) => setData('discount_percent', e.target.value)}>
                <option value="">Palier</option>
                {card.tiers.map((t) => (
                    <option key={t.points} value={t.percent}>
                        {t.percent} % ({t.points} pts)
                    </option>
                ))}
            </select>
            <input type="number" min={0} aria-label="Montant de la remise" placeholder="Montant de la remise" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
            <input type="date" aria-label="Date" value={data.used_on} onChange={(e) => setData('used_on', e.target.value)} />
            <button type="submit" className="mdash-btn ghost sm" disabled={processing}>
                Valider
            </button>
            {(errors.store_id || errors.discount_percent || errors.amount || errors.used_on) && <em>{errors.store_id ?? errors.discount_percent ?? errors.amount ?? errors.used_on}</em>}
        </form>
    );
}

function Card({ customerId, card }: { customerId: number; card: LoyaltyCardData }) {
    const [redeeming, setRedeeming] = useState(false);
    const [showHistory, setShowHistory] = useState(false);
    const top = card.tiers.at(-1)?.points ?? 1;
    const active = card.status === 'active';
    const progress = active ? Math.min(100, Math.round((card.points / top) * 100)) : Math.min(100, Math.round((card.cycle_amount / Math.max(1, card.threshold ?? 1)) * 100));

    return (
        <article className="mdash-panel c6">
            <div className="mdash-ph">
                <h2>{card.brand}</h2>
                <span className={`mdash-pill ${active ? 'info' : ''}`}>{card.status_label}</span>
            </div>
            <div className="mloy-balance">
                <b className="num">{card.points.toLocaleString('fr-FR')}</b>
                <span>points · {card.program}</span>
            </div>
            <div className="mloy-next">
                <div className="mloy-bar">
                    <i style={{ width: `${progress}%` }} />
                </div>
                <span>
                    {active
                        ? card.next_tier_points !== null
                            ? `Encore ${card.points_to_next_tier} points pour le palier de ${card.next_tier_points} points`
                            : 'Palier le plus haut atteint'
                        : `Encore ${money(card.amount_to_threshold)} d’achats avant le seuil de ${money(card.threshold ?? 0)}`}
                </span>
                {card.tiers.length > 0 && <span className="mloy-muted">Paliers : {card.tiers.map((t) => `${t.points} pts → ${t.percent} %`).join(' · ')}</span>}
            </div>

            {active && card.discount_percent !== null && <p className="mloy-muted">Remise disponible : {card.discount_percent} %</p>}
            {card.expires_on && (
                <p className="mloy-muted">
                    {card.status === 'expired' ? 'Carte expirée, valable jusqu’au' : 'Carte valable jusqu’au'} {day(card.expires_on)}
                    {card.discounts_used > 0 ? ` · remise utilisée ${card.discounts_used} fois` : ''}
                </p>
            )}

            {active &&
                card.discount_percent !== null &&
                (redeeming ? (
                    <Redeem customerId={customerId} card={card} onDone={() => setRedeeming(false)} />
                ) : (
                    <button type="button" className="mdash-btn ghost sm" onClick={() => setRedeeming(true)}>
                        Remise utilisée
                    </button>
                ))}

            {card.history.length > 0 && (
                <button type="button" className="mdash-link mloy-adjust-link" onClick={() => setShowHistory(!showHistory)}>
                    {showHistory ? 'Masquer l’historique' : 'Voir l’historique'}
                </button>
            )}
            {showHistory && (
                <ul className="mcust-timeline mloy-history">
                    {card.history.map((h) => (
                        <li key={h.id}>
                            <span className={`dot ${h.points < 0 ? 'bad' : ''}`} />
                            <span>
                                {h.type}
                                <span className="sub">
                                    {day(h.on)}
                                    {h.note ? ` · ${h.note}` : ''}
                                </span>
                            </span>
                            <span className={`mloy-points ${h.points < 0 ? 'neg' : ''}`}>
                                {h.points > 0 ? '+' : ''}
                                {h.points.toLocaleString('fr-FR')}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </article>
    );
}

export default function LoyaltyCardsPanel({ customerId, cards }: { customerId: number; cards: LoyaltyCardData[] }) {
    return (
        <>
            {cards.map((card) => (
                <Card key={card.id} customerId={customerId} card={card} />
            ))}
        </>
    );
}
