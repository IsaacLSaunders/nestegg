import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/api/client', () => ({
  api: vi.fn<(method: string, path: string, body?: unknown, opts?: { silentUnauthorized?: boolean }) => Promise<unknown>>(),
  ApiError: class extends Error {},
  setUnauthorizedHandler: vi.fn<(handler: () => void) => void>(),
}))

import { api } from '@/api/client'
import { useAuthStore } from '../auth'
import { usePortfoliosStore } from '../portfolios'

const mockedApi = vi.mocked(api)
const demoUser = { id: 1, email: 'a@b.c', birthDate: '1990-06-15', deathAge: 90 }

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mockedApi.mockReset()
  })

  it('fetchMe stores the user and marks checked', async () => {
    mockedApi.mockResolvedValueOnce(demoUser)
    const store = useAuthStore()
    await store.fetchMe()
    expect(store.user).toEqual(demoUser)
    expect(store.checked).toBe(true)
  })

  it('fetchMe swallows 401 and marks checked', async () => {
    mockedApi.mockRejectedValueOnce(new Error('401'))
    const store = useAuthStore()
    await store.fetchMe()
    expect(store.user).toBeNull()
    expect(store.checked).toBe(true)
  })

  it('login stores the returned user', async () => {
    mockedApi.mockResolvedValueOnce(demoUser)
    const store = useAuthStore()
    await store.login('a@b.c', 'pw')
    expect(mockedApi).toHaveBeenCalledWith('POST', '/api/auth/login', { email: 'a@b.c', password: 'pw' })
    expect(store.user).toEqual(demoUser)
  })

  it('register then logs in with the same credentials', async () => {
    mockedApi.mockResolvedValueOnce(demoUser) // register 201
    mockedApi.mockResolvedValueOnce(demoUser) // login
    const store = useAuthStore()
    await store.register({ email: 'a@b.c', password: 'pw', birthDate: '1990-06-15', deathAge: 90 })
    expect(mockedApi).toHaveBeenNthCalledWith(1, 'POST', '/api/auth/register', {
      email: 'a@b.c', password: 'pw', birthDate: '1990-06-15', deathAge: 90,
    })
    expect(mockedApi).toHaveBeenNthCalledWith(2, 'POST', '/api/auth/login', { email: 'a@b.c', password: 'pw' })
    expect(store.user).toEqual(demoUser)
  })

  it('logout clears the user', async () => {
    mockedApi.mockResolvedValueOnce(demoUser)
    const store = useAuthStore()
    await store.login('a@b.c', 'pw')
    mockedApi.mockResolvedValueOnce({ status: 'logged out' })
    await store.logout()
    expect(store.user).toBeNull()
  })

  it('logout resets the portfolios store', async () => {
    const authStore = useAuthStore()
    const portfoliosStore = usePortfoliosStore()

    mockedApi.mockResolvedValueOnce([{ id: 1, name: 'P1', ordinaryIncomeTaxRate: 0.22, capitalGainsTaxRate: 0.15, accounts: [] }, { id: 2, name: 'P2', ordinaryIncomeTaxRate: 0.22, capitalGainsTaxRate: 0.15, accounts: [] }])
    await portfoliosStore.load()
    expect(portfoliosStore.portfolios).toHaveLength(2)

    mockedApi.mockResolvedValueOnce({ status: 'logged out' })
    await authStore.logout()

    expect(portfoliosStore.portfolios).toEqual([])
    expect(portfoliosStore.loaded).toBe(false)
  })
})
