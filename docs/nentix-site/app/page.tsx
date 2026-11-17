import { Icon } from '@/components/Icon';
import { ChatbotMock, ClientsRelanceMock, SegmentsMock } from '@/components/mockups';
import { QuickTest } from '@/components/QuickTest';
import { FamilyStrip, ResourceOverview } from '@/components/sections';
import { Button, Container, SectionTitle } from '@/components/ui';
import { families, links } from '@/lib/site';

const tiles = ['bg-[#0B2D4A]', 'bg-[#0E7BD6]', 'bg-[#1F3A5F]', 'bg-[#00876A]', 'bg-[#071E33]', 'bg-[#0A66B2]'];

// Page d'accueil volontairement courte et claire : une idée par section.

const offers = [
  { icon: 'message', title: 'Parler à vos clients', text: 'Rappels de rendez-vous, relances et promotions sur WhatsApp. Le message est déjà écrit, vous l’envoyez.' },
  { icon: 'chart', title: 'Comprendre vos clients', text: 'Qui sont vos meilleurs clients, qui ne revient plus : NENTIX vous le dit chaque matin.' },
  { icon: 'calendar', title: 'Suivre votre activité', text: 'Agenda, ventes, fidélité et rapport du soir au même endroit, sans cahier.' },
];

const automation = [
  { icon: 'message', title: 'Réponses automatiques', text: 'Message d’accueil, réponse hors horaires et menu à boutons (prix, horaires, rendez-vous, parler à quelqu’un). Chaque nouveau contact a sa fiche, avec la pub qui l’a fait venir.' },
  { icon: 'bot', title: 'Un assistant IA sur votre WhatsApp', text: 'Il répond aux questions de vos clients 24 h/24 à partir de vos tarifs et de vos informations, et passe la main à votre équipe dès qu’il ne sait pas.' },
  { icon: 'bell', title: 'Rappels et relances qui partent seuls', text: 'Rappel la veille avec « Je confirme / Je décale », relance au bon moment, campagnes ciblées : vous validez, NENTIX envoie.' },
];

const analysis = [
  { icon: 'pie', title: 'Vos clients classés tout seuls', text: 'Champions, fidèles, à risque, à réactiver : chaque client a son segment et un score de 0 à 100, recalculés chaque nuit.' },
  { icon: 'chart', title: 'L’alerte avant le départ', text: 'NENTIX connaît le rythme de chaque client et vous prévient quand il dépasse son délai habituel, avant qu’il parte chez le concurrent.' },
  { icon: 'sun', title: 'Le rapport du soir', text: 'Chaque soir, la journée en chiffres, ce qui reste libre demain et les clients à appeler, dans votre boîte mail.' },
];

const facts = [
  { value: '14 jours', label: 'd’essai gratuit, sans carte bancaire' },
  { value: 'FCFA', label: 'des prix payables par Mobile Money' },
  { value: '1 clic', label: 'pour envoyer une relance WhatsApp' },
];

const steps = [
  { title: 'Créez votre compte', text: 'Choisissez votre secteur, NENTIX se règle tout seul.' },
  { title: 'Ajoutez vos clients', text: 'Un fichier Excel ou votre téléphone suffit.' },
  { title: 'Suivez « À faire »', text: 'Chaque jour, la liste des clients à relancer, message prêt.' },
];

