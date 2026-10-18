<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type ReportRow = Record<string, unknown>

interface OrgUnit {
  id: number | string
  name: string
  parent_id?: number | string | null
}

interface Props {
  contentReport:    ReportRow[]
  spotlightReport:  ReportRow[]
  ceoMessageReport: ReportRow[]
  divisions:        OrgUnit[]
  departments:      OrgUnit[]
  sections:         OrgUnit[]
}

const props = withDefaults(defineProps<Props>(), {
  contentReport:    () => [],
  spotlightReport:  () => [],
  ceoMessageReport: () => [],
  divisions:        () => [],
  departments:      () => [],
  sections:         () => [],
})

const activeTab = ref('content')
const toast     = reactive({ show: false, text: '', color: 'success' })

const filters = reactive({
  division:   null as number | string | null,
  department: null as number | string | null,
  section:    null as number | string | null,
  status:     null as string | null,
  type:       null as string | null,
  dateFrom:   null as string | null,
  dateTo:     null as string | null,
})

const reportCards = [
  {
    key:         'content',
    title:       t('communication_reports.cards.content.title'),
    description: t('communication_reports.cards.content.description'),
    icon:        'tabler-files',
    color:       '#7367F0',
  },
  {
    key:         'spotlight',
    title:       t('communication_reports.cards.spotlight.title'),
    description: t('communication_reports.cards.spotlight.description'),
    icon:        'tabler-star',
    color:       '#FF9F43',
  },
  {
    key:         'ceoMessage',
    title:       t('communication_reports.cards.ceo_message.title'),
    description: t('communication_reports.cards.ceo_message.description'),
    icon:        'tabler-user-tie',
    color:       '#00CFE8',
  },
]

const headersByReport: Record<string, { title: string; key: string }[]> = {
  content: [
    { title: t('communication_reports.table.headers.title'),       key: 'title'      },
    { title: t('communication_reports.table.headers.type'),        key: 'type'       },
    { title: t('communication_reports.table.headers.author'),      key: 'author'     },
    { title: t('communication_reports.table.headers.unit'),        key: 'unit'       },
    { title: t('communication_reports.table.headers.status'),      key: 'status'     },
    { title: t('communication_reports.table.headers.event_date'),  key: 'event_date' },
    { title: t('communication_reports.table.headers.expires'),     key: 'expired_at' },
    { title: t('communication_reports.table.headers.created'),     key: 'created_at' },
  ],
  spotlight: [
    { title: t('communication_reports.table.headers.title'),       key: 'title'       },
    { title: t('communication_reports.table.headers.type'),        key: 'type'        },
    { title: t('communication_reports.table.headers.employee'),    key: 'employee'    },
    { title: t('communication_reports.table.headers.unit'),        key: 'unit'        },
    { title: t('communication_reports.table.headers.month'),       key: 'month'       },
    { title: t('communication_reports.table.headers.description'), key: 'description' },
    { title: t('communication_reports.table.headers.created'),     key: 'created_at'  },
  ],
  ceoMessage: [
    { title: t('communication_reports.table.headers.title'),      key: 'title'      },
    { title: t('communication_reports.table.headers.status'),     key: 'status'     },
    { title: t('communication_reports.table.headers.created'),    key: 'created_at' },
    { title: t('communication_reports.table.headers.updated'),    key: 'updated_at' },
  ],
}

const typeOptionsByReport: Record<string, string[]> = {
  content:   ['Announcement', 'News', 'Event', 'Internal Notice'],
  spotlight: ['Award', 'Anniversary', 'Competition'],
  ceoMessage: [],
}

const availableDepartments = computed(() => {
  if (!filters.division) return props.departments
  return props.departments.filter(d => String(d.parent_id ?? '') === String(filters.division))
})

const availableSections = computed(() => {
  if (!filters.department) return props.sections
  return props.sections.filter(s => String(s.parent_id ?? '') === String(filters.department))
})

