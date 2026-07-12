# Nestegg Plan 5/5: Charts and Analytical Views Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The three analytical views that are the point of the app: the account projection view (live sliders → graph, today's-dollars toggle, depletion marker, explicit Save), the goal-seek view (mirrored UI solving required contribution), and the portfolio overview chart (per-account series + server-computed total, stacked toggle).

**Architecture:** A new stateless backend endpoint `POST /api/projection/portfolio` computes per-account series over the longest horizon plus the summed total server-side (ADR-0001 forbids client summation). Frontend: ECharts wrapped in one `LedgerChart` component; all chart options built by pure, unit-tested builder functions; one debounced-POST composable powers all three views; `AccountForm` gains a live `change` emit backed by an extracted pure payload-shaping helper. Save stays explicit with a dirty indicator (SPEC save semantics).

**Tech Stack:** ECharts 6 (npm `echarts`), Vue 3, Vitest, PHPUnit (backend task).

## Global Constraints

- **No financial math client-side** (ADR-0001). The frontend never sums, deflates, converts, or derives money values — it renders API fields. Sanctioned exceptions remain: PercentInput ×100/÷100 display, date→age labels, Intl formatting.
- Chart rules (dataviz method, non-negotiable): series colors come from the fixed validated palette order `#1b7a4e, #b0521a, #3f5bd6, #8c3f9e, #0b87b4, #c03434` — assigned by account position, never cycled or repainted on filter; ONE y-axis per chart; a legend is present for ≥2 series (none for a single series); ≤4 series also get direct end-labels; grid/axis lines recessive (`#ddd4bf`); tooltips on by default (axis crosshair); text in ink tokens, never series colors; 2px line width, no data-point symbols except on hover.
- The month axis uses the API's `YYYY-MM` strings with the user's age as a secondary label (`year - birthYear` — sanctioned). `depletionDate` is `YYYY-MM`.
- Projection requests: `{account: AccountInput, taxes: {ordinaryIncomeTaxRate, capitalGainsTaxRate}, birthDate, deathAge, startsOn: null}` — taxes from the owning portfolio, birthDate/deathAge from the auth user, `startsOn` null (server defaults to current month). `account.name` must be non-blank (send the form value; it's required anyway).
- Goal-seek: check `attainable` before rendering results; `requiredMonthlyContribution` is the base amount at the contribution-window start (label it "starting monthly contribution"); solved drawdown projections show `depletionDate: null`.
- Explicit Save with dirty indicator; slider changes never write until Save (SPEC).
- Frontend commands on host in `frontend/`; backend task uses `make test` etc. in-container. Commit trailer as in `git log`.
- After ANY edit to `AccountForm.vue`, re-run the whole frontend suite — Plan 4's fix round added input-coercion behavior there that must survive (the shaping helper you extract must keep it).

---

### Task 1: Backend — stateless portfolio projection endpoint

**Files:**
- Create: `backend/src/Dto/PortfolioProjectionRequest.php`
- Create: `backend/src/Controller/PortfolioProjectionController.php`
- Test: `backend/tests/Controller/PortfolioProjectionEndpointTest.php`

**Interfaces:**
- Consumes: `ProjectionController::{firstOfMonth, buildAssumptions, serialize}` (public statics), `AccountInput`/`TaxesInput` DTOs, `Projector`.
- Produces: `POST /api/projection/portfolio` with body `{"accounts": [<AccountInput>...], "taxes": {...}, "birthDate", "deathAge", "startsOn"}` → 200:
  `{"accounts": [{"name": string, "months": [...], "summary": {...}} ...], "total": {"months": [{"index", "date", "balance", "realBalance"}...], "horizonMonths": int}}`
  Semantics: every account is projected over the SAME horizon = max of the accounts' `horizonYears` (so the total is meaningful at every month; an account's own view keeps its own horizon — this endpoint is for the portfolio overview). Total months sum `balance` and `realBalance` across accounts per index — computed here precisely so the client never does it (ADR-0001). Total `realBalance` deflates the summed nominal balance using the FIRST account's inflation rate (portfolio-level display approximation; documented in code — accounts may have differing inflation assumptions, and v1 takes account[0]'s as the portfolio deflator). 422 when `accounts` is empty.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Controller/PortfolioProjectionEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ApiTestCase;

final class PortfolioProjectionEndpointTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function account(string $name, float $startingBalance, int $horizonYears): array
    {
        return [
            'name' => $name,
            'type' => 'roth_ira',
            'startingBalance' => $startingBalance,
            'annualReturnRate' => 0.0,
            'inflationRate' => 0.0,
            'horizonYears' => $horizonYears,
            'contribution' => ['monthlyAmount' => 0.0],
            'drawdown' => ['amount' => null],
        ];
    }

    public function testProjectsAllAccountsOverLongestHorizonAndSums(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/projection/portfolio', [
            'startsOn' => '2026-07-01',
            'taxes' => ['ordinaryIncomeTaxRate' => 0.25, 'capitalGainsTaxRate' => 0.15],
            'accounts' => [
                $this->account('Short', 1000.0, 1),
                $this->account('Long', 2000.0, 2),
            ],
        ]);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertCount(2, $data['accounts']);
        self::assertSame('Short', $data['accounts'][0]['name']);
        // Both accounts projected over the longest horizon (24 months).
        self::assertCount(24, $data['accounts'][0]['months']);
        self::assertCount(24, $data['accounts'][1]['months']);
        self::assertSame(24, $data['total']['horizonMonths']);
        // Total sums balances: 1000 + 2000, flat (no growth/flows).
        self::assertEqualsWithDelta(3000.0, $data['total']['months'][0]['balance'], 0.01);
        self::assertEqualsWithDelta(3000.0, $data['total']['months'][23]['balance'], 0.01);
        self::assertSame('2026-07', $data['total']['months'][0]['date']);
    }

    public function testEmptyAccountsRejected(): void
    {
        $client = $this->createAuthenticatedClient('empty@example.com');
        $client->jsonRequest('POST', '/api/projection/portfolio', [
            'taxes' => ['ordinaryIncomeTaxRate' => 0.25, 'capitalGainsTaxRate' => 0.15],
            'accounts' => [],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRequiresAuth(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/projection/portfolio', ['accounts' => []]);
        self::assertResponseStatusCodeSame(401);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

`make test` — FAIL, 404.

- [ ] **Step 3: Implement DTO and controller**

Create `backend/src/Dto/PortfolioProjectionRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PortfolioProjectionRequest
{
    /** @param list<AccountInput> $accounts */
    public function __construct(
        #[Assert\Valid]
        #[Assert\Count(min: 1)]
        public array $accounts,
        #[Assert\Valid]
        public TaxesInput $taxes = new TaxesInput(),
        #[Assert\Date]
        public ?string $birthDate = null,
        #[Assert\Range(min: 1, max: 120)]
        public ?int $deathAge = null,
        #[Assert\Date]
        public ?string $startsOn = null,
    ) {
    }
}
```

(The `@param list<AccountInput>` PhpDoc drives the serializer's array denormalization — `phpstan/phpdoc-parser`/property-info are installed via serializer-pack. If denormalization of the array fails at runtime, the fallback is `#[MapRequestPayload]`-free manual mapping — but verify with the test first; the PhpDoc route is expected to work on Symfony 7.4.)

Create `backend/src/Controller/PortfolioProjectionController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\AccountInput;
use App\Dto\PortfolioProjectionRequest;
use App\Dto\ProjectionRequest;
use App\Projection\Projector;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class PortfolioProjectionController extends ApiController
{
    #[Route('/api/projection/portfolio', name: 'api_projection_portfolio', methods: ['POST'])]
    public function project(#[MapRequestPayload] PortfolioProjectionRequest $request): JsonResponse
    {
        $start = ProjectionController::firstOfMonth(
            null !== $request->startsOn ? new \DateTimeImmutable($request->startsOn) : new \DateTimeImmutable('now'),
        );

        $maxHorizonYears = max(array_map(static fn (AccountInput $a): int => $a->horizonYears, $request->accounts));

        $projector = new Projector();
        $accountsOut = [];
        $sums = [];
        foreach ($request->accounts as $account) {
            $stretched = self::withHorizon($account, $maxHorizonYears);
            $projectionRequest = new ProjectionRequest(
                account: $stretched,
                taxes: $request->taxes,
                birthDate: $request->birthDate,
                deathAge: $request->deathAge,
                startsOn: $request->startsOn,
            );
            $assumptions = ProjectionController::buildAssumptions($projectionRequest, $start);
            $serialized = ProjectionController::serialize($projector->project($assumptions), $start, $assumptions->annualInflationRate);
            $accountsOut[] = ['name' => $account->name, ...$serialized];

            foreach ($serialized['months'] as $month) {
                $sums[$month['index']]['balance'] = ($sums[$month['index']]['balance'] ?? 0.0) + $month['balance'];
            }
        }

        // Portfolio-level real-dollar deflator: v1 uses the first account's inflation
        // rate (accounts may disagree; the overview needs one deflator).
        $monthlyInflation = (1 + $request->accounts[0]->inflationRate) ** (1 / 12) - 1;
        $totalMonths = [];
        foreach ($sums as $index => $sum) {
            $totalMonths[] = [
                'index' => $index,
                'date' => $start->modify(sprintf('+%d months', $index))->format('Y-m'),
                'balance' => round($sum['balance'], 2),
                'realBalance' => round($sum['balance'] / (1 + $monthlyInflation) ** ($index + 1), 2),
            ];
        }

        return $this->apiJson([
            'accounts' => $accountsOut,
            'total' => ['months' => $totalMonths, 'horizonMonths' => $maxHorizonYears * 12],
        ]);
    }

    private static function withHorizon(AccountInput $a, int $horizonYears): AccountInput
    {
        return new AccountInput(
            name: $a->name,
            type: $a->type,
            startingBalance: $a->startingBalance,
            startingBasis: $a->startingBasis,
            annualReturnRate: $a->annualReturnRate,
            inflationRate: $a->inflationRate,
            horizonYears: $horizonYears,
            contribution: $a->contribution,
            drawdown: $a->drawdown,
        );
    }
}
```

- [ ] **Step 4: Run tests, commit**

`make test` — green, pristine.

```bash
git add backend/src backend/tests
git commit -m "feat: stateless POST /api/projection/portfolio with server-computed total"
```

---

### Task 2: Chart infrastructure — palette module, LedgerChart, option builders

**Files:**
- Modify: `frontend/package.json` (npm i echarts)
- Create: `frontend/src/lib/palette.ts`
- Create: `frontend/src/lib/format.ts`
- Create: `frontend/src/components/LedgerChart.vue`
- Create: `frontend/src/lib/projectionChart.ts`
- Test: `frontend/src/lib/__tests__/projectionChart.spec.ts`

**Interfaces:**
- Consumes: design tokens (mirrored as TS constants — ECharts needs concrete strings), `ProjectionMonth` type.
- Produces:
  - `palette.ts`: `SERIES: readonly string[]` (the 6 validated hexes in order), `INK`, `INK_SOFT`, `INK_FAINT`, `LINE`, `PAPER_RAISED`, `DANGER`, `COPPER` constants.
  - `format.ts`: `money(n: number): string` (Intl USD, 0 digits), `moneyCompact(n: number): string` (e.g. `$1.2M`), `monthLabel(date: string): string` (`'2041-06'` → `'Jun 2041'`), `ageAt(date: string, birthDate: string | null): number | null` (year difference — sanctioned label math).
  - `LedgerChart.vue`: props `{ option: EChartsOption }`; renders a 100%-width, 360px-tall chart; re-applies on deep option change (`notMerge: true`); ResizeObserver; disposes on unmount.
  - `projectionChart.ts`: `buildProjectionOption(input: { months: ProjectionMonth[]; real: boolean; depletionDate: string | null; drawdownStart: string | null; drawdownEnd: string | null; birthDate: string | null }): EChartsOption` and `buildPortfolioOption(input: { accounts: { name: string; months: ProjectionMonth[] }[]; total: { date: string; balance: number; realBalance: number }[]; real: boolean; stacked: boolean; birthDate: string | null }): EChartsOption` — pure functions, no DOM.

- [ ] **Step 1: Install echarts**

```bash
cd /Users/isaacsaunders/workspace/nestegg/frontend && npm install echarts
```

- [ ] **Step 2: Write the failing builder tests**

Create `frontend/src/lib/__tests__/projectionChart.spec.ts`:

```ts
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
    const series = (nominal.series as { data: number[] }[])[0]
    expect(series.data).toEqual([1000, 1100, 1200])

    const real = buildProjectionOption({ months, real: true, depletionDate: null, drawdownStart: null, drawdownEnd: null, birthDate: null })
    expect((real.series as { data: number[] }[])[0].data).toEqual([900, 990, 1080])
  })

  it('has exactly one y axis and no legend for the single series', () => {
    const opt = buildProjectionOption({ months, real: false, depletionDate: null, drawdownStart: null, drawdownEnd: null, birthDate: null })
    expect(Array.isArray(opt.yAxis) ? (opt.yAxis as unknown[]).length : 1).toBe(1)
    expect(opt.legend).toBeUndefined()
  })

  it('marks the depletion month and shades the drawdown window', () => {
    const opt = buildProjectionOption({ months, real: false, depletionDate: '2026-09', drawdownStart: '2026-08', drawdownEnd: null, birthDate: null })
    const series = (opt.series as Record<string, unknown>[])[0]
    expect(JSON.stringify(series.markLine)).toContain('2026-09')
    expect(JSON.stringify(series.markArea)).toContain('2026-08')
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
    const series = opt.series as { name: string; stack?: string }[]
    expect(series.map((s) => s.name)).toEqual(['A', 'B', 'Total'])
    expect(opt.legend).toBeDefined()
    expect(series.every((s) => s.stack === undefined)).toBe(true)
  })

  it('stacked mode stacks account areas and drops the total line', () => {
    const opt = buildPortfolioOption({ accounts, total, real: false, stacked: true, birthDate: null })
    const series = opt.series as { name: string; stack?: string }[]
    expect(series.map((s) => s.name)).toEqual(['A', 'B'])
    expect(series.every((s) => s.stack === 'portfolio')).toBe(true)
  })

  it('assigns palette colors by account position', () => {
    const opt = buildPortfolioOption({ accounts, total, real: false, stacked: false, birthDate: null })
    const series = opt.series as { itemStyle: { color: string } }[]
    expect(series[0].itemStyle.color).toBe('#1b7a4e')
    expect(series[1].itemStyle.color).toBe('#b0521a')
  })
})
```

- [ ] **Step 3: Run to verify failure** — `npm run test:unit -- --run` (module missing).

- [ ] **Step 4: Implement palette, format, builders, wrapper**

Create `frontend/src/lib/palette.ts`:

```ts
// Mirrors the CSS custom properties in assets/main.css — ECharts needs concrete
// strings. The series order is CVD-validated; never reorder or cycle it.
export const SERIES = ['#1b7a4e', '#b0521a', '#3f5bd6', '#8c3f9e', '#0b87b4', '#c03434'] as const
export const INK = '#20281f'
export const INK_SOFT = '#5a6355'
export const INK_FAINT = '#98a08f'
export const LINE = '#ddd4bf'
export const PAPER_RAISED = '#fffcf4'
export const DANGER = '#c03434'
export const COPPER = '#b0521a'
```

Create `frontend/src/lib/format.ts`:

```ts
const moneyFmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 })
const compactFmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', notation: 'compact', maximumFractionDigits: 1 })
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

export function money(n: number): string {
  return moneyFmt.format(n)
}

export function moneyCompact(n: number): string {
  return compactFmt.format(n)
}

export function monthLabel(date: string): string {
  const [year, month] = date.split('-')
  return `${MONTHS[Number(month) - 1]} ${year}`
}

export function ageAt(date: string, birthDate: string | null): number | null {
  if (!birthDate) return null
  return Number(date.slice(0, 4)) - Number(birthDate.slice(0, 4))
}
```

Create `frontend/src/lib/projectionChart.ts`:

```ts
import type { EChartsOption } from 'echarts'
import type { ProjectionMonth } from '@/api/types'
import { SERIES, INK, INK_SOFT, INK_FAINT, LINE, PAPER_RAISED, DANGER } from './palette'
import { money, moneyCompact, monthLabel, ageAt } from './format'

const AXIS_TEXT = { color: INK_FAINT, fontFamily: 'IBM Plex Mono', fontSize: 11 }

function baseOption(dates: string[], birthDate: string | null): EChartsOption {
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

  const markArea =
    input.drawdownStart === null
      ? undefined
      : {
          silent: true,
          itemStyle: { color: 'rgba(176, 82, 26, 0.07)' },
          data: [[{ xAxis: input.drawdownStart }, { xAxis: input.drawdownEnd ?? dates[dates.length - 1] }]],
        }

  const markLine =
    input.depletionDate === null
      ? undefined
      : {
          symbol: 'none',
          lineStyle: { color: DANGER, type: 'dashed', width: 2 },
          label: { formatter: 'Runs dry', color: DANGER, fontFamily: 'IBM Plex Mono', fontSize: 11 },
          data: [{ xAxis: input.depletionDate }],
        }

  return {
    ...baseOption(dates, input.birthDate),
    tooltip: {
      ...(baseOption(dates, input.birthDate).tooltip as object),
      formatter: (params: unknown) => {
        const p = (params as { axisValue: string }[])[0]
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
        type: 'line',
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
```

Create `frontend/src/components/LedgerChart.vue`:

```vue
<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import * as echarts from 'echarts'
import type { EChartsOption } from 'echarts'

const props = defineProps<{ option: EChartsOption }>()
const el = ref<HTMLDivElement | null>(null)
let chart: echarts.ECharts | null = null
let observer: ResizeObserver | null = null

onMounted(() => {
  if (!el.value) return
  chart = echarts.init(el.value)
  chart.setOption(props.option)
  observer = new ResizeObserver(() => chart?.resize())
  observer.observe(el.value)
})

watch(
  () => props.option,
  (option) => chart?.setOption(option, { notMerge: true }),
  { deep: true },
)

onUnmounted(() => {
  observer?.disconnect()
  chart?.dispose()
})
</script>

<template>
  <div ref="el" class="ledger-chart"></div>
</template>

<style scoped>
.ledger-chart { width: 100%; height: 360px; }
</style>
```

- [ ] **Step 5: Verify and commit**

```bash
cd frontend && npm run test:unit -- --run && npm run type-check && npm run build && npm run lint:check
cd /Users/isaacsaunders/workspace/nestegg
git add frontend
git commit -m "feat: chart infrastructure — palette, option builders, LedgerChart"
```

---

### Task 3: Live payload plumbing — shaping helper, AccountForm change emit, compute composable

**Files:**
- Create: `frontend/src/lib/accountPayload.ts` (logic MOVED from AccountForm.submit — including the number-coercion hardening added in Plan 4's fix round)
- Modify: `frontend/src/components/AccountForm.vue` (use helper; add `change` emit; add `lockContributionAmount` prop)
- Modify: `frontend/src/api/types.ts` (request types)
- Create: `frontend/src/lib/useDebouncedPost.ts`
- Test: `frontend/src/lib/__tests__/accountPayload.spec.ts`, `frontend/src/lib/__tests__/useDebouncedPost.spec.ts`

**Interfaces:**
- Consumes: AccountForm internals (Plan 4 + its fix round).
- Produces:
  - `toAccountInput(form: AccountInput, hasDrawdown: boolean): AccountInput` — pure: deep-clones, resets drawdown when off, `''`→null dates, non-brokerage basis→null, coerces non-finite numbers (balance/monthlyAmount→0, horizonYears→40, basis/drawdown.amount→null).
  - `AccountForm` emits `change(input: AccountInput)` on every form mutation (deep watch, incl. `hasDrawdown`), and `save(input)` on submit — both built by the same helper. New optional prop `lockContributionAmount` disables the monthly-amount input and hints "solved by goal seek".
  - Types: `ProjectionRequest { account: AccountInput; taxes: {ordinaryIncomeTaxRate: number; capitalGainsTaxRate: number}; birthDate: string | null; deathAge: number | null; startsOn: string | null }`; `GoalInput { kind: 'drawdown' | 'target_value'; amount?: number; atDate?: string; amountInTodaysDollars?: boolean }`; `GoalSeekRequest extends ProjectionRequest { goal: GoalInput }`.
  - `useDebouncedPost<TReq, TRes>(path: string, payload: Ref<TReq | null>, delay = 250)` → `{ data: Ref<TRes | null>, pending: Ref<boolean>, error: Ref<string> }` — deep-watches payload, debounces, sequence-guards stale responses, maps ApiError message into `error`, immediate first run.

- [ ] **Step 1: Write the failing tests**

Create `frontend/src/lib/__tests__/accountPayload.spec.ts`:

```ts
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
```

Create `frontend/src/lib/__tests__/useDebouncedPost.spec.ts`:

```ts
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref, nextTick } from 'vue'

vi.mock('@/api/client', () => ({
  api: vi.fn(),
  ApiError: class ApiError extends Error {
    constructor(public status: number, message: string) { super(message) }
  },
}))

import { api, ApiError } from '@/api/client'
import { useDebouncedPost } from '../useDebouncedPost'

const mockedApi = vi.mocked(api)

describe('useDebouncedPost', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mockedApi.mockReset()
  })
  afterEach(() => vi.useRealTimers())

  it('debounces rapid payload changes into one call', async () => {
    mockedApi.mockResolvedValue({ ok: 1 })
    const payload = ref<{ v: number } | null>({ v: 1 })
    const { data } = useDebouncedPost<{ v: number }, { ok: number }>('/api/projection', payload)
    await nextTick()
    payload.value = { v: 2 }
    await nextTick()
    payload.value = { v: 3 }
    await nextTick()
    await vi.advanceTimersByTimeAsync(300)
    expect(mockedApi).toHaveBeenCalledTimes(1)
    expect(mockedApi).toHaveBeenCalledWith('POST', '/api/projection', { v: 3 })
    expect(data.value).toEqual({ ok: 1 })
  })

  it('ignores stale responses', async () => {
    let resolveFirst!: (v: unknown) => void
    mockedApi.mockImplementationOnce(() => new Promise((r) => (resolveFirst = r)))
    const payload = ref<{ v: number } | null>({ v: 1 })
    const { data } = useDebouncedPost<{ v: number }, { v: number }>('/api/projection', payload)
    await nextTick()
    await vi.advanceTimersByTimeAsync(300)

    mockedApi.mockResolvedValueOnce({ v: 2 })
    payload.value = { v: 2 }
    await nextTick()
    await vi.advanceTimersByTimeAsync(300)
    resolveFirst({ v: 1 }) // slow first response lands last
    await vi.runAllTimersAsync()
    expect(data.value).toEqual({ v: 2 })
  })

  it('maps ApiError message into error and clears on success', async () => {
    mockedApi.mockRejectedValueOnce(new ApiError(422, 'atDate is outside the projection horizon.'))
    const payload = ref<{ v: number } | null>({ v: 1 })
    const { error } = useDebouncedPost('/api/projection', payload)
    await nextTick()
    await vi.advanceTimersByTimeAsync(300)
    expect(error.value).toBe('atDate is outside the projection horizon.')
  })

  it('does nothing for null payloads', async () => {
    const payload = ref(null)
    useDebouncedPost('/api/projection', payload)
    await nextTick()
    await vi.advanceTimersByTimeAsync(300)
    expect(mockedApi).not.toHaveBeenCalled()
  })
})
```

- [ ] **Step 2: Run to verify failure** — modules missing.

- [ ] **Step 3: Implement**

Create `frontend/src/lib/accountPayload.ts` — MOVE the shaping logic out of `AccountForm.vue`'s `submit()` (keep behavior identical to the post-fix-round version, structured as):

```ts
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
```

In `AccountForm.vue`: import the helper; `submit()` becomes `emit('save', toAccountInput(form, hasDrawdown.value))`; add to the script:

```ts
import { watch } from 'vue'
// after form/hasDrawdown declarations:
watch([form, hasDrawdown], () => emit('change', toAccountInput(form, hasDrawdown.value)), { deep: true, immediate: true })
```

extend the emits declaration with `change: [input: AccountInput]`, add props `lockContributionAmount?: boolean` and `hideActions?: boolean`; on the monthly-amount input: `:disabled="lockContributionAmount"` plus, when locked, a `<span class="muted small">solved by goal seek</span>` under it; wrap the submit/cancel `.row` in `v-if="!hideActions"` (live-preview consumers drive saving themselves).

Append the request types to `frontend/src/api/types.ts`:

```ts
export interface TaxesInput {
  ordinaryIncomeTaxRate: number
  capitalGainsTaxRate: number
}

export interface ProjectionRequest {
  account: AccountInput
  taxes: TaxesInput
  birthDate: string | null
  deathAge: number | null
  startsOn: string | null
}

export interface GoalInput {
  kind: 'drawdown' | 'target_value'
  amount?: number
  atDate?: string
  amountInTodaysDollars?: boolean
}

export interface GoalSeekRequest extends ProjectionRequest {
  goal: GoalInput
}

export interface PortfolioProjectionRequest {
  accounts: AccountInput[]
  taxes: TaxesInput
  birthDate: string | null
  deathAge: number | null
  startsOn: string | null
}

export interface PortfolioTotalMonth {
  index: number
  date: string
  balance: number
  realBalance: number
}

export interface PortfolioProjectionResponse {
  accounts: ({ name: string } & ProjectionResponse)[]
  total: { months: PortfolioTotalMonth[]; horizonMonths: number }
}
```

Create `frontend/src/lib/useDebouncedPost.ts`:

```ts
import { ref, watch, type Ref } from 'vue'
import { api, ApiError } from '@/api/client'

export function useDebouncedPost<TReq, TRes>(path: string, payload: Ref<TReq | null>, delay = 250) {
  const data = ref<TRes | null>(null) as Ref<TRes | null>
  const pending = ref(false)
  const error = ref('')
  let timer: ReturnType<typeof setTimeout> | undefined
  let seq = 0

  watch(
    payload,
    (p) => {
      if (p === null) return
      clearTimeout(timer)
      timer = setTimeout(async () => {
        const mine = ++seq
        pending.value = true
        try {
          const res = await api<TRes>('POST', path, p)
          if (mine === seq) {
            data.value = res
            error.value = ''
          }
        } catch (e) {
          if (mine === seq) error.value = e instanceof ApiError ? e.message : 'Request failed.'
        } finally {
          if (mine === seq) pending.value = false
        }
      }, delay)
    },
    { deep: true, immediate: true },
  )

  return { data, pending, error }
}
```

- [ ] **Step 4: Verify and commit**

```bash
cd frontend && npm run test:unit -- --run && npm run type-check && npm run build && npm run lint:check
```
(Full suite matters here — AccountForm changed; Plan 4's tests must stay green.)

```bash
git add frontend
git commit -m "feat: live account payload shaping and debounced compute composable"
```

---

### Task 4: Account projection view

**Files:**
- Replace: `frontend/src/views/AccountView.vue`

**Interfaces:**
- Consumes: everything above; `POST /api/projection`.
- Produces: the projection view at `/accounts/:id`: left panel = live `AccountForm`; right = hero tiles (ending balance, depletion, total contributions, total tax — all from `summary`), today's-dollars toggle, `LedgerChart`, dirty-state Save bar, link to `/accounts/:id/goal-seek`. Plan-5 Task 5 links back from goal-seek.

- [ ] **Step 1: Implement the view**

Replace `frontend/src/views/AccountView.vue`:

```vue
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { usePortfoliosStore } from '@/stores/portfolios'
import { ApiError } from '@/api/client'
import type { AccountInput, ProjectionRequest, ProjectionResponse } from '@/api/types'
import { toAccountInput } from '@/lib/accountPayload'
import { useDebouncedPost } from '@/lib/useDebouncedPost'
import { buildProjectionOption } from '@/lib/projectionChart'
import { money, monthLabel } from '@/lib/format'
import AccountForm from '@/components/AccountForm.vue'
import LedgerChart from '@/components/LedgerChart.vue'

const route = useRoute()
const auth = useAuthStore()
const store = usePortfoliosStore()
const accountId = computed(() => Number(route.params.id))
const found = computed(() => store.accountById(accountId.value))

const draft = ref<AccountInput | null>(null)
const real = ref(false)
const saveError = ref('')
const saved = ref(false)
const loadError = ref('')

onMounted(() => {
  if (!store.loaded) store.load().catch(() => (loadError.value = 'Could not load your portfolios.'))
})

function savedShape(): AccountInput | null {
  if (!found.value) return null
  const { id: _id, portfolioId: _pid, ...input } = found.value.account
  return toAccountInput(input as AccountInput, (input as AccountInput).drawdown.amount !== null)
}

const dirty = computed(
  () => draft.value !== null && JSON.stringify(draft.value) !== JSON.stringify(savedShape()),
)

const payload = computed<ProjectionRequest | null>(() => {
  if (!found.value) return null
  const account = draft.value ?? savedShape()
  if (!account) return null
  return {
    account,
    taxes: {
      ordinaryIncomeTaxRate: found.value.portfolio.ordinaryIncomeTaxRate,
      capitalGainsTaxRate: found.value.portfolio.capitalGainsTaxRate,
    },
    birthDate: auth.user?.birthDate ?? null,
    deathAge: auth.user?.deathAge ?? null,
    startsOn: null,
  }
})

const { data, pending, error } = useDebouncedPost<ProjectionRequest, ProjectionResponse>('/api/projection', payload)

const chartOption = computed(() => {
  if (!data.value) return null
  const account = draft.value ?? savedShape()
  return buildProjectionOption({
    months: data.value.months,
    real: real.value,
    depletionDate: data.value.summary.depletionDate,
    drawdownStart: account?.drawdown.startsOn?.slice(0, 7) ?? null,
    drawdownEnd: account?.drawdown.endsOn?.slice(0, 7) ?? null,
    birthDate: auth.user?.birthDate ?? null,
  })
})

async function save() {
  if (!draft.value || !found.value) return
  saveError.value = ''
  try {
    await store.updateAccount(found.value.account.id, draft.value)
    saved.value = true
    setTimeout(() => (saved.value = false), 2000)
  } catch (e) {
    saveError.value = e instanceof ApiError ? e.message : 'Save failed.'
  }
}
</script>

<template>
  <section v-if="found">
    <p class="small"><RouterLink :to="`/portfolios/${found.portfolio.id}`">← {{ found.portfolio.name }}</RouterLink></p>
    <div class="head-row">
      <h1>{{ found.account.name }}</h1>
      <RouterLink class="btn" :to="`/accounts/${found.account.id}/goal-seek`">Goal seek →</RouterLink>
    </div>

    <div class="layout">
      <div class="panel card">
        <AccountForm :initial="found.account" @change="draft = $event" @save="save">
        </AccountForm>
      </div>

      <div class="results">
        <div v-if="data" class="tiles">
          <div class="tile card">
            <span class="tile-label">Ending balance</span>
            <span class="tile-value figure">{{ money(real ? data.summary.endingRealBalance : data.summary.endingBalance) }}</span>
          </div>
          <div class="tile card" :class="{ danger: data.summary.depletionDate }">
            <span class="tile-label">Runs dry</span>
            <span class="tile-value figure">{{ data.summary.depletionDate ? monthLabel(data.summary.depletionDate) : 'Never' }}</span>
          </div>
          <div class="tile card">
            <span class="tile-label">Total contributed</span>
            <span class="tile-value figure">{{ money(data.summary.totalContributions) }}</span>
          </div>
          <div class="tile card">
            <span class="tile-label">Total tax</span>
            <span class="tile-value figure">{{ money(data.summary.totalTaxPaid) }}</span>
          </div>
        </div>

        <div class="chart-card card">
          <div class="chart-head">
            <h2>Projection <span v-if="pending" class="muted small">computing…</span></h2>
            <label class="toggle small">
              <input v-model="real" type="checkbox" />
              Today's dollars
            </label>
          </div>
          <p v-if="error" class="form-error">{{ error }}</p>
          <LedgerChart v-if="chartOption" :option="chartOption" />
        </div>

        <div class="save-bar" :class="{ visible: dirty || saved }">
          <span v-if="dirty" class="small">Unsaved changes — the graph reflects your draft.</span>
          <span v-else-if="saved" class="small saved-note">Saved.</span>
          <button v-if="dirty" class="btn btn-primary" @click="save">Save account</button>
          <span v-if="saveError" class="form-error">{{ saveError }}</span>
        </div>
      </div>
    </div>
  </section>
  <p v-else-if="loadError" class="form-error">{{ loadError }}</p>
  <p v-else-if="store.loaded" class="muted">Account not found. <RouterLink to="/portfolios">Back to portfolios</RouterLink></p>
</template>

<style scoped>
.head-row { display: flex; justify-content: space-between; align-items: baseline; }
.layout { display: grid; grid-template-columns: minmax(20rem, 26rem) 1fr; gap: 1.25rem; align-items: start; }
@media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 0.75rem; margin-bottom: 0.75rem; }
.tile { display: grid; gap: 0.15rem; padding: 0.8rem 1rem; }
.tile-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-soft); }
.tile-value { font-size: 1.25rem; font-weight: 600; }
.tile.danger .tile-value { color: var(--danger); }
.chart-head { display: flex; justify-content: space-between; align-items: baseline; }
.toggle { display: inline-flex; gap: 0.4rem; align-items: center; }
.save-bar { display: flex; gap: 0.75rem; align-items: center; margin-top: 0.75rem; min-height: 2.2rem; visibility: hidden; }
.save-bar.visible { visibility: visible; }
.saved-note { color: var(--green-deep); }
</style>
```

Note: `AccountForm`'s internal submit button says "Save account" — that's the form's `save` emit and is fine here; the save-bar's button is the primary affordance when dirty. Both call the same `save()`.

- [ ] **Step 2: Verify — unit/type/build, then live browser check**

```bash
cd frontend && npm run test:unit -- --run && npm run type-check && npm run build && npm run lint:check
```

Playwright (http://127.0.0.1:5173, NOT localhost): log in as the demo user `demo@nestegg.local` / `demo-password-123` (from fixtures — if login fails, run `make fixtures` from the repo root first); open the Baseline portfolio → "Employer 401k" → verify the chart renders (snapshot shows canvas), tiles show figures, wiggle "Expected annual return" and watch the ending-balance tile change after the debounce, toggle today's dollars, verify the Save bar appears when dirty and saving clears it. Screenshot `plan5-account-view.png`. Debug console/network on any failure.

- [ ] **Step 3: Commit**

```bash
git add frontend
git commit -m "feat: account projection view with live chart and explicit save"
```

---

### Task 5: Goal-seek view

**Files:**
- Create: `frontend/src/views/GoalSeekView.vue`
- Modify: `frontend/src/router/index.ts` (route `/accounts/:id(\\d+)/goal-seek`, name `goal-seek`)

**Interfaces:**
- Consumes: `AccountForm` (`lockContributionAmount`), `useDebouncedPost`, `buildProjectionOption`, `POST /api/goal-seek`.
- Produces: mirrored view: same form (contribution amount locked), goal panel (drawdown vs target_value; target amount/date/today's-dollars checkbox), hero = required starting monthly + yearly contribution or a clear "not attainable" state, chart of the solved projection.

- [ ] **Step 1: Implement the view**

Create `frontend/src/views/GoalSeekView.vue`:

```vue
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { usePortfoliosStore } from '@/stores/portfolios'
import type { AccountInput, GoalSeekRequest, GoalSeekResponse } from '@/api/types'
import { toAccountInput } from '@/lib/accountPayload'
import { useDebouncedPost } from '@/lib/useDebouncedPost'
import { buildProjectionOption } from '@/lib/projectionChart'
import { money } from '@/lib/format'
import AccountForm from '@/components/AccountForm.vue'
import LedgerChart from '@/components/LedgerChart.vue'

const route = useRoute()
const auth = useAuthStore()
const store = usePortfoliosStore()
const found = computed(() => store.accountById(Number(route.params.id)))

const draft = ref<AccountInput | null>(null)
const real = ref(false)
const loadError = ref('')

const goalKind = ref<'drawdown' | 'target_value'>('drawdown')
const targetAmount = ref(500000)
const targetDate = ref('')
const targetTodaysDollars = ref(true)

onMounted(() => {
  if (!store.loaded) store.load().catch(() => (loadError.value = 'Could not load your portfolios.'))
})

function savedShape(): AccountInput | null {
  if (!found.value) return null
  const { id: _id, portfolioId: _pid, ...input } = found.value.account
  return toAccountInput(input as AccountInput, (input as AccountInput).drawdown.amount !== null)
}

const payload = computed<GoalSeekRequest | null>(() => {
  if (!found.value) return null
  const account = draft.value ?? savedShape()
  if (!account) return null
  if (goalKind.value === 'drawdown' && (account.drawdown.amount === null || account.drawdown.startsOn === null)) return null
  if (goalKind.value === 'target_value' && (!targetAmount.value || !targetDate.value)) return null
  return {
    account,
    taxes: {
      ordinaryIncomeTaxRate: found.value.portfolio.ordinaryIncomeTaxRate,
      capitalGainsTaxRate: found.value.portfolio.capitalGainsTaxRate,
    },
    birthDate: auth.user?.birthDate ?? null,
    deathAge: auth.user?.deathAge ?? null,
    startsOn: null,
    goal:
      goalKind.value === 'drawdown'
        ? { kind: 'drawdown' }
        : { kind: 'target_value', amount: targetAmount.value, atDate: targetDate.value, amountInTodaysDollars: targetTodaysDollars.value },
  }
})

const { data, pending, error } = useDebouncedPost<GoalSeekRequest, GoalSeekResponse>('/api/goal-seek', payload)

const chartOption = computed(() => {
  if (!data.value?.attainable) return null
  const account = draft.value ?? savedShape()
  return buildProjectionOption({
    months: data.value.projection.months,
    real: real.value,
    depletionDate: data.value.projection.summary.depletionDate,
    drawdownStart: account?.drawdown.startsOn?.slice(0, 7) ?? null,
    drawdownEnd: account?.drawdown.endsOn?.slice(0, 7) ?? null,
    birthDate: auth.user?.birthDate ?? null,
  })
})
</script>

<template>
  <section v-if="found">
    <p class="small"><RouterLink :to="`/accounts/${found.account.id}`">← {{ found.account.name }}</RouterLink></p>
    <h1>Goal seek</h1>
    <p class="muted small">
      Work backwards: set the goal, and Nestegg solves the starting monthly contribution
      (escalation still applies on top).
    </p>

    <div class="layout">
      <div class="panel">
        <div class="card goal-card">
          <h2>Goal</h2>
          <div class="field">
            <label for="gkind">Goal type</label>
            <select id="gkind" v-model="goalKind">
              <option value="drawdown">Sustain the account's drawdown</option>
              <option value="target_value">Reach a total value at a date</option>
            </select>
          </div>
          <template v-if="goalKind === 'target_value'">
            <div class="field">
              <label for="gamount">Target amount ($)</label>
              <input id="gamount" v-model.number="targetAmount" type="number" min="1" step="1000" />
            </div>
            <div class="field">
              <label for="gdate">At date</label>
              <input id="gdate" v-model="targetDate" type="date" />
            </div>
            <div class="field">
              <label class="toggle"><input v-model="targetTodaysDollars" type="checkbox" /> Amount is in today's dollars</label>
            </div>
          </template>
          <p v-else class="muted small">
            Uses the drawdown configured below — solved so it lasts until its end date, or your death age.
          </p>
        </div>

        <div class="card">
          <AccountForm :initial="found.account" lock-contribution-amount @change="draft = $event" @save="() => {}" />
        </div>
      </div>

      <div class="results">
        <p v-if="error" class="form-error">{{ error }}</p>
        <div v-if="data && !data.attainable" class="card unattainable">
          <h2>Not attainable</h2>
          <p class="small">No monthly contribution up to $10M/month reaches this goal — extend the timeline or shrink the target.</p>
        </div>
        <template v-if="data?.attainable">
          <div class="tiles">
            <div class="tile card hero">
              <span class="tile-label">Required starting monthly contribution</span>
              <span class="tile-value figure">{{ money(data.requiredMonthlyContribution) }}<span class="per">/mo</span></span>
            </div>
            <div class="tile card">
              <span class="tile-label">Per year</span>
              <span class="tile-value figure">{{ money(data.requiredYearlyContribution) }}</span>
            </div>
          </div>
          <div class="chart-card card">
            <div class="chart-head">
              <h2>Solved projection <span v-if="pending" class="muted small">computing…</span></h2>
              <label class="toggle small"><input v-model="real" type="checkbox" /> Today's dollars</label>
            </div>
            <LedgerChart v-if="chartOption" :option="chartOption" />
          </div>
        </template>
      </div>
    </div>
  </section>
  <p v-else-if="loadError" class="form-error">{{ loadError }}</p>
  <p v-else-if="store.loaded" class="muted">Account not found.</p>
</template>

<style scoped>
.layout { display: grid; grid-template-columns: minmax(20rem, 26rem) 1fr; gap: 1.25rem; align-items: start; }
@media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
.panel { display: grid; gap: 1rem; }
.goal-card { border-left: 3px solid var(--copper); }
.tiles { display: grid; grid-template-columns: 2fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem; }
.tile { display: grid; gap: 0.15rem; padding: 0.8rem 1rem; }
.tile-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-soft); }
.tile-value { font-size: 1.4rem; font-weight: 600; }
.hero .tile-value { color: var(--green-deep); font-size: 1.8rem; }
.per { font-size: 0.9rem; color: var(--ink-faint); }
.unattainable { border-left: 3px solid var(--danger); }
.chart-head { display: flex; justify-content: space-between; align-items: baseline; }
.toggle { display: inline-flex; gap: 0.4rem; align-items: center; }
</style>
```

Add the route in `frontend/src/router/index.ts` after the `account` route:

```ts
    { path: '/accounts/:id(\\d+)/goal-seek', name: 'goal-seek', component: () => import('../views/GoalSeekView.vue') },
```

- [ ] **Step 2: Verify — unit/type/build/lint, then live check**

Playwright: from the account view click "Goal seek →"; verify the drawdown goal solves (hero shows a figure near $1,900/mo for the fixture 401k — exact value depends on fixture assumptions, just assert a dollar figure renders and the chart appears); switch to target-value goal (500000 at 2041-07-01) and verify it re-solves; screenshot `plan5-goal-seek.png`.

- [ ] **Step 3: Commit**

```bash
git add frontend
git commit -m "feat: goal-seek view with solved-contribution hero and chart"
```

---

### Task 6: Portfolio overview chart, final smoke, push

**Files:**
- Modify: `frontend/src/views/PortfolioView.vue` (chart section above Accounts)

**Interfaces:**
- Consumes: `POST /api/projection/portfolio` (Task 1), `buildPortfolioOption`, `useDebouncedPost`, `toAccountInput`.
- Produces: the portfolio view leads with the combined chart: one series per account + bold Total line over the longest horizon, stacked-area toggle, today's-dollars toggle. Chart hides when the portfolio has no accounts.

- [ ] **Step 1: Add the chart to PortfolioView**

In `frontend/src/views/PortfolioView.vue` script, add imports:

```ts
import { useAuthStore } from '@/stores/auth'
import type { PortfolioProjectionRequest, PortfolioProjectionResponse } from '@/api/types'
import { toAccountInput } from '@/lib/accountPayload'
import { useDebouncedPost } from '@/lib/useDebouncedPost'
import { buildPortfolioOption } from '@/lib/projectionChart'
import LedgerChart from '@/components/LedgerChart.vue'
```

and after the existing state declarations:

```ts
const auth = useAuthStore()
const real = ref(false)
const stacked = ref(false)

const overviewPayload = computed<PortfolioProjectionRequest | null>(() => {
  if (!portfolio.value || portfolio.value.accounts.length === 0) return null
  return {
    accounts: portfolio.value.accounts.map((a) => {
      const { id: _id, portfolioId: _pid, ...input } = a
      return toAccountInput(input, input.drawdown.amount !== null)
    }),
    taxes: {
      ordinaryIncomeTaxRate: portfolio.value.ordinaryIncomeTaxRate,
      capitalGainsTaxRate: portfolio.value.capitalGainsTaxRate,
    },
    birthDate: auth.user?.birthDate ?? null,
    deathAge: auth.user?.deathAge ?? null,
    startsOn: null,
  }
})

const overview = useDebouncedPost<PortfolioProjectionRequest, PortfolioProjectionResponse>(
  '/api/projection/portfolio',
  overviewPayload,
)

const overviewOption = computed(() => {
  if (!overview.data.value) return null
  return buildPortfolioOption({
    accounts: overview.data.value.accounts,
    total: overview.data.value.total.months,
    real: real.value,
    stacked: stacked.value,
    birthDate: auth.user?.birthDate ?? null,
  })
})
```

In the template, insert between the settings form and the Accounts heading:

```vue
    <div v-if="portfolio.accounts.length" class="card chart-card">
      <div class="chart-head">
        <h2>Overview <span v-if="overview.pending.value" class="muted small">computing…</span></h2>
        <div class="chart-toggles">
          <label class="toggle small"><input v-model="stacked" type="checkbox" /> Stacked</label>
          <label class="toggle small"><input v-model="real" type="checkbox" /> Today's dollars</label>
        </div>
      </div>
      <p v-if="overview.error.value" class="form-error">{{ overview.error.value }}</p>
      <LedgerChart v-if="overviewOption" :option="overviewOption" />
    </div>
```

with scoped styles:

```css
.chart-card { margin-top: 1rem; }
.chart-head { display: flex; justify-content: space-between; align-items: baseline; }
.chart-toggles { display: flex; gap: 1rem; }
.toggle { display: inline-flex; gap: 0.4rem; align-items: center; }
```

- [ ] **Step 2: Full verification**

```bash
cd frontend && npm run test:unit -- --run && npm run type-check && npm run build && npm run lint:check
make test   # backend suite still green (Task 1 touched backend)
```

Playwright full smoke (127.0.0.1:5173, demo user): portfolio overview shows two account series + Total with legend; toggle stacked; toggle today's dollars; open account view (chart + tiles + live slider update + save); goal-seek view (both goal kinds). Screenshots: `plan5-portfolio.png`, re-shoot `plan5-account-view.png` and `plan5-goal-seek.png` if earlier ones predate fixes. Check the browser console for errors — a clean console is part of done.

- [ ] **Step 3: Commit and push**

```bash
git add frontend
git commit -m "feat: portfolio overview chart with stacked and today's-dollars toggles"
git push origin main
```
