import { discoverMenu, families, featureMenu, links, type MenuItem } from '@/lib/site';
import { Icon } from './Icon';
import { NavDropdown } from './NavDropdown';
import { Logo } from './ui';

// En-tête sombre : Activités et Fonctionnalités s'ouvrent au clic (NavDropdown),
// le menu mobile repose sur <details>, avec une rubrique dépliable pour chacun.
export function Header() {
  return (
    <header className="sticky top-0 z-40 border-b border-line bg-white/95 backdrop-blur-md">
      <div className="mx-auto flex h-[72px] w-full max-w-[1320px] items-center gap-6 px-5 sm:px-8">
        <a href="/" aria-label="ClientlyX, accueil" className="shrink-0">
          <Logo id="lg-header" />
        </a>

        <nav className="hidden items-center gap-1 lg:flex" aria-label="Navigation principale">
          <NavDropdown label="Activités">
            <div className="grid grid-cols-3 gap-2">
              {families.map((f) => (
                <a key={f.slug} href={`/activites/${f.slug}`} className="flex items-start gap-3 rounded-xl p-3 hover:bg-soft">
                  <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-blue-soft text-navy ring-1 ring-line">
                    <Icon name={f.icon} className="h-[18px] w-[18px]" />
                  </span>
                  <span>
                    <span className="block text-[15px] font-semibold text-navy">{f.name}</span>
                    <span className="block text-[13px] leading-snug text-muted">{f.trades.slice(0, 3).join(', ')}</span>
                  </span>
                </a>
              ))}
              <a href="/activites" className="flex items-center gap-2 rounded-xl p-3 text-[14px] font-semibold text-green-text hover:bg-soft">
                Toutes les activités <Icon name="arrow" className="h-4 w-4" />
              </a>
            </div>
          </NavDropdown>

          <NavDropdown label="Fonctionnalités">
            <div className="grid grid-cols-3 gap-x-2 gap-y-1">
              {featureMenu.map((col) => (
                <div key={col.title} className="flex flex-col gap-1">
                  <p className="px-3 pb-1 text-xs font-bold uppercase tracking-[0.16em] text-muted">{col.title}</p>
                  {col.items.map((i) => <FeatureCard key={i.label} item={i} />)}
                </div>
              ))}
            </div>
            <div className="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-line pt-4">
              {discoverMenu.map((i) => (
                <a key={i.label} href={i.href} className="inline-flex items-center gap-2 text-[14px] font-semibold text-navy/80 hover:text-green-text">
                  <Icon name={i.icon} className="h-4 w-4 text-green-text" /> {i.label}
                </a>
              ))}
            </div>
          </NavDropdown>

          <a href="/tarifs" className="rounded-full px-4 py-2 text-[15px] font-medium text-navy/80 hover:text-navy">Tarifs</a>
          <a href="/whatsapp" className="rounded-full px-4 py-2 text-[15px] font-medium text-navy/80 hover:text-navy">WhatsApp</a>
          <a href="/blog" className="rounded-full px-4 py-2 text-[15px] font-medium text-navy/80 hover:text-navy">Blog</a>
        </nav>

        <div className="ml-auto hidden items-center gap-3 lg:flex">
          <a href={links.login} className="whitespace-nowrap px-2 text-[15px] font-semibold text-navy/80 hover:text-navy">Se connecter</a>
          <a href="/contact" className="inline-flex h-11 items-center whitespace-nowrap rounded-lg border border-line px-4 text-[15px] font-semibold text-navy hover:border-navy/40">Demander une démo</a>
          <a href={links.signup} className="inline-flex h-11 items-center whitespace-nowrap rounded-lg bg-green px-5 text-[15px] font-semibold text-white hover:brightness-110">Essai gratuit</a>
        </div>

        <details className="group ml-auto lg:hidden">
          <summary className="inline-flex h-11 cursor-pointer list-none items-center gap-2 rounded-lg border border-line px-4 text-[15px] font-semibold text-navy [&::-webkit-details-marker]:hidden">
            Menu <Icon name="menu" className="h-5 w-5 group-open:hidden" /><Icon name="close" className="hidden h-5 w-5 group-open:block" />
          </summary>
          <div className="absolute inset-x-0 top-[72px] max-h-[calc(100dvh-72px)] overflow-y-auto border-b border-line bg-white px-5 pb-6 pt-2 shadow-float">
            <MobileSection label="Activités">
              {families.map((f) => (
                <a key={f.slug} href={`/activites/${f.slug}`} className="flex items-center gap-3 rounded-xl px-2 py-2.5 text-[16px] font-medium text-navy">
                  <Icon name={f.icon} className="h-[18px] w-[18px] text-green-text" /> {f.name}
                </a>
              ))}
            </MobileSection>
            <MobileSection label="Fonctionnalités">
              {featureMenu.map((col) => (
                <div key={col.title}>
                  <p className="px-2 pt-3 text-xs font-bold uppercase tracking-[0.16em] text-muted">{col.title}</p>
                  {col.items.map((i) => <MenuLink key={i.label} item={i} />)}
                </div>
              ))}
            </MobileSection>
            <div className="my-3 h-px bg-line" />
            <a href="/fonctionnalites" className="block rounded-xl px-2 py-2.5 text-[16px] font-medium text-navy">Toutes les fonctionnalités</a>
            <a href="/tarifs" className="block rounded-xl px-2 py-2.5 text-[16px] font-medium text-navy">Tarifs</a>
            <a href="/whatsapp" className="block rounded-xl px-2 py-2.5 text-[16px] font-medium text-navy">Guide WhatsApp</a>
            <a href="/blog" className="block rounded-xl px-2 py-2.5 text-[16px] font-medium text-navy">Blog</a>
            <a href="/contact" className="block rounded-xl px-2 py-2.5 text-[16px] font-medium text-navy">Demander une démo</a>
            <div className="mt-4 grid grid-cols-2 gap-2">
              <a href={links.login} className="inline-flex h-12 items-center justify-center rounded-lg border border-line font-semibold text-navy">Se connecter</a>
              <a href={links.signup} className="inline-flex h-12 items-center justify-center rounded-lg bg-green font-semibold text-white">Essai gratuit</a>
            </div>
          </div>
        </details>
      </div>
    </header>
  );
}

