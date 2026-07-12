import { describe, it, expect } from 'vitest'
import { buildProjectionOption, buildPortfolioOption } from '../projectionChart'
import type { ProjectionMonth } from '@/api/types'

function month(index: number, balance: number): ProjectionMonth {
  const year = 2026 + Math.floor((6 + index) / 12)
  const m = ((6 + index) % 12) + 1
  return {
    index,
    date: `${year}-${String(m).padStart(2, '0')}`,
    balance,
    realBalance: balance * 0.9,
    basis: 0,
    contribution: 100,
    realContribution: 97,
    grossWithdrawal: 0,
    realGrossWithdrawal: 0,
    netWithdrawal: 0,
    realNetWithdrawal: 0,
    taxPaid: 0,
    realTaxPaid: 0,
  }
}

const months = [month(0, 1000), month(1, 1100), month(2, 1200)]

describe('buildProjectionOption', () => {
  it('uses nominal balance by default and real when toggled', () => {
    const nominal = buildProjectionOption({ months, real: false, depletionDate: null, drawdownStart: null, drawdownEnd: null, birthDate: null })
    const series = (nominal.series as { data: number[] }[] | undefined)?.[0]
    expect(series?.data).toEqual([1000, 1100, 1200])

    const real = buildProjectionOption({ months, real: true, depletionDate: null, drawdownStart: null, drawdownEnd: null, birthDate: null })
    expect(((real.series as { data: number[] }[] | undefined)?.[0])?.data).toEqual([900, 990, 1080])
  })

  it('has exactly one y axis and no legend for the single series', () => {
    const opt = buildProjectionOption({ months, real: false, depletionDate: null, drawdownStart: null, drawdownEnd: null, birthDate: null })
    expect(Array.isArray(opt.yAxis) ? (opt.yAxis as unknown[]).length : 1).toBe(1)
    expect(opt.legend).toBeUndefined()
  })

  it('marks the depletion month and shades the drawdown window', () => {
    const opt = buildProjectionOption({ months, real: false, depletionDate: '2026-09', drawdownStart: '2026-08', drawdownEnd: null, birthDate: null })
    const series = (opt.series as Record<string, unknown>[] | undefined)?.[0]
    expect(JSON.stringify(series?.markLine)).toContain('2026-09')
    expect(JSON.stringify(series?.markArea)).toContain('2026-08')
  })
})

describe('buildPortfolioOption', () => {
  const accounts = [
    { name: 'A', months },
    { name: 'B', months },
  ]
  const total = months.map((m) => ({ date: m.date, balance: m.balance * 2, realBalance: m.realBalance * 2 }))

  it('one series per account plus a total line, with a legend', () => {
    const opt = buildPortfolioOption({ accounts, total, real: false, stacked: false, birthDate: null })
    const series = opt.series as { name: string; stack?: string }[] | undefined
    expect(series?.map((s) => s.name)).toEqual(['A', 'B', 'Total'])
    expect(opt.legend).toBeDefined()
    expect(series?.every((s) => s.stack === undefined)).toBe(true)
  })

  it('stacked mode stacks account areas and drops the total line', () => {
    const opt = buildPortfolioOption({ accounts, total, real: false, stacked: true, birthDate: null })
    const series = opt.series as { name: string; stack?: string }[] | undefined
    expect(series?.map((s) => s.name)).toEqual(['A', 'B'])
    expect(series?.every((s) => s.stack === 'portfolio')).toBe(true)
  })

  it('assigns palette colors by account position', () => {
    const opt = buildPortfolioOption({ accounts, total, real: false, stacked: false, birthDate: null })
    const series = opt.series as { itemStyle: { color: string } }[] | undefined
    expect(series?.[0]?.itemStyle.color).toBe('#1b7a4e')
    expect(series?.[1]?.itemStyle.color).toBe('#b0521a')
  })
})
