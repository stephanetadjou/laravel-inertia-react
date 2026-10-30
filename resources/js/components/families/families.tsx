import { formatNumber } from '../dashboard/Charts';
import '../../pages/Categories/families.css';

/* Petits éléments partagés par les écrans Familles (catégories) et la fiche client. */

export const money = (n: number) => `${formatNumber(n)} FCFA`;
export const capitalize = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

export function Share({ value }: { value: number }) {
    return (
        <span className="mfam-share">
            <span className="bar">
                <i style={{ width: `${Math.min(100, value)}%` }} />
            </span>
            <small>{value.toLocaleString('fr-FR')} %</small>
        </span>
    );
}

/** Lien vers les fiches clients filtrées (ciblage, groupe, campagne). */
export function customersUrl(params: Record<string, string | number>): string {
    return '/customers?' + new URLSearchParams(Object.entries(params).map(([k, v]) => [k, String(v)])).toString();
}

