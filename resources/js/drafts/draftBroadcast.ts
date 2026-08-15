import { DRAFT_BROADCAST_CHANNEL } from './draftKey'

export interface DraftBroadcastMessage {
  type: 'draft-updated'
  key: string
  updatedAt: string
  instanceId: string
  version?: number
}

function isBroadcastChannelSupported(): boolean {
  return typeof BroadcastChannel !== 'undefined'
}

function isBroadcastChannelClosedError(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'InvalidStateError'
}

export interface DraftBroadcastManager {
  init: () => void
  notify: (message: DraftBroadcastMessage) => void
  close: () => void
}

export function createDraftBroadcastManager(options: {
  isActive: () => boolean
  onMessage: (payload: DraftBroadcastMessage) => void
}): DraftBroadcastManager {
  let channel: BroadcastChannel | null = null
  let channelClosed = false

  const attachListener = (activeChannel: BroadcastChannel): void => {
    activeChannel.onmessage = (event: MessageEvent<DraftBroadcastMessage>) => {
      if (!options.isActive()) {
        return
      }

      options.onMessage(event.data)
    }
  }

  const openChannel = (): BroadcastChannel | null => {
    if (!isBroadcastChannelSupported() || !options.isActive()) {
      return null
    }

    if (channel && !channelClosed) {
      return channel
    }

    channelClosed = false

    try {
      channel = new BroadcastChannel(DRAFT_BROADCAST_CHANNEL)
      attachListener(channel)
      return channel
    } catch (error) {
      console.warn('[draft] Impossible d\'ouvrir BroadcastChannel', error)
      channel = null
      return null
    }
  }

  const closeChannel = (): void => {
    channelClosed = true

    if (!channel) {
      return
    }

    try {
      channel.onmessage = null
      channel.close()
    } catch {
      // Channel déjà fermé — ignorer
    }

    channel = null
  }

  const notify = (message: DraftBroadcastMessage): void => {
    if (!options.isActive()) {
      return
    }

    const activeChannel = openChannel()
    if (!activeChannel) {
      return
    }

    try {
      activeChannel.postMessage(message)
    } catch (error) {
      if (!isBroadcastChannelClosedError(error)) {
        console.warn('[draft] BroadcastChannel notification failed', error)
        return
      }

      closeChannel()

      if (!options.isActive()) {
        return
      }

      const retryChannel = openChannel()
      if (!retryChannel) {
        return
      }

      try {
        retryChannel.postMessage(message)
      } catch (retryError) {
        if (!isBroadcastChannelClosedError(retryError)) {
          console.warn('[draft] BroadcastChannel retry failed', retryError)
        }
      }
    }
  }

  return {
    init: () => {
      openChannel()
    },
    notify,
    close: closeChannel,
  }
}
