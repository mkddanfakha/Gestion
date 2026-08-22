export type InventoryApplicationSummary = {
  total_items: number
  adjusted_items: number
  unchanged_items: number
  positive_adjustments: number
  negative_adjustments: number
  total_positive_quantity: number
  total_negative_quantity: number
  net_adjustment: number
}

export function shouldShowApplyButton(
  status: string,
  canApply: boolean,
  hasPermission: boolean,
): boolean {
  return status === 'validated' && canApply && hasPermission
}

export function shouldShowCloseButton(
  status: string,
  canClose: boolean,
  hasPermission: boolean,
): boolean {
  return status === 'applied' && canClose && hasPermission
}

export function isInventoryReadOnly(status: string): boolean {
  return status === 'closed' || status === 'cancelled'
}

export function inventoryApplicationConfirmMessage(adjustedItems: number): string {
  return `Cette action modifiera le stock réel de ${adjustedItems} produit(s) et créera les mouvements d'inventaire correspondants. Cette opération ne pourra pas être annulée automatiquement.`
}

export function formatApplicationSummaryLine(summary: InventoryApplicationSummary): string {
  return `${summary.adjusted_items} produit(s) ajusté(s), ${summary.unchanged_items} inchangé(s), net ${summary.net_adjustment >= 0 ? '+' : ''}${summary.net_adjustment}`
}