const selectedDepartmentNames = computed(() => {
  if (filters.department) {
    const found = props.departments.find(d => String(d.id) === String(filters.department))
    return found ? [found.name] : []
  }
  if (filters.division) return availableDepartments.value.map(d => d.name)
  return [] as string[]
})

function normalizeDate(value: unknown): number | null {
  if (!value) return null
  const parsed = Date.parse(String(value))
  return Number.isNaN(parsed) ? null : parsed
}

function rowDateValue(row: ReportRow): number | null {
  const keys = ['created_at', 'updated_at', 'event_date', 'expired_at']
  for (const k of keys) {
    const p = normalizeDate(row[k])
    if (p !== null) return p
  }
  return null
}

function applyFilters(rows: ReportRow[]): ReportRow[] {
  let list = [...rows]

  if (selectedDepartmentNames.value.length > 0) {
    const allowed = new Set(selectedDepartmentNames.value)
    list = list.filter(r => typeof r.unit !== 'string' || allowed.has(r.unit))
  }

  if (filters.section) {
    const sec     = props.sections.find(s => String(s.id) === String(filters.section))
    const deptName= sec ? props.departments.find(d => String(d.id) === String(sec.parent_id ?? ''))?.name : undefined
    if (sec) {
      list = list.filter(r =>
        typeof r.unit === 'string'
          ? r.unit === sec.name || r.unit === deptName
          : true
      )
    }
  }

  if (filters.status) {
    const w = filters.status.toLowerCase()
    list = list.filter(r => String(r.status ?? '').toLowerCase() === w)
  }

  if (filters.type) {
    list = list.filter(r => String(r.type ?? '') === filters.type)
  }

  const fromTs = normalizeDate(filters.dateFrom)
  const toTs   = normalizeDate(filters.dateTo)
  if (fromTs !== null || toTs !== null) {
    list = list.filter(r => {
      const dt = rowDateValue(r)
      if (dt === null) return true
      if (fromTs !== null && dt < fromTs) return false
      if (toTs   !== null && dt > toTs)   return false
      return true
    })
  }

  return list
}

const reportDataMap = computed<Record<string, ReportRow[]>>(() => ({
  content:    props.contentReport,
  spotlight:  props.spotlightReport,
  ceoMessage: props.ceoMessageReport,
}))

function reportRows(key: string): ReportRow[] {
  return applyFilters(reportDataMap.value[key] ?? [])
}

const activeRows    = computed(() => reportRows(activeTab.value))
const activeHeaders = computed(() => headersByReport[activeTab.value] ?? [])
const activeTypes   = computed(() => typeOptionsByReport[activeTab.value] ?? [])

function setToast(text: string, color = 'success') {
  toast.text  = text
  toast.color = color
  toast.show  = true
}

function switchToReport(key: string) {
  activeTab.value  = key
  filters.type     = null
  filters.status   = null
}

function buildQS() {
  const p = new URLSearchParams()
  if (filters.division)   p.set('division',   String(filters.division))
  if (filters.department) p.set('department', String(filters.department))
  if (filters.section)    p.set('section',    String(filters.section))
  if (filters.status)     p.set('status',     filters.status)
  if (filters.type)       p.set('type',       filters.type)
  if (filters.dateFrom)   p.set('dateFrom',   filters.dateFrom)
  if (filters.dateTo)     p.set('dateTo',     filters.dateTo)
  return p.toString()
}

async function generateReport(key: string) {
  activeTab.value = key
  const qs  = buildQS()
  const url = `/cms-reports/generate/${key}${qs ? `?${qs}` : ''}`
  try {
    const res = await fetch(url, { headers: { Accept: 'application/json' } })
    if (!res.ok) throw new Error()
    const payload = await res.json()
    setToast(t('communication_reports.toast.generated', { count: payload.count, report: key }))
  } catch {
    setToast(t('communication_reports.toast.error', { report: key }), 'error')
  }
}

