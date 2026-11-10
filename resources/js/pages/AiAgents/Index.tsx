import { Link, router, usePage } from '@inertiajs/react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel, PlusIcon, formatDay } from '../../components/page/Page';

/*
 * Discussions › Agents IA : les assistants qui répondent automatiquement aux
 * messages WhatsApp avec le fournisseur IA choisi par l'entreprise.
 */

interface AgentRow {
    id: number;
    name: string;
    role: string | null;
    provider: string;
    model: string;
    schedule: string;
    stores: string;
    active: boolean;
    replies_count: number;
    last_reply_at: string | null;
    last_error: string | null;
    last_error_at: string | null;
}

interface PageProps {
    agents: AgentRow[];
    apiEnabled: boolean;
    autoRepliesEnabled: boolean;
    [key: string]: unknown;
}

export const RobotIcon = ({ className = 'h-6 w-6' }: { className?: string }) => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">
        <rect x="5" y="8" width="14" height="10" rx="3" />
        <path d="M12 4v4M9 13h.01M15 13h.01M9 21l1-3M15 21l-1-3" />
    </svg>
);

function AiAgentsIndex() {
    const { agents, apiEnabled, autoRepliesEnabled } = usePage<PageProps>().props;

    const newButton = (label: string) => (
        <Link href="/ai-agents/create" className="mdash-btn brand">
            <PlusIcon />
            {label}
        </Link>
    );

    return (
        <Page title="Agents IA" subtitle="Gérez les assistants IA de votre entreprise et leurs réponses automatiques sur WhatsApp" actions={newButton('Nouvel agent')}>
            {!apiEnabled && (
                <div className="mdash-flash bad">
                    Les agents répondent par l’API WhatsApp de votre établissement. Activez-la dans Paramètres › Entreprise, puis connectez votre numéro dans Paramètres › WhatsApp.
                </div>
            )}

            <Panel>
                {agents.length === 0 ? (
                    <div className="flex flex-col items-center px-4 py-14 text-center">
                        <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-xl bg-brand-50 text-brand-500 dark:bg-white/10">
                            <RobotIcon className="h-8 w-8" />
                        </div>
                        <b className="text-base text-gray-800 dark:text-white/90">Aucun agent IA pour l’instant</b>
                        <p className="mt-2 mb-5 max-w-md text-sm text-gray-500 dark:text-gray-400">
                            Créez votre premier agent IA pour répondre automatiquement aux messages WhatsApp avec le fournisseur IA de votre choix.
                        </p>
                        {newButton('Créer mon premier agent')}
                    </div>
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Agent</th>
                                    <th>Fournisseur</th>
                                    <th>Quand</th>
                                    <th>Établissements</th>
                                    <th className="r">Réponses</th>
                                    <th>Statut</th>
                                    <th className="r">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {agents.map((a) => (
                                    <tr key={a.id} className={a.active ? '' : 'opacity-60'}>
                                        <td className="cell-main">
                                            <b>{a.name}</b>
                                            {a.role && <span>{a.role}</span>}
                                        </td>
                                        <td className="wrap">
                                            {a.provider}
                                            <br />
                                            <span className="text-xs text-gray-500">{a.model}</span>
                                        </td>
                                        <td className="wrap">{a.schedule}</td>
                                        <td className="wrap">{a.stores}</td>
                                        <td className="r num">
                                            {a.replies_count}
                                            {a.last_reply_at && <div className="text-xs text-gray-500">dernière : {formatDay(a.last_reply_at, true)}</div>}
                                        </td>
                                        <td className="wrap">
                                            {a.active ? <span className="font-medium text-success-600">Actif</span> : <span className="text-gray-500">En pause</span>}
                                            {a.last_error && (
                                                <div className="mt-1 text-xs text-error-500" title={a.last_error_at ? formatDay(a.last_error_at, true) : undefined}>
                                                    {a.last_error}
                                                </div>
                                            )}
                                        </td>
                                        <td className="r">
                                            <span className="mpage-rowlinks">
                                                <Link href={`/ai-agents/${a.id}/edit`}>Modifier</Link>
                                                <button type="button" onClick={() => router.post(`/ai-agents/${a.id}/toggle`, {}, { preserveScroll: true })}>
                                                    {a.active ? 'Mettre en pause' : 'Activer'}
                                                </button>
                                                <button
                                                    type="button"
                                                    className="danger"
                                                    onClick={() => confirm(`Supprimer l’agent « ${a.name} » ?`) && router.delete(`/ai-agents/${a.id}`, { preserveScroll: true })}
                                                >
                                                    Supprimer
                                                </button>
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Un agent actif répond aux messages écrits des clients{autoRepliesEnabled ? ' (les boutons du menu des réponses automatiques restent gérés par le robot à règles)' : ''}. S’il ne sait pas
                répondre ou si le client demande une personne, la discussion passe à votre équipe dans « À faire » et l’agent se tait 24 h pour ce client.
            </p>
        </Page>
    );
}

AiAgentsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default AiAgentsIndex;
