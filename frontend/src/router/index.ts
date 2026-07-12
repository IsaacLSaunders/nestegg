import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', redirect: '/portfolios' },
    { path: '/portfolios', name: 'portfolios', component: () => import('../views/PortfoliosView.vue') },
    { path: '/portfolios/:id(\\d+)', name: 'portfolio', component: () => import('../views/PortfolioView.vue') },
    { path: '/accounts/:id(\\d+)', name: 'account', component: () => import('../views/AccountView.vue') },
    { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { public: true } },
    { path: '/register', name: 'register', component: () => import('../views/RegisterView.vue'), meta: { public: true } },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.checked) await auth.fetchMe()
  if (!to.meta.public && !auth.user) return { name: 'login' }
  if (to.meta.public && auth.user) return { name: 'portfolios' }
})

export default router
