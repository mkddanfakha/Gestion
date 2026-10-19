import { describe, expect, it } from 'vitest'

import { buildEchoOptions, resolvePusherClientConfig } from './pusherConfig'

describe('resolvePusherClientConfig', () => {
  it('retourne null si la clé est absente ou vide', () => {
    expect(resolvePusherClientConfig(undefined, 'eu')).toBeNull()
    expect(resolvePusherClientConfig('', 'eu')).toBeNull()
    expect(resolvePusherClientConfig('   ', 'eu')).toBeNull()
  })

  it('retourne la clé trimée et le cluster par défaut mt1', () => {
    expect(resolvePusherClientConfig('  app-key  ', undefined)).toEqual({
      key: 'app-key',
      cluster: 'mt1',
    })
  })

  it('conserve un cluster explicite', () => {
    expect(resolvePusherClientConfig('app-key', ' eu ')).toEqual({
      key: 'app-key',
      cluster: 'eu',
    })
  })
})

describe('buildEchoOptions', () => {
  it('conserve la configuration Pusher attendue', () => {
    const options = buildEchoOptions('test-key', 'eu', 'csrf-token')

    expect(options).toMatchObject({
      broadcaster: 'pusher',
      key: 'test-key',
      cluster: 'eu',
      forceTLS: true,
      encrypted: true,
      authEndpoint: '/broadcasting/auth',
      enabledTransports: ['ws', 'wss'],
      auth: {
        headers: {
          'X-CSRF-TOKEN': 'csrf-token',
        },
      },
    })
  })
})