export default function Home() {
  return (
    <>
      <section className="bg-[#F4F5F7] [background-image:linear-gradient(rgba(11,45,74,.045)_1px,transparent_1px),linear-gradient(90deg,rgba(11,45,74,.045)_1px,transparent_1px)] [background-size:48px_48px]">
        <Container className="flex flex-col items-center gap-6 pb-14 pt-16 text-center sm:pt-24">
          <span className="rounded-full border border-line bg-white px-3 py-1 text-[13px] font-semibold text-navy">The AI Customer Growth Platform for Africa.</span>
          <h1 className="max-w-4xl font-display text-[2.5rem] font-bold leading-[1.08] tracking-[-0.03em] text-navy sm:text-[3.3rem]">
            Le CRM intelligent qui transforme vos interactions clients, vos données et WhatsApp <span className="text-blue">en croissance mesurable.</span>
          </h1>
          <p className="max-w-2xl text-[1.2rem] leading-relaxed text-blue-text">
            Testez-le tout de suite : recevez sur votre téléphone <span className="underline decoration-blue/40 underline-offset-[6px]">le message de bienvenue de votre futur client</span>.
          </p>
          <QuickTest />
          <p className="text-[15px] text-muted">Essai gratuit de 14 jours · Prix en FCFA · Paiement par Mobile Money</p>
        </Container>
        <div className="flex gap-5 overflow-x-auto px-5 pb-16 sm:px-8 [scrollbar-width:none] lg:justify-center">
          {families.map((f, i) => (
            <a key={f.slug} href={`/activites/${f.slug}`} className={`relative flex h-64 w-72 shrink-0 flex-col justify-between overflow-hidden rounded-2xl p-5 text-white transition hover:-translate-y-0.5 ${tiles[i % tiles.length]}`}>
              <span className="inline-flex items-center gap-2 self-start rounded-full bg-black/25 px-3 py-1.5 text-[14px] font-medium ring-1 ring-white/20">
                <Icon name={f.icon} className="h-4 w-4" /> {f.name}
              </span>
              <span className="font-display text-[22px] font-bold leading-snug">{f.message}</span>
            </a>
          ))}
        </div>
      </section>

      <section className="bg-white pt-16">
        <Container className="pb-20">
          <div className="mx-auto max-w-5xl overflow-hidden rounded-2xl border border-line bg-white shadow-card">
            <ClientsRelanceMock />
          </div>
        </Container>
      </section>

      <section className="bg-soft py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center title="Ce que vous faites avec NENTIX" />
          <div className="grid gap-5 md:grid-cols-3">
            {offers.map((o) => (
              <div key={o.title} className="flex flex-col gap-3 rounded-2xl border border-line bg-white p-7">
                                <h3 className="font-display text-[19px] font-bold text-navy">{o.title}</h3>
                <p className="text-[15px] leading-relaxed text-muted">{o.text}</p>
                <a href="/fonctionnalites" className="mt-auto inline-flex items-center gap-1 pt-2 text-[14px] font-semibold text-blue-text">En savoir plus <Icon name="arrow" className="h-4 w-4" /></a>
              </div>
            ))}
          </div>
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-14">
          <SectionTitle
            center
            title="WhatsApp qui travaille pour vous, même la nuit"
            lead="Réponses automatiques, assistant IA et analyse de vos clients : NENTIX répond, trie et vous dit qui relancer, pendant que vous vous occupez de vos clients."
          />
          <div className="grid items-center gap-10 lg:grid-cols-2">
            <div className="flex flex-col gap-6">
              {automation.map((a) => (
                <div key={a.title} className="flex gap-4">
                  <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-blue-soft text-blue-text"><Icon name={a.icon} className="h-5 w-5" /></span>
                  <div className="flex flex-col gap-1">
                    <h3 className="font-display text-[18px] font-bold text-navy">{a.title}</h3>
                    <p className="text-[15px] leading-relaxed text-muted">{a.text}</p>
                  </div>
                </div>
              ))}
            </div>
            <div className="mx-auto w-full max-w-md"><ChatbotMock /></div>
          </div>
          <div className="grid items-center gap-10 lg:grid-cols-2">
            <div className="order-2 min-w-0 overflow-hidden rounded-2xl border border-line bg-white lg:order-1"><SegmentsMock /></div>
            <div className="order-1 flex flex-col gap-6 lg:order-2">
              {analysis.map((a) => (
                <div key={a.title} className="flex gap-4">
                  <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-green-soft text-green-text"><Icon name={a.icon} className="h-5 w-5" /></span>
                  <div className="flex flex-col gap-1">
                    <h3 className="font-display text-[18px] font-bold text-navy">{a.title}</h3>
                    <p className="text-[15px] leading-relaxed text-muted">{a.text}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center title="Chaque activité a une ressource à rentabiliser" lead="Une heure d’intervenante, une place dans une salle, un jour de location : ce qui n’est pas vendu aujourd’hui est perdu. NENTIX remplit les creux, lisse les jours trop pleins et signale ce qui reste inutilisé." />
          <ResourceOverview />
        </Container>
      </section>

      <section className="border-t border-line py-16">
        <Container className="grid gap-8 text-center sm:grid-cols-3">
          {facts.map((f) => (
            <div key={f.value} className="flex flex-col gap-1">
              <span className="font-display text-[2.4rem] font-bold text-navy">{f.value}</span>
              <span className="text-[15px] text-muted">{f.label}</span>
            </div>
          ))}
        </Container>
      </section>

      <section className="bg-soft py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center title="Une seule application pour les entreprises de services et les commerces." lead="Choisissez votre secteur, NENTIX s’adapte à votre façon de suivre vos clients." />
          <FamilyStrip />
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center title="Comment démarrer" />
          <ol className="grid gap-5 md:grid-cols-3">
            {steps.map((s, i) => (
              <li key={s.title} className="flex flex-col gap-2 rounded-2xl border border-line p-7">
                <span className="font-display text-[15px] font-bold text-green-text">Étape {i + 1}</span>
                <h3 className="font-display text-[19px] font-bold text-navy">{s.title}</h3>
                <p className="text-[15px] leading-relaxed text-muted">{s.text}</p>
              </li>
            ))}
          </ol>
        </Container>
      </section>

      <section className="pb-24">
        <Container>
          <div className="flex flex-col items-center gap-6 rounded-3xl bg-soft px-6 py-14 text-center">
            <h2 className="max-w-2xl font-display text-[2rem] font-bold leading-tight text-navy">Essayez NENTIX gratuitement pendant 14 jours.</h2>
            <p className="max-w-xl text-[16px] text-muted">Les 30 premières entreprises ont 3 mois offerts. Installation faite avec vous à Libreville.</p>
            <div className="flex w-full flex-col justify-center gap-3 sm:w-auto sm:flex-row">
              <Button href={links.signup} variant="brand" size="lg">Commencer l’essai</Button>
              <Button href="/contact" variant="outline" size="lg">Nous contacter</Button>
            </div>
          </div>
        </Container>
      </section>
    </>
  );
}
