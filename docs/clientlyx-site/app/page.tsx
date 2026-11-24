import { Icon } from '@/components/Icon';
import { AgendaMock, AgendaPhoneMock, ClientsRelanceMock, RelanceWhatsAppMock, ClientMock, DashboardMock, FideliteMock, RapportMock, RelancesMock, SegmentsMock, WhatsAppMock, WithPhone, ChatbotMock } from '@/components/mockups';
import { CtaBand, Faq, FeatureIndex, PricingCards, ProblemGrid, TrustRow } from '@/components/sections';
import { AppCarousel } from '@/components/AppCarousel';
import { SectorTabs } from '@/components/SectorTabs';
import { DataMarketing } from '@/components/DataMarketing';
import { CrmSpotlight, WhatsAppSpotlight } from '@/components/Spotlights';
import { Button, Container, Eyebrow, GradientText, SectionTitle } from '@/components/ui';
import { brand, families, links, pillars } from '@/lib/site';

export default function Home() {
  return (
    <>
      <Hero />

      <section className="py-10">
        <Container>
          <div className="flex flex-col items-start gap-5 rounded-3xl bg-navy px-6 py-8 sm:px-10 md:flex-row md:items-center md:justify-between">
            <div className="flex items-start gap-4">
              <span className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-sun text-navy"><Icon name="mobile" className="h-6 w-6" /></span>
              <div className="flex flex-col gap-1">
                <p className="font-display text-xl font-bold text-white">Nouveau : la carte de visite digitale gratuite</p>
                <p className="text-[15px] text-[#E2D8EC]">Prête en 5 minutes, avec QR code. Chaque contact reçu devient une fiche client dans ClientlyX.</p>
              </div>
            </div>
            <Button href="/carte-digitale" variant="brand" size="lg" className="w-full md:w-auto">Créer ma carte gratuite</Button>
          </div>
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle eyebrow="Le constat" title={"Vos journées sont chargées. Votre gestion ne devrait pas l’être."} lead="Dans la plupart des PME, l’agenda est dans un cahier, les clients dans un téléphone et les ventes dans la tête du patron. Résultat : on perd des clients sans le savoir." />
          <ProblemGrid />
        </Container>
      </section>

      <WhatsAppSpotlight />

      <CrmSpotlight />

      <DataMarketing />

      <GrowthPlatform />

      <section className="py-16">
        <Container className="flex flex-col gap-8">
          <SectionTitle eyebrow="Votre secteur" title="Beauté, bien-être, santé et sport d’abord. Et tous les établissements de services." lead="Choisissez votre activité puis votre métier : ClientlyX arrive réglé, avec le vocabulaire, les réservations, les relances et les tableaux de bord qui vous concernent." />
          <SectorTabs families={families} pitch={{}} trialHref={links.signup} />
        </Container>
      </section>

      <section className="py-20" id="tarifs">
        <Container className="flex flex-col gap-10">
          <SectionTitle center eyebrow="Tarifs" title="Un prix en FCFA, payable par Mobile Money." lead="Solo coûte moins qu’une prestation par mois. Une seule cliente récupérée le rembourse." />
          <PricingCards />
          <p className="text-center text-[14px] text-muted">Essai de 14 jours sans carte bancaire · Paiement annuel : 2 mois offerts · <a href="/tarifs" className="font-semibold text-blue-text underline-offset-4 hover:underline">Comparer les offres</a></p>
        </Container>
      </section>

      <section className="bg-soft py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center eyebrow="Questions fréquentes" title="Ce que les gérants nous demandent." />
          <Faq />
        </Container>
      </section>

      <CtaBand />
    </>
  );
}

const cycle = [
  { n: '1', k: 'Attirer', t: 'Campagnes WhatsApp, offres et suivi des pubs', ring: 'border-green' },
  { n: '2', k: 'Réserver', t: 'Rendez-vous en ligne et rappels automatiques', ring: 'border-sun' },
  { n: '3', k: 'Connaître', t: 'Fiche client complète, segments RFM et score client', ring: 'border-blue' },
  { n: '4', k: 'Fidéliser', t: 'Relances, fidélité et clients à risque repérés', ring: 'border-navy' },
];

const piliers = [
  { tag: 'Growth Marketing', icon: 'message', t: 'Faire revenir vos clients', d: 'Campagnes et relances WhatsApp ciblées, programme de fidélité et réactivation des clients endormis.', href: '/piliers/growth-marketing', c: 'text-green-text' },
  { tag: 'AI-Powered CRM', icon: 'users', t: 'Connaître chaque client', d: 'Fiche unique, historique des visites et achats, segments RFM, score client et alertes sur les clients qui risquent de partir.', href: '/piliers/ai-powered-crm', c: 'text-blue-text' },
  { tag: 'Advanced Analytics', icon: 'chart', t: 'Décider avec vos chiffres', d: 'Tableau de bord clair, chiffre d’affaires par prestation et par collaborateur, rapport automatique chaque soir.', href: '/piliers/advanced-analytics', c: 'text-navy' },
  { tag: 'Dynamic Booking', icon: 'calendar', t: 'Remplir votre agenda', d: 'Réservation en ligne, agenda par collaborateur, rappels WhatsApp pour réduire les rendez-vous manqués.', href: '/piliers/dynamic-booking', c: 'text-[#7A5C00]' },
];

