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

export type AsrStatus = 'none' | 'queued' | 'running' | 'done' | 'failed'

/** A word the recogniser was unsure of: its exact times and where it sits in the paragraph text. */
export interface UnclearWord {
  start_ms: number
  end_ms: number
  text: string
  offset: number
}

export interface AsrParagraph {
  start_ms: number
  end_ms: number
  text: string
  unclear?: UnclearWord[]
}

/** The editor's document as TipTap/ProseMirror JSON. */
export interface EditorNode {
  type?: string
  attrs?: Record<string, unknown>
  content?: EditorNode[]
  marks?: { type: string; attrs?: Record<string, unknown> }[]
  text?: string
}

export interface EditorDoc extends EditorNode {
  type: 'doc'
}

/** One snapshot in a transcript's history (edit = while working; restore/restored = around a restore). */
export interface TranscriptVersionSummary {
  id: number
  version: number
  reason: 'edit' | 'restore' | 'restored'
  title: string | null
  words: number
  author: { id: number; name: string } | null
  created_at: string
  updated_at: string
}

export interface TranscriptVersionDetail extends TranscriptVersionSummary {
  content: EditorDoc | null
  paragraphs: string[]
}

export interface Transcript {
  audio_file_id: number
  title: string | null
  content: EditorDoc | null
  version: number
  updated_at: string | null
  updated_by?: { id: number; name: string } | null
  asr: {
    available: boolean
    status: AsrStatus
    progress: number
    /** "loading" while the model is downloaded or opened (minutes on the first run), then "transcribing". */
    stage: 'loading' | 'transcribing' | null
    /** No sign of life for too long (engine killed, worker not running): it may be started again. */
    stalled: boolean
    error: string | null
    model: string | null
    finished_at: string | null
    paragraphs: AsrParagraph[] | null
  }
  export_available: boolean
}
