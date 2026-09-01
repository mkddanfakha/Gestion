/**
 * Idempotent sequential polling for async backup creation progress.
 * Does not dispatch create POSTs — status GET only via injected fetcher.
 */

export type BackupCreateProgressStatus = 'queued' | 'running' | 'completed' | 'failed' | string

export type BackupCreateProgressSnapshot = {
  job_id: string
  status: BackupCreateProgressStatus
  percentage: number
  message: string
  only_db?: boolean
  filename?: string | null
  updated_at?: string | null
}

export type BackupCreatePollingHooks = {
  fetchStatus: (jobId: string) => Promise<BackupCreateProgressSnapshot | 'skip' | 'not_found'>
  onProgress: (progress: BackupCreateProgressSnapshot) => void
  onCompleted: (progress: BackupCreateProgressSnapshot) => void
  onFailed: (progress: BackupCreateProgressSnapshot) => void
  onTimeout: (jobId: string) => void
  intervalMs?: number
  maxAttempts?: number
}

const DEFAULT_INTERVAL_MS = 2000
const DEFAULT_MAX_ATTEMPTS = 180

/**
 * Inertia options for refreshing the backups list after a successful create.
 * preserveState MUST stay true — false remounts Index.vue (Inertia key=Date.now())
 * and restarts polling from flash.backup_job_id (SUCCESS → 5% loop).
 */
export const backupCreateSuccessListRefreshOptions = {
  preserveScroll: true,
  preserveState: true,
  only: ['backups', 'summary', 'operations_busy'] as const,
}

export function isTerminalBackupCreateStatus(status: string | null | undefined): boolean {
  return status === 'completed' || status === 'failed'
}

/**
 * Pure guard: whether a new polling cycle may start for jobId.
 */
export function shouldStartBackupCreatePolling(options: {
  jobId: string
  activeJobId: string | null
  pollingActive: boolean
  terminalJobIds: Iterable<string>
  consumedFlashJobIds?: Iterable<string>
  fromFlash?: boolean
}): boolean {
  const { jobId, activeJobId, pollingActive, fromFlash = false } = options
  if (!jobId) {
    return false
  }

  const terminal = new Set(options.terminalJobIds)
  if (terminal.has(jobId)) {
    return false
  }

  if (fromFlash) {
    const consumed = new Set(options.consumedFlashJobIds ?? [])
    if (consumed.has(jobId)) {
      return false
    }
  }

  if (pollingActive && activeJobId === jobId) {
    return false
  }

  return true
}

/**
 * Whether the UI may reset the banner to the initial queued/5% state.
 * Never reset when the same job is already running/completed/failed locally.
 */
export function shouldInitializeQueuedProgress(options: {
  jobId: string
  current: BackupCreateProgressSnapshot | null
}): boolean {
  const { jobId, current } = options
  if (!current || current.job_id !== jobId) {
    return true
  }
  if (isTerminalBackupCreateStatus(current.status) || current.status === 'running') {
    return false
  }
  return current.status !== 'queued'
}

export class BackupCreatePollingController {
  private activeJobId: string | null = null

  private pollingActive = false

  private inFlight = false

  private timer: ReturnType<typeof setTimeout> | null = null

  private attempts = 0

  private readonly terminalJobIds = new Set<string>()

  private readonly consumedFlashJobIds = new Set<string>()

  private hooks: BackupCreatePollingHooks | null = null

  getActiveJobId(): string | null {
    return this.activeJobId
  }

  isPollingActive(): boolean {
    return this.pollingActive
  }

  getTerminalJobIds(): Set<string> {
    return new Set(this.terminalJobIds)
  }

  getConsumedFlashJobIds(): Set<string> {
    return new Set(this.consumedFlashJobIds)
  }

  markFlashConsumed(jobId: string): void {
    if (jobId) {
      this.consumedFlashJobIds.add(jobId)
    }
  }

  /**
   * Start polling. Idempotent for the same active jobId.
   * @returns true if a new cycle started (caller may show initial queued UI)
   */
  start(jobId: string, hooks: BackupCreatePollingHooks, options?: { fromFlash?: boolean }): boolean {
    const fromFlash = options?.fromFlash === true

    if (
      !shouldStartBackupCreatePolling({
        jobId,
        activeJobId: this.activeJobId,
        pollingActive: this.pollingActive,
        terminalJobIds: this.terminalJobIds,
        consumedFlashJobIds: this.consumedFlashJobIds,
        fromFlash,
      })
    ) {
      if (fromFlash) {
        this.markFlashConsumed(jobId)
      }
      return false
    }

    // New job replaces any previous cycle.
    this.clearTimer()
    this.inFlight = false
    this.activeJobId = jobId
    this.pollingActive = true
    this.attempts = 0
    this.hooks = hooks
    this.markFlashConsumed(jobId)

    void this.tick(0)
    return true
  }

  startFromFlash(jobId: string, hooks: BackupCreatePollingHooks): boolean {
    return this.start(jobId, hooks, { fromFlash: true })
  }

  stop(): void {
    this.clearTimer()
    this.pollingActive = false
    this.inFlight = false
    this.hooks = null
  }

  /** Hard stop and forget active job (keep terminal/consumed sets). */
  stopAndClearActive(): void {
    this.stop()
    this.activeJobId = null
  }

  private clearTimer(): void {
    if (this.timer !== null) {
      clearTimeout(this.timer)
      this.timer = null
    }
  }

  private schedule(delayMs: number): void {
    this.clearTimer()
    if (!this.pollingActive || !this.activeJobId || !this.hooks) {
      return
    }
    this.timer = setTimeout(() => {
      this.timer = null
      void this.tick(delayMs)
    }, delayMs)
  }

  private async tick(_scheduledDelay: number): Promise<void> {
    if (!this.pollingActive || !this.activeJobId || !this.hooks || this.inFlight) {
      return
    }

    const jobId = this.activeJobId
    const hooks = this.hooks
    const intervalMs = hooks.intervalMs ?? DEFAULT_INTERVAL_MS
    const maxAttempts = hooks.maxAttempts ?? DEFAULT_MAX_ATTEMPTS

    this.attempts += 1
    if (this.attempts > maxAttempts) {
      this.terminalJobIds.add(jobId)
      this.stopAndClearActive()
      hooks.onTimeout(jobId)
      return
    }

    this.inFlight = true
    try {
      const result = await hooks.fetchStatus(jobId)

      // Aborted / superseded while awaiting.
      if (!this.pollingActive || this.activeJobId !== jobId) {
        return
      }

      if (result === 'skip' || result === 'not_found') {
        this.schedule(intervalMs)
        return
      }

      hooks.onProgress(result)

      if (result.status === 'completed') {
        this.terminalJobIds.add(jobId)
        this.stopAndClearActive()
        hooks.onCompleted(result)
        return
      }

      if (result.status === 'failed') {
        this.terminalJobIds.add(jobId)
        this.stopAndClearActive()
        hooks.onFailed(result)
        return
      }

      this.schedule(intervalMs)
    } catch {
      if (this.pollingActive && this.activeJobId === jobId) {
        this.schedule(intervalMs)
      }
    } finally {
      this.inFlight = false
    }
  }
}
