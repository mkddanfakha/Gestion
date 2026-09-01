import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  BackupCreatePollingController,
  backupCreateSuccessListRefreshOptions,
  isTerminalBackupCreateStatus,
  shouldInitializeQueuedProgress,
  shouldStartBackupCreatePolling,
  type BackupCreateProgressSnapshot,
} from './backupCreatePolling'

describe('backupCreateSuccessListRefreshOptions', () => {
  it('requires preserveState true to avoid Index.vue remount / SUCCESS→5% loop', () => {
    expect(backupCreateSuccessListRefreshOptions.preserveState).toBe(true)
    expect(backupCreateSuccessListRefreshOptions.preserveScroll).toBe(true)
    expect(backupCreateSuccessListRefreshOptions.only).toEqual([
      'backups',
      'summary',
      'operations_busy',
    ])
  })
})

describe('backupCreatePolling guards', () => {
  it('detects terminal statuses', () => {
    expect(isTerminalBackupCreateStatus('completed')).toBe(true)
    expect(isTerminalBackupCreateStatus('failed')).toBe(true)
    expect(isTerminalBackupCreateStatus('running')).toBe(false)
    expect(isTerminalBackupCreateStatus('queued')).toBe(false)
  })

  it('allows first start and rejects duplicate active same jobId', () => {
    expect(
      shouldStartBackupCreatePolling({
        jobId: 'job-a',
        activeJobId: null,
        pollingActive: false,
        terminalJobIds: [],
      }),
    ).toBe(true)

    expect(
      shouldStartBackupCreatePolling({
        jobId: 'job-a',
        activeJobId: 'job-a',
        pollingActive: true,
        terminalJobIds: [],
      }),
    ).toBe(false)
  })

  it('rejects flash re-trigger for consumed or terminal jobId', () => {
    expect(
      shouldStartBackupCreatePolling({
        jobId: 'job-a',
        activeJobId: null,
        pollingActive: false,
        terminalJobIds: [],
        consumedFlashJobIds: ['job-a'],
        fromFlash: true,
      }),
    ).toBe(false)

    expect(
      shouldStartBackupCreatePolling({
        jobId: 'job-a',
        activeJobId: null,
        pollingActive: false,
        terminalJobIds: ['job-a'],
        fromFlash: true,
      }),
    ).toBe(false)
  })

  it('never re-initializes queued UI after running/completed for same job', () => {
    expect(
      shouldInitializeQueuedProgress({
        jobId: 'job-a',
        current: null,
      }),
    ).toBe(true)

    expect(
      shouldInitializeQueuedProgress({
        jobId: 'job-a',
        current: {
          job_id: 'job-a',
          status: 'running',
          percentage: 15,
          message: '…',
        },
      }),
    ).toBe(false)

    expect(
      shouldInitializeQueuedProgress({
        jobId: 'job-a',
        current: {
          job_id: 'job-a',
          status: 'completed',
          percentage: 100,
          message: 'ok',
        },
      }),
    ).toBe(false)
  })
})

