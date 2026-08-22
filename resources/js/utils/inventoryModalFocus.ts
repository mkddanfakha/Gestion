export function isElementWithinContainer(
  element: Element | null | undefined,
  container: Element | null | undefined,
): boolean {
  if (!element || !container) {
    return false
  }

  return container.contains(element)
}

/** Retire le focus uniquement si l'élément actif appartient à la modale. */
export function blurActiveElementWithin(container: Element | null | undefined): boolean {
  const active = document.activeElement as HTMLElement | null

  if (!active || typeof active.blur !== 'function') {
    return false
  }

  if (!isElementWithinContainer(active, container ?? null)) {
    return false
  }

  active.blur()
  return true
}

export function releaseInventoryModalFocus(modalRoot: HTMLElement | null | undefined): void {
  blurActiveElementWithin(modalRoot ?? null)

  if (
    isElementWithinContainer(document.activeElement, modalRoot ?? null)
    && typeof document.body?.focus === 'function'
  ) {
    document.body.focus()
  }
}

/** Attend le prochain cycle de rendu après fermeture (équivalent hidden.bs.modal pour v-if). */
export function waitForInventoryModalHidden(): Promise<void> {
  return new Promise((resolve) => {
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        resolve()
      })
    })
  })
}
