export type Role = 'admin' | 'project_manager' | 'transcriber' | 'linguistic_reviewer' | 'sharia_checker'

export interface User {
  id: number
  name: string
  email: string
  role: Role
  role_label: string
  is_active: boolean
  created_at?: string
}

export interface ProjectMember {
  id: number
  name: string
  role: Role
  role_label: string
}

export interface Project {
  id: number
  name: string
  description: string | null
  status: 'active' | 'archived'
  manager: ProjectMember | null
  members?: ProjectMember[]
  audio_files_count?: number
  can_manage: boolean
  created_at: string
}

export type AudioStatus = 'uploading' | 'uploaded' | 'processing' | 'ready' | 'failed'

export interface AudioFile {
  id: number
  project_id: number
  original_name: string
  size_bytes: number
  received_bytes: number
  status: AudioStatus
  duration_seconds: number | null
  error: string | null
  uploaded_by: { id: number; name: string } | null
  stream_url: string | null
  created_at: string
}

export interface Paginated<T> {
  data: T[]
}
