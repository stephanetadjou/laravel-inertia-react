/**
 * Cartes KPI partagées (tableau de bord, historique d'achat), stylées par
 * dashboard.css (.mdash-kpi / .mdash-delta).
 */

/** Variation en % vs période précédente ; null si la base est nulle. */
export function percentChange(current: number, previous: number): number | null {
    if (previous === 0) {
        return current === 0 ? 0 : null;
    }
    return ((current - previous) / previous) * 100;
}

export function Delta({ value, unit = '%', inverted = false, missing }: { value: number | null; unit?: string; inverted?: boolean; missing?: string }) {
    if (value === null) {
        return (
            <span className="mdash-delta flat" title={missing ?? 'Aucune valeur sur la période précédente'}>
                —
            </span>
        );
    }
    const rounded = Math.round(value * 10) / 10;
    const good = inverted ? rounded <= 0 : rounded >= 0;
    const cls = rounded === 0 ? 'flat' : good ? 'up' : 'down';
    return (
        <span className={`mdash-delta ${cls}`}>
            {rounded > 0 ? '+' : ''}
            {rounded.toLocaleString('fr-FR', { maximumFractionDigits: 1 })} {unit}
        </span>
    );
}

export function Kpi({ label, value, unit, delta, foot, hero }: { label: string; value: string; unit?: string; delta: React.ReactNode; foot?: string; hero?: boolean }) {
    return (
        <div className={`mdash-kpi ${hero ? 'hero' : ''}`}>
            <span className="k-label">{label}</span>
            <span className="k-val num">
                {value}
                {unit && <small>{unit}</small>}
            </span>
            <span className="k-foot">
                {delta}
                <span>vs période préc.</span>
                {foot && <span>· {foot}</span>}
            </span>
        </div>
    );
}
