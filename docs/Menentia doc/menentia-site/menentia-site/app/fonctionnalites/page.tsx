import type { Metadata } from 'next';
import { Icon } from '@/components/Icon';
import { AgendaMock, BookingMock, ChatbotMock, ClientMock, DashboardMock, FideliteMock, RelancesMock, SegmentsMock, WhatsAppMock, WithPhone } from '@/components/mockups';
import { PageHero } from '@/components/PageHero';
import { CtaBand } from '@/components/sections';
import { Button, Container, StatusBadge } from '@/components/ui';
import { featureGroups, links } from '@/lib/site';

export const metadata: Metadata = {
  title: 'Fonctionnalités',
  description: 'Agenda, réservation en ligne, fichier client, segments RFM, alertes de départ, relances WhatsApp, encaissement, fidélité et rapport quotidien.',
  alternates: { canonical: '/fonctionnalites' },
};

const visuals: Record<string, React.ReactNode> = {
  agenda: <WithPhone main={<AgendaMock />} phone={<BookingMock />} />,
  clients: <ClientMock />,
  segmentation: <SegmentsMock />,
  ia: <div className="flex justify-center"><ChatbotMock /></div>,
  whatsapp: <WithPhone main={<RelancesMock />} phone={<WhatsAppMock />} />,
  ventes: <DashboardMock />,
  fidelite: <div className="flex justify-center"><FideliteMock /></div>,
  pilotage: <DashboardMock />,
};

export default function Fonctionnalites() {
  return (
    <>
      <PageHero
        eyebrow="Fonctionnalités"
        title="Tout votre suivi client dans une seule application."
        lead="Les fonctions marquées « Disponible » sont déjà en service dans l’application. Celles marquées « Bientôt » arrivent avec le lancement et les versions suivantes."
      >
        <nav aria-label="Rubriques" className="flex flex-wrap gap-2">
          {featureGroups.map((g) => (
            <a key={g.id} href={`#${g.id}`} className="inline-flex items-center gap-2 rounded-full border border-line bg-white px-4 py-2 text-[14px] font-semibold text-navy hover:border-blue/40">
              <Icon name={g.icon} className="h-4 w-4 text-blue-text" /> {g.title}
            </a>
          ))}
        </nav>
      </PageHero>

      {featureGroups.map((g, i) => (
        <section key={g.id} id={g.id} className={`py-20 ${i % 2 ? 'bg-soft' : ''}`}>
          <Container className={`grid items-start gap-12 ${visuals[g.id] ? 'lg:grid-cols-2' : ''}`}>
            <div className={`flex flex-col gap-6 ${i % 2 && visuals[g.id] ? 'lg:order-2' : ''}`}>
              <span className="grid h-12 w-12 place-items-center rounded-2xl bg-gradient-to-br from-blue to-green text-white">
                <Icon name={g.icon} className="h-6 w-6" />
              </span>
              <h2 className="font-display text-[2rem] font-bold leading-tight tracking-[-0.01em] text-navy sm:text-[2.4rem]">{g.title}</h2>
              <p className="text-[17px] leading-relaxed text-muted">{g.lead}</p>
              <div className={`grid gap-3 ${visuals[g.id] ? '' : 'sm:grid-cols-3'}`}>
                {g.items.map((f) => (
                  <div key={f.title} className="flex flex-col gap-1.5 rounded-2xl border border-line bg-white p-5">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <h3 className="font-display text-[17px] font-bold text-navy">{f.title}</h3>
                      <StatusBadge status={f.status} />
                    </div>
                    <p className="text-[15px] leading-relaxed text-muted">{f.text}</p>
                  </div>
                ))}
              </div>
            </div>
            {visuals[g.id] && <div className={`min-w-0 lg:sticky lg:top-28 ${i % 2 ? 'lg:order-1' : ''}`}>{visuals[g.id]}</div>}
          </Container>
        </section>
      ))}

      <section className="py-16">
        <Container className="flex flex-col items-start gap-5 rounded-3xl border border-line p-8 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="font-display text-[22px] font-bold text-navy">Et ensuite ?</p>
            <p className="max-w-2xl text-[15.5px] text-muted">Boîte de réception WhatsApp, acompte Mobile Money, scénarios automatiques (anniversaire, avis) et Data Lab pour analyser vos fichiers Excel : ils arrivent en 2027, version par version.</p>
          </div>
          <Button href={links.signup} variant="dark">Commencer maintenant</Button>
        </Container>
      </section>

      <CtaBand />
    </>
  );
}
