<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const email = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true
  try {
    await auth.login(email.value, password.value)
    router.push('/')
  } catch (e) {
    error.value = e instanceof ApiError && e.status === 401 ? 'Wrong email or password.' : 'Login failed — try again.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="auth-card card">
    <h1>Sign in</h1>
    <p v-if="error" class="form-error">{{ error }}</p>
    <form @submit.prevent="submit">
      <div class="field">
        <label for="email">Email</label>
        <input id="email" v-model="email" type="email" required autocomplete="email" />
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" v-model="password" type="password" required autocomplete="current-password" />
      </div>
      <button class="btn btn-primary" type="submit" :disabled="busy">Sign in</button>
    </form>
    <p class="small muted">
      New here? <RouterLink to="/register">Create an account</RouterLink>
    </p>
  </section>
</template>

<style scoped>
.auth-card { max-width: 26rem; margin: 3rem auto; }
</style>
