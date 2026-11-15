import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { AxiosError } from 'axios'
import { ensureCsrfCookie, http } from '@/api/http'
import type { Role, User } from '@/api/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loaded = ref(false)

  const isAdmin = computed(() => user.value?.role === 'admin')
  const canCreateProjects = computed(() => user.value?.role === 'admin' || user.value?.role === 'project_manager')

  function hasRole(...roles: Role[]): boolean {
    return !!user.value && roles.includes(user.value.role)
  }

  /** Loads the signed-in user once per page load; a 401 simply means "signed out". */
  async function fetchUser(): Promise<User | null> {
    try {
      const { data } = await http.get<User>('/auth/me')
      user.value = data
    } catch (error) {
      if (!(error instanceof AxiosError && error.response?.status === 401)) throw error
      user.value = null
    } finally {
      loaded.value = true
    }
    return user.value
  }

  async function login(email: string, password: string, remember: boolean): Promise<void> {
    await ensureCsrfCookie(true)
    const { data } = await http.post<User>('/auth/login', { email, password, remember })
    user.value = data
    loaded.value = true
    // The session id changed: fetch a fresh CSRF token for the next writes.
    await ensureCsrfCookie(true)
  }

  async function logout(): Promise<void> {
    try {
      await http.post('/auth/logout')
    } finally {
      user.value = null
    }
  }

  return { user, loaded, isAdmin, canCreateProjects, hasRole, fetchUser, login, logout }
})
