import { Link, usePage } from '@inertiajs/react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel } from '../../components/page/Page';

/*
 * « Automatisations » d'une application : ce que ClientlyX fait tout seul pour
 * vos clients (WhatsApp, IA). Offre Gratuit : tout reste manuel (liens wa.me).
 */

interface Item {
    key: string;
    label: string;
    description: string;
    channel: 'whatsapp' | 'ia';
    enabled: boolean;
    pack: string;
    allowed: boolean;
}

interface PageProps {
    app: string;
    items: Item[];
    canManage: boolean;
    [key: string]: unknown;
}

const CHANNEL = { whatsapp: { label: 'WhatsApp', cls: 'bg-green-50 text-green-700' }, ia: { label: 'IA', cls: 'bg-fuchsia-50 text-fuchsia-700' } };

function Automations() {
    const { items, canManage } = usePage<PageProps>().props;

    return (
        <Page title="Automatisations" subtitle="Ce que ClientlyX fait tout seul pour vos clients, ici">
            <Panel>
                {items.length === 0 ? (
                    <Empty title="Pas d’automatisation pour cette application">Les relances restent disponibles dans « À faire ».</Empty>
                ) : (
                    <ul className="divide-y divide-gray-100 dark:divide-gray-800">
                        {items.map((item) => (
                            <li key={item.key} className="flex flex-wrap items-center gap-3 py-4">
                                <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${CHANNEL[item.channel].cls}`}>{CHANNEL[item.channel].label}</span>
                                <span className="min-w-0 flex-1">
                                    <span className="block font-semibold text-gray-900 dark:text-white">{item.label}</span>
                                    <span className="block text-sm text-gray-500">{item.description}</span>
                                </span>
                                {!item.allowed ? (
                                    <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">🔒 Pack {item.pack}</span>
                                ) : item.enabled ? (
                                    <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">● Active</span>
                                ) : canManage ? (
                                    <Link href="/settings/modules" className="mdash-btn brand sm">
                                        Activer
                                    </Link>
                                ) : (
                                    <span className="text-xs text-gray-500">Inactive</span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </Page>
    );
}

Automations.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default Automations;
