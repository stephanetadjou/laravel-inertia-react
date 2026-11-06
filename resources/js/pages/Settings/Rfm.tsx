import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel } from '../../components/page/Page';

type Settings = Record<'period_months' | 'active_min_r' | 'champions_min_r' | 'champions_min_fm' | 'loyal_min_fm' | 'cant_lose_min_fm' | 'at_risk_min_fm' | 'version', number>;

interface PageProps {
    settings: Settings;
    defaults: Settings;
    segments: { key: string; label: string; action: string }[];
    [key: string]: unknown;
}

const FIELDS: { key: keyof Settings; label: string; help: string }[] = [
    { key: 'active_min_r', label: 'Client « actif » à partir de la récence', help: 'R au moins égal : Champions, Fidèles, Potentiels, Nouveaux. En dessous : À risque, Hibernants, Perdus…' },
    { key: 'champions_min_r', label: 'Champions : récence minimale', help: 'Note R de 1 à 5' },
    { key: 'champions_min_fm', label: 'Champions : fréquence / montant minimum', help: 'Moyenne des notes F et M' },
    { key: 'loyal_min_fm', label: 'Clients fidèles : fréquence / montant minimum', help: 'Sinon : Potentiels' },
    { key: 'cant_lose_min_fm', label: 'À ne pas perdre : fréquence / montant minimum', help: 'Gros clients absents depuis longtemps (R = 1)' },
    { key: 'at_risk_min_fm', label: 'À risque : fréquence / montant minimum', help: 'Bons clients qui s’éloignent' },
];

function RfmSettings() {
    const { settings, defaults, segments } = usePage<PageProps>().props;
    const { data, setData, put, processing, errors } = useForm({ ...settings });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/settings/rfm', { preserveScroll: true });
    };

    return (
        <Page title="Segments RFM" subtitle={`Comment NENTIX classe vos clients (règles version ${settings.version}), pour le groupe et pour chaque marque`}>
            <div className="mdash-grid">
                <Panel title="Règles" className="c7">
                    <form onSubmit={submit} className="mpage-form" style={{ padding: '0 18px 18px' }}>
                        <div className="mpage-field">
                            <label htmlFor="period">Période d’analyse</label>
                            <select id="period" value={data.period_months} onChange={(e) => setData('period_months', Number(e.target.value))}>
                                <option value={0}>Tout l’historique</option>
                                <option value={6}>6 derniers mois</option>
                                <option value={12}>12 derniers mois</option>
                                <option value={24}>24 derniers mois</option>
                                <option value={36}>36 derniers mois</option>
                            </select>
                            <small>Seuls les achats de cette période comptent dans la récence, la fréquence et le montant.</small>
                        </div>
                        <div className="mpage-form-grid">
                            {FIELDS.map((f) => (
                                <div key={f.key} className="mpage-field">
                                    <label htmlFor={f.key}>{f.label}</label>
                                    <input id={f.key} type="number" min={1} max={5} value={data[f.key]} onChange={(e) => setData(f.key, Number(e.target.value))} />
                                    <small>
                                        {f.help} (défaut {defaults[f.key]})
                                    </small>
                                    {errors[f.key] && <span className="err">{errors[f.key]}</span>}
                                </div>
                            ))}
                        </div>
                        <div className="mpage-form-actions">
                            <button type="button" className="mdash-btn ghost" onClick={() => setData({ ...defaults, version: settings.version })}>
                                Revenir aux valeurs par défaut
                            </button>
                            <button type="submit" className="mdash-btn brand" disabled={processing}>
                                {processing ? 'Recalcul…' : 'Enregistrer et recalculer'}
                            </button>
                        </div>
                    </form>
                </Panel>
                <Panel title="Les 8 segments" sub="R, F, M : notes de 1 à 5, le client comparé aux autres acheteurs" className="c5">
                    <ul className="mpage-list">
                        {segments
                            .filter((s) => s.key !== 'none')
                            .map((s) => (
                                <li key={s.key}>
                                    <b>{s.label}</b> — <span className="mpage-muted">{s.action}</span>
                                </li>
                            ))}
                    </ul>
                </Panel>
            </div>
        </Page>
    );
}

RfmSettings.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default RfmSettings;
