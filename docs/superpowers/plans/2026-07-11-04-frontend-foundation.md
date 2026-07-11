# Nestegg Plan 4/5: Frontend Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A styled, authenticated Vue SPA where a user can register, log in, and manage portfolios and accounts end-to-end in the browser — the foundation Plan 5's charts and analytical views build on.

**Architecture:** Vue 3 + TS + Pinia + Vue Router (scaffolded in Plan 1). A single `api()` fetch wrapper is the only network path (401s route to login; 422 violation arrays become field-keyed messages). Pinia stores (`auth`, `portfolios`) are the only API consumers; views stay declarative. Zero financial math client-side (ADR-0001) — the frontend renders what the API returns. Design system: "editorial ledger" — warm paper surface, ink-green text, Fraunces display serif, Public Sans body, IBM Plex Mono for all figures.

**Tech Stack:** Vue 3.5, TypeScript, Pinia, Vue Router, Vitest (+ @vue/test-utils), @fontsource packages. No new chart deps in this plan.

## Global Constraints

- All API calls go through `src/api/client.ts`'s `api()` — no raw `fetch` anywhere else. `credentials: 'same-origin'`; the Vite proxy makes the API same-origin (no CORS).
- **No financial math in the frontend** (ADR-0001). Allowed presentation-only conversions: date→age labels (`year - birthYear`) and Intl number/currency formatting.
- API contracts (from Plans 2-3, verified): user `{id,email,birthDate,deathAge}`; portfolio `{id,name,ordinaryIncomeTaxRate,capitalGainsTaxRate,accounts:[...]}`; account per the AccountInput shape with nested `contribution`/`drawdown`; rates are decimal fractions (UI shows `%` by multiplying/dividing by 100 for display — presentation, not math on money); dates `YYYY-MM-DD`; errors: 401 `{error}`, 409 `{error}`, 422 RFC-7807 `{detail, violations:[{propertyPath,title}]}`.
- Registration does NOT create a session — register then login with the same credentials.
- Design tokens (exact values, defined once in `main.css`): paper `#f7f2e7`, raised `#fffcf4`, sunken `#efe8d8`, ink `#20281f`, ink-soft `#5a6355`, ink-faint `#98a08f`, line `#ddd4bf`, green `#1b7a4e`, green-deep `#14603d`, copper `#b0521a`, danger `#c03434`. Series palette (validated for CVD/contrast on the paper surface — do not alter): `#1b7a4e, #b0521a, #3f5bd6, #8c3f9e, #0b87b4, #c03434`. Light theme only in v1.
- Fonts: `@fontsource-variable/fraunces` (display), `@fontsource-variable/public-sans` (body), `@fontsource/ibm-plex-mono` 400+600 (figures). Every numeric figure uses the mono with `font-variant-numeric: tabular-nums`.
- Frontend commands run in `frontend/` on the host (Node 26) — `npm run test:unit -- --run`, `npm run build`, `npm run type-check`. The docker `frontend` service picks up changes via the bind mount (HMR).
- Commit trailer as in `git log`.

---

### Task 1: Design system, API client, app shell

**Files:**
- Modify: `frontend/package.json` (via npm install)
- Replace: `frontend/src/assets/main.css` (delete `frontend/src/assets/base.css`, `frontend/src/assets/logo.svg`)
- Modify: `frontend/src/main.ts`
- Replace: `frontend/src/App.vue`
- Replace: `frontend/src/views/HomeView.vue`; Delete: `frontend/src/views/AboutView.vue`, `frontend/src/components/*` (create-vue demo components + `__tests__`), `frontend/src/stores/counter.ts`
- Modify: `frontend/src/router/index.ts`
- Create: `frontend/src/api/client.ts`
- Test: `frontend/src/api/__tests__/client.spec.ts`

**Interfaces:**
- Consumes: nothing new.
- Produces: `api<T>(method, path, body?): Promise<T>` + `ApiError { status: number; violations: Record<string,string> }` + `setUnauthorizedHandler(fn)`; design tokens as CSS custom properties on `:root`; shared element classes `.card`, `.btn`, `.btn-primary`, `.btn-danger`, `.field`, `.figure`, `.page`; `App.vue` renders `<header class="masthead">` + `<RouterView/>` (auth widgets added in Task 2 via `<template #actions>`-free simple markup — Task 2 edits App.vue directly).

- [ ] **Step 1: Install fonts**

```bash
cd /Users/isaacsaunders/workspace/nestegg/frontend
npm install @fontsource-variable/fraunces @fontsource-variable/public-sans @fontsource/ibm-plex-mono
```

- [ ] **Step 2: Write the failing client tests**

