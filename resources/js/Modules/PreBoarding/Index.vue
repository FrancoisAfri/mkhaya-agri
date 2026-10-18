<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'

const route = ziggyRoute

type HrPerson = {
  id: number
  uuid: string
  status: { value: string; label: string; color: string }
  employee_number: string | null
  started_on: string | null
  days_until_start: number | null
  pending_tasks: number | null
  department: string | null
  job_title: string | null
  buddy: { id: number; name: string } | null
  candidate: { id: number; full_name: string; email: string } | null
  active_plan: {
    id: number
    status: { value: string; label: string; color: string }
    due_date: string | null
  } | null
  created_at: string
}

const props = defineProps<{
  persons: { data: HrPerson[]; links: any[]; meta: any }
  filters: Record<string, any>
  stats: { total: number; pre_boarding: number; onboarding: number; active: number }
}>()

// ─── Filters ─────────────────────────────────────────────────────────────────
const search = ref<string>(props.filters?.search ?? '')
const status = ref<string>(props.filters?.status ?? '')

let searchTimer: ReturnType<typeof setTimeout>

function applyFilters() {
  router.get(
    route('pre-boarding.index'),
    { search: search.value || undefined, status: status.value || undefined },
    { preserveState: true, replace: true },
  )
}

watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 400)
})

watch(status, applyFilters)

function clearFilters() {
  search.value = ''
  status.value = ''
  router.get(route('pre-boarding.index'), {}, { replace: true })
}

// ─── Stat cards ───────────────────────────────────────────────────────────────
const statCards = computed(() => [
  {
    label: 'Total New Hires',
    value: props.stats.total,
    icon:  'tabler-users',
    color: 'primary',
    bg:    'rgba(115,103,240,0.12)',
  },
  {
    label: 'Pre-Boarding',
    value: props.stats.pre_boarding,
    icon:  'tabler-clipboard-list',
    color: 'warning',
    bg:    'rgba(255,159,67,0.12)',
  },
  {
    label: 'Onboarding',
    value: props.stats.onboarding,
    icon:  'tabler-school',
    color: 'info',
    bg:    'rgba(0,207,232,0.12)',
  },
  {
    label: 'Active',
    value: props.stats.active,
    icon:  'tabler-user-check',
    color: 'success',
    bg:    'rgba(40,199,111,0.12)',
  },
])

// ─── Readiness helper ─────────────────────────────────────────────────────────
function readinessInfo(item: HrPerson): { color: string; label: string; icon: string } {
  const days    = item.days_until_start
  const pending = item.pending_tasks ?? 0
  if (days === null)  return { color: 'default', label: 'No start date',   icon: 'tabler-calendar-x'     }
  if (days < 0)       return { color: 'default', label: 'Started',         icon: 'tabler-check'           }
  if (pending === 0)  return { color: 'success', label: 'Ready',           icon: 'tabler-circle-check'    }
  if (days <= 3)      return { color: 'error',   label: `${days}d · ${pending} task${pending > 1 ? 's' : ''}`, icon: 'tabler-alert-triangle' }
  if (days <= 7)      return { color: 'warning', label: `${days}d · ${pending} task${pending > 1 ? 's' : ''}`, icon: 'tabler-alert-circle'   }
  return               { color: 'info',    label: `${days}d · ${pending} pending`, icon: 'tabler-clock' }
}

// ─── Table ────────────────────────────────────────────────────────────────────
const headers = [
  { title: 'New Hire',          key: 'candidate',       sortable: false },
  { title: 'Status',            key: 'status',          sortable: false, width: '140px' },
  { title: 'Day-1 Readiness',   key: 'readiness',       sortable: false, width: '175px' },
  { title: 'Pre-Boarding Plan', key: 'active_plan',     sortable: false, width: '190px' },
  { title: 'Start Date',        key: 'started_on',      sortable: false, width: '130px' },
  { title: 'Employee #',        key: 'employee_number', sortable: false, width: '130px' },
]

const rows  = computed(() => props.persons?.data  ?? [])
const links = computed(() => props.persons?.links ?? [])
const meta  = computed(() => props.persons?.meta  ?? {})

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function goToPage(url: string | null) {
  if (url) router.visit(url, { preserveState: true })
}

// ─── Quick filter chips ───────────────────────────────────────────────────────
const quickFilters = [
  { label: 'All',          value: '' },
  { label: 'Pre-Boarding', value: 'pre_boarding', color: 'warning' },
  { label: 'Onboarding',   value: 'onboarding',   color: 'info'    },
  { label: 'Active',       value: 'active',       color: 'success' },
  { label: 'Terminated',   value: 'terminated',   color: 'error'   },
]

const { t } = useI18n()
</script>

