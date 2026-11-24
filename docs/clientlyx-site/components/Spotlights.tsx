import type { ReactNode } from 'react';
import { Icon } from './Icon';
import { ChatbotMock, ClientMock } from './mockups';
import { Button, Container, SectionTitle } from './ui';
import { links } from '@/lib/site';

// Ordre de mise en avant voulu (24/11/2026) : 1) WhatsApp et l'Assistant IA,
// 2) le CRM (fiche client, connaissance client), 3) la data (RFM, score, départ).
function Spotlight({ id, eyebrow, title, lead, points, visual, cta, tinted = false, reverse = false }: {
  id: string;
  eyebrow: string;
  title: string;
  lead: string;
  points: string[];
  visual: ReactNode;
  cta: ReactNode;
  tinted?: boolean;
  reverse?: boolean;
}) {
  return (
    <section id={id} className={`py-20 ${tinted ? 'bg-soft' : ''}`}>
      <Container className="grid items-center gap-12 lg:grid-cols-2">
        <div className={`flex flex-col gap-7 ${reverse ? 'lg:order-2' : ''}`}>
          <SectionTitle eyebrow={eyebrow} title={title} lead={lead} />
          <ul className="flex flex-col gap-3">
            {points.map((p) => (
              <li key={p} className="flex items-start gap-3 text-[16px] leading-snug text-ink">
                <Icon name="check" className="mt-0.5 h-5 w-5 shrink-0 text-green-text" strokeWidth={2.6} /> {p}
              </li>
            ))}
          </ul>
          <div className="flex flex-col gap-3 sm:flex-row">{cta}</div>
        </div>
        <div className="flex min-w-0 justify-center">{visual}</div>
      </Container>
    </section>
  );
}

export function WhatsAppSpotlight() {
  return (
    <Spotlight
      id="whatsapp-assistant"
      eyebrow="WhatsApp et Assistant IA"
      title="Votre WhatsApp répond, réserve et qualifie vos clients, 24 h/24."
      lead="L’assistant ClientlyX répond sur votre numéro WhatsApp à partir de votre catalogue, de vos horaires et de vos réponses. Vous gardez la main à tout moment."
      points={[
        'Prix, horaires et disponibilités donnés tout de suite, même à minuit',
        'Rendez-vous pris par boutons sur vos vrais créneaux, sans double réservation',
        'Fiche client créée au premier message, avec la pub qui l’a fait venir',
        'Passage à votre équipe avec tout l’historique quand il le faut',
      ]}
      visual={<ChatbotMock />}
      cta={
        <>
          <a href={links.whatsapp} target="_blank" rel="noopener" data-source="site_whatsapp" className="inline-flex h-[52px] items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-green px-7 text-base font-semibold text-white hover:bg-green-text">
            <Icon name="message" className="h-5 w-5" /> Tester sur WhatsApp
          </a>
          <Button href={links.demo} variant="outline" size="lg">Réserver une démo</Button>
        </>
      }
    />
  );
}

export function CrmSpotlight() {
  return (
    <Spotlight
      id="fiche-client"
      tinted
      reverse
      eyebrow="CRM et connaissance client"
      title="Une fiche par client. Toute son histoire, au même endroit."
      lead="Fini les numéros dans le téléphone et les ventes dans un cahier : chaque message, rendez-vous et achat rejoint la fiche du client, pour toute l’équipe."
      points={[
        'Visites, achats, prestations préférées et dépenses sur une seule page',
        'Étape du parcours calculée seule : nouveau, actif, à risque, inactif',
        'Fidélité, notes de l’équipe et consentement WhatsApp daté',
        'Votre fichier Excel ou vos contacts importés en quelques minutes',
      ]}
      visual={<ClientMock />}
      cta={<Button href={links.signup} variant="brand" size="lg">Essayer gratuitement</Button>}
    />
  );
}
