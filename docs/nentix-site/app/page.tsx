import { Icon } from '@/components/Icon';
import { AgendaMock, BookingMock, ClientsRelanceMock, RelanceWhatsAppMock, ClientMock, DashboardMock, FideliteMock, RapportMock, RelancesMock, SegmentsMock, WhatsAppMock, WithPhone, ChatbotMock } from '@/components/mockups';
import { CtaBand, FamilyStrip, Faq, FeatureIndex, PricingCards, ProblemGrid, TrustRow } from '@/components/sections';
import { Button, Container, Eyebrow, GradientText, SectionTitle } from '@/components/ui';
import { links, pillars } from '@/lib/site';

export default function Home() {
  return (
    <>
      <Hero />

      <section className="py-16">
        <Container className="flex flex-col gap-8">
          <SectionTitle eyebrow="Pour qui" title="Une seule application pour les entreprises de services dont les clients reviennent." lead="Le produit est le même pour tous. Ce qui change, c’est le rythme de retour de vos clients, et NENTIX s’y adapte." />
          <FamilyStrip />
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle eyebrow="Le constat" title={<>Vos journées sont chargées. <GradientText>Votre gestion ne devrait pas l’être.</GradientText></>} lead="Dans la plupart des PME, l’agenda est dans un cahier, les clients dans un téléphone et les ventes dans la tête du patron. Résultat : on perd des clients sans le savoir." />
          <ProblemGrid />
        </Container>
      </section>

      <PillarRow pillar={pillars[0]} visual={<CommVisual />} />
      <PillarRow pillar={pillars[1]} visual={<UnderstandVisual />} reverse tinted />
      <PillarRow pillar={pillars[2]} visual={<FollowVisual />} />

      <AiSection />

      <DayTimeline />

      <section id="pourquoi" className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle eyebrow="Pourquoi NENTIX" title="Pensé pour les PME d’Afrique francophone, pas adapté après coup." />
          <Comparison />
        </Container>
      </section>

      <section className="bg-soft py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle eyebrow="Fonctionnalités" title="Tout ce qu’il faut pour gérer, comprendre et faire revenir vos clients." />
          <FeatureIndex />
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

function Hero() {
  return (
    <section className="relative overflow-hidden bg-navy-deep">
      <div aria-hidden className="pointer-events-none absolute inset-0 bg-[radial-gradient(55%_45%_at_50%_0%,rgba(14,123,214,.38),transparent),radial-gradient(40%_40%_at_85%_60%,rgba(0,200,150,.22),transparent),radial-gradient(35%_40%_at_10%_70%,rgba(14,123,214,.18),transparent)]" />
      <div aria-hidden className="pointer-events-none absolute inset-0 opacity-[.07] [background-image:linear-gradient(rgba(255,255,255,.6)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.6)_1px,transparent_1px)] [background-size:56px_56px] [mask-image:radial-gradient(60%_60%_at_50%_30%,#000,transparent)]" />
      <Container className="relative flex flex-col items-center gap-7 pb-10 pt-16 text-center sm:pt-24">
        <span className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-[13px] font-semibold text-white">
          <span className="h-2 w-2 rounded-full bg-green" /> Lancement à Libreville le 15 décembre 2026
        </span>
        <h1 className="max-w-4xl font-display text-[2.6rem] font-bold leading-[1.05] tracking-[-0.025em] text-white sm:text-[3.6rem] lg:text-[4.2rem]">
          Connaissez vos clients. Parlez-leur au bon moment. <span className="bg-gradient-to-r from-[#4FB3FF] to-green bg-clip-text text-transparent">Faites grandir votre activité.</span>
        </h1>
        <p className="max-w-2xl text-[1.15rem] leading-relaxed text-[#C9D9E8] sm:text-[1.25rem]">
          NENTIX réunit vos clients, vos ventes et vos échanges WhatsApp dans une seule application. Chaque matin, elle vous dit qui relancer, qui récompenser et qui ne revient plus.
        </p>
        <div className="flex w-full flex-col justify-center gap-3 sm:w-auto sm:flex-row">
          <a href="/contact" className="inline-flex h-[52px] items-center justify-center rounded-lg border border-white/20 px-7 text-base font-semibold text-white hover:border-white/50">Demander une démo</a>
          <a href={links.signup} className="inline-flex h-[52px] items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-blue to-green px-7 text-base font-semibold text-white shadow-[0_10px_30px_-10px_rgba(0,200,150,.7)] hover:brightness-110">Essai gratuit de 14 jours <Icon name="arrow" className="h-4 w-4" /></a>
        </div>
        <p className="-mt-2 max-w-2xl text-[14.5px] leading-relaxed text-[#9FB6CC]">La plateforme d’intelligence client des PME africaines. Agenda, cours, abonnements&nbsp;: activez seulement ce dont vous avez besoin.</p>
        <TrustRow light />
      </Container>
      <Container className="relative pb-0">
        <div className="relative mx-auto max-w-5xl rounded-t-2xl border border-b-0 border-white/15 bg-white/5 p-2 pb-0 shadow-[0_-20px_80px_-20px_rgba(14,123,214,.6)] sm:p-3 sm:pb-0">
          <div className="overflow-hidden rounded-t-xl bg-white">
            <ClientsRelanceMock />
          </div>
          <div className="absolute -right-2 bottom-6 z-20 hidden origin-bottom-right scale-[.72] md:block lg:-right-16">
            <RelanceWhatsAppMock />
          </div>
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
      <WithPhone main={<DashboardMock />} phone={<BookingMock />} />
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
          <SectionTitle light eyebrow="Au quotidien" title="Une journée dans un salon avec NENTIX." lead="Exemple dans un salon de coiffure de Louis, à Libreville. Les noms et les chiffres sont fictifs." />
          <ol className="relative flex flex-col gap-6 border-l border-white/15 pl-8">
            {day.map((d) => (
              <li key={d.t} className="relative">
                <span className="absolute -left-[45px] grid h-7 w-7 place-items-center rounded-full bg-gradient-to-br from-blue to-green text-white">
                  <Icon name={d.icon} className="h-3.5 w-3.5" />
                </span>
                <p className="font-display text-[14px] font-bold tabular-nums text-green">{d.t}</p>
                <p className="font-display text-[18px] font-bold">{d.title}</p>
                <p className="text-[15px] text-[#C9D9E8]">{d.text}</p>
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
            <th className="bg-navy px-5 py-4 font-display font-bold text-white">NENTIX</th>
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
      <div aria-hidden className="pointer-events-none absolute inset-0 bg-[radial-gradient(45%_60%_at_80%_50%,rgba(0,200,150,.12),transparent)]" />
      <Container className="relative grid items-center gap-12 lg:grid-cols-[1.15fr_1fr]">
        <div className="flex flex-col gap-8">
          <div className="flex flex-col gap-4">
            <span className="inline-flex w-fit items-center gap-2 rounded-full bg-blue-soft px-3 py-1 text-[13px] font-bold text-blue-text">
              <Icon name="sparkles" className="h-4 w-4" /> Intelligence artificielle · bientôt
            </span>
            <h2 className="font-display text-[2rem] font-bold leading-[1.08] tracking-[-0.01em] text-navy sm:text-[2.5rem]">Un assistant qui répond, réserve et vous conseille.</h2>
            <p className="text-[17px] leading-relaxed text-muted">L’IA de NENTIX travaille sur vos propres données : votre catalogue, vos créneaux, vos clients. Elle n’invente jamais un chiffre ni une disponibilité, et passe la main à un humain quand il le faut.</p>
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
