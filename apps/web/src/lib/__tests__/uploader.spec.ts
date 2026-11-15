import { describe, expect, it } from 'vitest'
import {
  FatalUploadError,
  OffsetConflictError,
  ResumableUpload,
  UploadGoneError,
  fingerprint,
  type KeyValueStore,
  type UploadTransport,
} from '../uploader'

class MemoryStore implements KeyValueStore {
  data = new Map<string, string>()
  getItem(key: string) {
    return this.data.get(key) ?? null
  }
  setItem(key: string, value: string) {
    this.data.set(key, value)
  }
  removeItem(key: string) {
    this.data.delete(key)
  }
}

/** A fake server: keeps the received bytes per upload and can drop the connection on chosen calls. */
class FakeServer implements UploadTransport {
  uploads = new Map<number, number[]>()
  nextId = 1
  failNextChunks = 0
  chunkCalls = 0

  async create() {
    const id = this.nextId++
    this.uploads.set(id, [])
    return { id, offset: 0 }
  }

  async getOffset(id: number) {
    const bytes = this.uploads.get(id)
    if (!bytes) throw new UploadGoneError('gone')
    return bytes.length
  }

  async sendChunk(id: number, offset: number, chunk: Blob) {
    this.chunkCalls++
    const bytes = this.uploads.get(id)
    if (!bytes) throw new UploadGoneError('gone')
    if (this.failNextChunks > 0) {
      this.failNextChunks--
      throw new Error('Network Error')
    }
    if (offset !== bytes.length) throw new OffsetConflictError(bytes.length)
    bytes.push(...new Uint8Array(await chunk.arrayBuffer()))
    return bytes.length
  }
}

function makeFile(size: number, name = 'lecture.mp3'): File {
  const content = new Uint8Array(size).map((_, i) => i % 251)
  return new File([content], name, { type: 'audio/mpeg', lastModified: 1700000000000 })
}

async function bytesOf(file: Blob): Promise<number[]> {
  return [...new Uint8Array(await file.arrayBuffer())]
}

const noSleep = async () => {}

describe('ResumableUpload', () => {
  it('uploads a file in chunks and forgets it once done', async () => {
    const server = new FakeServer()
    const storage = new MemoryStore()
    const file = makeFile(25)
    const upload = new ResumableUpload(file, { projectId: 7, transport: server, storage, chunkSize: 10, sleep: noSleep })

    await upload.start()

    expect(upload.snapshot().state).toBe('done')
    expect(server.uploads.get(1)).toEqual(await bytesOf(file))
    expect(server.chunkCalls).toBe(3)
    expect(storage.data.size).toBe(0)
  })

  it('resumes the same upload after a reload', async () => {
    const server = new FakeServer()
    const storage = new MemoryStore()
    const file = makeFile(30)

    // First session stops after the first chunk, as if the tab had been closed.
    const first: ResumableUpload = new ResumableUpload(file, {
      projectId: 7,
      transport: server,
      storage,
      chunkSize: 10,
      sleep: noSleep,
      onChange: (s) => {
        if (s.offset >= 10) first.pause()
      },
    })
    await first.start()
    expect(first.snapshot().state).toBe('paused')
    expect(storage.getItem(fingerprint(7, file))).toBe('1')

    // A new uploader for the same file continues the same server upload.
    const second = new ResumableUpload(file, { projectId: 7, transport: server, storage, chunkSize: 10, sleep: noSleep })
    await second.start()

    expect(second.snapshot().state).toBe('done')
    expect(second.id).toBe(1)
    expect(server.uploads.size).toBe(1)
    expect(server.uploads.get(1)).toEqual(await bytesOf(file))
    expect(server.chunkCalls).toBe(3)
  })

  it('retries after network errors with growing delays and keeps the bytes intact', async () => {
    const server = new FakeServer()
    server.failNextChunks = 3
    const file = makeFile(20)
    const delays: number[] = []
    const states: string[] = []
    const upload = new ResumableUpload(file, {
      projectId: 1,
      transport: server,
      chunkSize: 8,
      sleep: async (ms) => {
        delays.push(ms)
      },
      onChange: (s) => states.push(s.state),
    })

    await upload.start()

    expect(upload.snapshot().state).toBe('done')
    expect(delays).toEqual([1000, 2000, 4000])
    expect(states).toContain('waiting')
    expect(server.uploads.get(1)).toEqual(await bytesOf(file))
  })

  it('follows the server when the offsets disagree', async () => {
    const server = new FakeServer()
    const file = makeFile(16)
    await server.create()
    server.uploads.set(1, (await bytesOf(file)).slice(0, 12))
    const storage = new MemoryStore()
    storage.setItem(fingerprint(1, file), '1')

    // The client is told 4 bytes arrived; the 409 on its first chunk moves it to the real 12.
    const transport: UploadTransport = {
      create: () => server.create(),
      getOffset: async () => 4,
      sendChunk: (id, offset, chunk) => server.sendChunk(id, offset, chunk),
    }
    const upload = new ResumableUpload(file, { projectId: 1, transport, storage, chunkSize: 8, sleep: noSleep })
    await upload.start()

    expect(upload.snapshot().state).toBe('done')
    expect(server.uploads.get(1)).toEqual(await bytesOf(file))
  })

  it('starts over when the remembered upload no longer exists', async () => {
    const server = new FakeServer()
    server.nextId = 5
    const storage = new MemoryStore()
    const file = makeFile(10)
    storage.setItem(fingerprint(1, file), '99')

    const upload = new ResumableUpload(file, { projectId: 1, transport: server, storage, chunkSize: 4, sleep: noSleep })
    await upload.start()

    expect(upload.snapshot().state).toBe('done')
    expect(upload.id).toBe(5)
  })

  it('stops on errors that retrying cannot fix', async () => {
    const transport: UploadTransport = {
      create: async () => {
        throw new FatalUploadError('نوع الملف غير مدعوم')
      },
      getOffset: async () => 0,
      sendChunk: async () => 0,
    }
    const upload = new ResumableUpload(makeFile(4), { projectId: 1, transport, sleep: noSleep })
    await upload.start()

    expect(upload.snapshot()).toMatchObject({ state: 'error', error: 'نوع الملف غير مدعوم' })
  })

  it('gives up after the retry limit', async () => {
    const server = new FakeServer()
    server.failNextChunks = 100
    const upload = new ResumableUpload(makeFile(4), { projectId: 1, transport: server, maxRetries: 2, sleep: noSleep })
    await upload.start()

    expect(upload.snapshot().state).toBe('error')
  })
})
