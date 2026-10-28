import { router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/*
 * Fidélité sur la fiche client : solde, palier, récompenses échangeables
 * en un clic, ajustement (gestionnaires) et derniers mouvements.
 */

export interface LoyaltyData {
    enabled: boolean;
    fcfa_per_point: number;
    balance: number;
    tier: string | null;
    next_tier: { name: string; min_points: number } | null;
    earned_12m: number;
    can_adjust: boolean;
    rewards: { id: number; name: string; points_cost: number }[];
    history: { id: number; type: string; points: number; note: string | null; user: string | null; at: string }[];
}

const pts = (n: number) => `${n.toLocaleString('fr-FR')} pt${Math.abs(n) > 1 ? 's' : ''}`;

export default function LoyaltyPanel({ customerId, customerName, loyalty }: { customerId: number; customerName: string; loyalty: LoyaltyData }) {
    const [adjusting, setAdjusting] = useState(false);
    const adjust = useForm({ points: '', note: '' });

    function redeem(reward: { id: number; name: string; points_cost: number }) {
        if (confirm(`Échanger « ${reward.name} » pour ${customerName} (−${pts(reward.points_cost)}) ?`)) {
            router.post(`/customers/${customerId}/loyalty/redeem`, { reward_id: reward.id }, { preserveScroll: true });
        }
    }

    const submitAdjust: FormEventHandler = (e) => {
        e.preventDefault();
        adjust.post(`/customers/${customerId}/loyalty/adjust`, {
            preserveScroll: true,
            onSuccess: () => {
                adjust.reset();
                setAdjusting(false);
            },
        });
    };

    const progress = loyalty.next_tier ? Math.min(100, Math.round((loyalty.earned_12m / Math.max(1, loyalty.next_tier.min_points)) * 100)) : 100;

    return (
        <article className="mdash-panel c6">
            <div className="mdash-ph">
                <h2>Fidélité</h2>
                {loyalty.tier && <span className="mdash-pill info">{loyalty.tier}</span>}
            </div>

            <div className="mloy-balance">
                <b className="num">{loyalty.balance.toLocaleString('fr-FR')}</b>
                <span>points disponibles</span>
            </div>
            {loyalty.next_tier ? (
                <div className="mloy-next">
                    <div className="mloy-bar">
                        <i style={{ width: `${progress}%` }} />
                    </div>
                    <span>
                        Encore {pts(Math.max(0, loyalty.next_tier.min_points - loyalty.earned_12m))} sur 12 mois pour le palier {loyalty.next_tier.name}
                    </span>
                </div>
            ) : (
                loyalty.tier && <p className="mloy-muted">Palier le plus haut atteint.</p>
            )}
            {!loyalty.enabled && <p className="mloy-muted">Programme désactivé : les achats ne rapportent plus de points.</p>}

            {loyalty.rewards.length > 0 && (
                <ul className="mloy-rewards">
                    {loyalty.rewards.map((r) => (
                        <li key={r.id}>
                            <span>
                                {r.name}
                                <small>{pts(r.points_cost)}</small>
                            </span>
                            <button type="button" className="mdash-btn ghost sm" disabled={loyalty.balance < r.points_cost} onClick={() => redeem(r)}>
                                Échanger
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {loyalty.can_adjust &&
                (adjusting ? (
                    <form onSubmit={submitAdjust} className="mloy-adjust">
                        <input type="number" aria-label="Points (négatif pour retirer)" placeholder="+50 ou -20" value={adjust.data.points} onChange={(e) => adjust.setData('points', e.target.value)} />
                        <input aria-label="Raison" placeholder="Raison (geste commercial, correction…)" value={adjust.data.note} onChange={(e) => adjust.setData('note', e.target.value)} />
                        <button type="submit" className="mdash-btn ghost sm" disabled={adjust.processing}>
                            Valider
                        </button>
                        {(adjust.errors.points || adjust.errors.note) && <em>{adjust.errors.points ?? adjust.errors.note}</em>}
                    </form>
                ) : (
                    <button type="button" className="mdash-link mloy-adjust-link" onClick={() => setAdjusting(true)}>
                        Ajuster les points
                    </button>
                ))}

            {loyalty.history.length > 0 && (
                <ul className="mcust-timeline mloy-history">
                    {loyalty.history.map((h) => (
                        <li key={h.id}>
                            <span className={`dot ${h.points < 0 ? 'bad' : ''}`} />
                            <span>
                                {h.type}
                                <span className="sub">
                                    {h.note}
                                    {h.user ? ` · ${h.user}` : ''}
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
