<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import type { Account, AccountInput, AccountType } from '@/api/types'
import { toAccountInput } from '@/lib/accountPayload'
import PercentInput from './PercentInput.vue'

const props = defineProps<{
  initial?: Account
  lockContributionAmount?: boolean
  hideActions?: boolean
}>()
const emit = defineEmits<{ save: [input: AccountInput]; cancel: []; change: [input: AccountInput] }>()

const TYPE_LABELS: Record<AccountType, string> = {
  traditional_401k: 'Traditional 401k',
  roth_401k: 'Roth 401k',
  traditional_ira: 'Traditional IRA',
  roth_ira: 'Roth IRA',
  brokerage: 'Taxable brokerage',
  plan_529: '529 plan',
  cash: 'Cash savings',
}

const form = reactive<AccountInput>({
  name: props.initial?.name ?? '',
  type: props.initial?.type ?? 'traditional_401k',
  startingBalance: props.initial?.startingBalance ?? 0,
  startingBasis: props.initial?.startingBasis ?? null,
  annualReturnRate: props.initial?.annualReturnRate ?? 0.07,
  inflationRate: props.initial?.inflationRate ?? 0.03,
  horizonYears: props.initial?.horizonYears ?? 40,
  contribution: {
    monthlyAmount: props.initial?.contribution.monthlyAmount ?? 0,
    escalationRate: props.initial?.contribution.escalationRate ?? 0,
    startsOn: props.initial?.contribution.startsOn ?? null,
    endsOn: props.initial?.contribution.endsOn ?? null,
  },
  drawdown: {
    amount: props.initial?.drawdown.amount ?? null,
    frequency: props.initial?.drawdown.frequency ?? 'monthly',
    entryMode: props.initial?.drawdown.entryMode ?? 'gross',
    startsOn: props.initial?.drawdown.startsOn ?? null,
    endsOn: props.initial?.drawdown.endsOn ?? null,
    inflationIndexed: props.initial?.drawdown.inflationIndexed ?? true,
  },
})

const hasDrawdown = ref(form.drawdown.amount !== null)

watch(
  [form, hasDrawdown],
  () => emit('change', toAccountInput(form, hasDrawdown.value)),
  { deep: true, immediate: true },
)

function submit() {
  emit('save', toAccountInput(form, hasDrawdown.value))
}
</script>

<template>
  <form class="account-form" @submit.prevent="submit">
    <div class="cols">
      <fieldset>
        <legend>Account</legend>
        <div class="field">
          <label for="aname">Name</label>
          <input id="aname" v-model="form.name" required maxlength="120" />
        </div>
        <div class="field">
          <label for="atype">Type</label>
          <select id="atype" v-model="form.type">
            <option v-for="(label, value) in TYPE_LABELS" :key="value" :value="value">{{ label }}</option>
          </select>
        </div>
        <div class="field">
          <label for="abalance">Starting balance ($)</label>
          <input id="abalance" v-model.number="form.startingBalance" type="number" min="0" step="100" />
        </div>
        <div v-if="form.type === 'brokerage'" class="field">
          <label for="abasis">Starting cost basis ($)</label>
          <input id="abasis" v-model.number="form.startingBasis" type="number" min="0" step="100" />
        </div>
        <div class="field">
          <label for="aroi">Expected annual return</label>
          <PercentInput id="aroi" v-model="form.annualReturnRate" />
        </div>
        <div class="field">
          <label for="ainflation">Inflation</label>
          <PercentInput id="ainflation" v-model="form.inflationRate" :min="0" />
        </div>
        <div class="field">
          <label for="ahorizon">Horizon (years)</label>
          <input id="ahorizon" v-model.number="form.horizonYears" type="number" min="1" max="100" />
        </div>
      </fieldset>

      <fieldset>
        <legend>Contributions</legend>
        <div class="field">
          <label for="cmonthly">Monthly amount ($)</label>
          <input
            id="cmonthly"
            v-model.number="form.contribution.monthlyAmount"
            type="number"
            min="0"
            step="50"
            :disabled="lockContributionAmount"
          />
          <span v-if="lockContributionAmount" class="muted small">solved by goal seek</span>
        </div>
        <div class="field">
          <label for="cescalation">Annual escalation</label>
          <PercentInput id="cescalation" v-model="form.contribution.escalationRate" :min="0" />
        </div>
        <div class="field">
          <label for="cstart">Starts (blank = now)</label>
          <input id="cstart" v-model="form.contribution.startsOn" type="date" />
        </div>
        <div class="field">
          <label for="cend">Ends (blank = horizon)</label>
          <input id="cend" v-model="form.contribution.endsOn" type="date" />
        </div>
      </fieldset>

      <fieldset>
        <legend>
          <label class="toggle"><input v-model="hasDrawdown" type="checkbox" /> Drawdown</label>
        </legend>
        <template v-if="hasDrawdown">
          <div class="field">
            <label for="damount">Amount ($, in today's dollars)</label>
            <input id="damount" v-model.number="form.drawdown.amount" type="number" min="0" step="100" required />
          </div>
          <div class="field">
            <label for="dfreq">Frequency</label>
            <select id="dfreq" v-model="form.drawdown.frequency">
              <option value="monthly">Monthly</option>
              <option value="weekly">Weekly</option>
            </select>
          </div>
          <div class="field">
            <label for="dmode">Amount is</label>
            <select id="dmode" v-model="form.drawdown.entryMode">
              <option value="gross">Pre-tax (gross withdrawal)</option>
              <option value="net">After-tax (spending target)</option>
            </select>
          </div>
          <div class="field">
            <label for="dstart">Starts</label>
            <input id="dstart" v-model="form.drawdown.startsOn" type="date" required />
          </div>
          <div class="field">
            <label for="dend">Ends (blank = depletion/death)</label>
            <input id="dend" v-model="form.drawdown.endsOn" type="date" />
          </div>
          <div class="field">
            <label class="toggle">
              <input v-model="form.drawdown.inflationIndexed" type="checkbox" />
              Grow with inflation
            </label>
          </div>
        </template>
        <p v-else class="muted small">No withdrawals from this account.</p>
      </fieldset>
    </div>
    <div v-if="!hideActions" class="row">
      <button class="btn btn-primary" type="submit">Save account</button>
      <button class="btn" type="button" @click="emit('cancel')">Cancel</button>
    </div>
  </form>
</template>

<style scoped>
.cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 1.25rem; }
fieldset { border: 1px solid var(--line); border-radius: var(--radius); padding: 0.9rem 1rem; margin: 0; }
legend { font-family: var(--font-display); font-size: 1rem; padding: 0 0.4rem; }
.toggle { display: inline-flex; gap: 0.4rem; align-items: center; text-transform: none; letter-spacing: 0; font-size: 0.9rem; }
.row { display: flex; gap: 0.5rem; margin-top: 1rem; }
</style>
