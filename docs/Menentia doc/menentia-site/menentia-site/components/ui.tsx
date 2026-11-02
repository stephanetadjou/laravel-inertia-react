import type { ReactNode } from 'react';
import { Icon } from './Icon';
import type { Status } from '@/lib/site';

// Logo : un M en dégradé bleu-vert, sans le point (décision du 28/10/2026).
export function LogoMark({ className = 'h-7 w-8', id = 'lg' }: { className?: string; id?: string }) {
  return (
    <svg viewBox="0 0 100 84" className={className} aria-hidden="true">
      <defs>
        <linearGradient id={id} x1="0" x2="1">
          <stop offset="0" stopColor="#0E7BD6" />
          <stop offset="1" stopColor="#00C896" />
        </linearGradient>
      </defs>
      <path d="M16 70 V20 L50 54 L84 20 V70" fill="none" stroke={`url(#${id})`} strokeWidth="17" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

export function Logo({ light = false, id }: { light?: boolean; id?: string }) {
  return (
    <span className="inline-flex items-center gap-2.5">
      <LogoMark id={id} />
      <span className={`font-display text-[1.35rem] font-bold tracking-tight ${light ? 'text-white' : 'text-navy'}`}>Menentia</span>
    </span>
  );
}

type BtnProps = { href: string; children: ReactNode; variant?: 'dark' | 'outline' | 'light' | 'brand'; size?: 'md' | 'lg'; className?: string };

export function Button({ href, children, variant = 'dark', size = 'md', className = '' }: BtnProps) {
  const base = 'inline-flex whitespace-nowrap items-center justify-center gap-2 rounded-full font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue';
  const sizes = { md: 'h-11 px-5 text-[15px]', lg: 'h-[52px] px-7 text-base' };
  const variants = {
    dark: 'bg-navy text-white hover:bg-navy-deep',
    brand: 'bg-gradient-to-r from-blue to-green text-white hover:brightness-105 shadow-[0_8px_24px_-8px_rgba(14,123,214,.6)]',
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
      {lead && <p className={`text-lg leading-relaxed ${light ? 'text-[#C9D9E8]' : 'text-muted'}`}>{lead}</p>}
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
  return <span className="bg-gradient-to-r from-blue to-green bg-clip-text text-transparent">{children}</span>;
}
