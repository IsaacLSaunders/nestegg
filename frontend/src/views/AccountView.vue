<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { usePortfoliosStore } from '@/stores/portfolios'

const route = useRoute()
const store = usePortfoliosStore()
const found = computed(() => store.accountById(Number(route.params.id)))

onMounted(() => {
  if (!store.loaded) store.load()
})
</script>

<template>
  <section v-if="found" class="card">
    <p class="small"><RouterLink :to="`/portfolios/${found.portfolio.id}`">← {{ found.portfolio.name }}</RouterLink></p>
    <h1>{{ found.account.name }}</h1>
    <p class="muted">The projection view for this account arrives in the next plan.</p>
  </section>
</template>
