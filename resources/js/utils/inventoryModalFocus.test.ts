import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  blurActiveElementWithin,
  isElementWithinContainer,
  releaseInventoryModalFocus,
} from './inventoryModalFocus'

describe('inventoryModalFocus', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('detects whether the active element belongs to a modal container', () => {
    const input = { id: 'input' }
    const modal = {
      contains(element: unknown) {
        return element === input
      },
    }

    expect(isElementWithinContainer(input as Element, modal as Element)).toBe(true)
    expect(isElementWithinContainer({ id: 'outside' } as Element, modal as Element)).toBe(false)
  })

  it('blurs only focused elements inside the modal', () => {
    const blur = vi.fn()
    const input = { blur }
    const modal = {
      contains(element: unknown) {
        return element === input
      },
    }
    const outside = { blur: vi.fn() }

    vi.stubGlobal('document', {
      activeElement: input,
    })

    expect(blurActiveElementWithin(modal as Element)).toBe(true)
    expect(blur).toHaveBeenCalledOnce()

    vi.stubGlobal('document', {
      activeElement: outside,
    })

    expect(blurActiveElementWithin(modal as Element)).toBe(false)
    expect(outside.blur).not.toHaveBeenCalled()
  })

  it('releases modal focus without disturbing external elements', () => {
    const modal = {
      contains() {
        return false
      },
    }
    const outside = { blur: vi.fn() }

    vi.stubGlobal('document', {
      activeElement: outside,
      body: { focus: vi.fn() },
    })

    releaseInventoryModalFocus(modal as HTMLElement)

    expect(outside.blur).not.toHaveBeenCalled()
  })

  it('falls back to body focus when blur does not move focus away from modal', () => {
    const blur = vi.fn()
    const input = { blur }
    const modal = {
      contains(element: unknown) {
        return element === input
      },
    }
    const bodyFocus = vi.fn()

    vi.stubGlobal('document', {
      activeElement: input,
      body: { focus: bodyFocus },
    })

    releaseInventoryModalFocus(modal as HTMLElement)

    expect(blur).toHaveBeenCalledOnce()
    expect(bodyFocus).toHaveBeenCalledOnce()
  })
})
