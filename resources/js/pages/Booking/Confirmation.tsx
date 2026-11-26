import { Head, Link, router } from '@inertiajs/react';

import { MenentiaSymbol } from '../../components/common/MenentiaLogo';
import { intlLocale, locale, t } from '../../lib/i18n';
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
        staff_name: string | null;
        cancellable: boolean;
    };
    whatsappUrl: string | null;
    token: string;
}

/** Récapitulatif d'une réservation en ligne : lien WhatsApp vers l'établissement, annulation. */
export default function BookingConfirmation({ organization, appointment: a, whatsappUrl, token }: PageProps) {
    const day = new Date(a.starts_at).toLocaleDateString(intlLocale(), { weekday: 'long', day: 'numeric', month: 'long' });
    const when = day.charAt(0).toUpperCase() + day.slice(1);
    const time = locale() === 'en' ? a.starts_at.slice(11, 16) : a.starts_at.slice(11, 16).replace(':', 'h');
    const cancelled = a.status === 'cancelled';

    function cancel() {
        if (confirm(t('Annuler ce rendez-vous ?'))) {
            router.post(`/rdv/${organization.slug}/c/${token}/annuler`);
        }
    }

    return (
        <div className="mbook">
            <Head title={`${t('Votre rendez-vous')} · ${organization.name}`} />

            <div className="mbook-done">
                <span className={`mbook-badge ${cancelled ? 'off' : ''}`} aria-hidden="true">
                    {cancelled ? '×' : '✓'}
                </span>
                <h1>{cancelled ? t('Rendez-vous annulé') : a.status === 'requested' ? t('Demande envoyée') : t('Rendez-vous confirmé')}</h1>
                <p className="mbook-muted">
                    {cancelled
                        ? t('Votre rendez-vous a bien été annulé.')
                        : a.status === 'requested'
                          ? t(':name va confirmer votre rendez-vous.', { name: organization.name })
                          : t(':name vous attend.', { name: organization.name })}
                </p>

                <dl className="mbook-recap">
                    <dt>{t('Quand')}</dt>
                    <dd>
                        {when} {t('à')} {time}
                    </dd>
                    <dt>{t('Où')}</dt>
                    <dd>{a.store_name}</dd>
                    {a.service && (
                        <>
                            <dt>{t('Prestation')}</dt>
                            <dd>{a.service}</dd>
                        </>
                    )}
                    {a.price > 0 && (
                        <>
                            <dt>{t('Prix')}</dt>
                            <dd>{a.price.toLocaleString(intlLocale())} F</dd>
                        </>
                    )}
                    {a.staff_name && (
                        <>
                            <dt>{t('Avec')}</dt>
                            <dd>{a.staff_name}</dd>
                        </>
                    )}
                    <dt>{t('Au nom de')}</dt>
                    <dd>{a.customer_name}</dd>
                </dl>

                {!cancelled && whatsappUrl && (
                    <a className="mbook-wa" href={whatsappUrl} target="_blank" rel="noopener noreferrer">
                        {t('Recevoir le récapitulatif sur WhatsApp')}
                    </a>
                )}
                {a.cancellable && (
                    <button type="button" className="mbook-link" onClick={cancel}>
                        {t('Annuler le rendez-vous')}
                    </button>
                )}
                <Link className="mbook-link" href={`/rdv/${organization.slug}`}>
                    {t('Prendre un autre rendez-vous')}
                </Link>
                <p className="mbook-muted small">{t('Gardez cette page : son adresse permet d’annuler le rendez-vous.')}</p>
            </div>

            <footer className="mbook-foot">
                <MenentiaSymbol className="h-4 w-4" /> {t('Réservation par ClientlyX')}
            </footer>
        </div>
    );
}
