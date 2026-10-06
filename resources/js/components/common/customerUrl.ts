/**
 * Lien vers la fiche client d'un contact (la fiche contact a été fusionnée
 * dedans). Sans fiche rattachée, l'ancienne URL redirige côté serveur.
 */
export function customerUrl(contact: { id: number; customer_id?: number | null }): string {
    return contact.customer_id ? `/customers/${contact.customer_id}` : `/contacts/${contact.id}`;
}
