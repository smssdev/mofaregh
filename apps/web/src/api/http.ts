import axios, { AxiosError } from 'axios'

/** Same-origin client: Sanctum keeps the session in a cookie and axios echoes the XSRF-TOKEN cookie back. */
export const http = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

let csrfReady = false

export async function ensureCsrfCookie(force = false): Promise<void> {
  if (csrfReady && !force) return
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
  csrfReady = true
}

/** Turns a Laravel error response into one Arabic sentence for the UI. */
export function errorMessage(error: unknown, fallback = 'حدث خطأ غير متوقع، حاول مرة أخرى.'): string {
  if (error instanceof AxiosError) {
    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined
    if (first) return first
    if (error.response?.status === 403) return 'ليست لديك صلاحية لهذا الإجراء.'
    if (error.response?.status === 419) return 'انتهت الجلسة، حدّث الصفحة وسجّل الدخول مجددًا.'
    if (!error.response) return 'تعذّر الاتصال بالخادم، تحقق من الشبكة.'
    if (data?.message) return data.message
  }
  return fallback
}

export function validationErrors(error: unknown): Record<string, string> {
  if (error instanceof AxiosError && error.response?.status === 422) {
    const errors = (error.response.data as { errors?: Record<string, string[]> }).errors ?? {}
    return Object.fromEntries(Object.entries(errors).map(([key, list]) => [key, list[0] ?? '']))
  }
  return {}
}
