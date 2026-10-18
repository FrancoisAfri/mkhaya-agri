import { ref, computed, watch } from 'vue'

export interface CompanyUnit {
  id: number
  name: string
  parent_id: number | null
  type?: string | null
}

export interface Level {
  selectedId: number | null
  children: CompanyUnit[]
}

export const iconPresets = [
  { label: 'Bullhorn',  value: 'fa-solid fa-bullhorn'    },
  { label: 'Newspaper', value: 'fa-solid fa-newspaper'   },
  { label: 'Calendar',  value: 'fa-solid fa-calendar'    },
  { label: 'Info',      value: 'fa-solid fa-circle-info' },
  { label: 'Star',      value: 'fa-solid fa-star'        },
  { label: 'Fire',      value: 'fa-solid fa-fire'        },
  { label: 'Bell',      value: 'fa-solid fa-bell'        },
  { label: 'Tag',       value: 'fa-solid fa-tag'         },
  { label: 'Pin',       value: 'fa-solid fa-thumbtack'   },
  { label: 'Message',   value: 'fa-solid fa-message'     },
]

export const typeConfig: Record<string, { label: string; icon: string; mdiIcon: string; color: string; bg: string }> = {
  division:   { label: 'Division',   icon: 'fa-solid fa-building',     mdiIcon: 'mdi-office-building', color: '#6366f1', bg: '#eef2ff' },
  department: { label: 'Department', icon: 'fa-solid fa-sitemap',      mdiIcon: 'mdi-sitemap',         color: '#0d9488', bg: '#f0fdfa' },
  team:       { label: 'Team',       icon: 'fa-solid fa-people-group', mdiIcon: 'mdi-account-group',   color: '#f59e0b', bg: '#fffbeb' },
  section:    { label: 'Section',    icon: 'fa-solid fa-layer-group',  mdiIcon: 'mdi-view-dashboard',  color: '#ec4899', bg: '#fdf2f8' },
  unit:       { label: 'Unit',       icon: 'fa-solid fa-cube',         mdiIcon: 'mdi-cube-outline',    color: '#64748b', bg: '#f8fafc' },
}

export function getTypeConfig(type: string | undefined) {
  return typeConfig[type ?? ''] ?? { label: type ?? 'Unit', icon: 'fa-solid fa-cube', mdiIcon: 'mdi-cube-outline', color: '#64748b', bg: '#f8fafc' }
}

export function getLevelLabel(level: Level): string {
  return getTypeConfig(level.children[0]?.type).label
}

export function toDateInput(val: string | null): string {
  if (!val) return ''
  return val.slice(0, 10)
}

export function toDatetimeLocalInput(val: string | null): string {
  if (!val) return ''
  return val.slice(0, 16).replace(' ', 'T')
}

