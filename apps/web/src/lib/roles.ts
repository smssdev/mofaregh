import type { Role } from '@/api/types'

export const ROLE_LABELS: Record<Role, string> = {
  admin: 'مدير النظام',
  project_manager: 'مدير مشروع',
  transcriber: 'مفرِّغ',
  linguistic_reviewer: 'مراجع لغوي',
  sharia_checker: 'مدقق شرعي',
}

export const ROLES = Object.keys(ROLE_LABELS) as Role[]

/** Roles that can be added to a project as members (managers are set on the project itself). */
export const MEMBER_ROLES: Role[] = ['transcriber', 'linguistic_reviewer', 'sharia_checker']
