import type { EChartsOption, MarkAreaComponentOption, MarkLineComponentOption } from 'echarts'
import type { ProjectionMonth } from '@/api/types'
import { SERIES, INK, INK_SOFT, INK_FAINT, LINE, PAPER_RAISED, DANGER } from './palette'
import { money, moneyCompact, monthLabel, ageAt } from './format'

const AXIS_TEXT = { color: INK_FAINT, fontFamily: 'IBM Plex Mono', fontSize: 11 }

// COPPER (#b0521a) at 7% opacity — the shared drawdown-window shading treatment.
const DRAWDOWN_AREA_COLOR = 'rgba(176, 82, 26, 0.07)'

// Dash patterns for series beyond the validated 6-slot palette, cycled by (index - 6) % 3.
const OVERFLOW_DASH_PATTERNS: number[][] = [
  [4, 4],
  [8, 4],
  [2, 6],
]

// Target roughly this many year labels across the x-axis; a long horizon skips more years per label
// so labels never crowd together regardless of chart width.
const TARGET_YEAR_LABELS = 10

// Clamps a drawdown window to the visible date range: a start before the first date snaps to it,
// an end after (or missing) snaps to the last date, and a start after the last date omits the window.
function clampDrawdownWindow(start: string, end: string | null, dates: string[]): [string, string] | null {
  const first = dates[0]
  const last = dates[dates.length - 1]
  if (first === undefined || last === undefined) return null
  if (start > last) return null
  const clampedStart = start < first ? first : start
  const clampedEnd = end === null || end > last ? last : end
  return [clampedStart, clampedEnd]
}

// Color assigned to an account series at position i: the validated palette for the first 6 slots,
// neutral ink beyond that (never cycle hues — see palette.ts).
function seriesColor(i: number): string {
  return i < 6 ? SERIES[i]! : INK_FAINT
}

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

  const clampedDrawdown = input.drawdownStart === null ? null : clampDrawdownWindow(input.drawdownStart, input.drawdownEnd, dates)

  const markArea: MarkAreaComponentOption | undefined =
    clampedDrawdown === null
      ? undefined
      : {
          silent: true,
          itemStyle: { color: DRAWDOWN_AREA_COLOR },
          data: [[{ xAxis: clampedDrawdown[0] }, { xAxis: clampedDrawdown[1] }]],
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
  drawdownWindows: { start: string; end: string | null }[]
}): EChartsOption {
  const dates = input.total.map((t) => t.date)
  const pick = (m: ProjectionMonth) => (input.real ? m.realBalance : m.balance)

  const clampedWindows = input.drawdownWindows
    .map((w) => clampDrawdownWindow(w.start, w.end, dates))
    .filter((w): w is [string, string] => w !== null)

  const markArea: MarkAreaComponentOption | undefined =
    clampedWindows.length === 0
      ? undefined
      : {
          silent: true,
          itemStyle: { color: DRAWDOWN_AREA_COLOR },
          data: clampedWindows.map(([start, end]) => [{ xAxis: start }, { xAxis: end }]),
        }

  const accountSeries = input.accounts.map((a, i) => {
    const color = seriesColor(i)
    const overflow = i >= 6
    return {
      name: a.name,
      type: 'line' as const,
      data: a.months.map(pick),
      showSymbol: false,
      lineStyle: {
        color,
        width: 2,
        ...(overflow ? { type: OVERFLOW_DASH_PATTERNS[(i - 6) % OVERFLOW_DASH_PATTERNS.length] } : {}),
      },
      itemStyle: { color },
      markArea: i === 0 ? markArea : undefined,
      ...(input.stacked ? { stack: 'portfolio', areaStyle: { color, opacity: overflow ? 0.2 : 0.35 } } : {}),
      ...(input.accounts.length <= 4 && !input.stacked
        ? { endLabel: { show: true, formatter: a.name, color: INK_SOFT, fontSize: 11, fontFamily: 'IBM Plex Mono' } }
        : {}),
    }
  })

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

  const base = baseOption(dates, input.birthDate)

  return {
    ...base,
    tooltip: {
      ...(base.tooltip as object),
      formatter: (params: unknown) => {
        const rows = params as { axisValue?: string; marker?: string; seriesName?: string; value?: number }[]
        if (!rows || rows.length === 0) return ''
        const header = rows[0]?.axisValue
        return [
          `<strong>${monthLabel(header ?? '')}</strong>`,
          ...rows.map((p) => `${p.marker ?? ''}${p.seriesName} ${money(p.value ?? 0)}`),
        ].join('<br/>')
      },
    },
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
