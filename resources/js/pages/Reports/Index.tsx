import { usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel } from '../../components/page/Page';

/*
 * Analyses › Rapports : rapports A4 en PDF, générés par le serveur sur le
 * périmètre du rôle. La fiche client a son propre bouton sur la fiche.
 */

type Option = { value: string | number; label: string };

interface PageProps {
    months: Option[];
    stores: Option[];
    types: { key: string; label: string }[];
    canExport: boolean;
    [key: string]: unknown;
}

const DESCRIPTIONS: Record<string, string> = {
    direction: 'CA, clients, enseignes, magasins, familles, segments et fidélité du mois.',
    fidelite: 'Membres, coût des remises, clients proches du seuil ou d’un palier, cartes qui expirent.',
    magasin: 'Les chiffres du mois pour un magasin, ses familles et ses segments de clients.',
    relance: 'Les clients à contacter, segment par segment.',
};

function Reports() {
    const { months, stores, types, canExport } = usePage<PageProps>().props;
    const [month, setMonth] = useState(String(months[1]?.value ?? months[0]?.value));
    const [store, setStore] = useState('');

    const href = (type: string) => `/rapports/${type}?${new URLSearchParams({ month, ...(type === 'magasin' ? { store } : {}) }).toString()}`;

    return (
        <Page title="Rapports" subtitle="Rapports A4 prêts à imprimer ou à envoyer">
            {!canExport ? (
                <Panel>
                    <Empty title="Votre rôle ne permet pas d’exporter des données" />
                </Panel>
            ) : (
                <div className="mdash-grid">
                    <Panel title="Mois du rapport" className="c12">
                        <select aria-label="Mois du rapport" value={month} onChange={(e) => setMonth(e.target.value)}>
                            {months.map((m) => (
                                <option key={m.value} value={m.value}>
                                    {m.label}
                                </option>
                            ))}
                        </select>
                    </Panel>
                    {types.map((t) => (
                        <Panel key={t.key} title={t.label} sub={DESCRIPTIONS[t.key]} className="c6">
                            {t.key === 'magasin' && (
                                <select aria-label="Magasin" value={store} onChange={(e) => setStore(e.target.value)} className="mb-3 block">
                                    <option value="">Choisir un magasin</option>
                                    {stores.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </select>
                            )}
                            {t.key === 'magasin' && !store ? (
                                <span className="mdash-btn ghost" aria-disabled="true">
                                    Télécharger le PDF
                                </span>
                            ) : (
                                <a className="mdash-btn brand" href={href(t.key)}>
                                    Télécharger le PDF
                                </a>
                            )}
                        </Panel>
                    ))}
                </div>
            )}
        </Page>
    );
}

Reports.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default Reports;
