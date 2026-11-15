/**
 * Resumable chunked upload (Sprint 1: "رفع ملف 500MB يستأنف بعد انقطاع").
 *
 * Protocol (apps/api AudioUploadController):
 *   POST  /projects/{id}/audio-files      -> { id, received_bytes }
 *   GET   /audio-files/{id}/upload        -> { offset }
 *   PATCH /audio-files/{id}/upload        Upload-Offset: n, body = bytes [n, n+chunk) -> { offset }
 *         409 { offset } when the server holds a different offset (we continue from the server's value).
 *
 * The server's offset is the only source of truth. The upload id is remembered per file fingerprint in
 * storage, so choosing the same file again after a reload, a crash or a lost connection continues where the
 * server stopped instead of starting over.
 */

export interface UploadMeta {
  name: string
  size: number
  mimeType: string
}

export class OffsetConflictError extends Error {
  constructor(public readonly offset: number) {
    super(`Server offset is ${offset}`)
  }
}

export class UploadGoneError extends Error {}

/** Errors the uploader must not retry (validation, permissions): they are shown to the user as they are. */
export class FatalUploadError extends Error {}

export interface UploadTransport {
  create(projectId: number, meta: UploadMeta): Promise<{ id: number; offset: number }>
  getOffset(uploadId: number): Promise<number>
  sendChunk(uploadId: number, offset: number, chunk: Blob): Promise<number>
}

export interface KeyValueStore {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
  removeItem(key: string): void
}

export type UploadState = 'idle' | 'uploading' | 'paused' | 'waiting' | 'done' | 'error'

export interface UploadSnapshot {
  state: UploadState
  offset: number
  size: number
  uploadId: number | null
  error: string | null
}

export interface UploaderOptions {
  projectId: number
  transport: UploadTransport
  storage?: KeyValueStore | null
  chunkSize?: number
  maxRetries?: number
  retryDelay?: (attempt: number) => number
  sleep?: (ms: number) => Promise<void>
  onChange?: (snapshot: UploadSnapshot) => void
}

export const DEFAULT_CHUNK_SIZE = 8 * 1024 * 1024

const defaultSleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms))
const defaultDelay = (attempt: number) => Math.min(30_000, 1000 * 2 ** attempt)

export function fingerprint(projectId: number, file: { name: string; size: number; lastModified?: number }): string {
  return `mofaregh-upload:${projectId}:${file.name}:${file.size}:${file.lastModified ?? 0}`
}

/** localStorage when it is usable (it can throw in private windows), otherwise nothing. */
export function browserStorage(): KeyValueStore | null {
  try {
    const probe = '__mofaregh_probe__'
    window.localStorage.setItem(probe, '1')
    window.localStorage.removeItem(probe)
    return window.localStorage
  } catch {
    return null
  }
}

export class ResumableUpload {
  private state: UploadState = 'idle'
  private offset = 0
  private uploadId: number | null = null
  private error: string | null = null
  private running: Promise<void> | null = null
  private readonly key: string
  private readonly chunkSize: number
  private readonly maxRetries: number

  constructor(
    readonly file: File,
    private readonly options: UploaderOptions,
  ) {
    this.key = fingerprint(options.projectId, file)
    this.chunkSize = options.chunkSize ?? DEFAULT_CHUNK_SIZE
    this.maxRetries = options.maxRetries ?? 8
  }

  snapshot(): UploadSnapshot {
    return { state: this.state, offset: this.offset, size: this.file.size, uploadId: this.uploadId, error: this.error }
  }

  get id(): number | null {
    return this.uploadId
  }

  /** Starts or resumes. Resolves when the upload finishes, is paused, or fails. */
  start(): Promise<void> {
    if (this.running) return this.running
    if (this.state === 'done') return Promise.resolve()
    this.error = null
    this.set('uploading')
    this.running = this.run().finally(() => {
      this.running = null
    })
    return this.running
  }

  pause(): void {
    if (this.state === 'uploading' || this.state === 'waiting') this.set('paused')
  }

  private set(state: UploadState): void {
    this.state = state
    this.options.onChange?.(this.snapshot())
  }

  private isActive(): boolean {
    return this.state === 'uploading' || this.state === 'waiting'
  }

  private async run(): Promise<void> {
    let attempt = 0
    while (this.isActive()) {
      try {
        if (this.uploadId === null) await this.connect()
        if (this.offset >= this.file.size) {
          this.options.storage?.removeItem(this.key)
          this.set('done')
          return
        }
        const end = Math.min(this.offset + this.chunkSize, this.file.size)
        const chunk = this.file.slice(this.offset, end)
        this.offset = await this.options.transport.sendChunk(this.uploadId!, this.offset, chunk)
        attempt = 0
        if (this.state === 'waiting') this.set('uploading')
        else this.options.onChange?.(this.snapshot())
      } catch (error) {
        if (error instanceof OffsetConflictError) {
          this.offset = error.offset
          continue
        }
        if (error instanceof UploadGoneError) {
          // The server forgot this upload (deleted or expired): begin a fresh one.
          this.options.storage?.removeItem(this.key)
          this.uploadId = null
          this.offset = 0
          continue
        }
        if (error instanceof FatalUploadError || attempt >= this.maxRetries) {
          this.error = error instanceof Error ? error.message : String(error)
          this.set('error')
          return
        }
        // Network trouble: wait, then ask the server where it stopped and continue from there.
        if (this.state === 'uploading') this.set('waiting')
        await (this.options.sleep ?? defaultSleep)((this.options.retryDelay ?? defaultDelay)(attempt++))
        if (!this.isActive()) return
        if (this.uploadId !== null) {
          try {
            this.offset = await this.options.transport.getOffset(this.uploadId)
          } catch {
            // Still offline: the next turn of the loop tries again.
          }
        }
      }
    }
  }

  private async connect(): Promise<void> {
    const remembered = this.options.storage?.getItem(this.key)
    if (remembered) {
      const id = Number(remembered)
      try {
        this.offset = await this.options.transport.getOffset(id)
        this.uploadId = id
        return
      } catch (error) {
        if (!(error instanceof UploadGoneError)) throw error
        this.options.storage?.removeItem(this.key)
      }
    }
    const created = await this.options.transport.create(this.options.projectId, {
      name: this.file.name,
      size: this.file.size,
      mimeType: this.file.type || 'application/octet-stream',
    })
    this.uploadId = created.id
    this.offset = created.offset
    this.options.storage?.setItem(this.key, String(created.id))
  }
}
