import type { AccountInput } from '@/api/types'

function finiteOr(value: unknown, fallback: number): number {
  return typeof value === 'number' && Number.isFinite(value) ? value : fallback
}

function finiteOrNull(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

export function toAccountInput(form: AccountInput, hasDrawdown: boolean): AccountInput {
  const payload: AccountInput = JSON.parse(JSON.stringify(form))
  if (!hasDrawdown) {
    payload.drawdown = { amount: null, frequency: 'monthly', entryMode: 'gross', startsOn: null, endsOn: null, inflationIndexed: true }
  }
  for (const key of ['startsOn', 'endsOn'] as const) {
    if (payload.contribution[key] === '') payload.contribution[key] = null
    if (payload.drawdown[key] === '') payload.drawdown[key] = null
  }
  if (payload.type !== 'brokerage') payload.startingBasis = null
  payload.startingBalance = finiteOr(payload.startingBalance, 0)
  payload.horizonYears = finiteOr(payload.horizonYears, 40)
  payload.contribution.monthlyAmount = finiteOr(payload.contribution.monthlyAmount, 0)
  payload.startingBasis = finiteOrNull(payload.startingBasis)
  payload.drawdown.amount = finiteOrNull(payload.drawdown.amount)
  return payload
}