Create `frontend/src/api/__tests__/client.spec.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { api, ApiError, setUnauthorizedHandler } from '../client'

function mockFetch(status: number, body: unknown) {
  const res = {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  }
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(res))
}

describe('api client', () => {
  beforeEach(() => {
    vi.unstubAllGlobals()
    setUnauthorizedHandler(() => {})
  })

  it('returns parsed JSON on success', async () => {
    mockFetch(200, { id: 1, email: 'a@b.c' })
    await expect(api('GET', '/api/me')).resolves.toEqual({ id: 1, email: 'a@b.c' })
  })

  it('sends JSON body with content-type header', async () => {
    mockFetch(201, { id: 2 })
    await api('POST', '/api/portfolios', { name: 'X' })
    const call = (fetch as ReturnType<typeof vi.fn>).mock.calls[0]
    expect(call[0]).toBe('/api/portfolios')
    expect(call[1].method).toBe('POST')
    expect(call[1].headers['Content-Type']).toBe('application/json')
    expect(JSON.parse(call[1].body)).toEqual({ name: 'X' })
    expect(call[1].credentials).toBe('same-origin')
  })

  it('returns undefined on 204', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, status: 204, json: () => Promise.reject(new Error('no body')) }))
    await expect(api('DELETE', '/api/portfolios/1')).resolves.toBeUndefined()
  })

  it('maps 422 violations to field messages', async () => {
    mockFetch(422, {
      detail: 'Validation failed',
      violations: [
        { propertyPath: 'name', title: 'This value should not be blank.' },
        { propertyPath: 'name', title: 'second message ignored' },
        { propertyPath: 'ordinaryIncomeTaxRate', title: 'Out of range.' },
      ],
    })
    const err = await api('POST', '/api/portfolios', {}).catch((e: unknown) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect((err as ApiError).status).toBe(422)
    expect((err as ApiError).violations).toEqual({
      name: 'This value should not be blank.',
      ordinaryIncomeTaxRate: 'Out of range.',
    })
  })

  it('calls the unauthorized handler on 401', async () => {
    const handler = vi.fn()
    setUnauthorizedHandler(handler)
    mockFetch(401, { error: 'Authentication required.' })
    await expect(api('GET', '/api/me')).rejects.toBeInstanceOf(ApiError)
    expect(handler).toHaveBeenCalledOnce()
  })

  it('falls back to a generic message when the body is not JSON', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 500, json: () => Promise.reject(new Error('not json')) }))
    const err = await api('GET', '/api/health').catch((e: unknown) => e)
    expect((err as ApiError).message).toBe('Request failed (500)')
  })
})
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd frontend && npm run test:unit -- --run` — FAIL, `../client` missing. (The old `HelloWorld` example spec disappears with the demo components in Step 5; if the runner errors on it first, delete the demo files then re-run.)

- [ ] **Step 4: Implement the client**

Create `frontend/src/api/client.ts`:

```ts
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly violations: Record<string, string> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

type UnauthorizedHandler = () => void
let onUnauthorized: UnauthorizedHandler = () => {}

export function setUnauthorizedHandler(handler: UnauthorizedHandler): void {
  onUnauthorized = handler
}

interface ViolationItem {
  propertyPath: string
  title: string
}

export async function api<T>(method: string, path: string, body?: unknown): Promise<T> {
  const init: RequestInit & { headers: Record<string, string> } = {
    method,
    credentials: 'same-origin',
    headers: {},
  }
  if (body !== undefined) {
    init.headers['Content-Type'] = 'application/json'
    init.body = JSON.stringify(body)
  }

  const res = await fetch(path, init)
  if (res.status === 204) return undefined as T

  const data: Record<string, unknown> = await res.json().catch(() => ({}))
  if (!res.ok) {
    if (res.status === 401) onUnauthorized()
    const violations: Record<string, string> = {}
    if (Array.isArray(data.violations)) {
      for (const v of data.violations as ViolationItem[]) {
        violations[v.propertyPath] ??= v.title
      }
    }
    const message =
      (data.error as string | undefined) ??
      (data.detail as string | undefined) ??
      (data.title as string | undefined) ??
      `Request failed (${res.status})`
    throw new ApiError(res.status, message, violations)
  }
  return data as T
}
```

- [ ] **Step 5: Replace the design system and shell**

Delete `frontend/src/assets/base.css`, `frontend/src/assets/logo.svg`, `frontend/src/components/` (everything incl. `__tests__` and `icons`), `frontend/src/views/AboutView.vue`, `frontend/src/stores/counter.ts`.

Replace `frontend/src/assets/main.css`:

