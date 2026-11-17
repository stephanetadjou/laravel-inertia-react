import type { Metadata } from 'next';

/* « Gestion de flotte » est un module inclus dans « Location et vente de véhicules » : l'ancienne page renvoie vers celle-ci. */

export const metadata: Metadata = {
  title: 'Gestion de flotte',
  robots: { index: false },
  alternates: { canonical: '/activites/vehicules' },
};

export default function FlotteRedirect() {
  return (
    <>
      <meta httpEquiv="refresh" content="0; url=/activites/vehicules" />
      <section className="mx-auto max-w-2xl px-4 py-24 text-center">
        <p>
          La gestion de flotte fait partie de « Location et vente de véhicules », et reste disponible comme module pour tous les secteurs.{' '}
          <a href="/activites/vehicules" className="underline">
            Voir la page Location et vente de véhicules
          </a>
          .
        </p>
      </section>
    </>
  );
}