describe('BackupCreatePollingController', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  const snapshot = (
    status: string,
    percentage: number,
    jobId = 'job-a',
  ): BackupCreateProgressSnapshot => ({
    job_id: jobId,
    status,
    percentage,
    message: status,
  })

  it('starts only one polling cycle when start is called twice', async () => {
    vi.useFakeTimers()
    const fetchStatus = vi.fn(async () => snapshot('running', 15))
    const onProgress = vi.fn()
    const controller = new BackupCreatePollingController()
    const hooks = {
      fetchStatus,
      onProgress,
      onCompleted: vi.fn(),
      onFailed: vi.fn(),
      onTimeout: vi.fn(),
      intervalMs: 2000,
    }

    expect(controller.start('job-a', hooks)).toBe(true)
    expect(controller.start('job-a', hooks)).toBe(false)

    await vi.advanceTimersByTimeAsync(0)
    expect(fetchStatus).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(2000)
    expect(fetchStatus).toHaveBeenCalledTimes(2)

    controller.stop()
  })

  it('ignores flash start after onSuccess already consumed the jobId', async () => {
    vi.useFakeTimers()
    const fetchStatus = vi.fn(async () => snapshot('queued', 5))
    const controller = new BackupCreatePollingController()
    const hooks = {
      fetchStatus,
      onProgress: vi.fn(),
      onCompleted: vi.fn(),
      onFailed: vi.fn(),
      onTimeout: vi.fn(),
      intervalMs: 2000,
    }

    expect(controller.start('job-a', hooks)).toBe(true)
    expect(controller.startFromFlash('job-a', hooks)).toBe(false)

    await vi.advanceTimersByTimeAsync(0)
    expect(fetchStatus).toHaveBeenCalledTimes(1)
    controller.stop()
  })

  it('stops permanently on completed and does not poll again', async () => {
    vi.useFakeTimers()
    const fetchStatus = vi
      .fn()
      .mockResolvedValueOnce(snapshot('queued', 5))
      .mockResolvedValueOnce(snapshot('running', 15))
      .mockResolvedValueOnce(snapshot('completed', 100))

    const percentages: number[] = []
    const onCompleted = vi.fn()
    const controller = new BackupCreatePollingController()

    controller.start('job-a', {
      fetchStatus,
      onProgress: (p) => percentages.push(p.percentage),
      onCompleted,
      onFailed: vi.fn(),
      onTimeout: vi.fn(),
      intervalMs: 1000,
    })

    await vi.advanceTimersByTimeAsync(0)
    await vi.advanceTimersByTimeAsync(1000)
    await vi.advanceTimersByTimeAsync(1000)

    expect(percentages).toEqual([5, 15, 100])
    expect(onCompleted).toHaveBeenCalledTimes(1)
    expect(controller.isPollingActive()).toBe(false)

    // Further time must not fetch again.
    await vi.advanceTimersByTimeAsync(5000)
    expect(fetchStatus).toHaveBeenCalledTimes(3)

    // Same job cannot restart.
    expect(
      controller.start('job-a', {
        fetchStatus,
        onProgress: vi.fn(),
        onCompleted: vi.fn(),
        onFailed: vi.fn(),
        onTimeout: vi.fn(),
      }),
    ).toBe(false)
  })

  it('stops permanently on failed', async () => {
    vi.useFakeTimers()
    const onFailed = vi.fn()
    const onCompleted = vi.fn()
    const controller = new BackupCreatePollingController()
    const fetchStatus = vi.fn(async () => snapshot('failed', 0))
    const hooks = {
      fetchStatus,
      onProgress: vi.fn(),
      onCompleted,
      onFailed,
      onTimeout: vi.fn(),
    }

    controller.start('job-a', hooks)

    await vi.advanceTimersByTimeAsync(0)
    expect(onFailed).toHaveBeenCalledTimes(1)
    expect(onCompleted).toHaveBeenCalledTimes(0)
    expect(controller.isPollingActive()).toBe(false)

    // No failed→failed UI loop via flash remount restart.
    expect(controller.startFromFlash('job-a', hooks)).toBe(false)
    await vi.advanceTimersByTimeAsync(5000)
    expect(onFailed).toHaveBeenCalledTimes(1)
    expect(fetchStatus).toHaveBeenCalledTimes(1)
  })

  it('never regresses through a second start to 5% after running (caller guard)', async () => {
    const current = snapshot('running', 40)
    expect(
      shouldInitializeQueuedProgress({
        jobId: 'job-a',
        current,
      }),
    ).toBe(false)
  })

  it('allows a new job after a completed one', async () => {
    vi.useFakeTimers()
    const controller = new BackupCreatePollingController()
    const fetchA = vi.fn(async () => snapshot('completed', 100, 'job-a'))
    const fetchB = vi.fn(async () => snapshot('queued', 5, 'job-b'))

    controller.start('job-a', {
      fetchStatus: fetchA,
      onProgress: vi.fn(),
      onCompleted: vi.fn(),
      onFailed: vi.fn(),
      onTimeout: vi.fn(),
    })
    await vi.advanceTimersByTimeAsync(0)
    expect(controller.isPollingActive()).toBe(false)

    expect(
      controller.start('job-b', {
        fetchStatus: fetchB,
        onProgress: vi.fn(),
        onCompleted: vi.fn(),
        onFailed: vi.fn(),
        onTimeout: vi.fn(),
        intervalMs: 2000,
      }),
    ).toBe(true)

    await vi.advanceTimersByTimeAsync(0)
    expect(fetchB).toHaveBeenCalledTimes(1)
    controller.stop()
  })

  it('after completed keeps a single start — flash cannot restart same jobId', async () => {
    vi.useFakeTimers()
    const fetchStatus = vi
      .fn()
      .mockResolvedValueOnce(snapshot('queued', 5))
      .mockResolvedValueOnce(snapshot('running', 40))
      .mockResolvedValueOnce(snapshot('completed', 100))

    let startCount = 0
    const controller = new BackupCreatePollingController()
    const hooks = {
      fetchStatus,
      onProgress: vi.fn(),
      onCompleted: vi.fn(),
      onFailed: vi.fn(),
      onTimeout: vi.fn(),
      intervalMs: 1000,
    }

    expect(controller.start('job-a', hooks)).toBe(true)
    startCount += 1

    await vi.advanceTimersByTimeAsync(0)
    await vi.advanceTimersByTimeAsync(1000)
    await vi.advanceTimersByTimeAsync(1000)

    expect(hooks.onCompleted).toHaveBeenCalledTimes(1)
    expect(controller.isPollingActive()).toBe(false)

    // Simulate post-success flash / onMounted attempts (must stay at 1 start).
    expect(controller.startFromFlash('job-a', hooks)).toBe(false)
    expect(controller.start('job-a', hooks)).toBe(false)
    expect(startCount).toBe(1)

    await vi.advanceTimersByTimeAsync(5000)
    expect(fetchStatus).toHaveBeenCalledTimes(3)
  })
})
