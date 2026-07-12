import { ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '@/api/client'
import type { Account, AccountInput, Portfolio, PortfolioInput } from '@/api/types'

export const usePortfoliosStore = defineStore('portfolios', () => {
  const portfolios = ref<Portfolio[]>([])
  const loaded = ref(false)

  async function load(): Promise<void> {
    portfolios.value = await api<Portfolio[]>('GET', '/api/portfolios')
    loaded.value = true
  }

  function byId(id: number): Portfolio | undefined {
    return portfolios.value.find((p) => p.id === id)
  }

  function accountById(id: number): { account: Account; portfolio: Portfolio } | undefined {
    for (const portfolio of portfolios.value) {
      const account = portfolio.accounts.find((a) => a.id === id)
      if (account) return { account, portfolio }
    }
    return undefined
  }

  async function create(input: PortfolioInput): Promise<Portfolio> {
    const created = await api<Portfolio>('POST', '/api/portfolios', input)
    portfolios.value.push(created)
    return created
  }

  async function update(id: number, input: PortfolioInput): Promise<void> {
    const updated = await api<Portfolio>('PUT', `/api/portfolios/${id}`, input)
    const i = portfolios.value.findIndex((p) => p.id === id)
    if (i >= 0) portfolios.value[i] = updated
  }

  async function remove(id: number): Promise<void> {
    await api('DELETE', `/api/portfolios/${id}`)
    portfolios.value = portfolios.value.filter((p) => p.id !== id)
  }

  async function duplicate(id: number): Promise<Portfolio> {
    const copy = await api<Portfolio>('POST', `/api/portfolios/${id}/duplicate`)
    portfolios.value.push(copy)
    return copy
  }

  async function createAccount(portfolioId: number, input: AccountInput): Promise<Account> {
    const created = await api<Account>('POST', `/api/portfolios/${portfolioId}/accounts`, input)
    byId(portfolioId)?.accounts.push(created)
    return created
  }

  async function updateAccount(accountId: number, input: AccountInput): Promise<void> {
    const updated = await api<Account>('PUT', `/api/accounts/${accountId}`, input)
    const found = accountById(accountId)
    if (found) {
      const i = found.portfolio.accounts.findIndex((a) => a.id === accountId)
      found.portfolio.accounts[i] = updated
    }
  }

  async function removeAccount(accountId: number): Promise<void> {
    const found = accountById(accountId)
    await api('DELETE', `/api/accounts/${accountId}`)
    if (found) {
      found.portfolio.accounts = found.portfolio.accounts.filter((a) => a.id !== accountId)
    }
  }

  return {
    portfolios, loaded, load, byId, accountById,
    create, update, remove, duplicate,
    createAccount, updateAccount, removeAccount,
  }
})
