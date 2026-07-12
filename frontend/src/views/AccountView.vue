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
