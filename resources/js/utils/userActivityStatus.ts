/**
 * Libellés FR pour la colonne Statut du journal « Activité des utilisateurs ».
 * Sources : labels déjà utilisés dans les modèles / pages métier MKD-Pro.
 * Les valeurs backend restent inchangées (clés anglaises / codes métier).
 */
const SALE_STATUS_LABELS: Record<string, string> = {
  pending: 'En attente',
  completed: 'Terminé',
  cancelled: 'Annulé',
}

const QUOTE_STATUS_LABELS: Record<string, string> = {
  draft: 'Brouillon',
  sent: 'Envoyé',
  accepted: 'Accepté',
  rejected: 'Refusé',
  expired: 'Expiré',
}

const PURCHASE_ORDER_STATUS_LABELS: Record<string, string> = {
  draft: 'Brouillon',
  sent: 'Envoyé',
  confirmed: 'Confirmé',
  partially_received: 'Partiellement reçu',
  received: 'Reçu',
  cancelled: 'Annulé',
}

const DELIVERY_NOTE_STATUS_LABELS: Record<string, string> = {
  pending: 'En attente',
  validated: 'Validé',
  cancelled: 'Annulé',
}

/** Aligné sur Expense::getCategoryLabelAttribute (colonne « statut » = catégorie pour les dépenses). */
const EXPENSE_CATEGORY_LABELS: Record<string, string> = {
  fournitures: 'Fournitures',
  equipement: 'Équipement',
  marketing: 'Marketing',
  transport: 'Transport',
  formation: 'Formation',
  maintenance: 'Maintenance',
  utilities: 'Services publics',
  autres: 'Autres',
}

/** Aligné sur getInventoryStatusListLabel / InventoryExportService. */
const INVENTORY_STATUS_LABELS: Record<string, string> = {
  draft: 'Brouillon',
  counting: 'Comptage',
  review: 'Révision',
  validated: 'Validé',
  applied: 'Appliqué',
  closed: 'Clôturé',
  cancelled: 'Annulé',
}

const LABELS_BY_ACTIVITY_TYPE: Record<string, Record<string, string>> = {
  sale: SALE_STATUS_LABELS,
  expense: EXPENSE_CATEGORY_LABELS,
  quote: QUOTE_STATUS_LABELS,
  purchase_order: PURCHASE_ORDER_STATUS_LABELS,
  delivery_note: DELIVERY_NOTE_STATUS_LABELS,
  inventory: INVENTORY_STATUS_LABELS,
}

export function formatUserActivityStatus(
  activityType: string,
  status: string | null | undefined,
): string {
  if (status === null || status === undefined || status === '') {
    return '—'
  }

  const labels = LABELS_BY_ACTIVITY_TYPE[activityType]
  return labels?.[status] ?? status
}
