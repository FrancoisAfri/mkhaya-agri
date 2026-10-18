<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RESOURCE_TYPE_CONFIG } from '../Store/type'

const { t } = useI18n()

type ReportRow = Record<string, unknown>

interface OrgUnit {
  id:        number | string
  name:      string
  parent_id?: number | string | null
}

interface Props {
  resourceReport: ReportRow[]
  brandingReport: ReportRow[]
  divisions:      OrgUnit[]
  departments:    OrgUnit[]
  sections:       OrgUnit[]
}

const props = withDefaults(defineProps<Props>(), {
  resourceReport: () => [],
  brandingReport: () => [],
  divisions:      () => [],
  departments:    () => [],
  sections:       () => [],
})

const activeTab = ref('resource')
const toast     = reactive({ show: false, text: '', color: 'success' })

const filters = reactive({
  division:   null as number | string | null,
  department: null as number | string | null,
  section:    null as number | string | null,
  type:       null as string | null,
  status:     null as string | null,
  access:     null as string | null,
  dateFrom:   null as string | null,
  dateTo:     null as string | null,
})

const reportCards = [
  {
    key:         'resource',
    title:       t('resource_reports.cards.resource.title'),
    description: t('resource_reports.cards.resource.description'),
    icon:        'tabler-folders',
    color:       '#7367F0',
    gradient:    'linear-gradient(135deg,#7367F0,#5e52d4)',
  },
  {
    key:         'branding',
    title:       t('resource_reports.cards.branding.title'),
    description: t('resource_reports.cards.branding.description'),
    icon:        'tabler-palette',
    color:       '#FF9F43',
    gradient:    'linear-gradient(135deg,#FF9F43,#e0852a)',
  },
]

const headersByReport = computed<Record<string, { title: string; key: string }[]>>(() => ({
  resource: [
    { title: t('resource_reports.table.headers.title'),        key: 'title'        },
    { title: t('resource_reports.table.headers.type'),         key: 'type'         },
    { title: t('resource_reports.table.headers.author'),       key: 'author'       },
    { title: t('resource_reports.table.headers.unit'),         key: 'unit'         },
    { title: t('resource_reports.table.headers.access'),       key: 'access'       },
    { title: t('resource_reports.table.headers.status'),       key: 'status'       },
    { title: t('resource_reports.table.headers.published'),    key: 'published_at' },
    { title: t('resource_reports.table.headers.expires'),      key: 'expired_at'   },
    { title: t('resource_reports.table.headers.created'),      key: 'created_at'   },
  ],
  branding: [
    { title: t('resource_reports.table.headers.name'),          key: 'name'          },
    { title: t('resource_reports.table.headers.unit'),          key: 'unit'          },
    { title: t('resource_reports.table.headers.author'),        key: 'author'        },
    { title: t('resource_reports.table.headers.primary_color'), key: 'primary_color' },
    { title: t('resource_reports.table.headers.font'),          key: 'font_family'   },
    { title: t('resource_reports.table.headers.logo_light'),    key: 'logo_light'    },
    { title: t('resource_reports.table.headers.logo_dark'),     key: 'logo_dark'     },
    { title: t('resource_reports.table.headers.favicon'),       key: 'favicon'       },
    { title: t('resource_reports.table.headers.status'),        key: 'status'        },
    { title: t('resource_reports.table.headers.updated'),       key: 'updated_at'    },
  ],
}))

const typeOptionsByReport: Record<string, string[]> = {
  resource: Object.values(RESOURCE_TYPE_CONFIG).map(c => c.label),
  branding: [],
}

const activeTypes   = computed(() => typeOptionsByReport[activeTab.value] ?? [])
const activeHeaders = computed(() => headersByReport.value[activeTab.value] ?? [])

const availableDepartments = computed(() => {
  if (!filters.division) return props.departments
  return props.departments.filter(d => String(d.parent_id ?? '') === String(filters.division))
})

const availableSections = computed(() => {
  if (!filters.department) return props.sections
  return props.sections.filter(s => String(s.parent_id ?? '') === String(filters.department))
})

const selectedDeptNames = computed(() => {
  if (filters.department) {
    const found = props.departments.find(d => String(d.id) === String(filters.department))
    return found ? [found.name] : []
  }
  if (filters.division) return availableDepartments.value.map(d => d.name)
  return [] as string[]
})

function normalizeDate(val: unknown): number | null {
  if (!val) return null
  const p = Date.parse(String(val))
  return Number.isNaN(p) ? null : p
}

function rowDate(row: ReportRow): number | null {
  const keys = ['created_at', 'updated_at', 'published_at', 'expired_at']
  for (const k of keys) {
    const p = normalizeDate(row[k])
    if (p !== null) return p
  }
  return null
}

