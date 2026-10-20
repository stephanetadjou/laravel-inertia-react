import { Head, Link } from '@inertiajs/react';

import { MenentiaSymbol } from '../../components/common/MenentiaLogo';
import './booking.css';

interface PageProps {
    organization: { name: string };
    reason: 'closed' | 'no_services';
    /** Membre connecté de l'entreprise : on lui indique le réglage à faire. */
    isMember: boolean;
}

/** Page de réservation pas encore ouverte (ou sans prestation réservable). */
export default function BookingClosed({ organization, reason, isMember }: PageProps) {
    return (
        <div className="mbook">
            <Head title={`Prendre rendez-vous · ${organization.name}`} />

            <div className="mbook-done">
                <span className="mbook-badge off" aria-hidden="true">
                    ⏳
                </span>
                <h1>{organization.name}</h1>
                <p className="mbook-muted">La prise de rendez-vous en ligne n’est pas encore disponible. Contactez directement l’établissement.</p>

                {isMember && (
                    <div className="mbook-recap" style={{ display: 'block' }}>
                        <b>Pour ouvrir cette page</b>
                        {reason === 'closed' ? (
                            <p className="mbook-muted" style={{ margin: '6px 0 0' }}>
                                Allez dans Paramètres › Réservation en ligne, cochez « Page de réservation ouverte » puis enregistrez. Vérifiez aussi les horaires de chaque
                                établissement.
                            </p>
                        ) : (
                            <p className="mbook-muted" style={{ margin: '6px 0 0' }}>
                                La page est ouverte mais aucune prestation n’est réservable : ajoutez vos prestations dans Ventes › Catalogue (case « Réservable en ligne »).
                            </p>
                        )}
                        <Link className="mbook-link" href={reason === 'closed' ? '/settings/booking' : '/catalog'}>
                            {reason === 'closed' ? 'Ouvrir les réglages' : 'Ouvrir le catalogue'}
                        </Link>
                    </div>
                )}
            </div>

            <footer className="mbook-foot">
                <MenentiaSymbol className="h-4 w-4" /> Réservation par Menentia
            </footer>
        </div>
    );
}
