/** Couleur de la pastille de statut d'un import. */
export function statusTone(status: string): string {
    switch (status) {
        case 'completed':
            return 'ok';
        case 'completed_with_errors':
        case 'validated':
        case 'mapping_required':
            return 'warn';
        case 'failed':
            return 'bad';
        case 'processing':
            return 'info';
        default:
            return 'idle';
    }
}
