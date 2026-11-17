import { useForm, usePage } from '@inertiajs/react';

import { Page, Panel } from '../../components/page/Page';
import ShareLink from '../../components/share/ShareLink';
import AppLayout from '../../layout/AppLayout';

/* Paramètres › Ma page en ligne : les choix « Que souhaitez-vous ? » et le partage du lien. */

interface Props {
    enabled: boolean;
    available: { key: string; label: string; sub: string; stage_label: string }[];
    selected: string[];
    url: string;
    rideUrl: string | null;
    name: string;
    [key: string]: unknown;
}

function OnlinePageSettings() {
    const { enabled, available, selected, url, rideUrl, name } = usePage<Props>().props;
    const { data, setData, put, processing } = useForm({ enabled, choices: selected });
    const toggle = (key: string) => setData('choices', data.choices.includes(key) ? data.choices.filter((k) => k !== key) : [...data.choices, key]);

    return (
        <Page title="Ma page en ligne" subtitle="Le lien que vos clients ouvrent depuis WhatsApp, vos statuts, vos réseaux sociaux ou votre carte digitale">
            <Panel title="Partager mon lien">
                <div className="space-y-5">
                    <ShareLink url={url} label="Lien de votre entreprise" message={`Bonjour ! Réservez ou posez votre question à ${name} ici :`} filename="lien-entreprise.png" />
                    {rideUrl && <ShareLink url={rideUrl} label="Lien des courses et transferts" message={`Besoin d’une course ou d’un transfert ? ${name} :`} filename="lien-courses.png" />}
                </div>
            </Panel>
            <Panel title="Que souhaitez-vous ?" sub="Les choix proposés à vos clients, selon leur étape (découverte, intéressé, rendez-vous ou essai, commande)">
                <form
                    className="space-y-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        put('/settings/online-page', { preserveScroll: true });
                    }}
                >
                    <label className="flex items-center gap-2 font-medium">
                        <input type="checkbox" checked={data.enabled} onChange={(e) => setData('enabled', e.target.checked)} />
                        Afficher « Que souhaitez-vous ? » en page d’accueil du lien (sinon, le lien ouvre directement la prise de rendez-vous)
                    </label>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {available.map((c) => (
                            <label key={c.key} className={`flex items-start gap-2 rounded-xl border p-3 ${data.choices.includes(c.key) ? 'border-brand-300 bg-brand-50' : 'border-gray-200'}`}>
                                <input type="checkbox" className="mt-1" checked={data.choices.includes(c.key)} onChange={() => toggle(c.key)} />
                                <span>
                                    <b className="block">{c.label}</b>
                                    <span className="text-xs text-gray-500">
                                        {c.sub} · étape {c.stage_label}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>
                    <p className="text-xs text-gray-500">Chaque demande crée la fiche client (source « Lien en ligne ») et une tâche dans « À faire ». Avec une carte digitale, la demande est attribuée au collaborateur de la carte.</p>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        Enregistrer
                    </button>
                </form>
            </Panel>
        </Page>
    );
}

OnlinePageSettings.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default OnlinePageSettings;
