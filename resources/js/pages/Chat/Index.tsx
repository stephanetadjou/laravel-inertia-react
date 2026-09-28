import { Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import Badge from '../../components/ui/badge/Badge';

interface ContactSummary {
    id: number;
    name: string;
    store_name: string;
    last_message_preview: string | null;
    last_message_label: string | null;
    message_count: number;
}

interface SelectedContact {
    id: number;
    name: string;
    phone_e164: string;
    store_name: string;
}

interface MessageRow {
    id: number;
    body: string;
    status: string;
    error_message: string | null;
    sent_at: string;
}

interface PageProps {
    contacts: ContactSummary[];
    selectedContact: SelectedContact | null;
    messages: MessageRow[];
    [key: string]: unknown;
}

const statusColor: Record<string, 'success' | 'error' | 'light'> = {
    sent: 'success',
    failed: 'error',
    pending: 'light',
};

function initials(name: string) {
    return name
        .split(' ')
        .map((part) => part[0])
        .filter(Boolean)
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

function ChatIndex() {
    const { contacts, selectedContact, messages } = usePage<PageProps>().props;
    const [search, setSearch] = useState('');

    const filteredContacts = useMemo(
        () => contacts.filter((contact) => contact.name.toLowerCase().includes(search.toLowerCase())),
        [contacts, search],
    );

    return (
        <>
            <Head title="Chat" />

            <PageBreadcrumb pageTitle="Chat" />

            <div className="flex h-[75vh] overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div className="flex w-80 shrink-0 flex-col border-r border-gray-100 dark:border-gray-800">
                    <div className="border-b border-gray-100 p-4 dark:border-gray-800">
                        <h3 className="mb-3 text-base font-semibold text-gray-800 dark:text-white/90">Chats</h3>
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Rechercher un contact..."
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90"
                        />
                    </div>
                    <div className="flex-1 overflow-y-auto">
                        {filteredContacts.length === 0 && <p className="p-4 text-sm text-gray-400 dark:text-gray-500">Aucun contact.</p>}
                        {filteredContacts.map((contact) => (
                            <Link
                                key={contact.id}
                                href={`/chat/${contact.id}`}
                                className={`flex items-center gap-3 border-b border-gray-50 px-4 py-3 transition hover:bg-gray-50 dark:border-gray-800/60 dark:hover:bg-white/5 ${
                                    selectedContact?.id === contact.id ? 'bg-brand-50 dark:bg-brand-500/10' : ''
                                }`}
                            >
                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-600 dark:bg-brand-500/20 dark:text-brand-400">
                                    {initials(contact.name)}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="flex items-center justify-between gap-2">
                                        <span className="truncate text-sm font-medium text-gray-800 dark:text-white/90">{contact.name}</span>
                                        {contact.last_message_label && (
                                            <span className="shrink-0 text-xs text-gray-400">{contact.last_message_label}</span>
                                        )}
                                    </span>
                                    <span className="block truncate text-xs text-gray-500 dark:text-gray-400">
                                        {contact.last_message_preview ?? `Aucun message · ${contact.store_name}`}
                                    </span>
                                </span>
                            </Link>
                        ))}
                    </div>
                </div>

                <div className="flex flex-1 flex-col">
                    {!selectedContact ? (
                        <div className="flex flex-1 items-center justify-center text-sm text-gray-400 dark:text-gray-500">
                            Sélectionnez un contact pour voir son historique de messages.
                        </div>
                    ) : (
                        <>
                            <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-10 w-10 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-600 dark:bg-brand-500/20 dark:text-brand-400">
                                        {initials(selectedContact.name)}
                                    </span>
                                    <div>
                                        <p className="text-sm font-semibold text-gray-800 dark:text-white/90">{selectedContact.name}</p>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">
                                            {selectedContact.phone_e164} · {selectedContact.store_name}
                                        </p>
                                    </div>
                                </div>
                                <Link href={`/contacts/${selectedContact.id}`} className="text-sm font-medium text-brand-500 hover:text-brand-600">
                                    Voir la fiche contact
                                </Link>
                            </div>

                            <div className="border-b border-warning-500/30 bg-warning-50 px-5 py-2 text-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                                Historique des envois de campagne uniquement. Les réponses du client ne sont pas encore captées (webhook Meta à
                                venir).
                            </div>

                            <div className="flex-1 space-y-3 overflow-y-auto p-5">
                                {messages.length === 0 && (
                                    <p className="text-center text-sm text-gray-400 dark:text-gray-500">
                                        Aucun message envoyé à ce contact pour l'instant.
                                    </p>
                                )}
                                {messages.map((message) => (
                                    <div key={message.id} className="flex justify-end">
                                        <div className="max-w-md">
                                            <div className="rounded-2xl rounded-tr-sm bg-brand-500 px-4 py-2.5 text-sm text-white">
                                                {message.body}
                                            </div>
                                            <div className="mt-1 flex items-center justify-end gap-2 text-xs text-gray-400 dark:text-gray-500">
                                                <Badge size="sm" color={statusColor[message.status] ?? 'light'}>
                                                    {message.status}
                                                </Badge>
                                                <span>{message.sent_at}</span>
                                            </div>
                                            {message.error_message && (
                                                <p className="mt-1 text-right text-xs text-error-500">{message.error_message}</p>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}

ChatIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ChatIndex;
