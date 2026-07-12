<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { usePortfoliosStore } from '@/stores/portfolios'
import { ApiError } from '@/api/client'
import type { Account, AccountInput, PortfolioProjectionRequest, PortfolioProjectionResponse } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { toAccountInput } from '@/lib/accountPayload'
import { useDebouncedPost } from '@/lib/useDebouncedPost'
import { buildPortfolioOption } from '@/lib/projectionChart'
import AccountForm from '@/components/AccountForm.vue'
import PercentInput from '@/components/PercentInput.vue'
import LedgerChart from '@/components/LedgerChart.vue'

const route = useRoute()
const store = usePortfoliosStore()
const portfolioId = computed(() => Number(route.params.id))
const portfolio = computed(() => store.byId(portfolioId.value))

const editingAccount = ref<Account | null>(null)
const addingAccount = ref(false)
const error = ref('')
const settingsOpen = ref(false)
const settingsName = ref('')
const settingsIncomeRate = ref(0.22)
const settingsGainsRate = ref(0.15)

const auth = useAuthStore()
const real = ref(false)
const stacked = ref(false)

function accountShape(a: Account): AccountInput {
  const input: AccountInput = {
    name: a.name,
    type: a.type,
    startingBalance: a.startingBalance,
    startingBasis: a.startingBasis,
    annualReturnRate: a.annualReturnRate,
    inflationRate: a.inflationRate,
    horizonYears: a.horizonYears,
    contribution: a.contribution,
    drawdown: a.drawdown,
  }
  return toAccountInput(input, input.drawdown.amount !== null)
}

const overviewPayload = computed<PortfolioProjectionRequest | null>(() => {
  if (!portfolio.value || portfolio.value.accounts.length === 0) return null
  return {
    accounts: portfolio.value.accounts.map(accountShape),
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

onMounted(async () => {
  if (!store.loaded) {
    try {
      await store.load()
    } catch {
      error.value = 'Could not load portfolio.'
    }
  }
  syncSettings()
})

function syncSettings() {
  if (!portfolio.value) return
  settingsName.value = portfolio.value.name
  settingsIncomeRate.value = portfolio.value.ordinaryIncomeTaxRate
  settingsGainsRate.value = portfolio.value.capitalGainsTaxRate
}

async function saveSettings() {
  error.value = ''
  try {
    await store.update(portfolioId.value, {
      name: settingsName.value,
      ordinaryIncomeTaxRate: settingsIncomeRate.value,
      capitalGainsTaxRate: settingsGainsRate.value,
    })
    settingsOpen.value = false
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Save failed.'
  }
}

async function saveAccount(input: AccountInput) {
  error.value = ''
  try {
    if (editingAccount.value) {
      await store.updateAccount(editingAccount.value.id, input)
      editingAccount.value = null
    } else {
      await store.createAccount(portfolioId.value, input)
      addingAccount.value = false
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Save failed.'
  }
}

async function removeAccount(a: Account) {
  if (!confirm(`Delete account “${a.name}”?`)) return
  error.value = ''
  try {
    await store.removeAccount(a.id)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Could not delete account.'
  }
}

const money = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 })
</script>

<template>
  <section v-if="portfolio">
    <p class="small"><RouterLink to="/portfolios">← Portfolios</RouterLink></p>
    <div class="head-row">
      <h1>{{ portfolio.name }}</h1>
      <button class="btn" @click="settingsOpen = !settingsOpen; syncSettings()">Settings</button>
    </div>
    <p class="small muted">
      Income tax <span class="figure">{{ (portfolio.ordinaryIncomeTaxRate * 100).toFixed(0) }}%</span>
      · capital gains <span class="figure">{{ (portfolio.capitalGainsTaxRate * 100).toFixed(0) }}%</span>
    </p>
    <p v-if="error" class="form-error">{{ error }}</p>

    <form v-if="settingsOpen" class="card settings" @submit.prevent="saveSettings">
      <div class="field">
        <label for="sname">Name</label>
        <input id="sname" v-model="settingsName" required maxlength="120" />
      </div>
      <div class="field">
        <label for="sincome">Effective income tax rate</label>
        <PercentInput id="sincome" v-model="settingsIncomeRate" :min="0" />
      </div>
      <div class="field">
        <label for="sgains">Effective capital gains rate</label>
        <PercentInput id="sgains" v-model="settingsGainsRate" :min="0" />
      </div>
      <div class="row">
        <button class="btn btn-primary" type="submit">Save</button>
        <button class="btn" type="button" @click="settingsOpen = false">Cancel</button>
      </div>
    </form>

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

    <div class="head-row">
      <h2>Accounts</h2>
      <button v-if="!addingAccount && !editingAccount" class="btn btn-primary" @click="addingAccount = true">
        Add account
      </button>
    </div>

    <div v-if="addingAccount || editingAccount" class="card">
      <h3>{{ editingAccount ? `Edit ${editingAccount.name}` : 'New account' }}</h3>
      <AccountForm
        :key="editingAccount?.id ?? 'new'"
        :initial="editingAccount ?? undefined"
        @save="saveAccount"
        @cancel="addingAccount = false; editingAccount = null"
      />
    </div>

    <table v-if="portfolio.accounts.length" class="accounts">
      <thead>
        <tr>
          <th>Name</th><th>Type</th><th class="num">Starting</th><th class="num">Return</th>
          <th class="num">Monthly in</th><th class="num">Drawdown</th><th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="a in portfolio.accounts" :key="a.id">
          <td><RouterLink :to="`/accounts/${a.id}`">{{ a.name }}</RouterLink></td>
          <td class="small muted">{{ a.type.replace(/_/g, ' ') }}</td>
          <td class="num figure">{{ money.format(a.startingBalance) }}</td>
          <td class="num figure">{{ (a.annualReturnRate * 100).toFixed(1) }}%</td>
          <td class="num figure">{{ money.format(a.contribution.monthlyAmount) }}</td>
          <td class="num figure">
            {{ a.drawdown.amount === null ? '—' : `${money.format(a.drawdown.amount)}/${a.drawdown.frequency === 'weekly' ? 'wk' : 'mo'}` }}
          </td>
          <td class="row-actions">
            <button class="btn-link" @click="editingAccount = a; addingAccount = false">Edit</button>
            <button class="btn-link danger" @click="removeAccount(a)">Delete</button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-else class="muted">No accounts yet — add a 401k, IRA, brokerage, 529, or cash savings.</p>
  </section>
  <p v-else-if="store.loaded" class="muted">Portfolio not found. <RouterLink to="/portfolios">Back to portfolios</RouterLink></p>
</template>

<style scoped>
.head-row { display: flex; justify-content: space-between; align-items: baseline; margin-top: 1rem; }
.settings { max-width: 26rem; margin-bottom: 1rem; }
.chart-card { margin-top: 1rem; }
.chart-head { display: flex; justify-content: space-between; align-items: baseline; }
.chart-toggles { display: flex; gap: 1rem; }
.toggle { display: inline-flex; gap: 0.4rem; align-items: center; }
.row { display: flex; gap: 0.5rem; }
.accounts { width: 100%; border-collapse: collapse; background: var(--paper-raised); border: 1px solid var(--line); border-radius: var(--radius); }
.accounts th { text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-soft); padding: 0.55rem 0.8rem; border-bottom: 2px solid var(--line); }
.accounts td { padding: 0.55rem 0.8rem; border-bottom: 1px solid var(--line); }
.accounts .num { text-align: right; }
.row-actions { display: flex; gap: 0.75rem; justify-content: flex-end; }
.danger { color: var(--danger); }
</style>
