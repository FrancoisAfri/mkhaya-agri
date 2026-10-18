import { ref, computed, watch } from 'vue'
import type { CompanyUnit, Resource } from '../Store/type'

export interface UnitLevel {
  selectedId: number | null
  children:   CompanyUnit[]
}

// ── Unit level config ──────────────────────────────────────────────────────────
export const unitLevelConfig: Record<string, { label: string; icon: string; color: string; bg: string }> = {
  division:   { label: 'Division',   icon: 'mdi-office-building',  color: '#6366f1', bg: '#eef2ff' },
  department: { label: 'Department', icon: 'mdi-sitemap',          color: '#0d9488', bg: '#f0fdfa' },
  team:       { label: 'Team',       icon: 'mdi-account-group',    color: '#f59e0b', bg: '#fffbeb' },
  section:    { label: 'Section',    icon: 'mdi-view-dashboard',   color: '#ec4899', bg: '#fdf2f8' },
  unit:       { label: 'Unit',       icon: 'mdi-cube-outline',     color: '#64748b', bg: '#f8fafc' },
}

export function getUnitLevelConfig(type: string | null | undefined) {
  return unitLevelConfig[type ?? ''] ?? { label: 'Unit', icon: 'mdi-cube-outline', color: '#64748b', bg: '#f8fafc' }
}

// ── Path helpers ───────────────────────────────────────────────────────────────
function buildPath(units: CompanyUnit[], unitId: number): CompanyUnit[] {
  const path: CompanyUnit[] = []
  let cur = units.find(u => u.id === unitId)
  while (cur) {
    path.unshift(cur)
    cur = cur.parent_id ? units.find(u => u.id === cur!.parent_id) : undefined
  }
  return path
}

function levelsFromPath(units: CompanyUnit[], path: CompanyUnit[]): UnitLevel[] {
  const roots  = units.filter(u => u.parent_id === null)
  const levels: UnitLevel[] = []
  for (let i = 0; i < path.length; i++) {
    const ch = i === 0 ? roots : units.filter(u => u.parent_id === path[i - 1].id)
    levels.push({ selectedId: path[i].id, children: ch.length ? ch : [path[i]] })
  }
  const next = units.filter(u => u.parent_id === path[path.length - 1].id)
  if (next.length) levels.push({ selectedId: null, children: next })
  return levels
}

// ── Main composable ────────────────────────────────────────────────────────────
export function useResourceForm(getFlatUnits: () => CompanyUnit[]) {
  const title         = ref('')
  const description   = ref('')
  const type          = ref<number>(1)
  const internal_only = ref(false)
  const is_active     = ref(true)
  const published_at  = ref('')
  const expired_at    = ref('')
  const docFile       = ref<File | null>(null)
  const docName       = ref<string | null>(null)

  // ── Levels ──────────────────────────────────────────────────────────────────
  const levels = ref<UnitLevel[]>([])
  const roots  = computed(() => getFlatUnits().filter(u => u.parent_id === null))

  const initLevels = () => {
    levels.value = roots.value.length ? [{ selectedId: null, children: roots.value }] : []
  }

  const onLevelSelect = (idx: number, id: number | null) => {
    levels.value[idx].selectedId = id
    levels.value = levels.value.slice(0, idx + 1)
    if (id !== null) {
      const ch = getFlatUnits().filter(u => u.parent_id === id)
      if (ch.length) levels.value.push({ selectedId: null, children: ch })
    }
  }

  const selectedUnitId = computed(() => {
    const ids = levels.value.map(l => l.selectedId).filter((id): id is number => id !== null)
    return ids.length ? ids[ids.length - 1] : null
  })

  const selectedUnitPath = computed(() =>
    levels.value.filter(l => l.selectedId !== null)
      .map(l => l.children.find(c => c.id === l.selectedId)?.name ?? '')
      .join(' > ')
  )

  // ── File ────────────────────────────────────────────────────────────────────
  function handleFile(e: Event) {
    const f = (e.target as HTMLInputElement).files?.[0]
    if (!f) return
    docFile.value = f
    docName.value = f.name
  }

  function removeFile() { docFile.value = null; docName.value = null }

  // ── Reset ───────────────────────────────────────────────────────────────────
  function reset() {
    title.value         = ''
    description.value   = ''
    type.value          = 1
    internal_only.value = false
    is_active.value     = true
    published_at.value  = ''
    expired_at.value    = ''
    docFile.value       = null
    docName.value       = null
    initLevels()
  }

  // ── Pre-fill (edit) ─────────────────────────────────────────────────────────
  function fillFromResource(r: Resource) {
    title.value         = r.title         ?? ''
    description.value   = r.description   ?? ''
    type.value          = r.type
    internal_only.value = r.internal_only ?? false
    is_active.value     = r.is_active     ?? true
    published_at.value  = r.published_at  ? r.published_at.slice(0, 16).replace(' ', 'T') : ''
    expired_at.value    = r.expired_at    ? r.expired_at.slice(0,  16).replace(' ', 'T') : ''
    docFile.value       = null
    const doc           = r.asset?.document as any
    docName.value       = doc?.original_name ?? doc?.name ?? null
    if (r.company_unit_id) {
      levels.value = levelsFromPath(getFlatUnits(), buildPath(getFlatUnits(), r.company_unit_id))
    } else {
      initLevels()
    }
  }

  // ── FormData ────────────────────────────────────────────────────────────────
  function buildFormData(mode: 'add' | 'edit'): FormData {
    const fd = new FormData()
    fd.append('title',         title.value)
    fd.append('description',   description.value)
    fd.append('type',          String(type.value))
    fd.append('internal_only', internal_only.value ? '1' : '0')
    fd.append('is_active',     is_active.value     ? '1' : '0')
    if (published_at.value)  fd.append('published_at',     published_at.value)
    if (expired_at.value)    fd.append('expired_at',       expired_at.value)
    if (selectedUnitId.value) fd.append('company_unit_id', String(selectedUnitId.value))
    if (docFile.value instanceof File) fd.append('file', docFile.value)
    if (mode === 'edit') fd.append('_method', 'PUT')
    return fd
  }

  watch(getFlatUnits, initLevels, { immediate: true })

  return {
    title, description, type, internal_only, is_active, published_at, expired_at,
    docFile, docName,
    levels, selectedUnitId, selectedUnitPath,
    initLevels, onLevelSelect, handleFile, removeFile,
    reset, fillFromResource, buildFormData,
  }
}