<template>
  <div class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">
          {{ t('preboarding.title') }}
        </h4>
        <p class="text-medium-emphasis mb-0">
          {{ t('preboarding.subtitle') }}
        </p>
      </div>
    </div>

    <!-- Stat Cards -->
    <VRow class="mb-6">
      <VCol
        v-for="card in statCards"
        :key="card.label"
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-4 pa-5">
            <div
              class="d-flex align-center justify-center rounded-lg flex-shrink-0"
              :style="{ background: card.bg, width: '52px', height: '52px' }"
            >
              <VIcon :icon="card.icon" :color="card.color" size="28" />
            </div>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-1">{{ card.label }}</p>
              <p
                class="text-h4 font-weight-bold mb-0"
                :style="{ color: `var(--v-theme-${card.color})` }"
              >
                {{ card.value }}
              </p>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Quick Filter Chips -->
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip
        v-for="qf in quickFilters"
        :key="qf.value"
        size="default"
        variant="tonal"
        :color="status === qf.value ? (qf.color ?? 'primary') : 'default'"
        class="cursor-pointer font-weight-medium"
        @click="status = qf.value"
      >
        {{ qf.label }}
      </VChip>
    </div>

    <!-- Filter Bar -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" md="5">
            <AppTextField
              v-model="search"
              :label="t('preboarding.search_placeholder')"
              prepend-inner-icon="tabler-search"
              clearable
              hide-details
            />
          </VCol>
          <VCol cols="auto" class="ms-auto">
            <VBtn
              v-if="search || status"
              variant="text"
              color="default"
              prepend-icon="tabler-x"
              @click="clearFilters"
            >
              {{ t('preboarding.clear') }}
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard elevation="0" border>
      <VDataTable
        :headers="headers"
        :items="rows"
        item-value="id"
        hide-default-footer
        :items-per-page="20"
        hover
        @click:row="(_: unknown, row: any) => router.visit(route('pre-boarding.show', row.item.id))"
      >
        <!-- New Hire -->
        <template #item.candidate="{ item }">
          <div class="d-flex align-center gap-2">
            <VAvatar size="36" color="primary" variant="tonal">
              <span class="text-caption font-weight-bold">
                {{ (item.candidate?.full_name ?? '?')[0].toUpperCase() }}
              </span>
            </VAvatar>
            <div>
              <p class="text-body-2 font-weight-semibold mb-0">
                {{ item.candidate?.full_name ?? 'Unknown' }}
              </p>
              <p class="text-caption text-medium-emphasis mb-0">
                {{ item.candidate?.email ?? '' }}
              </p>
            </div>
          </div>
        </template>

        <!-- Status -->
        <template #item.status="{ item }">
          <VChip :color="item.status.color" size="small" label variant="tonal" class="font-weight-medium">
            {{ item.status.label }}
          </VChip>
        </template>

        <!-- Day-1 Readiness -->
        <template #item.readiness="{ item }">
          <div v-if="item.active_plan">
            <VChip
              :color="readinessInfo(item).color"
              size="small"
              label
              variant="tonal"
              class="font-weight-medium"
            >
              <VIcon :icon="readinessInfo(item).icon" size="14" start />
              {{ readinessInfo(item).label }}
            </VChip>
          </div>
          <span v-else class="text-body-2 text-medium-emphasis">—</span>
        </template>

        <!-- Pre-Boarding Plan -->
        <template #item.active_plan="{ item }">
          <div v-if="item.active_plan">
            <VChip :color="item.active_plan.status.color" size="small" label variant="tonal" class="font-weight-medium mb-1">
              {{ item.active_plan.status.label }}
            </VChip>
            <p v-if="item.active_plan.due_date" class="text-caption text-medium-emphasis mb-0">
              Due {{ formatDate(item.active_plan.due_date) }}
            </p>
          </div>
          <span v-else class="text-body-2 text-medium-emphasis">—</span>
        </template>

        <!-- Start Date -->
        <template #item.started_on="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ formatDate(item.started_on) }}</span>
        </template>

        <!-- Employee # -->
        <template #item.employee_number="{ item }">
          <span class="text-body-2 font-weight-medium">{{ item.employee_number ?? '—' }}</span>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center py-12">
            <VIcon icon="tabler-user-clock" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('preboarding.no_persons') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-0">
              {{ t('preboarding.no_persons_sub') }}
            </p>
          </div>
        </template>
      </VDataTable>

      <!-- Pagination -->
      <VDivider v-if="meta.last_page > 1" />
      <div v-if="meta.last_page > 1" class="d-flex align-center justify-space-between pa-4">
        <p class="text-body-2 text-medium-emphasis mb-0">
          Showing {{ meta.from ?? 0 }} – {{ meta.to ?? 0 }} of {{ meta.total }} new hires
        </p>
        <div class="d-flex gap-1">
          <VBtn
            v-for="link in links"
            :key="link.label"
            :disabled="!link.url"
            :variant="link.active ? 'flat' : 'text'"
            :color="link.active ? 'primary' : 'default'"
            size="small"
            @click="goToPage(link.url)"
            v-html="link.label"
          />
        </div>
      </div>
    </VCard>
  </div>
</template>
