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
  const account = found.value.account
  const input: AccountInput = {
    name: account.name,
    type: account.type,
    startingBalance: account.startingBalance,
    startingBasis: account.startingBasis,
    annualReturnRate: account.annualReturnRate,
    inflationRate: account.inflationRate,
    horizonYears: account.horizonYears,
    contribution: account.contribution,
    drawdown: account.drawdown,
  }
  return toAccountInput(input, input.drawdown.amount !== null)
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
          <AccountForm :initial="found.account" lock-contribution-amount hide-actions @change="draft = $event" />
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
