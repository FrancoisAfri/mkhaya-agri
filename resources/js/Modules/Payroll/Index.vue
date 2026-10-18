<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

// ─── Types ────────────────────────────────────────────────────────────────────
type PayrollRun = {
  id: number
  uuid: string
  name: string
  period_start: string
  period_end: string
  status: 'draft' | 'processing' | 'review' | 'released' | 'cancelled'
  total_gross: string | null
  total_deductions: string | null
  total_net: string | null
  employee_count: number
  ai_risk_level: 'low' | 'medium' | 'high' | 'critical' | null
  released_at: string | null
  created_at: string
}

const props = defineProps<{
  runs: {
    data: PayrollRun[]
    links: any[]
    meta: any
  }
  filters: { status?: string }
  stats: {
    total_runs: number
    total_released: number
    current_month_net: number
  }
}>()

// ─── Helpers ──────────────────────────────────────────────────────────────────
function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatCurrency(val: string | number | null) {
  if (val == null) return '—'
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(Number(val))
}

function statusColor(status: PayrollRun['status']): string {
  const map: Record<PayrollRun['status'], string> = {
    draft:      'secondary',
    processing: 'info',
    review:     'warning',
    released:   'success',
    cancelled:  'error',
  }
  return map[status] ?? 'default'
}

function riskColor(level: PayrollRun['ai_risk_level']): string {
  if (level === 'low')      return 'success'
  if (level === 'medium')   return 'warning'
  if (level === 'high')     return 'error'
  if (level === 'critical') return 'error'
  return 'default'
}

function riskIcon(level: PayrollRun['ai_risk_level']): string {
  if (level === 'high' || level === 'critical') return 'tabler-alert-triangle'
  if (level === 'medium') return 'tabler-alert-triangle'
  return 'tabler-shield-check'
}

// ─── Stat cards ───────────────────────────────────────────────────────────────
const statCards = computed(() => [
  {
    label: 'Total Runs',
    value: props.stats.total_runs,
    icon:  'tabler-player-play',
    color: 'primary',
    bg:    'rgba(115,103,240,0.12)',
    formatted: false,
  },
  {
    label: 'Released Runs',
    value: props.stats.total_released,
    icon:  'tabler-rosette-discount-check',
    color: 'success',
    bg:    'rgba(40,199,111,0.12)',
    formatted: false,
  },
  {
    label: 'Current Month Net Pay',
    value: formatCurrency(props.stats.current_month_net),
    icon:  'tabler-cash',
    color: 'info',
    bg:    'rgba(0,207,232,0.12)',
    formatted: true,
  },
])

// ─── Filters ──────────────────────────────────────────────────────────────────
const statusFilter = ref<string>(props.filters?.status ?? '')

const statusItems = [
  { title: 'All Statuses', value: '' },
  { title: 'Draft',        value: 'draft' },
  { title: 'Processing',   value: 'processing' },
  { title: 'Review',       value: 'review' },
  { title: 'Released',     value: 'released' },
  { title: 'Cancelled',    value: 'cancelled' },
]

watch(statusFilter, (val) => {
  router.get(
    route('payroll.runs.index'),
    { status: val || undefined },
    { preserveState: true, replace: true },
  )
})

// ─── Table ────────────────────────────────────────────────────────────────────
const rows  = computed(() => props.runs?.data  ?? [])
const links = computed(() => props.runs?.links ?? [])
const meta  = computed(() => props.runs?.meta  ?? {})

function goToPage(url: string | null) {
  if (url) router.visit(url, { preserveState: true })
}

// ─── New Run dialog ───────────────────────────────────────────────────────────
const newRunDialog = ref(false)

const form = useForm({
  name:         '',
  period_start: '',
  period_end:   '',
  notes:        '',
})

function submitRun() {
  form.post(route('payroll.runs.store'), {
    onSuccess: () => {
      newRunDialog.value = false
      form.reset()
    },
  })
}

watch(newRunDialog, (open) => {
  if (!open) form.reset()
})
</script>

