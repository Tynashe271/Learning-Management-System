// Dates are written the way the institution has chosen (Settings > Institution): its language format and its time zone.
let dateTime: Intl.DateTimeFormat
let dateOnly: Intl.DateTimeFormat
let timeOnly: Intl.DateTimeFormat

/** Applies the institution's locale and time zone. An unknown value falls back to the browser's own. */
export function configureFormats(options: { locale?: string | null; timeZone?: string | null } = {}): void {
  const build = (locale?: string, timeZone?: string) => {
    try {
      return [
        new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone }),
        new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: undefined }),
        new Intl.DateTimeFormat(locale, { timeStyle: 'short', timeZone }),
      ] as const
    } catch {
      return null
    }
  }
  const made = build(options.locale ?? undefined, options.timeZone ?? undefined) ?? build(undefined, undefined)
  ;[dateTime, dateOnly, timeOnly] = made as readonly [Intl.DateTimeFormat, Intl.DateTimeFormat, Intl.DateTimeFormat]
}
configureFormats()

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : dateTime.format(date)
}

/** Handles both plain dates ("2026-01-01") and full timestamps. A plain date is shown as that calendar day everywhere. */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const plain = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.slice(0, 10))
  const date = plain && value.length <= 10 ? new Date(Number(plain[1]), Number(plain[2]) - 1, Number(plain[3])) : new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : dateOnly.format(date)
}

export function formatTime(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : timeOnly.format(date)
}

/** "2026-01-01T00:00:00.000000Z" or "2026-01-01" becomes "2026-01-01", for date inputs. */
export function toDateInput(value: string | null | undefined): string {
  if (!value) return ''
  return value.slice(0, 10)
}

const pad = (n: number) => String(n).padStart(2, '0')

/** An ISO timestamp becomes the local "YYYY-MM-DDTHH:mm" a datetime-local input needs. */
export function toLocalInput(value: string | null | undefined): string {
  if (!value) return ''
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return ''
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/** The local "YYYY-MM-DDTHH:mm" from a datetime-local input becomes the UTC ISO timestamp the API expects. */
export function fromLocalInput(value: string): string {
  return new Date(value).toISOString()
}

export function relativeTime(value: string | null | undefined, now: Date = new Date()): string {
  if (!value) return ''
  const then = new Date(value).getTime()
  if (Number.isNaN(then)) return ''
  const seconds = Math.round((then - now.getTime()) / 1000)
  const abs = Math.abs(seconds)
  const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
  if (abs < 60) return rtf.format(seconds, 'second')
  if (abs < 3600) return rtf.format(Math.round(seconds / 60), 'minute')
  if (abs < 86400) return rtf.format(Math.round(seconds / 3600), 'hour')
  if (abs < 86400 * 30) return rtf.format(Math.round(seconds / 86400), 'day')
  return formatDate(value)
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

/** 12.5 -> "12.5", 12 -> "12": marks are shown without needless decimals. */
export function formatScore(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  const n = Number(value)
  if (Number.isNaN(n)) return '—'
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/0+$/, '').replace(/\.$/, '')
}

export function formatPercent(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : `${formatScore(value)}%`
}

export function plural(count: number, one: string, many = `${one}s`): string {
  return `${count} ${count === 1 ? one : many}`
}

export function isPast(value: string | null | undefined, now: Date = new Date()): boolean {
  return !!value && new Date(value).getTime() < now.getTime()
}

/** Groups agenda-style items into "Today", "Tomorrow", or a weekday/date heading, for a daily-plan view. */
export function dayLabel(value: string, now: Date = new Date()): string {
  const date = new Date(value)
  const startOf = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const days = Math.round((startOf(date) - startOf(now)) / 86_400_000)
  if (days === 0) return 'Today'
  if (days === 1) return 'Tomorrow'
  if (days > 1 && days < 7) return new Intl.DateTimeFormat(undefined, { weekday: 'long' }).format(date)
  return formatDate(value)
}

/** Ways to show the initials of a name in an avatar circle. */
export function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase() || '?'
}