function GrowthPlatform() {
  return (
    <>
      <section id="plateforme" className="border-y border-line bg-white py-20">
        <Container className="flex flex-col gap-12">
          <SectionTitle center eyebrow="Une plateforme, quatre piliers" title="Une seule base client. Tout est connecté." lead="Chaque rendez-vous, chaque message et chaque vente enrichit la même fiche client. Vous voyez enfin qui revient, qui part et pourquoi." />
          <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {piliers.map((p) => (
              <a key={p.tag} href={p.href} className="group flex flex-col gap-3 rounded-2xl border border-line bg-soft p-7 transition hover:border-navy/30 hover:shadow-card">
                <span className="grid h-12 w-12 place-items-center rounded-xl bg-white text-navy ring-1 ring-line"><Icon name={p.icon} className="h-6 w-6" /></span>
                <span className={`text-[12px] font-bold uppercase tracking-[0.1em] ${p.c}`}>{p.tag}</span>
                <h3 className="font-display text-xl font-bold text-navy">{p.t}</h3>
                <p className="text-[15px] leading-relaxed text-muted">{p.d}</p>
                <span className="mt-auto pt-1 text-[15px] font-semibold text-navy group-hover:text-green-text">En savoir plus →</span>
              </a>
            ))}
          </div>
        </Container>
      </section>
      <section id="apercu" className="bg-navy py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center light eyebrow="Aperçu de l’application" title="Voyez l’application en action" lead="Une interface simple, en français, utilisable sur ordinateur comme sur téléphone." />
          <AppCarousel />
        </Container>
      </section>
    </>
  );
}

function Hero() {
  return (
    <section className="bg-soft">
      <Container className="flex flex-col items-center gap-6 pb-16 pt-16 text-center sm:pt-24">
        <span className="inline-flex items-center gap-2 rounded-full bg-white px-3.5 py-1.5 text-[13px] font-semibold text-navy ring-1 ring-line">
          <span className="h-2 w-2 rounded-full bg-green" /> Lancement à Libreville le 15 décembre 2026
        </span>
        <h1 className="max-w-4xl font-display text-[2.5rem] font-bold leading-[1.05] tracking-[-0.025em] text-navy sm:text-[3.5rem] lg:text-[4rem]">
          Reprenez le contrôle de vos clients. <span className="text-muted">Décidez avec vos données.</span>
        </h1>
        <p className="max-w-2xl text-[1.15rem] leading-relaxed text-muted sm:text-[1.2rem]">
          {brand.name} réunit vos clients, vos rendez-vous, vos relances WhatsApp et vos chiffres dans une seule plateforme, pensée pour les PME de services en Afrique.
        </p>
        <div className="flex w-full flex-col justify-center gap-3 sm:w-auto sm:flex-row">
          <a href={links.whatsapp} target="_blank" rel="noopener" data-source="site_whatsapp" className="inline-flex h-[52px] items-center justify-center gap-2 rounded-lg bg-green px-7 text-base font-semibold text-white hover:bg-green-text"><Icon name="message" className="h-5 w-5" /> Tester sur WhatsApp</a>
          <a href={links.cardSignup} className="inline-flex h-[52px] items-center justify-center gap-2 rounded-lg bg-navy px-7 text-base font-semibold text-white hover:brightness-110">Créer ma Carte digitale gratuite <Icon name="arrow" className="h-4 w-4" /></a>
          <a href={links.demo} className="inline-flex h-[52px] items-center justify-center gap-2 rounded-lg border border-line bg-white px-7 text-base font-semibold text-navy hover:border-navy/40"><Icon name="calendar" className="h-4 w-4" /> Réserver une démo</a>
        </div>
        <ul className="flex flex-wrap justify-center gap-x-6 gap-y-2 text-[14px] text-muted">
          {['Offre gratuite permanente', 'Sans carte bancaire', 'Paiement Mobile Money', 'Accompagnement sur WhatsApp'].map((g) => (
            <li key={g} className="inline-flex items-center gap-1.5"><Icon name="check" className="h-4 w-4 text-green-text" strokeWidth={2.5} />{g}</li>
          ))}
        </ul>
        <div className="mt-8 w-full max-w-5xl rounded-2xl border border-line bg-white p-6 sm:p-8">
          <p className="mb-6 text-[13px] font-bold uppercase tracking-[0.14em] text-muted">Le cycle client, de bout en bout</p>
          <ol className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            {cycle.map((c) => (
              <li key={c.k} className="flex flex-col items-center gap-2.5">
                <span className={`grid h-14 w-14 place-items-center rounded-full border-2 bg-white font-display text-xl font-bold text-navy ${c.ring}`}>{c.n}</span>
                <span className="font-display text-xl font-bold text-navy">{c.k}</span>
                <span className="text-[14px] leading-snug text-muted">{c.t}</span>
              </li>
            ))}
          </ol>
        </div>
      </Container>
    </section>
  );
}

