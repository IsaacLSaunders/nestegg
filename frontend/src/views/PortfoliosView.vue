<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { usePortfoliosStore } from '@/stores/portfolios'
import { ApiError } from '@/api/client'

const store = usePortfoliosStore()
const router = useRouter()
const creating = ref(false)
const newName = ref('')
const error = ref('')

onMounted(() => {
  if (!store.loaded) store.load()
})

async function create() {
  error.value = ''
  try {
    const created = await store.create({ name: newName.value, ordinaryIncomeTaxRate: 0.22, capitalGainsTaxRate: 0.15 })
    newName.value = ''
    creating.value = false
    router.push(`/portfolios/${created.id}`)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Could not create portfolio.'
  }
}

async function duplicate(id: number) {
  await store.duplicate(id)
}

async function remove(id: number, name: string) {
  if (confirm(`Delete portfolio “${name}” and all its accounts?`)) await store.remove(id)
}
</script>

<template>
  <section>
    <div class="head-row">
      <h1>Portfolios</h1>
      <button v-if="!creating" class="btn btn-primary" @click="creating = true">New portfolio</button>
    </div>
    <p class="muted small">Each portfolio is one possible path — duplicate one to fork a scenario.</p>

    <form v-if="creating" class="card create-form" @submit.prevent="create">
      <p v-if="error" class="form-error">{{ error }}</p>
      <div class="field">
        <label for="pname">Name</label>
        <input id="pname" v-model="newName" required maxlength="120" placeholder="e.g. Retire at 55" />
      </div>
      <div class="row">
        <button class="btn btn-primary" type="submit">Create</button>
        <button class="btn" type="button" @click="creating = false">Cancel</button>
      </div>
    </form>

    <div class="grid">
      <article v-for="p in store.portfolios" :key="p.id" class="card">
        <h2>
          <RouterLink :to="`/portfolios/${p.id}`">{{ p.name }}</RouterLink>
        </h2>
        <p class="small muted">
          <span class="figure">{{ p.accounts.length }}</span> account{{ p.accounts.length === 1 ? '' : 's' }}
          · income tax <span class="figure">{{ (p.ordinaryIncomeTaxRate * 100).toFixed(0) }}%</span>
          · cap gains <span class="figure">{{ (p.capitalGainsTaxRate * 100).toFixed(0) }}%</span>
        </p>
        <div class="row">
          <button class="btn" @click="duplicate(p.id)">Duplicate</button>
          <button class="btn btn-danger" @click="remove(p.id, p.name)">Delete</button>
        </div>
      </article>
    </div>

    <p v-if="store.loaded && store.portfolios.length === 0" class="muted">
      No portfolios yet — create your first path.
    </p>
  </section>
</template>

<style scoped>
.head-row { display: flex; justify-content: space-between; align-items: baseline; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(19rem, 1fr)); gap: 1rem; margin-top: 1rem; }
.row { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
.create-form { margin: 1rem 0; max-width: 30rem; }
</style>
