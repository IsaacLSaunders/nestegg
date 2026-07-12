import { ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '@/api/client'
import type { RegisterInput, User } from '@/api/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const checked = ref(false)

  async function fetchMe(): Promise<void> {
    try {
      user.value = await api<User>('GET', '/api/me', undefined, { silentUnauthorized: true })
    } catch {
      user.value = null
    } finally {
      checked.value = true
    }
  }

  async function login(email: string, password: string): Promise<void> {
    user.value = await api<User>('POST', '/api/auth/login', { email, password })
    checked.value = true
  }

  async function register(input: RegisterInput): Promise<void> {
    await api<User>('POST', '/api/auth/register', input)
    await login(input.email, input.password)
  }

  async function logout(): Promise<void> {
    await api('POST', '/api/auth/logout')
    user.value = null
  }

  return { user, checked, fetchMe, login, register, logout }
})
