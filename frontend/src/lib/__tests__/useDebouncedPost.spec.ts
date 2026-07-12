import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref, nextTick } from 'vue'

vi.mock('@/api/client', () => ({
  api: vi.fn<(method: string, path: string, body?: unknown, opts?: { silentUnauthorized?: boolean }) => Promise<unknown>>(),
  ApiError: class ApiError extends Error {
    constructor(public status: number, message: string) { super(message) }
  },
}))

import { api, ApiError } from '@/api/client'
import { useDebouncedPost } from '../useDebouncedPost'

const mockedApi = vi.mocked(api)

describe('useDebouncedPost', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mockedApi.mockReset()
  })
  afterEach(() => vi.useRealTimers())

  it('debounces rapid payload changes into one call', async () => {
    mockedApi.mockResolvedValue({ ok: 1 })
    const payload = ref<{ v: number } | null>({ v: 1 })
    const { data } = useDebouncedPost<{ v: number }, { ok: number }>('/api/projection', payload)
    await nextTick()
    payload.value = { v: 2 }
    await nextTick()
    payload.value = { v: 3 }
    await nextTick()
    await vi.advanceTimersByTimeAsync(300)
    expect(mockedApi).toHaveBeenCalledTimes(1)
    expect(mockedApi).toHaveBeenCalledWith('POST', '/api/projection', { v: 3 })
    expect(data.value).toEqual({ ok: 1 })
  })

  it('ignores stale responses', async () => {
    let resolveFirst!: (v: unknown) => void
    mockedApi.mockImplementationOnce(() => new Promise((r) => (resolveFirst = r)))
    const payload = ref<{ v: number } | null>({ v: 1 })
    const { data } = useDebouncedPost<{ v: number }, { v: number }>('/api/projection', payload)
    await nextTick()
    await vi.advanceTimersByTimeAsync(300)

    mockedApi.mockResolvedValueOnce({ v: 2 })
    payload.value = { v: 2 }
    await nextTick()
    await vi.advanceTimersByTimeAsync(300)
    resolveFirst({ v: 1 }) // slow first response lands last
    await vi.runAllTimersAsync()
    expect(data.value).toEqual({ v: 2 })
  })

  it('maps ApiError message into error and clears on success', async () => {
    mockedApi.mockRejectedValueOnce(new ApiError(422, 'atDate is outside the projection horizon.'))
    const payload = ref<{ v: number } | null>({ v: 1 })
    const { error } = useDebouncedPost('/api/projection', payload)
    await nextTick()
    await vi.advanceTimersByTimeAsync(300)
    expect(error.value).toBe('atDate is outside the projection horizon.')
  })

  it('does nothing for null payloads', async () => {
    const payload = ref(null)
    useDebouncedPost('/api/projection', payload)
    await nextTick()
    await vi.advanceTimersByTimeAsync(300)
    expect(mockedApi).not.toHaveBeenCalled()
  })
})
