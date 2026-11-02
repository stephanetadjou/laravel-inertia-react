import type { Metadata } from 'next';
import { Icon } from '@/components/Icon';
import { BookingMock } from '@/components/mockups';
import { CtaBand } from '@/components/sections';
import { Button, Container, Eyebrow, SectionTitle } from '@/components/ui';
import { families, links } from '@/lib/site';

export const metadata: Metadata = {
  title: 'Marketplace',
  description: 'La marketplace Menentia rend votre entreprise visible et permet à vos futurs clients de réserver en ligne.',
  alternates: { canonical: '/marketplace' },
};

export default function Marketplace() {
  return (
    <>
      <section className="relative overflow-hidden bg-navy text-white">
        <div aria-hidden className="pointer-events-none absolute -right-20 -top-20 h-96 w-96 rounded-full bg-green/25 blur-3xl" />
        <Container className="relative grid items-center gap-12 py-16 lg:grid-cols-[1.2fr_1fr] lg:py-24">
          <div className="flex flex-col gap-6">
            <Eyebrow tone="light">Marketplace Menentia · en préparation</Eyebrow>
            <h1 className="font-display text-[2.5rem] font-bold leading-[1.04] tracking-[-0.018em] sm:text-[3.4rem]">Soyez trouvé par vos futurs clients, et réservé en ligne.</h1>
            <p className="max-w-xl text-[1.12rem] leading-relaxed text-[#C9D9E8]">
              La marketplace présentera les entreprises Menentia de Libreville par activité et par quartier. Un client cherche « tresses à Louis » ou « kiné Akanda », voit vos prestations, vos prix et vos créneaux libres, et réserve.
            </p>
            <div className="w-full max-w-xl">
              <div className="flex items-center gap-2 rounded-full bg-white p-1.5 pl-5 text-[15px] text-muted shadow-float">
                <Icon name="search" className="h-5 w-5 shrink-0 text-navy" />
                <span className="truncate">Tresses, kiné, garage…</span>
                <span className="ml-auto hidden items-center gap-1.5 border-l border-line pl-3 sm:flex"><Icon name="mapPin" className="h-4 w-4" /> Libreville</span>
                <span className="rounded-full bg-navy px-4 py-2 font-semibold text-white">Rechercher</span>
              </div>
              <p className="mt-2 text-[13px] text-[#9FB4C8]">Aperçu de la recherche : la marketplace ouvre avec le lancement.</p>
            </div>
          </div>
          <div className="flex justify-center"><BookingMock /></div>
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle eyebrow="Pour les entreprises" title="Une fiche publique, gratuite pour les clients Menentia." lead="Vous choisissez ce qui est publié. Votre fichier client n’est jamais visible." />
          <div className="grid gap-4 md:grid-cols-3">
            {[
              ['store', 'Votre vitrine en ligne', 'Photos, horaires, adresse, prestations et prix. Souvent la première page web d’une entreprise.'],
              ['calendar', 'Réservation sur vos vrais créneaux', 'Les rendez-vous arrivent directement dans votre agenda Menentia, sans double réservation.'],
              ['search', 'Trouvé sur Google', 'Chaque fiche est construite pour apparaître dans les recherches locales.'],
            ].map(([i, t, d]) => (
              <div key={t} className="flex flex-col gap-3 rounded-2xl border border-line p-6">
                <span className="grid h-11 w-11 place-items-center rounded-xl bg-blue-soft text-blue-text"><Icon name={i} className="h-5 w-5" /></span>
                <h3 className="font-display text-[19px] font-bold text-navy">{t}</h3>
                <p className="text-[15px] text-muted">{d}</p>
              </div>
            ))}
          </div>
          <div className="flex flex-wrap gap-2">
            {families.map((f) => (
              <span key={f.slug} className="inline-flex items-center gap-2 rounded-full bg-soft px-4 py-2 text-[14.5px] font-semibold text-navy"><Icon name={f.icon} className="h-4 w-4 text-blue-text" /> {f.name}</span>
            ))}
          </div>
          <div className="flex flex-col gap-3 sm:flex-row">
            <Button href={links.signup} variant="dark" size="lg">Créer mon compte et ma fiche</Button>
            <Button href="/contact" variant="outline" size="lg">Être prévenu de l’ouverture</Button>
          </div>
        </Container>
      </section>
      <CtaBand />
    </>
  );
}
