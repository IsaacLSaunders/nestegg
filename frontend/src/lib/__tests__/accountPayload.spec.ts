import { describe, it, expect } from 'vitest'
import { toAccountInput } from '../accountPayload'
import type { AccountInput } from '@/api/types'

const base: AccountInput = {
  name: 'X',
  type: 'brokerage',
  startingBalance: 100,
  startingBasis: 80,
  annualReturnRate: 0.07,
  inflationRate: 0.03,
  horizonYears: 40,
  contribution: { monthlyAmount: 50, escalationRate: 0, startsOn: '', endsOn: '2040-01-01' },
  drawdown: { amount: 100, frequency: 'monthly', entryMode: 'gross', startsOn: '2041-01-01', endsOn: '', inflationIndexed: true },
}

describe('toAccountInput', () => {
  it('nulls empty date strings', () => {
    const out = toAccountInput(base, true)
    expect(out.contribution.startsOn).toBeNull()
    expect(out.drawdown.endsOn).toBeNull()
    expect(out.contribution.endsOn).toBe('2040-01-01')
  })

  it('resets drawdown when toggled off', () => {
    const out = toAccountInput(base, false)
    expect(out.drawdown.amount).toBeNull()
    expect(out.drawdown.startsOn).toBeNull()
  })

  it('nulls basis for non-brokerage', () => {
    const out = toAccountInput({ ...base, type: 'roth_ira' }, true)
    expect(out.startingBasis).toBeNull()
  })

  it('coerces cleared number inputs', () => {
    const dirty = {
      ...base,
      startingBalance: '' as unknown as number,
      horizonYears: '' as unknown as number,
      startingBasis: '' as unknown as number,
      contribution: { ...base.contribution, monthlyAmount: '' as unknown as number },
    }
    const out = toAccountInput(dirty, true)
    expect(out.startingBalance).toBe(0)
    expect(out.horizonYears).toBe(40)
    expect(out.startingBasis).toBeNull()
    expect(out.contribution.monthlyAmount).toBe(0)
  })

  it('does not mutate its argument', () => {
    toAccountInput(base, false)
    expect(base.drawdown.amount).toBe(100)
  })
})
