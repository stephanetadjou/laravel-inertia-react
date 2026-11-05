import { Link, router, usePage } from "@inertiajs/react";

import { useSidebar } from "../context/SidebarContext";
import {
    MenentiaSymbol,
    MenentiaWordmark,
} from "../components/common/MenentiaLogo";
import { InfoIcon, LockIcon } from "../icons";
import { buildSections, currentSection, navContext } from "./navigation";

interface SidebarProps {
    auth?: {
        user: {
            can_manage_organization?: boolean;
            can_import?: boolean;
            is_platform_admin?: boolean;
        } | null;
        organization?: {
            name?: string;
            sector?: string | null;
            whatsapp_api_enabled?: boolean;
            sells_products?: boolean;
            modules?: string[];
        } | null;
    };
    support?: string | null;
    [key: string]: unknown;
}

/** Menu latéral NENTIX, bleu nuit (inspiré du portail NEXUS), court : une entrée par grande rubrique (cf. navigation.tsx). */
const AppSidebar: React.FC = () => {
    const { isExpanded, isMobileOpen, isHovered, setIsHovered } = useSidebar();
    const { url, props } = usePage<SidebarProps>();

    const sections = buildSections(navContext(props.auth));
    const active = currentSection(sections, url);
    const showLabels = isExpanded || isHovered || isMobileOpen;

    const org = props.auth?.organization;
    const initials = (org?.name ?? "N")
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]!.toUpperCase())
        .join("");
    const itemBase = `group flex items-center gap-3 rounded-xl border px-3 py-2.5 text-[14px] whitespace-nowrap transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 ${showLabels ? "" : "justify-center"}`;

    return (
        <aside
            className={`nx-sidebar fixed top-0 left-0 z-50 mt-14 flex h-screen flex-col px-3 text-white/85 transition-all duration-300 ease-in-out lg:mt-0 ${
                showLabels ? "w-[248px]" : "w-[76px]"
            } ${isMobileOpen ? "translate-x-0" : "-translate-x-full"} lg:translate-x-0`}
            onMouseEnter={() => !isExpanded && setIsHovered(true)}
            onMouseLeave={() => setIsHovered(false)}
        >
            <div
                className={`flex h-16 shrink-0 items-center ${showLabels ? "px-2" : "justify-center"}`}
            >
                <Link
                    href="/dashboard"
                    className="flex items-center gap-2.5"
                    aria-label="NENTIX, accueil"
                >
                    <MenentiaSymbol className="h-8 w-8" onDark />
                    {showLabels && (
                        <MenentiaWordmark onDark className="text-[24px]" />
                    )}
                </Link>
            </div>

            {org?.name && (
                <div
                    className={`mb-3 flex shrink-0 items-center gap-3 rounded-xl bg-white/10 p-2.5 ring-1 ring-white/10 ${showLabels ? "" : "justify-center"}`}
                    title={showLabels ? undefined : org.name}
                >
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-cyan-400 to-blue-600 text-[13px] font-bold text-white shadow-sm">
                        {initials}
                    </span>
                    {showLabels && (
                        <span className="min-w-0">
                            <span className="block truncate text-[13px] font-semibold text-white uppercase">
                                {org.name}
                            </span>
                            <span className="block truncate text-[11.5px] text-cyan-200/80">
                                Espace entreprise
                            </span>
                        </span>
                    )}
                </div>
            )}

            <nav
                className="no-scrollbar flex flex-1 flex-col gap-1.5 overflow-y-auto pb-4"
                aria-label="Menu principal"
            >
                {sections.map((section) => {
                    const isActive = active?.key === section.key;

                    return (
                        <Link
                            key={section.key}
                            href={section.path}
                            aria-current={isActive ? "page" : undefined}
                            title={showLabels ? undefined : section.name}
                            className={`${itemBase} ${
                                isActive
                                    ? "border-white/25 bg-white font-semibold text-[#0B1F3A] shadow-lg shadow-black/20"
                                    : "border-white/10 bg-white/[0.04] hover:border-white/25 hover:bg-white/10 hover:text-white"
                            } ${section.key === "settings" ? "mt-3" : ""}`}
                        >
                            <span
                                className={`shrink-0 [&_svg]:size-[18px] ${isActive ? "text-[#1D4ED8]" : "text-cyan-200/90 group-hover:text-white"}`}
                            >
                                {section.icon}
                            </span>
                            {showLabels && <span>{section.name}</span>}
                        </Link>
                    );
                })}
            </nav>

            <div className="mb-16 flex shrink-0 flex-col gap-1 border-t border-white/10 pt-3 lg:mb-4">
                {props.support && (
                    <a
                        href={props.support}
                        target="_blank"
                        rel="noopener noreferrer"
                        title={showLabels ? undefined : "Besoin d’aide ?"}
                        className={`${itemBase} border-transparent hover:bg-white/10 hover:text-white`}
                    >
                        <span className="shrink-0 text-cyan-200/90 [&_svg]:size-[18px]">
                            <InfoIcon />
                        </span>
                        {showLabels && <span>Besoin d’aide ?</span>}
                    </a>
                )}
                <button
                    type="button"
                    onClick={() => router.post("/logout")}
                    title={showLabels ? undefined : "Déconnexion"}
                    className={`${itemBase} border-transparent text-left text-rose-200 hover:bg-white/10 hover:text-rose-100`}
                >
                    <span className="shrink-0 [&_svg]:size-[18px]">
                        <LockIcon />
                    </span>
                    {showLabels && <span>Déconnexion</span>}
                </button>
            </div>
        </aside>
    );
};

export default AppSidebar;
