<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { usePortfoliosStore } from '@/stores/portfolios'

const route = useRoute()
const store = usePortfoliosStore()
const found = computed(() => store.accountById(Number(route.params.id)))
const error = ref('')

onMounted(() => {
  if (!store.loaded) store.load().catch(() => { error.value = 'Could not load account.' })
})
</script>

<template>
  <p v-if="error" class="form-error">{{ error }}</p>
  <section v-if="found" class="card">
    <p class="small"><RouterLink :to="`/portfolios/${found.portfolio.id}`">← {{ found.portfolio.name }}</RouterLink></p>
    <h1>{{ found.account.name }}</h1>
    <p class="muted">The projection view for this account arrives in the next plan.</p>
  </section>
</template>
