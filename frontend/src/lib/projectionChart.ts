import type { EChartsOption, MarkAreaComponentOption, MarkLineComponentOption } from 'echarts'
import type { ProjectionMonth } from '@/api/types'
import { SERIES, INK, INK_SOFT, INK_FAINT, LINE, PAPER_RAISED, DANGER } from './palette'
import { money, moneyCompact, monthLabel, ageAt } from './format'

const AXIS_TEXT = { color: INK_FAINT, fontFamily: 'IBM Plex Mono', fontSize: 11 }

// Target roughly this many year labels across the x-axis; a long horizon skips more years per label
// so labels never crowd together regardless of chart width.
const TARGET_YEAR_LABELS = 10

function baseOption(dates: string[], birthDate: string | null): EChartsOption {
  const startYear = dates.length > 0 ? Number((dates[0] ?? '0').slice(0, 4)) : 0
  const totalYears = Math.max(1, dates.length / 12)
  const strideYears = Math.max(1, Math.round(totalYears / TARGET_YEAR_LABELS))

  return {
    grid: { left: 64, right: 24, top: 28, bottom: 40 },
    xAxis: {
      type: 'category',
      data: dates,
      axisLine: { lineStyle: { color: LINE } },
      axisTick: { show: false },
      axisLabel: {
        ...AXIS_TEXT,
        formatter: (value: string) => {
          if (!value.endsWith('-01')) return ''
          const year = Number(value.slice(0, 4))
          if ((year - startYear) % strideYears !== 0) return ''
          const age = ageAt(value, birthDate)
          return value.slice(0, 4) + (age === null ? '' : `\n${age}`)
        },
        interval: 0,
      },
    },
    yAxis: {
      type: 'value',
      axisLabel: { ...AXIS_TEXT, formatter: (v: number) => moneyCompact(v) },
      splitLine: { lineStyle: { color: LINE, opacity: 0.6 } },
    },
    tooltip: {
      trigger: 'axis',
      backgroundColor: PAPER_RAISED,
      borderColor: LINE,
      textStyle: { color: INK, fontSize: 12 },
      axisPointer: { type: 'line', lineStyle: { color: INK_FAINT } },
    },
  }
}

export function buildProjectionOption(input: {
  months: ProjectionMonth[]
  real: boolean
  depletionDate: string | null
  drawdownStart: string | null
  drawdownEnd: string | null
  birthDate: string | null
}): EChartsOption {
  const { months, real } = input
  const dates = months.map((m) => m.date)
  const balances = months.map((m) => (real ? m.realBalance : m.balance))
  const byDate = new Map(months.map((m) => [m.date, m]))
  const suffix = real ? " (today's $)" : ''

  const markArea: MarkAreaComponentOption | undefined =
    input.drawdownStart === null
      ? undefined
      : {
          silent: true,
          // COPPER (#b0521a) at 7% opacity
          itemStyle: { color: 'rgba(176, 82, 26, 0.07)' },
          data: [[{ xAxis: input.drawdownStart }, { xAxis: input.drawdownEnd ?? dates[dates.length - 1] }]],
        }

  const markLine: MarkLineComponentOption | undefined =
    input.depletionDate === null
      ? undefined
      : {
          symbol: 'none',
          lineStyle: { color: DANGER, type: 'dashed', width: 2 },
          label: { formatter: 'Runs dry', color: DANGER, fontFamily: 'IBM Plex Mono', fontSize: 11 },
          data: [{ xAxis: input.depletionDate }],
        }

  const base = baseOption(dates, input.birthDate)

  return {
    ...base,
    tooltip: {
      ...(base.tooltip as object),
      formatter: (params: unknown) => {
        const p = (params as { axisValue?: string }[] | undefined)?.[0]
        if (!p?.axisValue) return ''
        const m = byDate.get(p.axisValue)
        if (!m) return ''
        const rows = [
          `<strong>${monthLabel(m.date)}</strong>`,
          `Balance ${money(real ? m.realBalance : m.balance)}`,
          m.contribution > 0 ? `Contribution ${money(real ? m.realContribution : m.contribution)}` : '',
          m.grossWithdrawal > 0 ? `Withdrawal ${money(real ? m.realNetWithdrawal : m.netWithdrawal)} net` : '',
          m.taxPaid > 0 ? `Tax ${money(real ? m.realTaxPaid : m.taxPaid)}` : '',
        ]
        return rows.filter(Boolean).join('<br/>')
      },
    },
    series: [
      {
        name: `Balance${suffix}`,
        type: 'line' as const,
        data: balances,
        showSymbol: false,
        lineStyle: { color: SERIES[0], width: 2 },
        itemStyle: { color: SERIES[0] },
        areaStyle: { color: SERIES[0], opacity: 0.06 },
        markArea,
        markLine,
      },
    ],
  }
}

export function buildPortfolioOption(input: {
  accounts: { name: string; months: ProjectionMonth[] }[]
  total: { date: string; balance: number; realBalance: number }[]
  real: boolean
  stacked: boolean
  birthDate: string | null
}): EChartsOption {
  const dates = input.total.map((t) => t.date)
  const pick = (m: ProjectionMonth) => (input.real ? m.realBalance : m.balance)

  const accountSeries = input.accounts.map((a, i) => ({
    name: a.name,
    type: 'line' as const,
    data: a.months.map(pick),
    showSymbol: false,
    lineStyle: { color: SERIES[i % SERIES.length], width: 2 },
    itemStyle: { color: SERIES[i % SERIES.length] },
    ...(input.stacked
      ? { stack: 'portfolio', areaStyle: { color: SERIES[i % SERIES.length], opacity: 0.35 } }
      : {}),
    ...(input.accounts.length <= 4 && !input.stacked
      ? { endLabel: { show: true, formatter: a.name, color: INK_SOFT, fontSize: 11, fontFamily: 'IBM Plex Mono' } }
      : {}),
  }))

  const totalSeries = input.stacked
    ? []
    : [
        {
          name: 'Total',
          type: 'line' as const,
          data: input.total.map((t) => (input.real ? t.realBalance : t.balance)),
          showSymbol: false,
          lineStyle: { color: INK, width: 3 },
          itemStyle: { color: INK },
        },
      ]

  return {
    ...baseOption(dates, input.birthDate),
    legend: {
      top: 0,
      textStyle: { color: INK_SOFT, fontSize: 12 },
      icon: 'roundRect',
      itemWidth: 12,
      itemHeight: 12,
    },
    grid: { left: 64, right: 96, top: 40, bottom: 40 },
    series: [...accountSeries, ...totalSeries],
  }
}
