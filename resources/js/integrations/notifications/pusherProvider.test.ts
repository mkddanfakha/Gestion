import { describe, expect, it, vi } from 'vitest'

vi.mock('@/echo', () => ({
  default: null,
}))

import { createPusherNotificationProvider } from './pusherProvider'

describe('createPusherNotificationProvider', () => {
  it("tolère l'absence d'Echo avec un désabonnement no-op", () => {
    const provider = createPusherNotificationProvider(42)
    const onMessage = vi.fn()

    const unsubscribe = provider.subscribe(onMessage)

    expect(onMessage).not.toHaveBeenCalled()
    expect(() => unsubscribe()).not.toThrow()
  })
})
