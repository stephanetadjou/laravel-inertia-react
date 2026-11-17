import { Link } from '@inertiajs/react';

/*
 * Accueil › Occupation de la semaine : taux de réservation des intervenants,
 * remplissage des cours, carte des heures (jours × heures) et signaux du
 * moteur d'occupation (creux à remplir, jours saturés, ressources immobilisées).
 */

export interface OccupationSignal {
    type: 'creux' | 'saturation' | 'immobilisation';
    kind: 'staff' | 'class' | 'resource';
    date: string | null;
    label: string;
    detail: string;
    rate: number | null;
}

export interface OccupationData {
    rate: number | null;
    free_hours: number;
    classes: { sessions: number; places: number; filled: number; rate: number | null; full: number } | null;
    heatmap: { hours: number[]; rows: { day: string; cells: (number | null)[] }[] } | null;
    resources: { name: string; kind: string; used_days: number; days: number; rate: number | null; idle_days: number | null }[];
    signals: OccupationSignal[];
    signal_count: number;
}

const TYPE_LABELS: Record<OccupationSignal['type'], { label: string; className: string }> = {
    creux: { label: 'À remplir', className: 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-400' },
    saturation: { label: 'Saturé', className: 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-orange-400' },
    immobilisation: { label: 'Immobilisé', className: 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300' },
};

/** Couleur d'une case de la carte des heures : du clair (vide) au foncé (plein). */
function cellStyle(rate: number | null): React.CSSProperties {
    if (rate === null) {
        return { background: 'transparent' };
    }
    const alpha = 0.08 + (rate / 100) * 0.82;

    return { background: `rgba(14, 123, 214, ${alpha.toFixed(2)})`, color: rate >= 60 ? '#fff' : undefined };
}

export default function OccupationPanel({ data }: { data: OccupationData }) {
    const hours = data.heatmap?.hours ?? [];

    return (
        <section className="mdash-grid mt-4" aria-label="Occupation">
            <article className="mdash-panel c8">
                <div className="mdash-ph">
                    <div>
                        <h2>Occupation de la semaine</h2>
                        <small className="mdash-sub">
                            {data.rate !== null ? `${data.rate} % des heures de l’équipe réservées · ${data.free_hours} h encore libres` : 'Heures réservées, places de cours et ressources'}
                            {data.classes && data.classes.rate !== null && ` · cours remplis à ${data.classes.rate} %`}
                        </small>
                    </div>
                    <Link href="/settings/occupation" className="mdash-btn ghost sm">
                        Seuils
                    </Link>
                </div>

                {data.heatmap && data.heatmap.rows.length > 0 ? (
                    <div className="overflow-x-auto">
                        <table className="w-full border-separate border-spacing-0.5 text-[11px]">
                            <thead>
                                <tr>
                                    <th />
                                    {hours.map((h) => (
                                        <th key={h} className="px-0.5 font-medium text-gray-400">
                                            {h}h
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {data.heatmap.rows.map((row) => (
                                    <tr key={row.day}>
                                        <th className="pr-2 text-left font-medium text-gray-500">{row.day}</th>
                                        {row.cells.map((rate, i) => (
                                            <td
                                                key={hours[i]}
                                                title={rate === null ? 'Fermé' : `${row.day} ${hours[i]}h : ${rate} % réservé`}
                                                className="h-7 min-w-7 rounded text-center tabular-nums"
                                                style={cellStyle(rate)}
                                            >
                                                {rate === null ? '' : rate}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <p className="mt-2 text-xs text-gray-500">Pourcentage des heures réservées, par jour et par heure. Les cases claires sont les creux à remplir.</p>
                    </div>
                ) : data.classes ? (
                    <p className="py-6 text-sm text-gray-600 dark:text-gray-300">
                        {data.classes.filled} inscrits pour {data.classes.places} places sur {data.classes.sessions} séances cette semaine
                        {data.classes.full > 0 && `, dont ${data.classes.full} complètes`}.
                    </p>
                ) : (
                    <p className="py-6 text-center text-sm text-gray-500">Renseignez les horaires de vos intervenants pour mesurer l’occupation.</p>
                )}

                {data.resources.length > 0 && (
                    <ul className="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                        {data.resources.map((r) => (
                            <li key={r.name} className="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800">
                                <span className="truncate">
                                    {r.name} <span className="text-xs text-gray-400">{r.kind}</span>
                                </span>
                                <span className="shrink-0 text-xs text-gray-500">
                                    {r.used_days} j réservés sur {r.days}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </article>

            <article className="mdash-panel c4">
                <div className="mdash-ph">
                    <div>
                        <h2>À optimiser</h2>
                        <small className="mdash-sub">{data.signal_count > 0 ? `${data.signal_count} point(s) à traiter` : 'Rien à signaler'}</small>
                    </div>
                </div>
                {data.signals.length === 0 ? (
                    <p className="py-8 text-center text-sm text-gray-500">Pas de creux, de jour saturé ni de ressource immobilisée en vue.</p>
                ) : (
                    <ul className="-mx-2 divide-y divide-gray-100 dark:divide-gray-800">
                        {data.signals.map((s, i) => (
                            <li key={`${s.label}-${i}`} className="flex items-start gap-3 px-2 py-3 text-sm">
                                <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium ${TYPE_LABELS[s.type].className}`}>{TYPE_LABELS[s.type].label}</span>
                                <span className="min-w-0">
                                    <span className="block truncate font-medium first-letter:uppercase">{s.label}</span>
                                    <span className="block text-xs text-gray-500">{s.detail}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </article>
        </section>
    );
}
