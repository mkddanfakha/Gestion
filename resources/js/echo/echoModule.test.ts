import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const echoConstructor = vi.fn(function EchoMock(this: unknown) {
  return {
    connector: {
      pusher: {
        connection: {
          bind: vi.fn(),
        },
      },
    },
  }
})

describe('echo module', () => {
  beforeEach(() => {
    vi.resetModules()
    echoConstructor.mockClear()
  })

  afterEach(() => {
    vi.unstubAllEnvs()
  })

  it("n'instancie pas Echo lorsque VITE_PUSHER_APP_KEY est absente", async () => {
    vi.stubEnv('VITE_PUSHER_APP_KEY', '')
    vi.doMock('laravel-echo', () => ({ default: echoConstructor }))
    vi.doMock('pusher-js', () => ({ default: vi.fn() }))

    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})

    const { default: echo } = await import('../echo')

    expect(echo).toBeNull()
    expect(echoConstructor).not.toHaveBeenCalled()
    expect(warnSpy).toHaveBeenCalled()

    warnSpy.mockRestore()
  })

  it('instancie Echo lorsque VITE_PUSHER_APP_KEY est définie', async () => {
    vi.stubEnv('VITE_PUSHER_APP_KEY', 'build-time-key')
    vi.stubEnv('VITE_PUSHER_APP_CLUSTER', 'eu')
    vi.doMock('laravel-echo', () => ({ default: echoConstructor }))
    vi.doMock('pusher-js', () => ({ default: vi.fn() }))

    const { default: echo } = await import('../echo')

    expect(echo).not.toBeNull()
    expect(echoConstructor).toHaveBeenCalledTimes(1)
    expect(echoConstructor.mock.calls[0][0]).toMatchObject({
      broadcaster: 'pusher',
      key: 'build-time-key',
      cluster: 'eu',
      authEndpoint: '/broadcasting/auth',
    })
  })
})
