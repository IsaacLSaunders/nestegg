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
