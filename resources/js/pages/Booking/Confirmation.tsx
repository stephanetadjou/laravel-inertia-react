import { Head, Link, router } from '@inertiajs/react';

import { MenentiaSymbol } from '../../components/common/MenentiaLogo';
import './booking.css';

interface PageProps {
    organization: { name: string; slug: string };
    appointment: {
        service: string | null;
        starts_at: string;
        duration_minutes: number;
        store_name: string;
        status: string;
        price: number;
        customer_name: string;
        cancellable: boolean;
    };
    whatsappUrl: string | null;
    token: string;
}

/** Récapitulatif d'une réservation en ligne : lien WhatsApp vers l'établissement, annulation. */
export default function BookingConfirmation({ organization, appointment: a, whatsappUrl, token }: PageProps) {
    const day = new Date(a.starts_at).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
    const when = day.charAt(0).toUpperCase() + day.slice(1);
    const time = a.starts_at.slice(11, 16).replace(':', 'h');
    const cancelled = a.status === 'cancelled';

    function cancel() {
        if (confirm('Annuler ce rendez-vous ?')) {
            router.post(`/rdv/${organization.slug}/c/${token}/annuler`);
        }
    }

    return (
        <div className="mbook">
            <Head title={`Votre rendez-vous · ${organization.name}`} />

            <div className="mbook-done">
                <span className={`mbook-badge ${cancelled ? 'off' : ''}`} aria-hidden="true">
                    {cancelled ? '×' : '✓'}
                </span>
                <h1>{cancelled ? 'Rendez-vous annulé' : a.status === 'requested' ? 'Demande envoyée' : 'Rendez-vous confirmé'}</h1>
                <p className="mbook-muted">
                    {cancelled
                        ? 'Votre rendez-vous a bien été annulé.'
                        : a.status === 'requested'
                          ? `${organization.name} va confirmer votre rendez-vous.`
                          : `${organization.name} vous attend.`}
                </p>

                <dl className="mbook-recap">
                    <dt>Quand</dt>
                    <dd>
                        {when} à {time}
                    </dd>
                    <dt>Où</dt>
                    <dd>{a.store_name}</dd>
                    {a.service && (
                        <>
                            <dt>Prestation</dt>
                            <dd>{a.service}</dd>
                        </>
                    )}
                    {a.price > 0 && (
                        <>
                            <dt>Prix</dt>
                            <dd>{a.price.toLocaleString('fr-FR')} F</dd>
                        </>
                    )}
                    <dt>Au nom de</dt>
                    <dd>{a.customer_name}</dd>
                </dl>

                {!cancelled && whatsappUrl && (
                    <a className="mbook-wa" href={whatsappUrl} target="_blank" rel="noopener noreferrer">
                        Recevoir le récapitulatif sur WhatsApp
                    </a>
                )}
                {a.cancellable && (
                    <button type="button" className="mbook-link" onClick={cancel}>
                        Annuler le rendez-vous
                    </button>
                )}
                <Link className="mbook-link" href={`/rdv/${organization.slug}`}>
                    Prendre un autre rendez-vous
                </Link>
                <p className="mbook-muted small">Gardez cette page : son adresse permet d’annuler le rendez-vous.</p>
            </div>

            <footer className="mbook-foot">
                <MenentiaSymbol className="h-4 w-4" /> Réservation par Menentia
            </footer>
        </div>
    );
}
