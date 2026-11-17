/* Devis et factures : pastilles de statut et petits utilitaires partagés. */

export const STATUS_TONE: Record<string, string> = {
    draft: 'info',
    sent: 'info',
    accepted: 'ok',
    invoiced: 'ok',
    refused: 'bad',
    expired: 'warn',
    open: 'info',
    partial: 'warn',
    paid: 'ok',
    cancelled: 'bad',
    overdue: 'bad',
};

export const money = (n: number) => `${new Intl.NumberFormat('fr-FR').format(n)} F`;

export interface DocumentRow {
    id: number;
    type: 'quote' | 'invoice' | 'credit';
    kind: string;
    type_label: string;
    number: string;
    title: string | null;
    status: string;
    status_label: string;
    issued_on: string;
    due_on: string | null;
    total: number;
    paid_amount: number;
    balance: number;
    customer: { id: number; name: string | null; stage: string | null };
    store_name: string | null;
    editable: boolean;
    reminder_url: string | null;
    last_reminder_at: string | null;
}
