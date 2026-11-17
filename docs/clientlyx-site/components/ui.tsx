import type { ReactNode } from 'react';
import { Icon } from './Icon';
import type { Status } from '@/lib/site';

// Logo ClientlyX « Piste B » (15/11/2026) : hexagone prune, C orange, flèche jaune.
// Sur fond sombre (light) : hexagone #3D2757 cerné d'orange pour rester visible.
export function LogoMark({ className = 'h-8 w-8', light: dark = false }: { className?: string; id?: string; light?: boolean }) {
  return (
    <svg viewBox="0 0 100 100" className={className} aria-hidden="true">
      <polygon points="50,4 89.8,27 89.8,73 50,96 10.2,73 10.2,27" fill={dark ? '#3D2757' : '#2B1A3D'} stroke={dark ? '#E8590C' : 'none'} strokeWidth={dark ? 4 : 0} strokeLinejoin="round" />
      <path d="M63 35 A20 20 0 1 0 63 65" fill="none" stroke="#E8590C" strokeWidth="10" strokeLinecap="round" />
      <path d="M62 27 L74 27 L74 39" fill="none" stroke="#FFD43B" strokeWidth="5" strokeLinecap="round" strokeLinejoin="round" />
      <path d="M60 41 L73 28" stroke="#FFD43B" strokeWidth="5" strokeLinecap="round" />
    </svg>
  );
}

export function Logo({ light = false, id }: { light?: boolean; id?: string }) {
  return (
    <span className="inline-flex items-center gap-2.5" data-logo={id} aria-label="ClientlyX">
      <LogoMark className="h-9 w-9" light={light} />
      <span aria-hidden className={`font-display text-[22px] font-bold tracking-[-0.02em] ${light ? 'text-white' : 'text-navy'}`}>
        Cliently<span className="text-green">X</span>
      </span>
    </span>
  );
}

type BtnProps = { href: string; children: ReactNode; variant?: 'dark' | 'outline' | 'light' | 'brand'; size?: 'md' | 'lg'; className?: string };

export function Button({ href, children, variant = 'dark', size = 'md', className = '' }: BtnProps) {
  const base = 'inline-flex whitespace-nowrap items-center justify-center gap-2 rounded-full font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue';
  const sizes = { md: 'h-11 px-5 text-[15px]', lg: 'h-[52px] px-7 text-base' };
  const variants = {
    dark: 'bg-navy text-white hover:bg-navy-deep',
    brand: 'bg-green text-white hover:bg-green-text',
    outline: 'border border-line bg-white text-navy hover:border-navy/40',
    light: 'bg-white text-navy hover:bg-soft',
  };
  return (
    <a href={href} className={`${base} ${sizes[size]} ${variants[variant]} ${className}`}>
      {children}
    </a>
  );
}

export function Eyebrow({ children, tone = 'blue' }: { children: ReactNode; tone?: 'blue' | 'green' | 'light' }) {
  const tones = { blue: 'text-blue-text', green: 'text-green-text', light: 'text-green' };
  return <p className={`text-[13px] font-bold uppercase tracking-[0.16em] ${tones[tone]}`}>{children}</p>;
}

export function SectionTitle({ eyebrow, title, lead, center = false, light = false }: { eyebrow?: string; title: ReactNode; lead?: ReactNode; center?: boolean; light?: boolean }) {
  return (
    <div className={`flex flex-col gap-4 ${center ? 'items-center text-center mx-auto' : ''} max-w-3xl`}>
      {eyebrow && <Eyebrow tone={light ? 'light' : 'blue'}>{eyebrow}</Eyebrow>}
      <h2 className={`font-display text-[2rem] font-bold leading-[1.08] tracking-[-0.01em] text-balance sm:text-[2.6rem] ${light ? 'text-white' : 'text-navy'}`}>{title}</h2>
      {lead && <p className={`text-lg leading-relaxed ${light ? 'text-[#E2D8EC]' : 'text-muted'}`}>{lead}</p>}
    </div>
  );
}

export function StatusBadge({ status }: { status: Status }) {
  return status === 'dispo' ? (
    <span className="inline-flex items-center gap-1 rounded-full bg-green-soft px-2.5 py-0.5 text-xs font-semibold text-green-text">
      <Icon name="check" className="h-3 w-3" strokeWidth={3} /> Disponible
    </span>
  ) : (
    <span className="inline-flex items-center rounded-full bg-blue-soft px-2.5 py-0.5 text-xs font-semibold text-blue-text">Bientôt</span>
  );
}

export function Container({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`mx-auto w-full max-w-page px-5 sm:px-8 ${className}`}>{children}</div>;
}

export function GradientText({ children }: { children: ReactNode }) {
  return <span className="text-green">{children}</span>;
}