type Pillar = (typeof pillars)[number];

function PillarRow({ pillar, visual, reverse = false, tinted = false }: { pillar: Pillar; visual: React.ReactNode; reverse?: boolean; tinted?: boolean }) {
  return (
    <section id={pillar.key} className={`py-20 ${tinted ? 'bg-soft' : ''}`}>
      <Container className="grid items-center gap-12 lg:grid-cols-2">
        <div className={`flex flex-col gap-6 ${reverse ? 'lg:order-2' : ''}`}>
          <Eyebrow tone="green">{pillar.eyebrow}</Eyebrow>
          <h2 className="font-display text-[2rem] font-bold leading-[1.08] tracking-[-0.01em] text-navy sm:text-[2.5rem]">{pillar.title}</h2>
          <p className="text-[17px] leading-relaxed text-muted">{pillar.text}</p>
          <ul className="flex flex-col gap-3">
            {pillar.points.map((p) => (
              <li key={p} className="flex items-start gap-3 text-[16px] text-ink">
                <span className="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-green-soft text-green-text">
                  <Icon name="check" className="h-3.5 w-3.5" strokeWidth={3} />
                </span>
                {p}
              </li>
            ))}
          </ul>
          <a href="/fonctionnalites" className="inline-flex w-fit items-center gap-1.5 font-semibold text-blue-text hover:gap-2.5">
            Voir les fonctionnalités <Icon name="arrow" className="h-4 w-4" />
          </a>
        </div>
        <div className={`min-w-0 ${reverse ? 'lg:order-1' : ''}`}>{visual}</div>
      </Container>
    </section>
  );
}

function CommVisual() {
  return <WithPhone main={<RelancesMock />} phone={<WhatsAppMock />} />;
}

function UnderstandVisual() {
  return (
    <div className="flex flex-col gap-5">
      <SegmentsMock />
      <ClientMock />
    </div>
  );
}

function FollowVisual() {
  return (
    <div className="flex flex-col gap-5">
      <WithPhone main={<DashboardMock />} phone={<AgendaPhoneMock />} />
      <FideliteMock />
    </div>
  );
}

const day = [
  { t: '7:30', icon: 'sun', title: 'La liste du jour est prête', text: 'Rendez-vous à rappeler, clientes à risque, anniversaires : tout est dans « À faire ».' },
  { t: '8:30', icon: 'phone', title: 'Les confirmations partent', text: 'Six rendez-vous à confirmer : un clic par cliente, le message est prêt.' },
  { t: '12:30', icon: 'coffee', title: 'Une cliente réserve pendant la pause', text: 'Depuis le lien dans votre statut WhatsApp, sans vous appeler.' },
  { t: '16:00', icon: 'wallet', title: 'Encaissement depuis le rendez-vous', text: 'La prestation et le produit vendus, les points de fidélité ajoutés.' },
  { t: '18:00', icon: 'userX', title: 'Les clientes à risque relancées', text: 'Mireille a 9 jours de retard sur ses tresses : elle reçoit une offre et réserve.' },
  { t: '21:00', icon: 'moon', title: 'Le rapport du soir arrive', text: 'Chiffre d’affaires du jour, absences, programme de demain et clientes à relancer, par email.' },
];

function DayTimeline() {
  return (
    <section className="bg-navy py-20 text-white">
      <Container className="grid gap-12 lg:grid-cols-[1.2fr_1fr] lg:items-center">
        <div className="flex flex-col gap-10">
          <SectionTitle light eyebrow="Au quotidien" title="Une journée dans un salon avec ClientlyX." lead="Exemple dans un salon de coiffure de Louis, à Libreville. Les noms et les chiffres sont fictifs." />
          <ol className="relative flex flex-col gap-6 border-l border-white/15 pl-8">
            {day.map((d) => (
              <li key={d.t} className="relative">
                <span className="absolute -left-[45px] grid h-7 w-7 place-items-center rounded-full bg-blue-soft text-navy">
                  <Icon name={d.icon} className="h-3.5 w-3.5" />
                </span>
                <p className="font-display text-[14px] font-bold tabular-nums text-green">{d.t}</p>
                <p className="font-display text-[18px] font-bold">{d.title}</p>
                <p className="text-[15px] text-[#E2D8EC]">{d.text}</p>
              </li>
            ))}
          </ol>
        </div>
        <div className="flex justify-center">
          <RapportMock dark />
        </div>
      </Container>
    </section>
  );
}

