import { describe, it, expect, vi, beforeEach } from 'vitest'
import { api, ApiError, setUnauthorizedHandler } from '../client'

function mockFetch(status: number, body: unknown) {
  const res = {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  }
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(res))
}

describe('api client', () => {
  beforeEach(() => {
    vi.unstubAllGlobals()
    setUnauthorizedHandler(() => {})
  })

  it('returns parsed JSON on success', async () => {
    mockFetch(200, { id: 1, email: 'a@b.c' })
    await expect(api('GET', '/api/me')).resolves.toEqual({ id: 1, email: 'a@b.c' })
  })

  it('sends JSON body with content-type header', async () => {
    mockFetch(201, { id: 2 })
    await api('POST', '/api/portfolios', { name: 'X' })
    const call = (fetch as ReturnType<typeof vi.fn>).mock.calls[0]!
    expect(call[0]).toBe('/api/portfolios')
    expect(call[1].method).toBe('POST')
    expect(call[1].headers['Content-Type']).toBe('application/json')
    expect(JSON.parse(call[1].body)).toEqual({ name: 'X' })
    expect(call[1].credentials).toBe('same-origin')
  })

  it('returns undefined on 204', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, status: 204, json: () => Promise.reject(new Error('no body')) }))
    await expect(api('DELETE', '/api/portfolios/1')).resolves.toBeUndefined()
  })

  it('maps 422 violations to field messages', async () => {
    mockFetch(422, {
      detail: 'Validation failed',
      violations: [
        { propertyPath: 'name', title: 'This value should not be blank.' },
        { propertyPath: 'name', title: 'second message ignored' },
        { propertyPath: 'ordinaryIncomeTaxRate', title: 'Out of range.' },
      ],
    })
    const err = await api('POST', '/api/portfolios', {}).catch((e: unknown) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect((err as ApiError).status).toBe(422)
    expect((err as ApiError).violations).toEqual({
      name: 'This value should not be blank.',
      ordinaryIncomeTaxRate: 'Out of range.',
    })
  })

  it('calls the unauthorized handler on 401', async () => {
    const handler = vi.fn<() => void>()
    setUnauthorizedHandler(handler)
    mockFetch(401, { error: 'Authentication required.' })
    await expect(api('GET', '/api/me')).rejects.toBeInstanceOf(ApiError)
    expect(handler).toHaveBeenCalledOnce()
  })

  it('skips the unauthorized handler on 401 when silentUnauthorized is set', async () => {
    const handler = vi.fn<() => void>()
    setUnauthorizedHandler(handler)
    mockFetch(401, { error: 'Authentication required.' })
    const err = await api('GET', '/api/me', undefined, { silentUnauthorized: true }).catch((e: unknown) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect((err as ApiError).status).toBe(401)
    expect(handler).not.toHaveBeenCalled()
  })

  it('falls back to a generic message when the body is not JSON', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 500, json: () => Promise.reject(new Error('not json')) }))
    const err = await api('GET', '/api/health').catch((e: unknown) => e)
    expect((err as ApiError).message).toBe('Request failed (500)')
  })
})
