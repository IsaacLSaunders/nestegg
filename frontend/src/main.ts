// @ts-expect-error - fontsource packages lack type declarations
import '@fontsource-variable/fraunces'
// @ts-expect-error - fontsource packages lack type declarations
import '@fontsource-variable/public-sans'
import '@fontsource/ibm-plex-mono/400.css'
import '@fontsource/ibm-plex-mono/600.css'
import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { setUnauthorizedHandler } from './api/client'
import { useAuthStore } from './stores/auth'

const app = createApp(App)

app.use(createPinia())
app.use(router)

setUnauthorizedHandler(() => {
  const auth = useAuthStore()
  auth.user = null
  if (router.currentRoute.value.name !== 'login') router.push({ name: 'login' })
})

app.mount('#app')
