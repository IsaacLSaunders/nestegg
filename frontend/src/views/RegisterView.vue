<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const email = ref('')
const password = ref('')
const birthDate = ref('')
const deathAge = ref(90)
const error = ref('')
const violations = ref<Record<string, string>>({})
const busy = ref(false)

async function submit() {
  error.value = ''
  violations.value = {}
  busy.value = true
  try {
    await auth.register({
      email: email.value,
      password: password.value,
      birthDate: birthDate.value,
      deathAge: deathAge.value,
    })
    router.push('/')
  } catch (e) {
    if (e instanceof ApiError) {
      violations.value = e.violations
      error.value = Object.keys(e.violations).length ? '' : e.message
    } else {
      error.value = 'Registration failed — try again.'
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="auth-card card">
    <h1>Create account</h1>
    <p class="small muted">Birth date and death age bound every projection you'll run.</p>
    <p v-if="error" class="form-error">{{ error }}</p>
    <form @submit.prevent="submit">
      <div class="field">
        <label for="email">Email</label>
        <input id="email" v-model="email" type="email" required autocomplete="email" />
        <span v-if="violations.email" class="error">{{ violations.email }}</span>
      </div>
      <div class="field">
        <label for="password">Password (10+ characters)</label>
        <input id="password" v-model="password" type="password" required minlength="10" autocomplete="new-password" />
        <span v-if="violations.password" class="error">{{ violations.password }}</span>
      </div>
      <div class="field">
        <label for="birthDate">Birth date</label>
        <input id="birthDate" v-model="birthDate" type="date" required />
        <span v-if="violations.birthDate" class="error">{{ violations.birthDate }}</span>
      </div>
      <div class="field">
        <label for="deathAge">Assumed death age</label>
        <input id="deathAge" v-model.number="deathAge" type="number" min="1" max="120" required />
        <span v-if="violations.deathAge" class="error">{{ violations.deathAge }}</span>
      </div>
      <button class="btn btn-primary" type="submit" :disabled="busy">Create account</button>
    </form>
    <p class="small muted">
      Already registered? <RouterLink to="/login">Sign in</RouterLink>
    </p>
  </section>
</template>

<style scoped>
.auth-card { max-width: 26rem; margin: 3rem auto; }
</style>