<template>
  <div class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">Payroll Runse</h4>
        <p class="text-medium-emphasis mb-0">Manage and process payroll for each pay period</p>
      </div>
      <VBtn
        color="primary"
        prepend-icon="tabler-plus"
        @click="newRunDialog = true"
      >
        New Payroll Run
      </VBtn>
    </div>

    <!-- Stat Cards -->
    <VRow class="mb-6">
      <VCol
        v-for="card in statCards"
        :key="card.label"
        cols="12"
        sm="4"
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
                class="font-weight-bold mb-0"
                :class="card.formatted ? 'text-h6' : 'text-h4'"
                :style="{ color: `var(--v-theme-${card.color})` }"
              >
                {{ card.value }}
              </p>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filter Row -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4" md="3">
            <VSelect
              v-model="statusFilter"
              :items="statusItems"
              item-title="title"
              item-value="value"
              label="Status"
              hide-details
              density="comfortable"
              variant="outlined"
            />
          </VCol>
          <VCol cols="auto" class="ms-auto">
            <VBtn
              v-if="statusFilter"
              variant="text"
              color="default"
              prepend-icon="tabler-x"
              @click="statusFilter = ''"
            >
              Clear
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Payroll Runs Table -->
    <VCard elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th class="text-body-2 font-weight-semibold">Name</th>
            <th class="text-body-2 font-weight-semibold">Period</th>
            <th class="text-body-2 font-weight-semibold">Status</th>
            <th class="text-body-2 font-weight-semibold text-end">Employees</th>
            <th class="text-body-2 font-weight-semibold text-end">Net Pay</th>
            <th class="text-body-2 font-weight-semibold">AI Risk</th>
            <th class="text-body-2 font-weight-semibold text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!rows.length">
            <td colspan="7">
              <div class="text-center py-12">
                <VIcon icon="tabler-table" size="64" color="medium-emphasis" class="mb-4" />
                <p class="text-body-1 font-weight-medium mb-1">No payroll runs found</p>
                <p class="text-body-2 text-medium-emphasis mb-4">
                  Start by creating a new payroll run for the current period.
                </p>
                <VBtn color="primary" variant="tonal" prepend-icon="tabler-plus" @click="newRunDialog = true">
                  New Payroll Run
                </VBtn>
              </div>
            </td>
          </tr>
          <tr
            v-for="run in rows"
            :key="run.id"
            class="cursor-pointer"
            @click="router.visit(route('payroll.runs.show', run.uuid))"
          >
            <td>
              <p class="text-body-2 font-weight-semibold text-primary mb-0">{{ run.name }}</p>
              <p class="text-caption text-medium-emphasis mb-0">
                Created {{ formatDate(run.created_at) }}
              </p>
            </td>
            <td>
              <span class="text-body-2">
                {{ formatDate(run.period_start) }} – {{ formatDate(run.period_end) }}
              </span>
            </td>
            <td>
              <VChip
                :color="statusColor(run.status)"
                size="small"
                label
                variant="tonal"
                class="font-weight-medium text-capitalize"
              >
                {{ run.status }}
              </VChip>
            </td>
            <td class="text-end">
              <span class="text-body-2">{{ run.employee_count }}</span>
            </td>
            <td class="text-end">
              <span class="text-body-2 font-weight-medium">{{ formatCurrency(run.total_net) }}</span>
            </td>
            <td>
              <VChip
                v-if="run.ai_risk_level"
                :color="riskColor(run.ai_risk_level)"
                size="small"
                label
                variant="tonal"
                class="font-weight-medium text-capitalize"
              >
                <template #prepend>
                  <VIcon :icon="riskIcon(run.ai_risk_level)" size="14" class="me-1" />
                </template>
                {{ run.ai_risk_level }}
              </VChip>
              <span v-else class="text-body-2 text-medium-emphasis">—</span>
            </td>
            <td class="text-end" @click.stop>
              <VBtn
                size="small"
                color="primary"
                variant="tonal"
                prepend-icon="tabler-eye"
                @click="router.visit(route('payroll.runs.show', run.uuid))"
              >
                View
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>

      <!-- Pagination -->
      <VDivider v-if="meta.last_page > 1" />
      <div v-if="meta.last_page > 1" class="d-flex align-center justify-space-between pa-4">
        <p class="text-body-2 text-medium-emphasis mb-0">
          Showing {{ meta.from ?? 0 }} – {{ meta.to ?? 0 }} of {{ meta.total }} runs
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

    <!-- New Payroll Run Dialog -->
    <VDialog v-model="newRunDialog" max-width="520" persistent>
      <VCard elevation="0" border>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
          New Payroll Run
        </VCardTitle>
        <VCardText class="pa-5 pt-2 d-flex flex-column gap-4">
          <VTextField
            v-model="form.name"
            label="Run Name"
            placeholder="e.g. May 2026 Payroll"
            variant="outlined"
            density="comfortable"
            :error-messages="form.errors.name"
            hide-details="auto"
          />
          <VRow dense>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.period_start"
                label="Period Start"
                type="date"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.period_start"
                hide-details="auto"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.period_end"
                label="Period End"
                type="date"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.period_end"
                hide-details="auto"
              />
            </VCol>
          </VRow>
          <VTextarea
            v-model="form.notes"
            label="Notes (optional)"
            variant="outlined"
            density="comfortable"
            rows="3"
            :error-messages="form.errors.notes"
            hide-details="auto"
          />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2">
          <VSpacer />
          <VBtn variant="text" color="default" :disabled="form.processing" @click="newRunDialog = false">
            Cancel
          </VBtn>
          <VBtn
            color="primary"
            variant="flat"
            :loading="form.processing"
            :disabled="!form.name || !form.period_start || !form.period_end"
            @click="submitRun"
          >
            Create Run
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