```css
:root {
  --paper: #f7f2e7;
  --paper-raised: #fffcf4;
  --paper-sunken: #efe8d8;
  --ink: #20281f;
  --ink-soft: #5a6355;
  --ink-faint: #98a08f;
  --line: #ddd4bf;
  --green: #1b7a4e;
  --green-deep: #14603d;
  --copper: #b0521a;
  --danger: #c03434;
  --series-1: #1b7a4e;
  --series-2: #b0521a;
  --series-3: #3f5bd6;
  --series-4: #8c3f9e;
  --series-5: #0b87b4;
  --series-6: #c03434;
  --font-display: 'Fraunces Variable', Georgia, serif;
  --font-body: 'Public Sans Variable', system-ui, sans-serif;
  --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
  --radius: 6px;
  --shadow: 0 1px 2px rgba(32, 40, 31, 0.06), 0 4px 16px rgba(32, 40, 31, 0.05);
}

*,
*::before,
*::after {
  box-sizing: border-box;
}

body {
  margin: 0;
  min-height: 100vh;
  background:
    repeating-linear-gradient(0deg, transparent 0 31px, rgba(32, 40, 31, 0.025) 31px 32px),
    var(--paper);
  color: var(--ink);
  font-family: var(--font-body);
  font-size: 15px;
  line-height: 1.55;
  -webkit-font-smoothing: antialiased;
}

h1, h2, h3 {
  font-family: var(--font-display);
  font-weight: 560;
  letter-spacing: -0.01em;
  color: var(--ink);
  margin: 0 0 0.4em;
}

h1 { font-size: 2rem; }
h2 { font-size: 1.35rem; }
h3 { font-size: 1.05rem; }

a {
  color: var(--green-deep);
  text-decoration-color: color-mix(in srgb, var(--green-deep) 40%, transparent);
  text-underline-offset: 3px;
}

.figure {
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
}

.page {
  max-width: 1060px;
  margin: 0 auto;
  padding: 2rem 1.5rem 4rem;
}

.masthead {
  border-bottom: 3px double var(--line);
  background: var(--paper-raised);
}

.masthead-inner {
  max-width: 1060px;
  margin: 0 auto;
  padding: 0.85rem 1.5rem;
  display: flex;
  align-items: baseline;
  gap: 1.5rem;
}

.brand {
  font-family: var(--font-display);
  font-size: 1.5rem;
  font-weight: 640;
  color: var(--ink);
  text-decoration: none;
}

.brand em {
  color: var(--green);
  font-style: normal;
}

.masthead nav {
  display: flex;
  gap: 1.1rem;
  margin-left: auto;
  align-items: baseline;
}

.masthead nav a {
  font-size: 0.9rem;
  text-decoration: none;
  color: var(--ink-soft);
}

.masthead nav a.router-link-active {
  color: var(--green-deep);
  border-bottom: 2px solid var(--green);
}

.card {
  background: var(--paper-raised);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  padding: 1.25rem 1.4rem;
}

.btn {
  font-family: var(--font-body);
  font-size: 0.88rem;
  font-weight: 600;
  padding: 0.45rem 0.95rem;
  border-radius: var(--radius);
  border: 1px solid var(--line);
  background: var(--paper-raised);
  color: var(--ink);
  cursor: pointer;
  transition: border-color 120ms ease, background 120ms ease, transform 80ms ease;
}

.btn:hover { border-color: var(--ink-faint); }
.btn:active { transform: translateY(1px); }

.btn-primary {
  background: var(--green);
  border-color: var(--green-deep);
  color: #fdfaf2;
}

.btn-primary:hover { background: var(--green-deep); }

.btn-danger {
  color: var(--danger);
  border-color: color-mix(in srgb, var(--danger) 45%, var(--line));
}

.btn-link {
  border: none;
  background: none;
  color: var(--green-deep);
  text-decoration: underline;
  text-underline-offset: 3px;
  cursor: pointer;
  font-size: 0.88rem;
  padding: 0;
}

.field { display: grid; gap: 0.25rem; margin-bottom: 0.9rem; }

.field label {
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--ink-soft);
}

.field input,
.field select {
  font: inherit;
  font-family: var(--font-mono);
  font-size: 0.9rem;
  padding: 0.45rem 0.6rem;
  border: 1px solid var(--line);
  border-radius: var(--radius);
  background: #fff;
  color: var(--ink);
}

.field input:focus,
.field select:focus {
  outline: 2px solid color-mix(in srgb, var(--green) 55%, transparent);
  outline-offset: 1px;
}

.field .error { color: var(--danger); font-size: 0.8rem; }

.form-error {
  color: var(--danger);
  background: color-mix(in srgb, var(--danger) 8%, var(--paper-raised));
  border: 1px solid color-mix(in srgb, var(--danger) 30%, var(--line));
  border-radius: var(--radius);
  padding: 0.5rem 0.75rem;
  font-size: 0.85rem;
  margin-bottom: 0.9rem;
}

.muted { color: var(--ink-faint); }
.small { font-size: 0.82rem; }
```

Replace `frontend/src/main.ts`:

