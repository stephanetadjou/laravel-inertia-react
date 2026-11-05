import { Link, usePage } from "@inertiajs/react";

import { CalenderIcon, PlusIcon, UserIcon } from "../../icons";

interface BannerProps {
    auth?: {
        user: { name: string; role_name?: string | null } | null;
        organization?: { name?: string; has_agenda?: boolean } | null;
    };
    [key: string]: unknown;
}

/** Bannière d'accueil NENTIX (inspirée du portail NEXUS) : salutation, pastilles, action principale. */
export default function WelcomeBanner() {
    const { auth } = usePage<BannerProps>().props;
    const org = auth?.organization;
    const today = new Date().toLocaleDateString("fr-FR", {
        weekday: "long",
        day: "numeric",
        month: "long",
        year: "numeric",
    });
    const hour = new Date().getHours();
    const hello = hour < 18 ? "Bonjour" : "Bonsoir";
    const action = org?.has_agenda
        ? { href: "/appointments", label: "Nouveau RDV" }
        : { href: "/purchases", label: "Nouvelle vente" };

    return (
        <section className="nx-banner" aria-label="Bienvenue">
            <div className="nx-banner-in">
                <span className="nx-chip nx-chip-glow">
                    {hello}, {org?.name ?? auth?.user?.name}
                </span>
                <h1>Bienvenue sur NENTIX !</h1>
                <p>
                    Connaissez vos clients, parlez-leur au bon moment et faites
                    grandir votre activité : tout se pilote ici, simplement.
                </p>
                <div className="nx-banner-chips">
                    {auth?.user && (
                        <span className="nx-chip">
                            <UserIcon /> {auth.user.name}
                            {auth.user.role_name
                                ? ` · ${auth.user.role_name}`
                                : ""}
                        </span>
                    )}
                    <span className="nx-chip">
                        <CalenderIcon /> {today}
                    </span>
                </div>
            </div>
            <Link href={action.href} className="nx-banner-cta">
                <PlusIcon /> {action.label}
            </Link>
        </section>
    );
}

/** Accès rapides : raccourcis vers les écrans les plus utilisés. */
export function QuickAccess({
    links,
}: {
    links: { href: string; label: string; icon: React.ReactNode }[];
}) {
    return (
        <article className="mdash-panel nx-quick">
            <h2>Accès rapides</h2>
            <div className="nx-quick-grid">
                {links.map((l) => (
                    <Link
                        key={l.href + l.label}
                        href={l.href}
                        className="nx-quick-item"
                    >
                        <span aria-hidden="true">{l.icon}</span>
                        {l.label}
                    </Link>
                ))}
            </div>
        </article>
    );
}
