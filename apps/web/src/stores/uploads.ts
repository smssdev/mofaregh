import { defineStore } from 'pinia'
import { markRaw, ref, type Ref } from 'vue'
import { httpUploadTransport } from '@/api/uploadTransport'
import { ResumableUpload, browserStorage, type UploadSnapshot } from '@/lib/uploader'

export interface UploadItem {
  key: string
  projectId: number
  name: string
  snapshot: UploadSnapshot
  upload: ResumableUpload
}

/**
 * Uploads live in a store (not in a page), so they keep going while the user moves around the app,
 * and they resume by themselves when the browser comes back online.
 */
export const useUploadsStore = defineStore('uploads', () => {
  const items = ref<UploadItem[]>([]) as Ref<UploadItem[]>
  const listeners = new Set<(projectId: number) => void>()
  let counter = 0

  function add(projectId: number, file: File): UploadItem {
    const key = `u${++counter}`
    const upload = markRaw(
      new ResumableUpload(file, {
        projectId,
        transport: httpUploadTransport,
        storage: browserStorage(),
        onChange: (snapshot) => {
          const item = items.value.find((i) => i.key === key)
          if (!item) return
          const wasDone = item.snapshot.state === 'done'
          item.snapshot = snapshot
          if (snapshot.state === 'done' && !wasDone) listeners.forEach((fn) => fn(projectId))
        },
      }),
    )
    const item: UploadItem = { key, projectId, name: file.name, snapshot: upload.snapshot(), upload }
    items.value.push(item)
    void upload.start()
    return item
  }

  function pause(key: string) {
    items.value.find((i) => i.key === key)?.upload.pause()
  }

  function resume(key: string) {
    void items.value.find((i) => i.key === key)?.upload.start()
  }

  function dismiss(key: string) {
    const item = items.value.find((i) => i.key === key)
    item?.upload.pause()
    items.value = items.value.filter((i) => i.key !== key)
  }

  function forProject(projectId: number): UploadItem[] {
    return items.value.filter((i) => i.projectId === projectId)
  }

  /** Called when an upload of the project finishes (to refresh its file list). */
  function onFinished(fn: (projectId: number) => void): () => void {
    listeners.add(fn)
    return () => listeners.delete(fn)
  }

  if (typeof window !== 'undefined') {
    window.addEventListener('online', () => {
      items.value
        .filter((i) => i.snapshot.state === 'waiting' || i.snapshot.state === 'error')
        .forEach((i) => void i.upload.start())
    })
    window.addEventListener('beforeunload', (event) => {
      if (items.value.some((i) => ['uploading', 'waiting'].includes(i.snapshot.state))) event.preventDefault()
    })
  }

  return { items, add, pause, resume, dismiss, forProject, onFinished }
})