function MenuLink({ item }: { item: MenuItem }) {
  return (
    <a href={item.href} className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-semibold text-navy hover:bg-soft">
      <Icon name={item.icon} className="h-5 w-5 shrink-0 text-green-text" />
      <span className="whitespace-nowrap">{item.label}</span>
      {item.soon && <span className="rounded-full bg-blue-soft px-2 py-0.5 text-[11px] font-semibold text-blue-text">Bientôt</span>}
    </a>
  );
}

function FeatureCard({ item }: { item: MenuItem }) {
  return (
    <a href={item.href} className="flex items-start gap-3 rounded-xl p-3 hover:bg-soft">
      <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-blue-soft text-navy ring-1 ring-line">
        <Icon name={item.icon} className="h-[18px] w-[18px]" />
      </span>
      <span>
        <span className="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-navy">
          {item.label}
          {item.soon && <span className="rounded-full bg-blue-soft px-2 py-0.5 text-[11px] font-semibold text-blue-text">Bientôt</span>}
        </span>
        {item.desc && <span className="block text-[13px] leading-snug text-muted">{item.desc}</span>}
      </span>
    </a>
  );
}

function MobileSection({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <details className="group/sec border-b border-line">
      <summary className="flex cursor-pointer list-none items-center justify-between px-2 py-3.5 text-[16px] font-semibold text-navy [&::-webkit-details-marker]:hidden">
        {label} <Icon name="chevron" className="h-5 w-5 text-muted transition group-open/sec:rotate-180" />
      </summary>
      <div className="pb-3">{children}</div>
    </details>
  );
}