function applyFilters(rows: ReportRow[]): ReportRow[] {
  let list = [...rows]

  if (selectedDeptNames.value.length > 0) {
    const allowed = new Set(selectedDeptNames.value)
    list = list.filter(r => typeof r.unit !== 'string' || allowed.has(r.unit))
  }

  if (filters.section) {
    const sec  = props.sections.find(s => String(s.id) === String(filters.section))
    const dept = sec ? props.departments.find(d => String(d.id) === String(sec.parent_id ?? ''))?.name : undefined
    if (sec) {
      list = list.filter(r =>
        typeof r.unit === 'string'
          ? r.unit === sec.name || r.unit === dept
          : true
      )
    }
  }

  if (filters.type) {
    list = list.filter(r => String(r.type ?? '') === filters.type)
  }

  if (filters.status) {
    const w = filters.status.toLowerCase()
    list = list.filter(r => String(r.status ?? '').toLowerCase() === w)
  }

  if (filters.access) {
    const w = filters.access.toLowerCase()
    list = list.filter(r => String(r.access ?? '').toLowerCase() === w)
  }

  const fromTs = normalizeDate(filters.dateFrom)
  const toTs   = normalizeDate(filters.dateTo)
  if (fromTs !== null || toTs !== null) {
    list = list.filter(r => {
      const dt = rowDate(r)
      if (dt === null) return true
      if (fromTs !== null && dt < fromTs) return false
      if (toTs   !== null && dt > toTs)   return false
      return true
    })
  }

  return list
}

const reportDataMap = computed<Record<string, ReportRow[]>>(() => ({
  resource: props.resourceReport,
  branding: props.brandingReport,
}))

const activeRows = computed(() => applyFilters(reportDataMap.value[activeTab.value] ?? []))

function setToast(text: string, color = 'success') {
  toast.text = text; toast.color = color; toast.show = true
}

function switchTab(key: string) {
  activeTab.value  = key
  filters.type     = null
  filters.status   = null
  filters.access   = null
}

function buildQS() {
  const p = new URLSearchParams()
  if (filters.division)   p.set('division',   String(filters.division))
  if (filters.department) p.set('department', String(filters.department))
  if (filters.section)    p.set('section',    String(filters.section))
  if (filters.type)       p.set('type',       filters.type)
  if (filters.status)     p.set('status',     filters.status)
  if (filters.access)     p.set('access',     filters.access)
  if (filters.dateFrom)   p.set('dateFrom',   filters.dateFrom)
  if (filters.dateTo)     p.set('dateTo',     filters.dateTo)
  return p.toString()
}

async function generateReport(key: string) {
  activeTab.value = key
  const qs  = buildQS()
  const url = `/resource-reports/generate/${key}${qs ? `?${qs}` : ''}`
  try {
    const res = await fetch(url, { headers: { Accept: 'application/json' } })
    if (!res.ok) throw new Error()
    const payload = await res.json()
    setToast(t('resource_reports.toast.generated', { count: payload.count, report: key }))
  } catch {
    setToast(t('resource_reports.toast.error', { report: key }), 'error')
  }
}

function exportReport(key: string, format: 'excel' | 'pdf') {
  const qs  = buildQS()
  const fmt = format === 'excel' ? 'xlsx' : 'pdf'
  window.open(`/resource-reports/export/${key}/${fmt}${qs ? `?${qs}` : ''}`, '_blank')
  setToast(t('resource_reports.toast.export_started', { format: format.toUpperCase(), report: key }))
}

function clearFilters() {
  filters.division   = null
  filters.department = null
  filters.section    = null
  filters.type       = null
  filters.status     = null
  filters.access     = null
  filters.dateFrom   = null
  filters.dateTo     = null
}

function statusColor(val: unknown): string {
  const s = String(val ?? '').toLowerCase()
  if (s === 'active')   return 'success'
  if (s === 'inactive') return 'default'
  return 'warning'
}

function accessColor(val: unknown): string {
  return String(val ?? '').toLowerCase() === 'internal' ? 'warning' : 'info'
}

function logoChipColor(val: unknown): string {
  return String(val ?? '').toLowerCase() === 'set' ? 'success' : 'default'
}

function typeColor(val: unknown): string {
  const label = String(val ?? '').toLowerCase()
  const entry = Object.values(RESOURCE_TYPE_CONFIG).find(c => c.label.toLowerCase() === label)
  return entry ? entry.color : '#aaa'
}
</script>

