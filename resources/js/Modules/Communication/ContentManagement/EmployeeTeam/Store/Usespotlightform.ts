import { ref, computed, watch } from 'vue'
import type { EmployeeSpotlight, EmployeeSpotlightType, UserProperties, CompanyUnit } from './type'

// ── Company unit level types ──────────────────────────────────────────────────
export interface UnitItem { id: number; name: string; parent_id: number | null; type: string | null }

export interface Level {
  selectedId: number | null
  children:   UnitItem[]
}

export const typeItems = [
  { title: 'Award',       value: 1, icon: 'mdi-trophy-outline',       color: 'warning' },
  { title: 'Anniversary', value: 2, icon: 'mdi-cake-variant-outline', color: 'info'    },
  { title: 'Competition', value: 3, icon: 'mdi-medal-outline',        color: 'success' },
] as const

export const typeConfig = {
  1: { label: 'Award',       icon: 'mdi-trophy-outline',       color: 'warning', bg: '#fef9ee' },
  2: { label: 'Anniversary', icon: 'mdi-cake-variant-outline', color: 'info',    bg: '#eef6ff' },
  3: { label: 'Competition', icon: 'mdi-medal-outline',        color: 'success', bg: '#edfaf1' },
} as const

export function getTypeConfig(t: number) {
  return typeConfig[t as 1 | 2 | 3] ?? { label: 'Unknown', icon: 'mdi-help', color: 'default', bg: '#f5f5f5' }
}

// ── Helper: resolve display name from UserProperties ─────────────────────────
export function resolveUserName(user?: UserProperties | null): string {
  if (!user) return 'Unknown'
  const p = user.person
  if (p) return [p.first_name, p.surname].filter(Boolean).join(' ') || user.email || 'Unknown'
  return user.email || 'Unknown'
}

export function resolveUserInitial(user?: UserProperties | null): string {
  if (!user) return '?'
  const p = user.person
  const first = p?.first_name ?? user.email ?? '?'
  return first.charAt(0).toUpperCase()
}

// ── Hierarchical unit helpers ─────────────────────────────────────────────────
export function buildPathToUnit(units: UnitItem[], unitId: number): UnitItem[] {
  const path: UnitItem[] = []
  let current = units.find(u => u.id === unitId)
  while (current) {
    path.unshift(current)
    current = current.parent_id ? units.find(u => u.id === current!.parent_id) : undefined
  }
  return path
}

export function buildLevelsFromPath(units: UnitItem[], path: UnitItem[]): Level[] {
  const roots  = units.filter(u => u.parent_id === null)
  const levels: Level[] = []
  for (let i = 0; i < path.length; i++) {
    const children = i === 0 ? roots : units.filter(u => u.parent_id === path[i - 1].id)
    levels.push({ selectedId: path[i].id, children: children.length > 0 ? children : [path[i]] })
  }
  const next = units.filter(u => u.parent_id === path[path.length - 1].id)
  if (next.length > 0) levels.push({ selectedId: null, children: next })
  return levels
}

