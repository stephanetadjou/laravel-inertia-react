/*
 * Réglages de base des modules cochés (inscription et Paramètres › Modules) :
 * capacité et rattrapage des cours, période d'abonnement et saison, jour
 * d'échéance et relances des tranches, libellé des personnes suivies.
 */

export interface ModuleSettingsValues {
    seances_groupe: { capacity: number; makeup_after: number; makeup_weekday: number };
    abonnements: { period: 'month' | 'season'; season_start: string | null; season_end: string | null };
    tranches: { due_day: number; reminders: number[] };
    beneficiaires: { label: string; minor_needs_responsible: boolean };
    fidelite: { points_per_attendance: number };
}

export const WEEKDAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

function Label({ text, hint, error, children }: { text: string; hint?: string; error?: string; children: React.ReactNode }) {
    return (
        <label className="block text-sm">
            <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">{text}</span>
            {children}
            {hint && !error && <span className="mt-1 block text-xs text-gray-500">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-error-500">{error}</span>}
        </label>
    );
}

export function ModuleSettingsFields({
    enabled,
    values,
    onChange,
    beneficiaryLabels,
    errors = {},
    className = 'grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4',
}: {
    enabled: string[];
    values: ModuleSettingsValues;
    onChange: (values: ModuleSettingsValues) => void;
    beneficiaryLabels: { value: string; label: string }[];
    errors?: Record<string, string>;
    className?: string;
}) {
    const set = <K extends keyof ModuleSettingsValues>(module: K, patch: Partial<ModuleSettingsValues[K]>) => onChange({ ...values, [module]: { ...values[module], ...patch } });
    const err = (key: string) => errors[`settings.${key}`];
    const blocks: React.ReactNode[] = [];

    if (enabled.includes('seances_groupe')) {
        blocks.push(
            <fieldset key="seances" className="space-y-3">
                <legend className="mb-1 text-xs font-semibold tracking-wide text-gray-500 uppercase">Cours et séances de groupe</legend>
                <Label text="Places par cours" hint="capacité proposée à la création d’un cours" error={err('seances_groupe.capacity')}>
                    <input type="number" min={1} className={field} value={values.seances_groupe.capacity} onChange={(e) => set('seances_groupe', { capacity: Number(e.target.value) })} />
                </Label>
                <Label text="Rattrapage proposé après" hint="séances annulées d’un même cours" error={err('seances_groupe.makeup_after')}>
                    <input type="number" min={1} className={field} value={values.seances_groupe.makeup_after} onChange={(e) => set('seances_groupe', { makeup_after: Number(e.target.value) })} />
                </Label>
                <Label text="Jour des rattrapages">
                    <select className={field} value={values.seances_groupe.makeup_weekday} onChange={(e) => set('seances_groupe', { makeup_weekday: Number(e.target.value) })}>
                        {WEEKDAYS.map((d, i) => (
                            <option key={d} value={i + 1}>
                                {d}
                            </option>
                        ))}
                    </select>
                </Label>
            </fieldset>,
        );
    }

    if (enabled.includes('abonnements')) {
        blocks.push(
            <fieldset key="abonnements" className="space-y-3">
                <legend className="mb-1 text-xs font-semibold tracking-wide text-gray-500 uppercase">Formules et abonnements</legend>
                <Label text="Période habituelle">
                    <select className={field} value={values.abonnements.period} onChange={(e) => set('abonnements', { period: e.target.value as 'month' | 'season' })}>
                        <option value="month">Au mois</option>
                        <option value="season">À la saison</option>
                    </select>
                </Label>
                <div className="grid grid-cols-2 gap-2">
                    <Label text="Début de saison" error={err('abonnements.season_start')}>
                        <input type="date" className={field} value={values.abonnements.season_start ?? ''} onChange={(e) => set('abonnements', { season_start: e.target.value })} />
                    </Label>
                    <Label text="Fin de saison" error={err('abonnements.season_end')}>
                        <input type="date" className={field} value={values.abonnements.season_end ?? ''} onChange={(e) => set('abonnements', { season_end: e.target.value })} />
                    </Label>
                </div>
            </fieldset>,
        );
    }

    if (enabled.includes('tranches') || enabled.includes('abonnements')) {
        blocks.push(
            <fieldset key="tranches" className="space-y-3">
                <legend className="mb-1 text-xs font-semibold tracking-wide text-gray-500 uppercase">Échéances</legend>
                <Label text="Jour d’échéance du mois" hint="du 1 au 28" error={err('tranches.due_day')}>
                    <input type="number" min={1} max={28} className={field} value={values.tranches.due_day} onChange={(e) => set('tranches', { due_day: Number(e.target.value) })} />
                </Label>
                <Label text="Relances (jours par rapport à l’échéance)" hint="ex. -2, 0, 7 : deux jours avant, le jour même, une semaine après" error={err('tranches.reminders')}>
                    <input
                        className={field}
                        defaultValue={values.tranches.reminders.join(', ')}
                        onChange={(e) =>
                            set('tranches', {
                                reminders: e.target.value
                                    .split(/[\s,;]+/)
                                    .filter((v) => v !== '' && !Number.isNaN(Number(v)))
                                    .map(Number),
                            })
                        }
                    />
                </Label>
            </fieldset>,
        );
    }

    if (enabled.includes('beneficiaires')) {
        blocks.push(
            <fieldset key="beneficiaires" className="space-y-3">
                <legend className="mb-1 text-xs font-semibold tracking-wide text-gray-500 uppercase">Personnes suivies</legend>
                <Label text="On les appelle">
                    <select className={field} value={values.beneficiaires.label} onChange={(e) => set('beneficiaires', { label: e.target.value })}>
                        {beneficiaryLabels.map((l) => (
                            <option key={l.value} value={l.value}>
                                {l.label}
                            </option>
                        ))}
                    </select>
                </Label>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={values.beneficiaires.minor_needs_responsible} onChange={(e) => set('beneficiaires', { minor_needs_responsible: e.target.checked })} />
                    Responsable obligatoire pour un mineur
                </label>
            </fieldset>,
        );
    }

    if (enabled.includes('fidelite') && enabled.includes('seances_groupe')) {
        blocks.push(
            <fieldset key="fidelite" className="space-y-3">
                <legend className="mb-1 text-xs font-semibold tracking-wide text-gray-500 uppercase">Fidélité</legend>
                <Label text="Points par présence à un cours" hint="0 = les points ne viennent que des achats" error={err('fidelite.points_per_attendance')}>
                    <input type="number" min={0} className={field} value={values.fidelite.points_per_attendance} onChange={(e) => set('fidelite', { points_per_attendance: Number(e.target.value) })} />
                </Label>
            </fieldset>,
        );
    }

    if (blocks.length === 0) {
        return <p className="text-sm text-gray-500">Aucun réglage pour les modules cochés.</p>;
    }

    return <div className={className}>{blocks}</div>;
}
