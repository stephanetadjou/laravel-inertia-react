import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { Panel } from '../../components/page/Page';

export interface LifecycleSettings {
    new_customer_days: number;
    at_risk_days: number;
    churn_days: number;
    vip_top_percent: number;
    vip_period_days: number;
}

const FIELDS: { key: keyof LifecycleSettings; label: string; unit: string; help: string }[] = [
    { key: 'new_customer_days', label: 'Nouveau client', unit: 'jours', help: 'Reste « nouveau » pendant ce délai après son 1er achat.' },
    { key: 'at_risk_days', label: 'À risque', unit: 'jours', help: 'Sans achat depuis plus de ce délai.' },
    { key: 'churn_days', label: 'Inactif', unit: 'jours', help: 'Sans achat depuis plus de ce délai.' },
    { key: 'vip_top_percent', label: 'VIP (par boutique)', unit: '% des acheteurs', help: 'Les X % d’acheteurs au plus fort CA de la boutique (0 = pas de VIP).' },
    { key: 'vip_period_days', label: 'Période VIP', unit: 'jours', help: 'CA de la boutique pris en compte pour le classement VIP.' },
];

/**
 * Seuils du parcours client de l'organisation (Contact → Prospect → Nouveau
 * → Actif → À risque → Inactif) et du statut VIP, calculé boutique par
 * boutique. L'enregistrement recalcule les fiches.
 */
export default function LifecycleSettingsCard({ settings, defaults }: { settings: LifecycleSettings; defaults: LifecycleSettings }) {
    const { data, setData, put, processing, errors } = useForm<LifecycleSettings>({ ...settings });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/organisation/lifecycle', { preserveScroll: true });
    };

    return (
        <Panel title="Parcours client" sub="Seuils qui classent chaque fiche client ; recalcul à l'enregistrement puis chaque nuit">
            <form onSubmit={submit} className="mpage-form">
                <div className="mpage-form-grid">
                    {FIELDS.map((f) => (
                        <div key={f.key} className="mpage-field">
                            <label htmlFor={`lc_${f.key}`}>{f.label}</label>
                            <span className="unit">
                                <input id={`lc_${f.key}`} type="number" min={0} value={data[f.key]} onChange={(e) => setData(f.key, Number(e.target.value))} />
                                {f.unit}
                            </span>
                            <small>
                                {f.help} Par défaut : {defaults[f.key]}.
                            </small>
                            {errors[f.key] && <span className="err">{errors[f.key]}</span>}
                        </div>
                    ))}
                </div>
                <div className="mpage-form-actions">
                    <button type="button" className="mdash-btn ghost" onClick={() => setData({ ...defaults })}>
                        Valeurs par défaut
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        {processing ? 'Recalcul…' : 'Enregistrer et recalculer'}
                    </button>
                </div>
            </form>
        </Panel>
    );
}