// ── Main composable ────────────────────────────────────────────────────────────
export function useContentForm(getCompanyUnits: () => CompanyUnit[], showSnack: (msg: string, color: 'success' | 'error') => void) {
  const title        = ref('')
  const summary      = ref('')
  const icon         = ref('')
  const event_start  = ref('')
  const event_end    = ref('')
  const event_date   = ref('')
  const expired_at   = ref('')
  const is_active    = ref(true)
  const imageFile    = ref<File | null>(null)
  const videoFile    = ref<File | null>(null)
  const imagePreview = ref<string | null>(null)
  const videoPreview = ref<string | null>(null)
  const currentStep  = ref(0)

  const levels = ref<Level[]>([])
  const roots  = computed(() => getCompanyUnits().filter(u => u.parent_id === null))

  const initLevels = () => {
    levels.value = roots.value.length ? [{ selectedId: null, children: roots.value }] : []
  }

  const buildPathToUnit = (unitId: number): CompanyUnit[] => {
    const path: CompanyUnit[] = []
    let current = getCompanyUnits().find(u => u.id === unitId)
    while (current) {
      path.unshift(current)
      current = current.parent_id ? getCompanyUnits().find(u => u.id === current!.parent_id) : null
    }
    return path
  }

  const buildLevelsFromPath = (path: CompanyUnit[]) => {
    levels.value = []
    for (let i = 0; i < path.length; i++) {
      const children = i === 0 ? roots.value : getCompanyUnits().filter(u => u.parent_id === path[i - 1].id)
      levels.value.push({ selectedId: path[i].id, children: children.length > 0 ? children : [path[i]] })
    }
    const nextChildren = getCompanyUnits().filter(u => u.parent_id === path[path.length - 1].id)
    if (nextChildren.length > 0) levels.value.push({ selectedId: null, children: nextChildren })
  }

  const onLevelSelect = (levelIndex: number, selectedId: number | null) => {
    levels.value[levelIndex].selectedId = selectedId
    levels.value = levels.value.slice(0, levelIndex + 1)
    if (selectedId !== null) {
      const children = getCompanyUnits().filter(u => u.parent_id === selectedId)
      if (children.length > 0) levels.value.push({ selectedId: null, children })
    }
  }

  const selectedCompanyUnitId = computed(() => {
    const ids = levels.value.map(l => l.selectedId).filter((id): id is number => id !== null)
    return ids.length > 0 ? ids[ids.length - 1] : null
  })

  const selectedUnitPath = computed(() =>
    levels.value.filter(l => l.selectedId !== null)
      .map(l => l.children.find(c => c.id === l.selectedId)?.name ?? '')
      .join(' > ')
  )

  function handleImage(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0]
    if (!file) return
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { showSnack('Invalid image format', 'error'); return }
    if (file.size > 5 * 1024 * 1024) { showSnack('Image must not exceed 5MB', 'error'); return }
    imageFile.value = file; videoFile.value = null; videoPreview.value = null
    imagePreview.value = URL.createObjectURL(file)
  }

  function handleVideo(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0]
    if (!file) return
    if (!file.type.startsWith('video/')) { showSnack('Invalid video file', 'error'); return }
    if (file.size > 100 * 1024 * 1024) { showSnack('Video must not exceed 100MB', 'error'); return }
    videoFile.value = file; imageFile.value = null; imagePreview.value = null
    videoPreview.value = URL.createObjectURL(file)
  }

  function removeMedia() {
    imageFile.value = null; videoFile.value = null
    imagePreview.value = null; videoPreview.value = null
  }

  const iconsSteps = [
    { title: 'Content',          icon: 'fa-solid fa-pen-to-square'  },
    { title: 'Media & Icon',     icon: 'fa-solid fa-photo-film'     },
    { title: 'Organization',     icon: 'fa-solid fa-sitemap'        },
    { title: 'Dates',            icon: 'fa-solid fa-calendar-days'  },
    { title: 'Review & Publish', icon: 'fa-solid fa-circle-check'   },
  ]

  const canGoNext = computed(() => currentStep.value === 0 ? title.value.trim().length > 0 : true)

  function nextStep() { if (currentStep.value < iconsSteps.length - 1) currentStep.value++ }
  function prevStep() { if (currentStep.value > 0) currentStep.value-- }

  function reset() {
    title.value = ''; summary.value = ''; icon.value = ''
    event_start.value = ''; event_end.value = ''; event_date.value = ''
    expired_at.value = ''; is_active.value = true
    imageFile.value = null; videoFile.value = null
    imagePreview.value = null; videoPreview.value = null
    currentStep.value = 0
    initLevels()
  }

  watch(getCompanyUnits, initLevels, { immediate: true })

  return {
    title, summary, icon, event_start, event_end, event_date, expired_at, is_active,
    imageFile, videoFile, imagePreview, videoPreview, currentStep,
    levels, selectedCompanyUnitId, selectedUnitPath,
    initLevels, buildPathToUnit, buildLevelsFromPath, onLevelSelect,
    handleImage, handleVideo, removeMedia,
    iconsSteps, canGoNext, nextStep, prevStep, reset,
  }
}
