export function formatDate(value: unknown): string {
  if (!value) return '-'

  const stringValue = String(value)
  if (/^\d{4}-\d{2}-\d{2}/.test(stringValue)) return stringValue.slice(0, 10)

  const date = new Date(stringValue)
  if (Number.isNaN(date.getTime())) return '-'

  return date.toISOString().slice(0, 10)
}

export function formatDateTime(value: unknown): string {
  if (!value) return '—'

  const s = String(value)

  // ISO 8601: 2026-05-10T18:07:39[.000000][Z]
  const match = s.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/)
  if (match) return `${match[1]} ${match[2]}`

  // already a bare datetime or date
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(s)) return s.slice(0, 19)
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s

  const d = new Date(s)
  if (Number.isNaN(d.getTime())) return '—'

  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

export function normalizeDateForInput(value: unknown): string {
  if (!value) return ''

  const stringValue = String(value)
  if (stringValue.includes('T')) return stringValue.split('T')[0]
  if (/^\d{4}-\d{2}-\d{2}$/.test(stringValue)) return stringValue

  const date = new Date(stringValue)
  if (Number.isNaN(date.getTime())) return ''

  return date.toISOString().split('T')[0]
}

export function normalizeTags(value: unknown): string[] {
  if (Array.isArray(value)) {
    return value.map(tag => String(tag).trim()).filter(Boolean)
  }

  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value)
      if (Array.isArray(parsed)) {
        return parsed.map(tag => String(tag).trim()).filter(Boolean)
      }
    } catch {
      // Ignore parse failure and fallback to comma split.
    }

    return value.split(',').map(tag => tag.trim()).filter(Boolean)
  }

  return []
}
