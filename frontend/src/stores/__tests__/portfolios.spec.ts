import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/api/client', () => ({ api: vi.fn() }))

import { api } from '@/api/client'
import { usePortfoliosStore } from '../portfolios'

const mockedApi = vi.mocked(api)

const acct = (id: number, portfolioId: number) => ({
  id, portfolioId, name: `A${id}`, type: 'roth_ira', startingBalance: 0, startingBasis: null,
  annualReturnRate: 0.07, inflationRate: 0.03, horizonYears: 40,
  contribution: { monthlyAmount: 0, escalationRate: 0, startsOn: null, endsOn: null },
  drawdown: { amount: null, frequency: 'monthly', entryMode: 'gross', startsOn: null, endsOn: null, inflationIndexed: true },
})
const pf = (id: number, accounts: object[] = []) => ({
  id, name: `P${id}`, ordinaryIncomeTaxRate: 0.22, capitalGainsTaxRate: 0.15, accounts,
})

describe('portfolios store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mockedApi.mockReset()
  })

  it('load fills the list', async () => {
    mockedApi.mockResolvedValueOnce([pf(1), pf(2)])
    const store = usePortfoliosStore()
    await store.load()
    expect(store.portfolios).toHaveLength(2)
    expect(store.loaded).toBe(true)
    expect(store.byId(2)?.name).toBe('P2')
  })

  it('create appends the API response', async () => {
    mockedApi.mockResolvedValueOnce([])
    const store = usePortfoliosStore()
    await store.load()
    mockedApi.mockResolvedValueOnce(pf(5))
    await store.create({ name: 'P5', ordinaryIncomeTaxRate: 0.22, capitalGainsTaxRate: 0.15 })
    expect(store.portfolios.map((p) => p.id)).toEqual([5])
  })

  it('update replaces in place, remove deletes, duplicate appends', async () => {
    mockedApi.mockResolvedValueOnce([pf(1), pf(2)])
    const store = usePortfoliosStore()
    await store.load()

    mockedApi.mockResolvedValueOnce({ ...pf(1), name: 'Renamed' })
    await store.update(1, { name: 'Renamed', ordinaryIncomeTaxRate: 0.3, capitalGainsTaxRate: 0.15 })
    expect(store.byId(1)?.name).toBe('Renamed')

    mockedApi.mockResolvedValueOnce(pf(3))
    await store.duplicate(2)
    expect(store.portfolios).toHaveLength(3)

    mockedApi.mockResolvedValueOnce(undefined)
    await store.remove(2)
    expect(store.portfolios.map((p) => p.id)).toEqual([1, 3])
  })

  it('account mutations resync the owning portfolio', async () => {
    mockedApi.mockResolvedValueOnce([pf(1, [acct(10, 1)])])
    const store = usePortfoliosStore()
    await store.load()

    mockedApi.mockResolvedValueOnce(acct(11, 1))
    await store.createAccount(1, {} as never)
    expect(store.byId(1)?.accounts).toHaveLength(2)

    mockedApi.mockResolvedValueOnce({ ...acct(10, 1), name: 'Renamed' })
    await store.updateAccount(10, {} as never)
    expect(store.accountById(10)?.account.name).toBe('Renamed')
    expect(store.accountById(10)?.portfolio.id).toBe(1)

    mockedApi.mockResolvedValueOnce(undefined)
    await store.removeAccount(11)
    expect(store.byId(1)?.accounts.map((a) => a.id)).toEqual([10])
  })
})
