import { brand, families, links, MARKETPLACE_URL, WHATSAPP_DISPLAY } from '@/lib/site';
import { Container, Logo } from './ui';

export function Footer() {
  return (
    <footer className="bg-navy-deep text-[#B7C8D9]">
      <Container className="grid gap-10 py-16 md:grid-cols-[1.4fr_1fr_1fr_1fr]">
        <div className="flex max-w-xs flex-col gap-4">
          <Logo light id="lg-footer" />
          <p className="text-[15px] leading-relaxed">{brand.description}</p>
          <p className="text-sm font-semibold tracking-wide text-green">{brand.signature}</p>
        </div>
        <FooterCol title="Activités" items={families.map((f) => ({ label: f.name, href: `/activites/${f.slug}` }))} />
        <FooterCol
          title="Produit"
          items={[
            { label: 'Fonctionnalités', href: '/fonctionnalites' },
            { label: 'Guide WhatsApp', href: '/whatsapp' },
            { label: 'Tarifs', href: '/tarifs' },
            { label: 'Marketplace', href: MARKETPLACE_URL },
            { label: 'Se connecter', href: links.login },
            { label: 'Essai gratuit', href: links.signup },
          ]}
        />
        <div className="flex flex-col gap-3">
          <p className="font-display text-[15px] font-bold text-white">Contact</p>
          <a href="/contact" className="text-[15px] hover:text-white">Demander une démo</a>
          <p className="text-[15px]">WhatsApp : <span className="select-all text-white">{WHATSAPP_DISPLAY}</span></p>
          <p className="text-[15px]">Libreville, Gabon</p>
        </div>
      </Container>
      <Container className="flex flex-col gap-2 border-t border-white/10 py-6 text-[13px] sm:flex-row sm:justify-between">
        <p>© 2026 NENTIX. Tous droits réservés.</p>
        <p>Paiement par Mobile Money · Support en français sur WhatsApp</p>
      </Container>
    </footer>
  );
}

function FooterCol({ title, items }: { title: string; items: { label: string; href: string }[] }) {
  return (
    <div className="flex flex-col gap-3">
      <p className="font-display text-[15px] font-bold text-white">{title}</p>
      {items.map((i) => (
        <a key={i.href} href={i.href} className="text-[15px] hover:text-white">
          {i.label}
        </a>
      ))}
    </div>
  );
}
