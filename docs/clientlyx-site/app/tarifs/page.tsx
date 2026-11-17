import type { Metadata } from 'next';
import { Icon } from '@/components/Icon';
import { PageHero } from '@/components/PageHero';
import { CtaBand, Faq, PricingCards } from '@/components/sections';
import { Container, SectionTitle } from '@/components/ui';
import { apps, offers, pricingNote } from '@/lib/site';

export const metadata: Metadata = {
  title: 'Tarifs',
  description: 'Gratuit, Solo 9 900, Équipe 25 000, Premium 50 000 FCFA par mois, Entreprise sur devis. Essai 14 jours sans carte bancaire, paiement Mobile Money.',
  alternates: { canonical: '/tarifs' },
};

type Cell = boolean | string;
const table: [string, Cell, Cell, Cell, Cell, Cell][] = [
  ['Fiche client, agenda, catalogue, import Excel', true, true, true, true, true],
  ['Relances par lien WhatsApp en un clic', true, true, true, true, true],
  ['Segments RFM', true, true, true, true, true],
  ['Utilisateurs', '1', '1', '5', '15', 'Sur mesure'],
  ['Clients', '100', '500', 'Illimités', 'Illimités', 'Illimités'],
  ['Cartes digitales', '3', '10', '30', 'Illimitées', 'Sur devis'],
  ['API WhatsApp : rappels, relances, réponses auto', false, true, true, true, true],
  ['Module de votre métier', false, true, true, true, true],
  ['Stock des produits', false, true, true, true, true],
  ['Devis et factures, acceptés sur WhatsApp', false, false, true, true, true],
  ['Caisse du jour et dépenses', false, false, true, true, true],
  ['Prospects, campagnes, fidélité', false, false, true, true, true],
  ['Analyse IA : score, alerte de départ, segments', false, false, false, true, true],
  ['Agents IA sur WhatsApp', false, false, false, true, true],
  ['Plusieurs établissements', false, false, false, '5', 'Sur mesure'],
  ['Support', 'Aide en ligne', 'WhatsApp', 'WhatsApp', 'Prioritaire', 'Dédié'],
];

export default function Tarifs() {
  return (
    <>
      <PageHero eyebrow="Tarifs" title="Moins cher qu’une prestation par mois." lead="Commencez gratuitement. Un prix par entreprise, pas par utilisateur, payable par Mobile Money. Ajoutez une application quand vous en avez besoin." />
      <section className="py-20">
        <Container className="flex flex-col gap-8">
          <PricingCards />
          <div className="grid gap-4 md:grid-cols-3 lg:grid-cols-5">
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
          <SectionTitle eyebrow="Applications" title="Une application par besoin, le client au centre de chacune." />
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {apps.map(([i, name, text, pack]) => (
              <div key={name} className="flex flex-col gap-2 rounded-2xl border border-line bg-white p-5">
                <div className="flex items-center justify-between">
                  <span className="grid h-10 w-10 place-items-center rounded-xl bg-blue-soft text-navy"><Icon name={i} className="h-5 w-5" /></span>
                  <span className="rounded-full bg-soft px-2.5 py-1 text-[12px] font-semibold text-blue-text">Dès {pack}</span>
                </div>
                <p className="font-display text-[17px] font-bold text-navy">{name}</p>
                <p className="text-[14px] text-muted">{text}</p>
              </div>
            ))}
          </div>
        </Container>
      </section>
      <section className="py-20">
        <Container className="flex flex-col gap-8">
          <SectionTitle eyebrow="Comparer" title="Ce que contient chaque offre." />
          <div className="overflow-x-auto rounded-2xl border border-line bg-white">
            <table className="w-full min-w-[860px] border-collapse text-[15px]">
              <thead>
                <tr className="border-b border-line">
                  <th className="px-5 py-4 text-left font-semibold text-muted">Fonction</th>
                  {['Gratuit', 'Solo', 'Équipe', 'Premium', 'Entreprise'].map((p) => <th key={p} className="px-5 py-4 font-display font-bold text-navy">{p}</th>)}
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