```ts
import '@fontsource-variable/fraunces'
import '@fontsource-variable/public-sans'
import '@fontsource/ibm-plex-mono/400.css'
import '@fontsource/ibm-plex-mono/600.css'
import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
```

Replace `frontend/src/App.vue`:

```vue
<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router'
</script>

<template>
  <header class="masthead">
    <div class="masthead-inner">
      <RouterLink to="/" class="brand">Nest<em>egg</em></RouterLink>
      <nav></nav>
    </div>
  </header>
  <main class="page">
    <RouterView />
  </main>
</template>
```

Replace `frontend/src/views/HomeView.vue`:

```vue
<template>
  <section class="card">
    <h1>Nestegg</h1>
    <p class="muted">Portfolio planning workspace — views arrive in the next tasks.</p>
  </section>
</template>
```

Replace `frontend/src/router/index.ts`:

```ts
import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [{ path: '/', name: 'home', component: HomeView }],
})

export default router
```

- [ ] **Step 6: Verify**

```bash
cd frontend && npm run test:unit -- --run && npm run type-check && npm run build
```
Expected: 6 client tests pass, type-check clean, build succeeds.

- [ ] **Step 7: Commit**

```bash
cd /Users/isaacsaunders/workspace/nestegg
git add frontend
git commit -m "feat: ledger design system, API client, and app shell"
```

---

### Task 2: Auth store, login/register views, route guards

**Files:**
- Create: `frontend/src/api/types.ts` (User only for now)
- Create: `frontend/src/stores/auth.ts`
- Create: `frontend/src/views/LoginView.vue`, `frontend/src/views/RegisterView.vue`
- Modify: `frontend/src/router/index.ts` (routes + guard), `frontend/src/App.vue` (user widget), `frontend/src/main.ts` (unauthorized handler)
- Test: `frontend/src/stores/__tests__/auth.spec.ts`

**Interfaces:**
- Consumes: `api`, `ApiError`, `setUnauthorizedHandler` (Task 1); backend auth endpoints.
- Produces: `useAuthStore()` with `user: Ref<User|null>`, `checked: Ref<boolean>`, `fetchMe()`, `login(email, password)`, `register(input: RegisterInput)`, `logout()`; router meta `{ public?: true }`; guard redirects unauthenticated → `/login` and authenticated-on-public → `/`. Later tasks read `useAuthStore().user` for birthDate/deathAge.

- [ ] **Step 1: Write the failing store tests**

Create `frontend/src/stores/__tests__/auth.spec.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/api/client', () => ({
  api: vi.fn(),
  ApiError: class extends Error {},
  setUnauthorizedHandler: vi.fn(),
}))

import { api } from '@/api/client'
import { useAuthStore } from '../auth'

const mockedApi = vi.mocked(api)
const demoUser = { id: 1, email: 'a@b.c', birthDate: '1990-06-15', deathAge: 90 }

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mockedApi.mockReset()
  })

  it('fetchMe stores the user and marks checked', async () => {
    mockedApi.mockResolvedValueOnce(demoUser)
    const store = useAuthStore()
    await store.fetchMe()
    expect(store.user).toEqual(demoUser)
    expect(store.checked).toBe(true)
  })

  it('fetchMe swallows 401 and marks checked', async () => {
    mockedApi.mockRejectedValueOnce(new Error('401'))
    const store = useAuthStore()
    await store.fetchMe()
    expect(store.user).toBeNull()
    expect(store.checked).toBe(true)
  })

  it('login stores the returned user', async () => {
    mockedApi.mockResolvedValueOnce(demoUser)
    const store = useAuthStore()
    await store.login('a@b.c', 'pw')
    expect(mockedApi).toHaveBeenCalledWith('POST', '/api/auth/login', { email: 'a@b.c', password: 'pw' })
    expect(store.user).toEqual(demoUser)
  })

  it('register then logs in with the same credentials', async () => {
    mockedApi.mockResolvedValueOnce(demoUser) // register 201
    mockedApi.mockResolvedValueOnce(demoUser) // login
    const store = useAuthStore()
    await store.register({ email: 'a@b.c', password: 'pw', birthDate: '1990-06-15', deathAge: 90 })
    expect(mockedApi).toHaveBeenNthCalledWith(1, 'POST', '/api/auth/register', {
      email: 'a@b.c', password: 'pw', birthDate: '1990-06-15', deathAge: 90,
    })
    expect(mockedApi).toHaveBeenNthCalledWith(2, 'POST', '/api/auth/login', { email: 'a@b.c', password: 'pw' })
    expect(store.user).toEqual(demoUser)
  })

  it('logout clears the user', async () => {
    mockedApi.mockResolvedValueOnce(demoUser)
    const store = useAuthStore()
    await store.login('a@b.c', 'pw')
    mockedApi.mockResolvedValueOnce({ status: 'logged out' })
    await store.logout()
    expect(store.user).toBeNull()
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

`cd frontend && npm run test:unit -- --run` — FAIL, `../auth` missing.

- [ ] **Step 3: Implement types and store**

Create `frontend/src/api/types.ts`:

```ts
export interface User {
  id: number
  email: string
  birthDate: string
  deathAge: number
}

