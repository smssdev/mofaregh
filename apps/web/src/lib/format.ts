const numberFormat = new Intl.NumberFormat('ar', { maximumFractionDigits: 1 })

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${numberFormat.format(bytes)} بايت`
  const units = ['ك.ب', 'م.ب', 'ج.ب']
  let value = bytes / 1024
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }
  return `${numberFormat.format(value)} ${units[unit]}`
}

export function formatDuration(seconds: number | null): string {
  if (seconds === null) return '—'
  const total = Math.round(seconds)
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  const pad = (n: number) => String(n).padStart(2, '0')
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`
}

export function formatDate(iso: string): string {
  return new Intl.DateTimeFormat('ar', { dateStyle: 'medium' }).format(new Date(iso))
}

export function percent(part: number, whole: number): number {
  if (whole <= 0) return 0
  return Math.min(100, Math.floor((part / whole) * 100))
}
