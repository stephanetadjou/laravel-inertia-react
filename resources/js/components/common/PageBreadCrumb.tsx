import { Link } from '@inertiajs/react';

interface BreadcrumbProps {
    pageTitle: string;
}

const PageBreadcrumb: React.FC<BreadcrumbProps> = ({ pageTitle }) => {
    return (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-xl font-semibold text-gray-800 dark:text-white/90">{pageTitle}</h2>
            <nav>
                <ol className="flex items-center gap-1.5">
                    <li>
                        <Link className="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400" href="/dashboard">
                            Accueil
                        </Link>
                    </li>
                    <li className="text-sm text-gray-800 dark:text-white/90">{pageTitle}</li>
                </ol>
            </nav>
        </div>
    );
};

export default PageBreadcrumb;
