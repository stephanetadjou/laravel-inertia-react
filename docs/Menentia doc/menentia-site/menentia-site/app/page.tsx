import { Icon } from '@/components/Icon';
import { AgendaMock, BookingMock, ClientMock, DashboardMock, FideliteMock, RapportMock, RelancesMock, SegmentsMock, WhatsAppMock, WithPhone, ChatbotMock } from '@/components/mockups';
import { CtaBand, FamilyStrip, Faq, FeatureIndex, PricingCards, ProblemGrid, TrustRow } from '@/components/sections';
import { Button, Container, Eyebrow, GradientText, SectionTitle } from '@/components/ui';
import { links, pillars } from '@/lib/site';

export default function Home() {
  return (
    <>
      <Hero />

      <section className="py-16">
        <Container className="flex flex-col gap-8">
          <SectionTitle eyebrow="Pour qui" title="Une seule application pour les entreprises sur rendez-vous, et pour les commerces qui veulent mieux connaître leurs clients." lead="Le produit est le même pour tous. Ce qui change, c’est le rythme de retour de vos clients, et Menentia s’y adapte." />
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

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle eyebrow="Pourquoi Menentia" title="Pensé pour les PME d’Afrique francophone, pas adapté après coup." />
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
    <section className="relative overflow-hidden">
      <div aria-hidden className="pointer-events-none absolute inset-0 bg-[radial-gradient(60%_50%_at_85%_10%,rgba(0,200,150,.14),transparent),radial-gradient(50%_50%_at_10%_0%,rgba(14,123,214,.12),transparent)]" />
      <Container className="relative grid items-center gap-14 pb-20 pt-14 lg:grid-cols-[1.02fr_1fr] lg:pt-20">
        <div className="flex flex-col gap-7">
          <span className="inline-flex w-fit items-center gap-2 rounded-full border border-line bg-white px-3 py-1.5 text-[13px] font-semibold text-navy shadow-sm">
            <span className="h-2 w-2 rounded-full bg-green" /> Lancement à Libreville le 15 décembre 2026
          </span>
          <h1 className="font-display text-[2.7rem] font-bold leading-[1.05] tracking-[-0.025em] text-navy sm:text-[3.3rem] lg:text-[3.6rem]">
            Connaissez vos clients. Parlez-leur au bon moment. <GradientText>Faites grandir votre activité.</GradientText>
          </h1>
          <p className="max-w-xl text-[1.15rem] leading-relaxed text-muted">
            Fiche client, ventes, fidélité et relances WhatsApp réunies dans une seule application. Chaque matin, Menentia vous dit qui relancer, qui remercier et qui risque de ne plus revenir.
          </p>
          <div className="flex flex-col gap-3 sm:flex-row">
            <Button href={links.signup} variant="brand" size="lg">Essai gratuit de 14 jours <Icon name="arrow" className="h-4 w-4" /></Button>
            <Button href="/contact" variant="outline" size="lg">Demander une démo</Button>
          </div>
          <TrustRow />
        </div>
        <div className="relative pb-10 lg:pb-0">
          <AgendaMock />
          <div className="absolute -bottom-10 right-0 z-20 hidden origin-bottom-right scale-[.78] sm:block lg:-bottom-20 lg:-right-10">
            <WhatsAppMock />
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
          <SectionTitle light eyebrow="Au quotidien" title="Une journée dans un salon avec Menentia." lead="Exemple dans un salon de coiffure de Louis, à Libreville. Les noms et les chiffres sont fictifs." />
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
            <th className="bg-navy px-5 py-4 font-display font-bold text-white">Menentia</th>
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
  { icon: 'message', title: 'Chatbot IA sur WhatsApp', text: 'Il répond à vos clients jour et nuit, propose vos vrais créneaux et prend le rendez-vous.' },
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
            <p className="text-[17px] leading-relaxed text-muted">L’IA de Menentia travaille sur vos propres données : votre catalogue, vos créneaux, vos clients. Elle n’invente jamais un chiffre ni une disponibilité, et passe la main à un humain quand il le faut.</p>
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