function exportReport(key: string, format: 'excel' | 'pdf') {
  const qs  = buildQS()
  const fmt = format === 'excel' ? 'xlsx' : 'pdf'
  const url = `/cms-reports/export/${key}/${fmt}${qs ? `?${qs}` : ''}`
  window.open(url, '_blank')
  setToast(t('communication_reports.toast.export_started', { format: format.toUpperCase(), report: key }))
}

function clearFilters() {
  filters.division   = null
  filters.department = null
  filters.section    = null
  filters.status     = null
  filters.type       = null
  filters.dateFrom   = null
  filters.dateTo     = null
}

function statusColor(val: unknown): string {
  const s = String(val ?? '').toLowerCase()
  if (s === 'active')   return 'success'
  if (s === 'inactive') return 'default'
  return 'warning'
}

function typeColor(val: unknown): string {
  const map: Record<string, string> = {
    'announcement':   'warning',
    'news':           'primary',
    'event':          'info',
    'internal notice':'error',
    'award':          'warning',
    'anniversary':    'info',
    'competition':    'success',
  }
  return map[String(val ?? '').toLowerCase()] ?? 'default'
}
</script>

<template>
  <VRow>

    <VCol cols="12">
      <VCard class="reports-ribbon" flat>
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
          <div>
            <div class="text-overline text-white mb-1" style="opacity:.85">
              {{ t('communication_reports.hero.overline') }}
            </div>
            <h4 class="text-h4 text-white font-weight-bold mb-1">
              {{ t('communication_reports.hero.title') }}
            </h4>
            <p class="mb-0 text-white" style="opacity:.85">
              {{ t('communication_reports.hero.subtitle') }}
            </p>
          </div>
          <VChip color="white" class="text-primary font-weight-bold" label>
            {{ t('communication_reports.hero.active_rows', { count: activeRows.length }) }}
          </VChip>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12">
      <VCard elevation="0" border>
        <VCardItem>
          <VCardTitle>{{ t('communication_reports.filters.title') }}</VCardTitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" sm="6" md="2">
              <VSelect v-model="filters.division" :items="divisions"
                item-title="name" item-value="id" :label="t('communication_reports.filters.division')"
                variant="outlined" density="compact" clearable />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VSelect v-model="filters.department" :items="availableDepartments"
                item-title="name" item-value="id" :label="t('communication_reports.filters.department')"
                variant="outlined" density="compact" clearable />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VSelect v-model="filters.section" :items="availableSections"
                item-title="name" item-value="id" :label="t('communication_reports.filters.section')"
                variant="outlined" density="compact" clearable />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VSelect v-model="filters.type" :items="activeTypes"
                :label="t('communication_reports.filters.type')" variant="outlined" density="compact"
                clearable :disabled="activeTypes.length === 0" />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VSelect v-model="filters.status"
                :items="[t('communication_reports.statuses.active'), t('communication_reports.statuses.inactive')]"
                :label="t('communication_reports.filters.status')" variant="outlined" density="compact" clearable />
            </VCol>
            <VCol cols="6" sm="3" md="1">
              <VTextField v-model="filters.dateFrom" type="date" :label="t('communication_reports.filters.from')"
                variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" sm="3" md="1">
              <VTextField v-model="filters.dateTo" type="date" :label="t('communication_reports.filters.to')"
                variant="outlined" density="compact" />
            </VCol>
          </VRow>
          <div class="d-flex justify-end mt-2">
            <VBtn variant="tonal" color="secondary" size="small"
              prepend-icon="tabler-filter-off" @click="clearFilters">
              {{ t('communication_reports.filters.clear') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12">
      <VCard elevation="0" border>
        <VCardItem>
          <VCardTitle>{{ t('communication_reports.engine.title') }}</VCardTitle>
        </VCardItem>
        <VCardText>
          <VRow>
            <VCol v-for="report in reportCards" :key="report.key" cols="12" md="4">
              <VCard variant="outlined" class="h-100"
                :style="{ borderTop: `3px solid ${report.color}` }">
                <VCardText>
                  <div class="d-flex align-center gap-2 mb-3">
                    <VIcon :icon="report.icon" :color="report.color" size="22" />
                    <p class="text-subtitle-2 font-weight-semibold mb-0">{{ report.title }}</p>
                  </div>
                  <p class="text-caption text-medium-emphasis mb-4">{{ report.description }}</p>
                  <div class="d-flex flex-wrap gap-2">
                    <VBtn size="small" color="primary" variant="tonal"
                      prepend-icon="tabler-player-play" @click="generateReport(report.key)">
                      {{ t('communication_reports.cards.generate') }}
                    </VBtn>
                    <VBtn size="small" variant="tonal"
                      prepend-icon="tabler-table" @click="switchToReport(report.key)">
                      {{ t('communication_reports.cards.open_table') }}
                    </VBtn>
                    <VMenu>
                      <template #activator="{ props: mp }">
                        <VBtn v-bind="mp" size="small" color="secondary" variant="tonal"
                          prepend-icon="tabler-upload">
                          {{ t('communication_reports.cards.export') }}
                        </VBtn>
                      </template>
                      <VList>
                        <VListItem :title="t('communication_reports.cards.export_excel')"
                          prepend-icon="tabler-file-type-csv"
                          @click="exportReport(report.key, 'excel')" />
                        <VListItem :title="t('communication_reports.cards.export_pdf')"
                          prepend-icon="tabler-file-type-pdf"
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
          <VTab value="content">
            <VIcon icon="tabler-files" size="16" class="me-2" />
            {{ t('communication_reports.tabs.content') }}
          </VTab>
          <VTab value="spotlight">
            <VIcon icon="tabler-star" size="16" class="me-2" />
            {{ t('communication_reports.tabs.spotlight') }}
          </VTab>
          <VTab value="ceoMessage">
            <VIcon icon="tabler-user-tie" size="16" class="me-2" />
            {{ t('communication_reports.tabs.ceo_message') }}
          </VTab>
        </VTabs>

        <VDivider />

        <VCardText class="d-flex flex-wrap gap-2 py-3">
          <VBtn color="primary" size="small"
            prepend-icon="tabler-player-play" @click="generateReport(activeTab)">
            {{ t('communication_reports.table.generate') }}
          </VBtn>
          <VBtn color="success" variant="tonal" size="small"
            prepend-icon="tabler-file-type-csv" @click="exportReport(activeTab, 'excel')">
            {{ t('communication_reports.table.export_excel') }}
          </VBtn>
          <VBtn color="error" variant="tonal" size="small"
            prepend-icon="tabler-file-type-pdf" @click="exportReport(activeTab, 'pdf')">
            {{ t('communication_reports.table.export_pdf') }}
          </VBtn>
          <VSpacer />
          <VChip size="small" variant="tonal" color="primary">
            {{ t('communication_reports.table.rows', { count: activeRows.length }) }}
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

          <template #item.type="{ item }">
            <VChip size="small" variant="tonal" :color="typeColor(item.type)">
              {{ item.type }}
            </VChip>
          </template>

          <template #no-data>
            <div class="d-flex flex-column align-center justify-center py-12 text-medium-emphasis">
              <VIcon icon="tabler-table-off" size="52" class="mb-3" style="opacity:.25" />
              <p class="text-body-1 font-weight-medium mb-1">{{ t('communication_reports.table.empty.title') }}</p>
              <p class="text-caption mb-0">{{ t('communication_reports.table.empty.description') }}</p>
            </div>
          </template>
        </VDataTable>
      </VCard>
    </VCol>

    <VSnackbar v-model="toast.show" :color="toast.color" timeout="2500" location="top right">
      {{ toast.text }}
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
    top: -60px;
    right: -60px;
    width: 220px;
    height: 220px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
    border-radius: 50%;
    pointer-events: none;
  }
}

:deep(.v-data-table-header__content) {
  font-weight: 700;
}
</style>
