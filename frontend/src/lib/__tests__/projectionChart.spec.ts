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

  it('clamps a drawdown start before the visible window to the first date', () => {
    // months begin at 2026-07 (see the month() helper above)
    const opt = buildProjectionOption({ months, real: false, depletionDate: null, drawdownStart: '2020-01', drawdownEnd: null, birthDate: null })
    const series = (opt.series as Record<string, unknown>[] | undefined)?.[0]
    expect(JSON.stringify(series?.markArea)).toContain('2026-07')
    expect(JSON.stringify(series?.markArea)).not.toContain('2020-01')
  })
})

describe('buildPortfolioOption', () => {
  const accounts = [
    { name: 'A', months },
    { name: 'B', months },
  ]
  const total = months.map((m) => ({ date: m.date, balance: m.balance * 2, realBalance: m.realBalance * 2 }))

  it('one series per account plus a total line, with a legend', () => {
    const opt = buildPortfolioOption({ accounts, total, real: false, stacked: false, birthDate: null, drawdownWindows: [] })
    const series = opt.series as { name: string; stack?: string }[] | undefined
    expect(series?.map((s) => s.name)).toEqual(['A', 'B', 'Total'])
    expect(opt.legend).toBeDefined()
    expect(series?.every((s) => s.stack === undefined)).toBe(true)
  })

  it('stacked mode stacks account areas and drops the total line', () => {
    const opt = buildPortfolioOption({ accounts, total, real: false, stacked: true, birthDate: null, drawdownWindows: [] })
    const series = opt.series as { name: string; stack?: string }[] | undefined
    expect(series?.map((s) => s.name)).toEqual(['A', 'B'])
    expect(series?.every((s) => s.stack === 'portfolio')).toBe(true)
  })

  it('assigns palette colors by account position', () => {
    const opt = buildPortfolioOption({ accounts, total, real: false, stacked: false, birthDate: null, drawdownWindows: [] })
    const series = opt.series as { itemStyle: { color: string } }[] | undefined
    expect(series?.[0]?.itemStyle.color).toBe('#1b7a4e')
    expect(series?.[1]?.itemStyle.color).toBe('#b0521a')
  })

  it('renders drawdown windows as a markArea on the first series', () => {
    const opt = buildPortfolioOption({
      accounts,
      total,
      real: false,
      stacked: false,
      birthDate: null,
      drawdownWindows: [{ start: '2026-08', end: null }],
    })
    const series = opt.series as Record<string, unknown>[] | undefined
    expect(JSON.stringify(series?.[0]?.markArea)).toContain('2026-08')
  })

  it('has no markArea when there are no drawdown windows', () => {
    const opt = buildPortfolioOption({ accounts, total, real: false, stacked: false, birthDate: null, drawdownWindows: [] })
    const series = opt.series as { markArea?: unknown }[] | undefined
    expect(series?.[0]?.markArea).toBeUndefined()
  })

  it('renders series beyond the 6-slot palette as neutral and dashed', () => {
    const manyAccounts = Array.from({ length: 7 }, (_, i) => ({ name: `Account ${i}`, months }))
    const manyTotal = total
    const opt = buildPortfolioOption({
      accounts: manyAccounts,
      total: manyTotal,
      real: false,
      stacked: false,
      birthDate: null,
      drawdownWindows: [],
    })
    const series = opt.series as { itemStyle: { color: string }; lineStyle: { type?: unknown } }[] | undefined
    expect(series?.[6]?.itemStyle.color).toBe('#98a08f')
    expect(series?.[6]?.lineStyle.type).toBeDefined()
  })

  it('formats the tooltip header with monthLabel and rows with money', () => {
    const opt = buildPortfolioOption({ accounts, total, real: false, stacked: false, birthDate: null, drawdownWindows: [] })
    const formatter = (opt.tooltip as { formatter: (p: unknown) => string }).formatter
    const rendered = formatter([
      { axisValue: '2047-04', marker: '●', seriesName: 'Employer 401k', value: 249809 },
      { axisValue: '2047-04', marker: '●', seriesName: 'Total', value: 578271 },
    ])
    expect(rendered).toContain('Apr 2047')
    expect(rendered).toContain('$249,809')
    expect(rendered).toContain('$578,271')
  })
})
