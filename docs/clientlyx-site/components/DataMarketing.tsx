import { Icon } from './Icon';
import { SegmentsMock } from './mockups';
import { Button, Container, SectionTitle } from './ui';
import { links } from '@/lib/site';

// Data marketing (fonctions en service au lancement) : segmentation RFM, score
// client, alerte de départ calculée sur le rythme de chaque client, campagnes
// ciblées. Pas de promesse d'IA prédictive ni de clustering ici.
const items = [
  {
    icon: 'pie',
    title: 'Segmentation RFM',
    text: 'Vos clients rangés en 8 segments (champions, fidèles, à risque, endormis…) selon la récence, la fréquence et le montant de leurs achats. Recalculés chaque nuit, aussi par marque.',
  },
  {
    icon: 'chart',
    title: 'Score client de 0 à 100',
    text: 'Chaque fiche a son score : vous savez en un coup d’œil qui sont vos meilleurs clients et qui mérite une attention.',
  },
  {
    icon: 'bell',
    title: 'Alerte de départ',
    text: 'ClientlyX connaît le rythme d’achat de chaque client et vous prévient dès qu’il le dépasse, avant qu’il parte chez un concurrent.',
  },
  {
    icon: 'send',
    title: 'Campagnes ciblées',
    text: 'Écrivez au bon segment : à risque, fidèles, une famille de produits, une marque, les clients jamais venus dans votre autre enseigne. Par WhatsApp, en un clic ou en campagne.',
  },
];

export function DataMarketing() {
  return (
    <section id="data-marketing" className="py-20">
      <Container className="grid items-center gap-12 lg:grid-cols-[1.05fr_1fr]">
        <div className="flex flex-col gap-8">
          <SectionTitle
            eyebrow="Data marketing"
            title="Vos données clients enfin au travail."
            lead="Chaque vente et chaque visite alimentent la fiche du client. ClientlyX en tire des segments, un score et des alertes, puis vous aide à écrire au bon client au bon moment."
          />
          <div className="grid gap-4 sm:grid-cols-2">
            {items.map((i) => (
              <div key={i.title} className="flex flex-col gap-2.5 rounded-2xl border border-line bg-white p-5">
                <span className="grid h-10 w-10 place-items-center rounded-xl bg-blue-soft text-navy"><Icon name={i.icon} className="h-5 w-5" /></span>
                <h3 className="font-display text-[17px] font-bold text-navy">{i.title}</h3>
                <p className="text-[14.5px] leading-relaxed text-muted">{i.text}</p>
              </div>
            ))}
          </div>
          <div className="flex flex-col gap-3 sm:flex-row">
            <Button href={links.signup} variant="brand" size="lg">Essayer gratuitement</Button>
            <Button href={links.demo} variant="outline" size="lg">Voir une démo sur mes données</Button>
          </div>
        </div>
        <div className="min-w-0">
          <SegmentsMock />
        </div>
      </Container>
    </section>
  );
}
