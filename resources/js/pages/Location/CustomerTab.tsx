import { usePage } from '@inertiajs/react';

import { fmt, money } from './shared';

/* Onglet « Location » de la fiche client (module location). */

export interface LocationModuleProps {
    account: { discount_percent: number; day_rate: number | null; notes: string | null } | null;
    revenue: number;
    requests: { id: number; summary: string; status_label: string; lost: string | null; created_at: string }[];
    bookings: { id: number; vehicle: string; starts_at: string; ends_at: string; status_label: string; amount: number; balance: number; deposit_label: string | null; deposit_retained: number | null }[];
    sales: { id: number; vehicle: string; status_label: string; offer_amount: number | null }[];
}

export default function LocationCustomerTab() {
    const m = usePage<{ locationModule: LocationModuleProps }>().props.locationModule;

    return (
        <div className="space-y-4 text-sm">
            <p>
                Locations : <b>{m.bookings.length}</b> · CA : <b>{money(m.revenue)}</b>
                {m.account && <> · Compte entreprise : {m.account.day_rate ? `${money(m.account.day_rate)} / jour` : `-${m.account.discount_percent} %`}</>}
            </p>
            {m.bookings.length > 0 && (
                <ul className="divide-y divide-gray-100 dark:divide-white/5">
                    {m.bookings.map((b) => (
                        <li key={b.id} className="py-2">
                            <span className="font-medium">{b.vehicle}</span> · {b.status_label} · {money(b.amount)}
                            <span className="block text-xs text-gray-500">
                                {fmt(b.starts_at)} → {fmt(b.ends_at)}
                                {b.balance > 0 ? ` · reste ${money(b.balance)}` : ''}
                                {b.deposit_label ? ` · ${b.deposit_label}` : ''}
                                {b.deposit_retained ? ` (${money(b.deposit_retained)} retenus)` : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {m.requests.length > 0 && (
                <div>
                    <h3 className="mb-1 font-semibold">Demandes</h3>
                    <ul className="space-y-1">
                        {m.requests.map((r) => (
                            <li key={r.id}>
                                {r.summary} · <span className="text-gray-500">{r.status_label}{r.lost ? ` (${r.lost})` : ''}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {m.sales.length > 0 && (
                <div>
                    <h3 className="mb-1 font-semibold">Achats de véhicules</h3>
                    <ul className="space-y-1">
                        {m.sales.map((s) => (
                            <li key={s.id}>
                                {s.vehicle} · {s.status_label}
                                {s.offer_amount ? ` · ${money(s.offer_amount)}` : ''}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
