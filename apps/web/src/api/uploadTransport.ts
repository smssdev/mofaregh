import { AxiosError } from 'axios'
import { errorMessage, http } from './http'
import type { AudioFile } from './types'
import {
  FatalUploadError,
  OffsetConflictError,
  UploadGoneError,
  type UploadMeta,
  type UploadTransport,
} from '@/lib/uploader'

/** Maps HTTP answers onto the uploader's error types; anything else (network, 5xx) is retried. */
function translate(error: unknown): never {
  if (error instanceof AxiosError && error.response) {
    const status = error.response.status
    if (status === 409) throw new OffsetConflictError(Number(error.response.data?.offset ?? 0))
    if (status === 404 || status === 410) throw new UploadGoneError('upload gone')
    if (status >= 400 && status < 500 && status !== 408 && status !== 429) {
      throw new FatalUploadError(errorMessage(error))
    }
  }
  // Only transport trouble (no response, 5xx, 408, 429) is worth retrying; anything else is a bug to surface.
  if (!(error instanceof AxiosError)) throw new FatalUploadError(errorMessage(error))
  throw error
}

export const httpUploadTransport: UploadTransport = {
  async create(projectId: number, meta: UploadMeta) {
    try {
      const { data } = await http.post<AudioFile>(`/projects/${projectId}/audio-files`, {
        name: meta.name,
        size: meta.size,
        mime_type: meta.mimeType,
      })
      return { id: data.id, offset: data.received_bytes }
    } catch (error) {
      translate(error)
    }
  },
  async getOffset(uploadId: number) {
    try {
      const { data } = await http.get(`/audio-files/${uploadId}/upload`)
      return data.offset as number
    } catch (error) {
      translate(error)
    }
  },
  async sendChunk(uploadId: number, offset: number, chunk: Blob) {
    try {
      const { data } = await http.patch(`/audio-files/${uploadId}/upload`, chunk, {
        headers: { 'Content-Type': 'application/offset+octet-stream', 'Upload-Offset': String(offset) },
        timeout: 120_000,
      })
      return data.offset as number
    } catch (error) {
      translate(error)
    }
  },
}
