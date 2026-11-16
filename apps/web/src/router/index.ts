import { createRouter, createWebHistory } from 'vue-router'
import type { Role } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { ensureCsrfCookie } from '@/api/http'

declare module 'vue-router' {
  interface RouteMeta {
    guest?: boolean
    roles?: Role[]
    title?: string
  }
}

export const router = createRouter({
  history: createWebHistory('/'),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true, title: 'تسجيل الدخول' } },
    {
      path: '/',
      component: () => import('@/layouts/AppLayout.vue'),
      children: [
        { path: '', redirect: { name: 'projects' } },
        { path: 'projects', name: 'projects', component: () => import('@/views/ProjectsView.vue'), meta: { title: 'المشاريع' } },
        { path: 'projects/:id(\\d+)', name: 'project', component: () => import('@/views/ProjectView.vue'), props: (r) => ({ id: Number(r.params.id) }), meta: { title: 'المشروع' } },
        { path: 'users', name: 'users', component: () => import('@/views/UsersView.vue'), meta: { roles: ['admin'], title: 'المستخدمون' } },
        { path: 'account', name: 'account', component: () => import('@/views/AccountView.vue'), meta: { title: 'حسابي' } },
      ],
    },
    // The editor fills the whole window (player, ribbon, page), so it sits outside the app layout.
    {
      path: '/audio-files/:id(\\d+)/edit',
      name: 'transcript-editor',
      component: () => import('@/views/TranscriptEditorView.vue'),
      props: (r) => ({ id: Number(r.params.id) }),
      meta: { title: 'محرّر التفريغ' },
    },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue'), meta: { title: 'الصفحة غير موجودة' } },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.loaded) {
    await ensureCsrfCookie().catch(() => undefined)
    await auth.fetchUser().catch(() => undefined)
  }

  if (to.meta.guest) {
    return auth.user ? { name: 'projects' } : true
  }
  if (to.name !== 'not-found' && !auth.user) {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }
  if (to.meta.roles && !auth.hasRole(...to.meta.roles)) {
    return { name: 'projects' }
  }
  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · مُفرِّغ` : 'مُفرِّغ'
})