export interface RegisterInput {
  email: string
  password: string
  birthDate: string
  deathAge?: number
}
```

Create `frontend/src/stores/auth.ts`:

```ts
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '@/api/client'
import type { RegisterInput, User } from '@/api/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const checked = ref(false)

  async function fetchMe(): Promise<void> {
    try {
      user.value = await api<User>('GET', '/api/me')
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
```

- [ ] **Step 4: Views, routes, guard, shell widget**

Create `frontend/src/views/LoginView.vue`:

```vue
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
```

Create `frontend/src/views/RegisterView.vue`:

```vue
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
```

Replace `frontend/src/router/index.ts`:

```ts
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import HomeView from '../views/HomeView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', name: 'home', component: HomeView },
    { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { public: true } },
    { path: '/register', name: 'register', component: () => import('../views/RegisterView.vue'), meta: { public: true } },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.checked) await auth.fetchMe()
  if (!to.meta.public && !auth.user) return { name: 'login' }
  if (to.meta.public && auth.user) return { name: 'home' }
})

export default router
```

Modify `frontend/src/main.ts` — after `app.use(router)`, add:

```ts
import { setUnauthorizedHandler } from './api/client'
import { useAuthStore } from './stores/auth'

setUnauthorizedHandler(() => {
  const auth = useAuthStore()
  auth.user = null
  if (router.currentRoute.value.name !== 'login') router.push({ name: 'login' })
})
```

(Place the imports at the top of the file with the others.)

Replace `frontend/src/App.vue`:

```vue
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
```

- [ ] **Step 5: Verify**

```bash
cd frontend && npm run test:unit -- --run && npm run type-check && npm run build
```
Then a live check with the running stack: `curl -s http://localhost:5173/login | grep -o '<title>[^<]*'` returns the Vite index (SPA routes render client-side — this just proves the dev server serves).

- [ ] **Step 6: Commit**

```bash
git add frontend
git commit -m "feat: auth store, login/register views, and route guards"
```

---

### Task 3: API types and portfolios store

**Files:**
- Modify: `frontend/src/api/types.ts` (portfolio/account/projection types)
- Create: `frontend/src/stores/portfolios.ts`
- Test: `frontend/src/stores/__tests__/portfolios.spec.ts`

**Interfaces:**
- Consumes: `api` (Task 1).
- Produces (Plan 5 depends on these exact names):
  - Types: `AccountType` union, `Portfolio`, `Account`, `AccountInput`, `ContributionInput`, `DrawdownInput`, `PortfolioInput`, plus `ProjectionMonth`, `ProjectionSummary`, `ProjectionResponse`, `GoalSeekResponse` mirroring the Plan 3 endpoint shapes (incl. `realBalance`, `realContribution`, `realGrossWithdrawal`, `realNetWithdrawal`, `realTaxPaid`, `depletionDate`).
  - `usePortfoliosStore()` with `portfolios: Ref<Portfolio[]>`, `loaded: Ref<boolean>`, `load()`, `byId(id): Portfolio|undefined`, `accountById(id): {account, portfolio}|undefined`, `create(input)`, `update(id, input)`, `remove(id)`, `duplicate(id)`, `createAccount(portfolioId, input)`, `updateAccount(accountId, input)`, `removeAccount(accountId)` — every mutation keeps `portfolios` in sync from the API response (no optimistic math).

- [ ] **Step 1: Write the failing store tests**

