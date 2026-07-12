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

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
