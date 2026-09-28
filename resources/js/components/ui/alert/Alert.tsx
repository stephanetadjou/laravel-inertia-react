interface AlertProps {
    variant: 'success' | 'error' | 'warning' | 'info';
    title: string;
    message: string;
}

const Alert: React.FC<AlertProps> = ({ variant, title, message }) => {
    const variantClasses = {
        success: {
            container: 'border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15',
            icon: 'text-success-500',
        },
        error: {
            container: 'border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15',
            icon: 'text-error-500',
        },
        warning: {
            container: 'border-warning-500 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/15',
            icon: 'text-warning-500',
        },
        info: {
            container: 'border-blue-light-500 bg-blue-light-50 dark:border-blue-light-500/30 dark:bg-blue-light-500/15',
            icon: 'text-blue-light-500',
        },
    };

    return (
        <div className={`rounded-xl border p-4 ${variantClasses[variant].container}`}>
            <div className="flex items-start gap-3">
                <div className={`mt-0.5 ${variantClasses[variant].icon}`}>
                    <svg className="fill-current" width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2Zm1 15h-2v-2h2v2Zm0-4h-2V7h2v6Z" />
                    </svg>
                </div>
                <div>
                    <h4 className="mb-1 text-sm font-semibold text-gray-800 dark:text-white/90">{title}</h4>
                    <p className="text-sm text-gray-500 dark:text-gray-400">{message}</p>
                </div>
            </div>
        </div>
    );
};

export default Alert;