Create `frontend/src/stores/__tests__/portfolios.spec.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/api/client', () => ({ api: vi.fn() }))

import { api } from '@/api/client'
import { usePortfoliosStore } from '../portfolios'

const mockedApi = vi.mocked(api)

const acct = (id: number, portfolioId: number) => ({
  id, portfolioId, name: `A${id}`, type: 'roth_ira', startingBalance: 0, startingBasis: null,
  annualReturnRate: 0.07, inflationRate: 0.03, horizonYears: 40,
  contribution: { monthlyAmount: 0, escalationRate: 0, startsOn: null, endsOn: null },
  drawdown: { amount: null, frequency: 'monthly', entryMode: 'gross', startsOn: null, endsOn: null, inflationIndexed: true },
})
const pf = (id: number, accounts: object[] = []) => ({
  id, name: `P${id}`, ordinaryIncomeTaxRate: 0.22, capitalGainsTaxRate: 0.15, accounts,
})

describe('portfolios store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mockedApi.mockReset()
  })

  it('load fills the list', async () => {
    mockedApi.mockResolvedValueOnce([pf(1), pf(2)])
    const store = usePortfoliosStore()
    await store.load()
    expect(store.portfolios).toHaveLength(2)
    expect(store.loaded).toBe(true)
    expect(store.byId(2)?.name).toBe('P2')
  })

  it('create appends the API response', async () => {
    mockedApi.mockResolvedValueOnce([])
    const store = usePortfoliosStore()
    await store.load()
    mockedApi.mockResolvedValueOnce(pf(5))
    await store.create({ name: 'P5', ordinaryIncomeTaxRate: 0.22, capitalGainsTaxRate: 0.15 })
    expect(store.portfolios.map((p) => p.id)).toEqual([5])
  })

  it('update replaces in place, remove deletes, duplicate appends', async () => {
    mockedApi.mockResolvedValueOnce([pf(1), pf(2)])
    const store = usePortfoliosStore()
    await store.load()

    mockedApi.mockResolvedValueOnce({ ...pf(1), name: 'Renamed' })
    await store.update(1, { name: 'Renamed', ordinaryIncomeTaxRate: 0.3, capitalGainsTaxRate: 0.15 })
    expect(store.byId(1)?.name).toBe('Renamed')

    mockedApi.mockResolvedValueOnce(pf(3))
    await store.duplicate(2)
    expect(store.portfolios).toHaveLength(3)

    mockedApi.mockResolvedValueOnce(undefined)
    await store.remove(2)
    expect(store.portfolios.map((p) => p.id)).toEqual([1, 3])
  })

  it('account mutations resync the owning portfolio', async () => {
    mockedApi.mockResolvedValueOnce([pf(1, [acct(10, 1)])])
    const store = usePortfoliosStore()
    await store.load()

    mockedApi.mockResolvedValueOnce(acct(11, 1))
    await store.createAccount(1, {} as never)
    expect(store.byId(1)?.accounts).toHaveLength(2)

    mockedApi.mockResolvedValueOnce({ ...acct(10, 1), name: 'Renamed' })
    await store.updateAccount(10, {} as never)
    expect(store.accountById(10)?.account.name).toBe('Renamed')
    expect(store.accountById(10)?.portfolio.id).toBe(1)

    mockedApi.mockResolvedValueOnce(undefined)
    await store.removeAccount(11)
    expect(store.byId(1)?.accounts.map((a) => a.id)).toEqual([10])
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

`cd frontend && npm run test:unit -- --run` — FAIL, `../portfolios` missing.

- [ ] **Step 3: Implement types**

Append to `frontend/src/api/types.ts`:

```ts
export type AccountType =
  | 'traditional_401k'
  | 'roth_401k'
  | 'traditional_ira'
  | 'roth_ira'
  | 'brokerage'
  | 'plan_529'
  | 'cash'

export type DrawdownFrequency = 'weekly' | 'monthly'
export type DrawdownEntryMode = 'gross' | 'net'

export interface ContributionInput {
  monthlyAmount: number
  escalationRate: number
  startsOn: string | null
  endsOn: string | null
}

export interface DrawdownInput {
  amount: number | null
  frequency: DrawdownFrequency
  entryMode: DrawdownEntryMode
  startsOn: string | null
  endsOn: string | null
  inflationIndexed: boolean
}

export interface AccountInput {
  name: string
  type: AccountType
  startingBalance: number
  startingBasis: number | null
  annualReturnRate: number
  inflationRate: number
  horizonYears: number
  contribution: ContributionInput
  drawdown: DrawdownInput
}

export interface Account extends AccountInput {
  id: number
  portfolioId: number
}

export interface PortfolioInput {
  name: string
  ordinaryIncomeTaxRate: number
  capitalGainsTaxRate: number
}

export interface Portfolio extends PortfolioInput {
  id: number
  accounts: Account[]
}

export interface ProjectionMonth {
  index: number
  date: string
  balance: number
  realBalance: number
  basis: number
  contribution: number
  realContribution: number
  grossWithdrawal: number
  realGrossWithdrawal: number
  netWithdrawal: number
  realNetWithdrawal: number
  taxPaid: number
  realTaxPaid: number
}

export interface ProjectionSummary {
  endingBalance: number
  endingRealBalance: number
  depletionDate: string | null
  totalContributions: number
  totalGrossWithdrawals: number
  totalNetWithdrawals: number
  totalTaxPaid: number
}

export interface ProjectionResponse {
  months: ProjectionMonth[]
  summary: ProjectionSummary
}

export interface GoalSeekResponse {
  attainable: boolean
  requiredMonthlyContribution: number
  requiredYearlyContribution: number
  projection: ProjectionResponse
}
```

- [ ] **Step 4: Implement the store**

Create `frontend/src/stores/portfolios.ts`:

```ts
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '@/api/client'
import type { Account, AccountInput, Portfolio, PortfolioInput } from '@/api/types'

