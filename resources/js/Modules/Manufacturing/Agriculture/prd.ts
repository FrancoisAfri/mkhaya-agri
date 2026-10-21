/** Formatting shared by the agricultural production, Command Centre and intelligence screens. */
export { human, num, money, statusColor } from '@/Modules/Agri/Components/agri'

export const SEVERITY_COLOR: Record<string, string> = { info: 'info', advisory: 'secondary', warning: 'warning', critical: 'error', emergency: 'error' }
export const severityColor = (s: string | null | undefined) => SEVERITY_COLOR[s ?? ''] ?? 'default'

const COMMAND_COLOR: Record<string, string> = {
  requested: 'info', pending_approval: 'warning', approved: 'primary', sent: 'primary', accepted: 'primary',
  executed: 'success', rejected: 'secondary', failed: 'error', unconfirmed: 'error', cancelled: 'secondary',
}
export const commandColor = (s: string) => COMMAND_COLOR[s] ?? 'default'

export const CATEGORY_ICON: Record<string, string> = {
  poultry: 'tabler-feather', crop: 'tabler-plant-2', livestock: 'tabler-cow', dairy: 'tabler-bottle', aquaculture: 'tabler-fish',
  greenhouse: 'tabler-building-warehouse', specialist: 'tabler-flower', processing: 'tabler-building-factory-2',
}

/** A moment shown in the farm's own zone, with the zone named so nobody mistakes it. */
export const at = (iso: string | null | undefined, tz?: string) => {
  if (!iso) return '—'
  const d = new Date(iso)
  try {
    return new Intl.DateTimeFormat('en-ZA', { dateStyle: 'medium', timeStyle: 'short', timeZone: tz || undefined }).format(d)
  }
  catch { return d.toLocaleString() }
}

/** "4 min ago", for the age of a live value. */
export const ago = (iso: string | null | undefined) => {
  if (!iso) return 'never'
  const s = Math.round((Date.now() - new Date(iso).getTime()) / 1000)
  if (s < 60) return `${Math.max(s, 0)} s ago`
  if (s < 3600) return `${Math.round(s / 60)} min ago`
  if (s < 86400) return `${Math.round(s / 3600)} h ago`
  return `${Math.round(s / 86400)} d ago`
}
