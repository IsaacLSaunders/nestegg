<script setup lang="ts">
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function logout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <header class="masthead">
    <div class="masthead-inner">
      <RouterLink to="/" class="brand">Nest<em>egg</em></RouterLink>
      <nav>
        <template v-if="auth.user">
          <RouterLink to="/">Portfolios</RouterLink>
          <span class="muted small">{{ auth.user.email }}</span>
          <button class="btn-link" @click="logout">Sign out</button>
        </template>
      </nav>
    </div>
  </header>
  <main class="page">
    <RouterView />
  </main>
</template>