export const usePortfoliosStore = defineStore('portfolios', () => {
  const portfolios = ref<Portfolio[]>([])
  const loaded = ref(false)

  async function load(): Promise<void> {
    portfolios.value = await api<Portfolio[]>('GET', '/api/portfolios')
    loaded.value = true
  }

  function byId(id: number): Portfolio | undefined {
    return portfolios.value.find((p) => p.id === id)
  }

  function accountById(id: number): { account: Account; portfolio: Portfolio } | undefined {
    for (const portfolio of portfolios.value) {
      const account = portfolio.accounts.find((a) => a.id === id)
      if (account) return { account, portfolio }
    }
    return undefined
  }

  async function create(input: PortfolioInput): Promise<Portfolio> {
    const created = await api<Portfolio>('POST', '/api/portfolios', input)
    portfolios.value.push(created)
    return created
  }

  async function update(id: number, input: PortfolioInput): Promise<void> {
    const updated = await api<Portfolio>('PUT', `/api/portfolios/${id}`, input)
    const i = portfolios.value.findIndex((p) => p.id === id)
    if (i >= 0) portfolios.value[i] = updated
  }

  async function remove(id: number): Promise<void> {
    await api('DELETE', `/api/portfolios/${id}`)
    portfolios.value = portfolios.value.filter((p) => p.id !== id)
  }

  async function duplicate(id: number): Promise<Portfolio> {
    const copy = await api<Portfolio>('POST', `/api/portfolios/${id}/duplicate`)
    portfolios.value.push(copy)
    return copy
  }

  async function createAccount(portfolioId: number, input: AccountInput): Promise<Account> {
    const created = await api<Account>('POST', `/api/portfolios/${portfolioId}/accounts`, input)
    byId(portfolioId)?.accounts.push(created)
    return created
  }

  async function updateAccount(accountId: number, input: AccountInput): Promise<void> {
    const updated = await api<Account>('PUT', `/api/accounts/${accountId}`, input)
    const found = accountById(accountId)
    if (found) {
      const i = found.portfolio.accounts.findIndex((a) => a.id === accountId)
      found.portfolio.accounts[i] = updated
    }
  }

  async function removeAccount(accountId: number): Promise<void> {
    const found = accountById(accountId)
    await api('DELETE', `/api/accounts/${accountId}`)
    if (found) {
      found.portfolio.accounts = found.portfolio.accounts.filter((a) => a.id !== accountId)
    }
  }

  return {
    portfolios, loaded, load, byId, accountById,
    create, update, remove, duplicate,
    createAccount, updateAccount, removeAccount,
  }
})
```

- [ ] **Step 5: Verify and commit**

```bash
cd frontend && npm run test:unit -- --run && npm run type-check
cd /Users/isaacsaunders/workspace/nestegg
git add frontend
git commit -m "feat: API types and portfolios store"
```

---

### Task 4: Portfolio views, account form, browser smoke test

**Files:**
- Create: `frontend/src/views/PortfoliosView.vue`
- Create: `frontend/src/views/PortfolioView.vue`
- Create: `frontend/src/components/AccountForm.vue`
- Create: `frontend/src/components/PercentInput.vue`
- Modify: `frontend/src/router/index.ts` (routes), `frontend/src/views/HomeView.vue` → delete (replaced by redirect)

**Interfaces:**
- Consumes: portfolios store (Task 3), auth store (Task 2).
- Produces: routes `/` (redirect `/portfolios`), `/portfolios` (list), `/portfolios/:id` (detail with account CRUD). `AccountForm` emits `save(input: AccountInput)` / `cancel`; `PercentInput` is a `v-model:number` field that displays `rate*100` and emits fractions (presentation conversion only). Plan 5 adds "open account" navigation from the detail view to the projection view.

- [ ] **Step 1: PercentInput component**

Create `frontend/src/components/PercentInput.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ modelValue: number; id: string; step?: number; min?: number; max?: number }>()
const emit = defineEmits<{ 'update:modelValue': [value: number] }>()

const display = computed({
  get: () => Math.round(props.modelValue * 10000) / 100,
  set: (v: number) => emit('update:modelValue', (Number.isFinite(v) ? v : 0) / 100),
})
</script>

<template>
  <span class="percent-input">
    <input :id="id" v-model.number="display" type="number" :step="step ?? 0.1" :min="min ?? -100" :max="max ?? 100" />
    <span class="unit">%</span>
  </span>
</template>

<style scoped>
.percent-input { display: inline-flex; align-items: center; gap: 0.35rem; }
.percent-input input { width: 6rem; }
.unit { color: var(--ink-faint); font-size: 0.85rem; }
</style>
```

- [ ] **Step 2: PortfoliosView**

Create `frontend/src/views/PortfoliosView.vue`:

```vue
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
```

- [ ] **Step 3: AccountForm**

Create `frontend/src/components/AccountForm.vue`:

```vue
<script setup lang="ts">
import { reactive, ref } from 'vue'
import type { Account, AccountInput, AccountType } from '@/api/types'
import PercentInput from './PercentInput.vue'