// ── Main form composable ──────────────────────────────────────────────────────
export function useSpotlightForm(
  getFlatUnits: () => UnitItem[],
  getEmployees: () => UserProperties[],
) {
  const title        = ref('')
  const description  = ref('')
  const type         = ref<EmployeeSpotlightType>(1)
  const month        = ref('')
  const employeeId   = ref<number | null>(null)
  const imageFile    = ref<File | null>(null)
  const imagePreview = ref<string | null>(null)

  // ── Levels (hierarchical unit selector) ─────────────────────────────────────
  const levels = ref<Level[]>([])

  const roots = computed(() => getFlatUnits().filter(u => u.parent_id === null))

  const initLevels = () => {
    levels.value = roots.value.length ? [{ selectedId: null, children: roots.value }] : []
  }

  const onLevelSelect = (levelIndex: number, selectedId: number | null) => {
    levels.value[levelIndex].selectedId = selectedId
    levels.value = levels.value.slice(0, levelIndex + 1)
    if (selectedId !== null) {
      const children = getFlatUnits().filter(u => u.parent_id === selectedId)
      if (children.length > 0) levels.value.push({ selectedId: null, children })
    }
  }

  const selectedUnitId = computed(() => {
    const ids = levels.value.map(l => l.selectedId).filter((id): id is number => id !== null)
    return ids.length > 0 ? ids[ids.length - 1] : null
  })

  const selectedUnitPath = computed(() =>
    levels.value.filter(l => l.selectedId !== null)
      .map(l => l.children.find(c => c.id === l.selectedId)?.name ?? '')
      .join(' > ')
  )

  // ── Computed ──────────────────────────────────────────────────────────────────
  const isAnniversary = computed(() => type.value === 2)

  const maxMonth = computed(() => {
    const now = new Date()
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
  })

  const employeeItems = computed(() =>
    getEmployees().map(u => ({
      title: resolveUserName(u),
      value: u.id,
    }))
  )

  const canSubmit = computed(() => !!title.value.trim() && !!month.value)

  // ── Clear unit when Anniversary selected ─────────────────────────────────────
  watch(type, (val) => {
    if (val === 2) initLevels()
  })

  // ── Image ─────────────────────────────────────────────────────────────────────
  function handleImage(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0]
    if (!file) return
    imageFile.value    = file
    imagePreview.value = URL.createObjectURL(file)
  }

  function removeImage() {
    imageFile.value    = null
    imagePreview.value = null
  }

  // ── Reset ─────────────────────────────────────────────────────────────────────
  function reset() {
    title.value        = ''
    description.value  = ''
    type.value         = 1
    month.value        = ''
    employeeId.value   = null
    imageFile.value    = null
    imagePreview.value = null
    initLevels()
  }

  // ── Pre-fill from existing spotlight (edit mode) ──────────────────────────────
function fillFromSpotlight(spotlight: any) {
  title.value = spotlight.title || ''
  description.value = spotlight.description || ''
  type.value = spotlight.type || 1
  employeeId.value = spotlight.employee_id || null

  if (spotlight.month) {
    if (spotlight.month.length === 10) {
      month.value = spotlight.month
    } else if (spotlight.month.length === 7) {
      month.value = `${spotlight.month}-01`
    } else {
      const date = new Date(spotlight.month)
      if (!isNaN(date.getTime())) {
        month.value = date.toISOString().split('T')[0]
      }
    }
  } else {
    month.value = ''
  }

  // Gestion de l'image
  if (spotlight.image_url) {
    imagePreview.value = spotlight.image_url
  } else {
    imagePreview.value = null
  }
  imageFile.value = null

  // Gestion des units
  // ... votre logique existante pour les units
}

  // ── Build FormData ────────────────────────────────────────────────────────────
function buildFormData(mode: 'add' | 'edit'): FormData {
    const fd = new FormData()
    fd.append('title',       title.value)
    fd.append('description', description.value)
    fd.append('type',        String(type.value))

    if (month.value) {
      const fullDate = month.value.length === 7
        ? `${month.value}-01`
        : month.value
      fd.append('month', fullDate)
    }

    if (employeeId.value) fd.append('employee_id', String(employeeId.value))
    if (!isAnniversary.value && selectedUnitId.value) {
      fd.append('company_unit_id', String(selectedUnitId.value))
    }
    if (imageFile.value instanceof File) fd.append('image', imageFile.value)
    if (mode === 'edit') fd.append('_method', 'PUT')
    return fd
  }

  watch(getFlatUnits, initLevels, { immediate: true })

  return {
    title, description, type, month, employeeId,
    imageFile, imagePreview,
    levels, selectedUnitId, selectedUnitPath,
    isAnniversary, maxMonth, employeeItems, canSubmit,
    onLevelSelect, handleImage, removeImage,
    reset, fillFromSpotlight, buildFormData, initLevels,
  }
}
