import { Head } from '@inertiajs/react';

import RideRequestForm, { PublicFare } from '../../components/rides/RideRequestForm';
import '../Booking/booking.css';

/* Page publique « Demander une course » (/rdv/{slug}/course), sans compte. */

interface Props {
    organization: { name: string; slug: string };
    stores: { id: number; name: string; city: string | null }[];
    fares: PublicFare[];
    sent: boolean;
}

export default function RideRequest({ organization, stores, fares, sent }: Props) {
    return (
        <div className="mbook">
            <Head title={`Demander une course · ${organization.name}`} />
            <header className="mbook-head">
                <span className="mbook-logo">{organization.name.slice(0, 1).toUpperCase()}</span>
                <div>
                    <h1>{organization.name}</h1>
                    <p>Course ou transfert (aéroport, hôtel, domicile) : demandez en une minute.</p>
                </div>
            </header>
            <div className="mbook-form">
                {sent ? (
                    <section className="mbook-step text-center">
                        <h2>Demande reçue ✅</h2>
                        <p>Merci ! L’équipe de {organization.name} vous confirme la course sur WhatsApp, avec le nom du chauffeur et le véhicule.</p>
                        <a href={`/rdv/${organization.slug}/course`} className="mt-3 inline-block font-semibold underline">
                            Faire une autre demande
                        </a>
                    </section>
                ) : (
                    <section className="mbook-step">
                        <RideRequestForm action={`/rdv/${organization.slug}/course`} fares={fares} stores={stores} />
                    </section>
                )}
            </div>
        </div>
    );
}