const rows: [string, string, string, string][] = [
  ['Canal client', 'Appels et messages à la main', 'Application, email, SMS', 'WhatsApp d’abord, sans application à installer'],
  ['Paiement', 'Espèces', 'Carte bancaire, euros ou dollars', 'FCFA et Mobile Money'],
  ['Analyse', 'Aucune', 'Rapports de vente', 'RFM, score, alerte de départ selon votre métier'],
  ['Relances', 'De mémoire', 'Email automatique', 'Liste du jour et message WhatsApp prêt'],
  ['Mise en route', 'Rien à installer', 'Seul, en anglais', 'Import Excel et installation sur place, en français'],
];

function Comparison() {
  return (
    <div className="overflow-x-auto rounded-2xl border border-line">
      <table className="w-full min-w-[720px] border-collapse text-left text-[15px]">
        <thead>
          <tr className="bg-soft">
            <th className="w-[18%] px-5 py-4 font-semibold text-muted" />
            <th className="px-5 py-4 font-semibold text-muted">Cahier et WhatsApp</th>
            <th className="px-5 py-4 font-semibold text-muted">Logiciels étrangers</th>
            <th className="bg-navy px-5 py-4 font-display font-bold text-white">ClientlyX</th>
          </tr>
        </thead>
        <tbody>
          {rows.map(([k, a, b, c]) => (
            <tr key={k} className="border-t border-line">
              <th className="px-5 py-4 font-semibold text-navy">{k}</th>
              <td className="px-5 py-4 text-muted">{a}</td>
              <td className="px-5 py-4 text-muted">{b}</td>
              <td className="bg-green-soft/50 px-5 py-4 font-medium text-navy">
                <span className="flex items-start gap-2">
                  <Icon name="check" className="mt-0.5 h-4 w-4 shrink-0 text-green-text" strokeWidth={2.6} /> {c}
                </span>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

const aiPoints = [
  { icon: 'message', title: 'Agent IA de prise de rendez-vous', text: 'Il répond à vos clients jour et nuit sur WhatsApp et réserve sur vos vrais créneaux et intervenants.' },
  { icon: 'pie', title: 'Segmentation automatique', text: 'L’IA regroupe les clients qui se ressemblent et vous dit à qui écrire quoi.' },
  { icon: 'sparkles', title: 'Campagnes rédigées pour vous', text: 'Le bon message pour chaque segment, dans votre ton. Vous relisez avant l’envoi.' },
  { icon: 'chart', title: 'Vos chiffres expliqués', text: 'Pourquoi le chiffre baisse, qui relancer en priorité, en phrases simples.' },
];

function AiSection() {
  return (
    <section id="ia" className="relative overflow-hidden py-20">
      <div aria-hidden className="pointer-events-none absolute inset-0 bg-[radial-gradient(45%_60%_at_80%_50%,rgba(232,89,12,.12),transparent)]" />
      <Container className="relative grid items-center gap-12 lg:grid-cols-[1.15fr_1fr]">
        <div className="flex flex-col gap-8">
          <div className="flex flex-col gap-4">
            <span className="inline-flex w-fit items-center gap-2 rounded-full bg-blue-soft px-3 py-1 text-[13px] font-bold text-blue-text">
              <Icon name="sparkles" className="h-4 w-4" /> Intelligence artificielle · bientôt
            </span>
            <h2 className="font-display text-[2rem] font-bold leading-[1.08] tracking-[-0.01em] text-navy sm:text-[2.5rem]">Un assistant qui répond, réserve et vous conseille.</h2>
            <p className="text-[17px] leading-relaxed text-muted">L’IA de ClientlyX travaille sur vos propres données : votre catalogue, vos créneaux, vos clients. Elle n’invente jamais un chiffre ni une disponibilité, et passe la main à un humain quand il le faut.</p>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            {aiPoints.map((p) => (
              <div key={p.title} className="flex flex-col gap-2 rounded-2xl border border-line bg-white p-5">
                <Icon name={p.icon} className="h-6 w-6 text-blue-text" />
                <p className="font-display text-[17px] font-bold text-navy">{p.title}</p>
                <p className="text-[14.5px] leading-snug text-muted">{p.text}</p>
              </div>
            ))}
          </div>
          <a href="/fonctionnalites#ia" className="inline-flex w-fit items-center gap-1.5 font-semibold text-blue-text hover:gap-2.5">
            Tout savoir sur l’IA <Icon name="arrow" className="h-4 w-4" />
          </a>
        </div>
        <div className="flex justify-center">
          <ChatbotMock />
        </div>
      </Container>
    </section>
  );
}
