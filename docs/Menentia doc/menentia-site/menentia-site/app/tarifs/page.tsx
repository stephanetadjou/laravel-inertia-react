import type { Metadata } from 'next';
import { Icon } from '@/components/Icon';
import { PageHero } from '@/components/PageHero';
import { CtaBand, Faq, PricingCards } from '@/components/sections';
import { Container, SectionTitle } from '@/components/ui';
import { offers, pricingNote } from '@/lib/site';

export const metadata: Metadata = {
  title: 'Tarifs',
  description: 'Solo 9 900, Équipe 25 000, Premium 50 000 FCFA par mois. Essai 14 jours sans carte bancaire, paiement Mobile Money.',
  alternates: { canonical: '/tarifs' },
};

const table: [string, boolean | string, boolean | string, boolean | string][] = [
  ['Agenda et réservation en ligne', true, true, true],
  ['Fiche client, import Excel', true, true, true],
  ['Encaissement et catalogue', true, true, true],
  ['Relances WhatsApp en un clic', true, true, true],
  ['Programme de fidélité', true, true, true],
  ['Intervenants', '1', '2 à 8', 'Plusieurs équipes'],
  ['Segments RFM', false, true, true],
  ['Campagnes WhatsApp', false, true, true],
  ['Module de votre métier', false, true, true],
  ['Plusieurs établissements', false, false, true],
  ['Score client et alertes de départ', false, false, true],
  ['Assistant IA', false, false, 'Bientôt'],
  ['Support', 'WhatsApp', 'WhatsApp', 'Prioritaire'],
];

export default function Tarifs() {
  return (
    <>
      <PageHero eyebrow="Tarifs" title="Moins cher qu’une prestation par mois." lead="Prix en FCFA, payables par Mobile Money. Essai de 14 jours sans carte bancaire, sans engagement." />
      <section className="py-20">
        <Container className="flex flex-col gap-8">
          <PricingCards />
          <div className="grid gap-4 md:grid-cols-4">
            {offers.map(([i, t, d]) => (
              <div key={t} className="flex flex-col gap-2 rounded-2xl bg-soft p-5">
                <Icon name={i} className="h-6 w-6 text-blue-text" />
                <p className="font-display text-[17px] font-bold text-navy">{t}</p>
                <p className="text-[14.5px] text-muted">{d}</p>
              </div>
            ))}
          </div>
        </Container>
      </section>
      <section className="bg-soft py-20">
        <Container className="flex flex-col gap-8">
          <SectionTitle eyebrow="Comparer" title="Ce que contient chaque offre." />
          <div className="overflow-x-auto rounded-2xl border border-line bg-white">
            <table className="w-full min-w-[640px] border-collapse text-[15px]">
              <thead>
                <tr className="border-b border-line">
                  <th className="px-5 py-4 text-left font-semibold text-muted">Fonction</th>
                  {['Solo', 'Équipe', 'Premium'].map((p) => <th key={p} className="px-5 py-4 font-display font-bold text-navy">{p}</th>)}
                </tr>
              </thead>
              <tbody>
                {table.map(([label, ...cells]) => (
                  <tr key={label} className="border-b border-line last:border-0">
                    <th className="px-5 py-3.5 text-left font-medium text-ink">{label}</th>
                    {cells.map((c, i) => (
                      <td key={i} className="px-5 py-3.5 text-center">
                        {c === true ? <Icon name="check" className="mx-auto h-5 w-5 text-green-text" strokeWidth={2.6} /> : c === false ? <span className="text-line">—</span> : <span className="text-[14px] font-medium text-navy">{c}</span>}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="text-[14px] text-muted">{pricingNote}</p>
        </Container>
      </section>
      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center eyebrow="Questions fréquentes" title="Avant de vous lancer." />
          <Faq />
        </Container>
      </section>
      <CtaBand />
    </>
  );
}