<template>
  <VRow>

    <VCol cols="12">
      <VCard class="reports-ribbon" flat>
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6 px-7">
          <div>
            <div class="text-overline text-white mb-1" style="opacity:.85">
              {{ t('resource_reports.hero.overline') }}
            </div>
            <h4 class="text-h4 text-white font-weight-bold mb-1">
              {{ t('resource_reports.hero.title') }}
            </h4>
            <p class="mb-0 text-white" style="opacity:.8">
              {{ t('resource_reports.hero.subtitle') }}
            </p>
          </div>
          <VChip color="white" label class="font-weight-bold" style="color:#7367F0">
            {{ t('resource_reports.hero.active_rows', { count: activeRows.length }) }}
          </VChip>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12">
      <VCard elevation="0" border>
        <VCardItem>
          <VCardTitle class="d-flex align-center gap-2">
            <VIcon icon="tabler-filter" size="18" />
            {{ t('resource_reports.filters.title') }}
          </VCardTitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" sm="6" md="2">
              <VSelect v-model="filters.division" :items="divisions"
                item-title="name" item-value="id" :label="t('resource_reports.filters.division')"
                variant="outlined" density="compact" clearable />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VSelect v-model="filters.department" :items="availableDepartments"
                item-title="name" item-value="id" :label="t('resource_reports.filters.department')"
                variant="outlined" density="compact" clearable />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VSelect v-model="filters.section" :items="availableSections"
                item-title="name" item-value="id" :label="t('resource_reports.filters.section')"
                variant="outlined" density="compact" clearable />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VSelect v-model="filters.type" :items="activeTypes"
                :label="t('resource_reports.filters.resource_type')" variant="outlined" density="compact"
                clearable :disabled="activeTypes.length === 0" />
            </VCol>
            <VCol cols="6" sm="3" md="1">
              <VSelect v-model="filters.status"
                :items="[t('resource_reports.filters.active'), t('resource_reports.filters.inactive')]"
                :label="t('resource_reports.filters.status')" variant="outlined" density="compact" clearable />
            </VCol>
            <VCol cols="6" sm="3" md="1">
              <VSelect v-model="filters.access"
                :items="[t('resource_reports.filters.public'), t('resource_reports.filters.internal')]"
                :label="t('resource_reports.filters.access')" variant="outlined" density="compact" clearable />
            </VCol>
            <VCol cols="6" sm="3" md="1">
              <VTextField v-model="filters.dateFrom" type="date" :label="t('resource_reports.filters.from')"
                variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" sm="3" md="1">
              <VTextField v-model="filters.dateTo" type="date" :label="t('resource_reports.filters.to')"
                variant="outlined" density="compact" />
            </VCol>
          </VRow>
          <div class="d-flex justify-end mt-2">
            <VBtn variant="tonal" color="secondary" size="small"
              prepend-icon="tabler-filter-off" @click="clearFilters">
              {{ t('resource_reports.filters.clear') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol>
      <VCard elevation="0" border>
        <VCardItem>
          <VCardTitle>{{ t('resource_reports.engine.title') }}</VCardTitle>
          <VCardSubtitle>{{ t('resource_reports.engine.subtitle') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow>
            <VCol v-for="report in reportCards" :key="report.key" cols="12" md="6">
              <VCard variant="outlined" class="h-100"
                :style="{ borderTop: `3px solid ${report.color}` }">
                <VCardText>
                  <div class="d-flex align-center gap-3 mb-3">
                    <div class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                      :style="{ width: '40px', height: '40px', background: report.gradient }">
                      <VIcon :icon="report.icon" color="white" size="20" />
                    </div>
                    <p class="text-subtitle-2 font-weight-semibold mb-0">{{ report.title }}</p>
                  </div>
                  <p class="text-caption text-medium-emphasis mb-4">{{ report.description }}</p>
                  <div class="d-flex flex-wrap gap-2">
                    <VBtn size="small" color="primary" variant="tonal"
                      prepend-icon="tabler-player-play" @click="generateReport(report.key)">
                      {{ t('resource_reports.cards.generate') }}
                    </VBtn>
                    <VBtn size="small" variant="tonal"
                      prepend-icon="tabler-table" @click="switchTab(report.key)">
                      {{ t('resource_reports.cards.open_table') }}
                    </VBtn>
                    <VMenu>
                      <template #activator="{ props: mp }">
                        <VBtn v-bind="mp" size="small" color="secondary" variant="tonal"
                          prepend-icon="tabler-upload">
                          {{ t('resource_reports.cards.export') }}
                        </VBtn>
                      </template>
                      <VList>
                        <VListItem prepend-icon="tabler-file-type-csv"
                          :title="t('resource_reports.cards.export_csv')"
                          @click="exportReport(report.key, 'excel')" />
                        <VListItem prepend-icon="tabler-file-type-pdf"
                          :title="t('resource_reports.cards.export_pdf')"
                          @click="exportReport(report.key, 'pdf')" />
                      </VList>
                    </VMenu>
                  </div>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12">
      <VCard elevation="0" border>

        <VTabs v-model="activeTab" color="primary">
          <VTab value="resource">
            <VIcon icon="tabler-folders" size="16" class="me-2" />
            {{ t('resource_reports.tabs.resource') }}
          </VTab>
          <VTab value="branding">
            <VIcon icon="tabler-palette" size="16" class="me-2" />
            {{ t('resource_reports.tabs.branding') }}
          </VTab>
        </VTabs>

        <VDivider />

        <VCardText class="d-flex flex-wrap align-center gap-2 py-3">
          <VBtn color="primary" size="small"
            prepend-icon="tabler-player-play" @click="generateReport(activeTab)">
            {{ t('resource_reports.table.generate') }}
          </VBtn>
          <VBtn color="success" variant="tonal" size="small"
            prepend-icon="tabler-file-type-csv" @click="exportReport(activeTab, 'excel')">
            {{ t('resource_reports.table.export_csv') }}
          </VBtn>
          <VBtn color="error" variant="tonal" size="small"
            prepend-icon="tabler-file-type-pdf" @click="exportReport(activeTab, 'pdf')">
            {{ t('resource_reports.table.export_pdf') }}
          </VBtn>
          <VSpacer />
          <VChip size="small" variant="tonal" color="primary">
            {{ t('resource_reports.table.rows', { count: activeRows.length }) }}
          </VChip>
        </VCardText>

        <VDataTable
          :headers="activeHeaders"
          :items="activeRows"
          :items-per-page="15"
          height="600"
          fixed-header
        >
          <template #item.status="{ item }">
            <VChip size="small" variant="tonal" :color="statusColor(item.status)">
              {{ item.status }}
            </VChip>
          </template>

          <template #item.access="{ item }">
            <VChip size="small" variant="tonal" :color="accessColor(item.access)">
              <VIcon :icon="String(item.access).toLowerCase() === 'internal' ? 'tabler-lock' : 'tabler-world'"
                size="11" class="me-1" />
              {{ item.access }}
            </VChip>
          </template>

          <template #item.type="{ item }">
            <VChip size="small" variant="tonal" :color="typeColor(item.type)">
              {{ item.type }}
            </VChip>
          </template>

          <template #item.logo_light="{ item }">
            <VChip size="x-small" variant="tonal" :color="logoChipColor(item.logo_light)">
              {{ item.logo_light }}
            </VChip>
          </template>
          <template #item.logo_dark="{ item }">
            <VChip size="x-small" variant="tonal" :color="logoChipColor(item.logo_dark)">
              {{ item.logo_dark }}
            </VChip>
          </template>
          <template #item.favicon="{ item }">
            <VChip size="x-small" variant="tonal" :color="logoChipColor(item.favicon)">
              {{ item.favicon }}
            </VChip>
          </template>

          <template #item.primary_color="{ item }">
            <div class="d-flex align-center gap-2">
              <div v-if="item.primary_color !== '—'"
                class="rounded"
                :style="{ width: '16px', height: '16px', background: String(item.primary_color), border: '1px solid rgba(0,0,0,.12)', flexShrink: 0 }" />
              <span class="text-caption" style="font-family:monospace">
                {{ item.primary_color }}
              </span>
            </div>
          </template>

          <template #no-data>
            <div class="d-flex flex-column align-center justify-center py-12 text-medium-emphasis">
              <VIcon icon="tabler-table-off" size="52" class="mb-3" style="opacity:.25" />
              <p class="text-body-1 font-weight-medium mb-1">{{ t('resource_reports.table.empty.title') }}</p>
              <p class="text-caption mb-0">{{ t('resource_reports.table.empty.description') }}</p>
            </div>
          </template>
        </VDataTable>
      </VCard>
    </VCol>

    <VSnackbar v-model="toast.show" :color="toast.color" timeout="2500" location="top right">
      <div class="d-flex align-center gap-2">
        <VIcon :icon="toast.color === 'success' ? 'tabler-circle-check' : 'tabler-alert-circle'" size="16" />
        {{ toast.text }}
      </div>
    </VSnackbar>

  </VRow>
</template>

<style scoped lang="scss">
.reports-ribbon {
  background: linear-gradient(135deg, #7367F0 0%, #4e42c8 100%);
  border: 0;
  overflow: hidden;
  border-radius: 12px;
  position: relative;

  &::after {
    content: '';
    position: absolute;
    top: -50px; right: -50px;
    width: 200px; height: 200px;
    background: radial-gradient(circle, rgba(255,255,255,.1) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
  }
}

:deep(.v-data-table-header__content) {
  font-weight: 700;
}
</style>