const props = defineProps<{ initial?: Account }>()
const emit = defineEmits<{ save: [input: AccountInput]; cancel: [] }>()

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

function submit() {
  const payload: AccountInput = JSON.parse(JSON.stringify(form))
  if (!hasDrawdown.value) {
    payload.drawdown = { amount: null, frequency: 'monthly', entryMode: 'gross', startsOn: null, endsOn: null, inflationIndexed: true }
  }
  for (const key of ['startsOn', 'endsOn'] as const) {
    if (payload.contribution[key] === '') payload.contribution[key] = null
    if (payload.drawdown[key] === '') payload.drawdown[key] = null
  }
  if (payload.type !== 'brokerage') payload.startingBasis = null
  emit('save', payload)
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
          <input id="cmonthly" v-model.number="form.contribution.monthlyAmount" type="number" min="0" step="50" />
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
    <div class="row">
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
```

- [ ] **Step 4: PortfolioView**

Create `frontend/src/views/PortfolioView.vue`:

```vue
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { usePortfoliosStore } from '@/stores/portfolios'
import { ApiError } from '@/api/client'
import type { Account, AccountInput } from '@/api/types'
import AccountForm from '@/components/AccountForm.vue'
import PercentInput from '@/components/PercentInput.vue'

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

onMounted(async () => {
  if (!store.loaded) await store.load()
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
  if (confirm(`Delete account “${a.name}”?`)) await store.removeAccount(a.id)
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
.row { display: flex; gap: 0.5rem; }
.accounts { width: 100%; border-collapse: collapse; background: var(--paper-raised); border: 1px solid var(--line); border-radius: var(--radius); }
.accounts th { text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-soft); padding: 0.55rem 0.8rem; border-bottom: 2px solid var(--line); }
.accounts td { padding: 0.55rem 0.8rem; border-bottom: 1px solid var(--line); }
.accounts .num { text-align: right; }
.row-actions { display: flex; gap: 0.75rem; justify-content: flex-end; }
.danger { color: var(--danger); }
</style>
```

Also create the placeholder `frontend/src/views/AccountView.vue` (Plan 5 replaces it — this keeps the table's account links navigable):

```vue
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
```

- [ ] **Step 5: Routes**

Delete `frontend/src/views/HomeView.vue`. In `frontend/src/router/index.ts` replace the routes array:

```ts
  routes: [
    { path: '/', redirect: '/portfolios' },
    { path: '/portfolios', name: 'portfolios', component: () => import('../views/PortfoliosView.vue') },
    { path: '/portfolios/:id(\\d+)', name: 'portfolio', component: () => import('../views/PortfolioView.vue') },
    { path: '/accounts/:id(\\d+)', name: 'account', component: () => import('../views/AccountView.vue') },
    { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { public: true } },
    { path: '/register', name: 'register', component: () => import('../views/RegisterView.vue'), meta: { public: true } },
  ],
```

Remove the now-unused `HomeView` import; update `App.vue`'s nav link `to="/portfolios"` and the guard's authenticated-on-public redirect target to `{ name: 'portfolios' }`; update LoginView/RegisterView `router.push('/')` targets to `/portfolios` (they redirect anyway via `/`, so leaving them is also fine — pick one and be consistent: use `/portfolios`).

- [ ] **Step 6: Verify — unit, types, build, then live browser smoke**

```bash
cd frontend && npm run test:unit -- --run && npm run type-check && npm run build
```

Then, with the stack running, use the Playwright browser tools (browser_navigate / browser_fill_form / browser_click / browser_snapshot) against `http://127.0.0.1:5173` (IMPORTANT: use 127.0.0.1, not localhost — another project's dev server owns the localhost IPv6 binding on 5173):
1. Navigate to /register; create a fresh user (unique email like `smoke+<epoch>@nestegg.local`, password 10+ chars, birth date 1990-06-15).
2. Land on /portfolios; create portfolio "Smoke test".
3. Open it; add account "My 401k" (Traditional 401k, starting 50000, drawdown 4000/mo net starting 2041-07-01).
4. Verify the account row renders; edit it (rename to "My 401k v2"); verify rename; take a screenshot of the portfolio page and save it via browser_take_screenshot (filename `plan4-smoke.png`).
5. Sign out; verify redirect to /login.
Record each step's outcome in your report. If any step fails, debug (browser console via browser_console_messages, network via browser_network_requests) and fix before committing.

- [ ] **Step 7: Commit and push**

```bash
git add frontend
git commit -m "feat: portfolio and account management views"
git push origin main
```
