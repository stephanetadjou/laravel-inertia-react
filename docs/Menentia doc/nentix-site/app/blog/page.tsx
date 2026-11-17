import type { Metadata } from 'next';
import { Icon } from '@/components/Icon';
import { PageHero } from '@/components/PageHero';
import { CtaBand } from '@/components/sections';
import { Container } from '@/components/ui';
import { blogCategories, formatDate, posts, readingMinutes, type Post } from '@/lib/blog';

export const metadata: Metadata = {
  title: 'Blog WhatsApp',
  description: 'Guides pratiques pour utiliser WhatsApp Business et l’API WhatsApp dans votre PME : rappels, relances, tarifs, configuration, fidélisation.',
  alternates: { canonical: '/blog' },
};

export default function Blog() {
  const [first, ...rest] = posts;
  return (
    <>
      <PageHero
        eyebrow="Blog"
        title="WhatsApp pour les PME, expliqué simplement."
        lead="Des guides concrets pour les salons, salles de sport, agences et boutiques : faire revenir vos clients, réduire les absences et choisir les bons outils."
      >
        <div className="flex flex-wrap gap-2">
          {blogCategories.map((c) => (
            <span key={c} className="rounded-full border border-white/20 px-4 py-1.5 text-[14px] font-semibold text-white/90">{c}</span>
          ))}
        </div>
      </PageHero>

      <section className="py-16">
        <Container className="flex flex-col gap-14">
          <a href={`/blog/${first.slug}`} className="group grid gap-6 rounded-[1.75rem] border border-line bg-soft p-6 sm:p-10 lg:grid-cols-[1fr_1.4fr] lg:items-center">
            <Cover post={first} big />
            <div className="flex flex-col gap-4">
              <Meta post={first} />
              <h2 className="font-display text-[1.9rem] font-bold leading-tight text-navy group-hover:text-blue-text">{first.title}</h2>
              <p className="text-[1.05rem] leading-relaxed text-muted">{first.excerpt}</p>
              <span className="inline-flex items-center gap-2 font-semibold text-blue-text">Lire l’article <Icon name="arrow" className="h-4 w-4" /></span>
            </div>
          </a>

          <div className="flex flex-col gap-6">
            <h2 className="font-display text-[1.5rem] font-bold text-navy">Tous les articles</h2>
            <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
              {rest.map((p) => <Card key={p.slug} post={p} />)}
            </div>
          </div>
        </Container>
      </section>
      <CtaBand />
    </>
  );
}

function Card({ post }: { post: Post }) {
  return (
    <a href={`/blog/${post.slug}`} className="group flex flex-col gap-4 rounded-2xl border border-line bg-white p-5 transition hover:-translate-y-0.5 hover:shadow-float">
      <Cover post={post} />
      <Meta post={post} />
      <h3 className="font-display text-[1.15rem] font-bold leading-snug text-navy group-hover:text-blue-text">{post.title}</h3>
      <p className="text-[15px] leading-relaxed text-muted">{post.excerpt}</p>
    </a>
  );
}

function Cover({ post, big = false }: { post: Post; big?: boolean }) {
  return (
    <div className={`relative grid place-items-center overflow-hidden rounded-xl bg-navy-deep ${big ? 'aspect-[4/3]' : 'aspect-[16/9]'}`}>
      <div aria-hidden className="absolute inset-0 bg-[radial-gradient(60%_80%_at_85%_10%,rgba(0,200,150,.35),transparent),radial-gradient(60%_80%_at_10%_100%,rgba(14,123,214,.45),transparent)]" />
      <span className="relative grid h-16 w-16 place-items-center rounded-2xl bg-white/10 text-green ring-1 ring-white/20">
        <Icon name={post.icon} className="h-8 w-8" />
      </span>
      <span className="absolute left-3 top-3 rounded-full bg-white/10 px-3 py-1 text-[12px] font-semibold text-white">{post.category}</span>
    </div>
  );
}

function Meta({ post }: { post: Post }) {
  return (
    <p className="text-[13px] font-medium text-muted">
      {formatDate(post.date)} · {readingMinutes(post)} min de lecture
    </p>
  );
}

