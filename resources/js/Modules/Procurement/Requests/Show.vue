<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
type Item = {
  name: string
  item_type: string
  quantity: number
  unit_price: number
  line_total: number
}

type Attachment = {
  id: number
  file_name: string
}

type History = {
  action: string
  from_status: string | null
  to_status: string | null
  note: string | null
  created_at: string | null
  performed_by: string | null
}

type Step = {
  step: number
  name: string
  role: string | null
  status: string
  comment: string | null
  acted_at: string | null
}

type Approval = {
  status: string
  current_step: number
  total_steps: number
  steps: Step[]
}

type Violation = {
  id: number
  rule_type: string
  severity: string
  message: string
  status: string
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  request: {
    id: number
    request_number: string
    status: string
    urgency: string
    is_emergency: boolean
    justification: string | null
    department: string | null
    project_code: string | null
    delivery_location: string | null
    needed_by: string | null
    currency: string | null
    estimated_total: number | null
    reserved_amount: number | null
    budget: { code: string; name: string } | null
    items: Item[]
    attachments: Attachment[]
    history: History[]
    approval: Approval | null
  }
  violations: Violation[]
  statuses: string[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null)
const file = ref<File | null>(null)
const uploading = ref(false)

// ── Constants ──────────────────────────────────────────────────────────────────
const TERMINAL = ['approved', 'rejected', 'cancelled', 'converted']

const statusColor: Record<string, string> = {
  draft: 'grey',
  pending_approval: 'warning',
  validation_failed: 'error',
  approved: 'success',
  rejected: 'error',
  cancelled: 'grey',
  converted: 'info',
}

const stepColor: Record<string, string> = {
  pending: 'grey',
  current: 'warning',
  approved: 'success',
  rejected: 'error',
  skipped: 'grey',
}

// ── Helpers ────────────────────────────────────────────────────────────────────
function getStatusLabel(status: string): string {
  const map: Record<string, string> = {
    draft: t('purchase_request_show.statuses.draft'),
    pending_approval: t('purchase_request_show.statuses.pending_approval'),
    validation_failed: t('purchase_request_show.statuses.validation_failed'),
    approved: t('purchase_request_show.statuses.approved'),
    rejected: t('purchase_request_show.statuses.rejected'),
    cancelled: t('purchase_request_show.statuses.cancelled'),
    converted: t('purchase_request_show.statuses.converted'),
  }
  return map[status] || status
}

function getStepStatusLabel(status: string): string {
  const map: Record<string, string> = {
    pending: t('purchase_request_show.step_statuses.pending'),
    current: t('purchase_request_show.step_statuses.current'),
    approved: t('purchase_request_show.step_statuses.approved'),
    rejected: t('purchase_request_show.step_statuses.rejected'),
    skipped: t('purchase_request_show.step_statuses.skipped'),
  }
  return map[status] || status
}

function getUrgencyLabel(urgency: string): string {
  const map: Record<string, string> = {
    low: t('purchase_request_show.urgency_levels.low'),
    normal: t('purchase_request_show.urgency_levels.normal'),
    high: t('purchase_request_show.urgency_levels.high'),
    emergency: t('purchase_request_show.urgency_levels.emergency'),
  }
  return map[urgency] || urgency
}

function formatCurrency(value: number | null): string {
  if (value == null) return '—'
  try {
    return new Intl.NumberFormat('en-ZA', {
      style: 'currency',
      currency: props.request.currency || 'ZAR',
      maximumFractionDigits: 0,
    }).format(value)
  } catch {
    return `${props.request.currency || ''} ${value}`.trim()
  }
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

// ── Computed ──────────────────────────────────────────────────────────────────
const canSubmit = computed(() =>
  ['draft', 'validation_failed'].includes(props.request.status)
)

const canCancel = computed(() =>
  !TERMINAL.includes(props.request.status)
)

const blockers = computed(() =>
  props.violations.filter(v => v.severity === 'block')
)

const warnings = computed(() =>
  props.violations.filter(v => v.severity !== 'block')
)

// ── Methods ────────────────────────────────────────────────────────────────────
function goToIndex(): void {
  router.get(route('purchase-requests.index'))
}

function submitRequest(): void {
  router.post(route('purchase-requests.submit', props.request.uuid), {}, { preserveScroll: true })
}

function convertToRequisition(): void {
  router.post(route('purchase-requests.convert', props.request.uuid), {}, { preserveScroll: true })
}

function cancelRequest(): void {
  confirmDialog.value?.open(
    {
      title: t('purchase_request_show.confirm.cancel_title'),
      message: t('purchase_request_show.confirm.cancel_message'),
      confirmText: t('purchase_request_show.confirm.cancel_confirm'),
      confirmColor: 'warning',
      icon: 'tabler-alert-triangle',
    },
    () => {
      router.post(route('purchase-requests.cancel', props.request.uuid), {}, { preserveScroll: true })
    }
  )
}

function downloadAttachment(a: Attachment): void {
  window.open(route('purchase-requests.attachments.download', a.id))
}

function uploadAttachment(): void {
  if (!file.value) return
  uploading.value = true
  router.post(
    route('purchase-requests.attachments.store', props.request.uuid),
    { file: file.value },
    {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => (file.value = null),
      onFinish: () => (uploading.value = false),
    }
  )
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VBtn icon="tabler-arrow-left" variant="text" @click="goToIndex" />
        <div>
          <h4 class="text-h4 mb-0">{{ request.request_number }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('purchase_request_show.page_title') }}</p>
        </div>
        <VChip :color="statusColor[request.status] || 'grey'" variant="tonal">
          {{ getStatusLabel(request.status) }}
        </VChip>
        <VChip
          v-if="request.is_emergency"
          color="error"
          variant="flat"
          prepend-icon="tabler-alert-triangle"
        >
          {{ t('purchase_request_show.emergency') }}
        </VChip>
      </div>
      <div class="d-flex gap-2">
        <VBtn
          v-if="canSubmit"
          color="primary"
          prepend-icon="tabler-send"
          @click="submitRequest"
        >
          {{ t('purchase_request_show.submit') }}
        </VBtn>
        <VBtn
          v-if="request.status === 'approved'"
          color="success"
          prepend-icon="tabler-clipboard-check"
          @click="convertToRequisition"
        >
          {{ t('purchase_request_show.convert_to_requisition') }}
        </VBtn>
        <VBtn
          v-if="canCancel"
          color="error"
          variant="tonal"
          prepend-icon="tabler-x"
          @click="cancelRequest"
        >
          {{ t('purchase_request_show.cancel') }}
        </VBtn>
      </div>
    </div>

    <!-- Violations -->
    <VAlert
      v-if="blockers.length"
      type="error"
      variant="tonal"
      class="mb-4"
      icon="tabler-shield-x"
    >
      <div class="font-weight-medium mb-1">
        {{ t('purchase_request_show.blocking_violations') }}
      </div>
      <ul class="ms-4">
        <li v-for="v in blockers" :key="v.id">
          {{ v.rule_type }}: {{ v.message }}
        </li>
      </ul>
    </VAlert>

    <VAlert
      v-if="warnings.length"
      type="warning"
      variant="tonal"
      class="mb-4"
      icon="tabler-alert-triangle"
    >
      <div class="font-weight-medium mb-1">
        {{ t('purchase_request_show.warnings') }}
      </div>
      <ul class="ms-4">
        <li v-for="v in warnings" :key="v.id">
          {{ v.rule_type }}: {{ v.message }}
        </li>
      </ul>
    </VAlert>

    <VRow>
      <!-- ── Left Column ────────────────────────────────────────────────── -->
      <VCol cols="12" md="7">
        <!-- Details -->
        <VCard border class="mb-4">
          <VCardTitle>{{ t('purchase_request_show.details') }}</VCardTitle>
          <VCardText>
            <VRow>
              <VCol cols="6">
                <div class="text-caption text-medium-emphasis">{{ t('purchase_request_show.department') }}</div>
                {{ request.department ?? '—' }}
              </VCol>
              <VCol cols="6">
                <div class="text-caption text-medium-emphasis">{{ t('purchase_request_show.project') }}</div>
                {{ request.project_code ?? '—' }}
              </VCol>
              <VCol cols="6">
                <div class="text-caption text-medium-emphasis">{{ t('purchase_request_show.urgency') }}</div>
                {{ getUrgencyLabel(request.urgency) }}
              </VCol>
              <VCol cols="6">
                <div class="text-caption text-medium-emphasis">{{ t('purchase_request_show.budget') }}</div>
                {{ request.budget ? `${request.budget.code} — ${request.budget.name}` : '—' }}
              </VCol>
              <VCol cols="6">
                <div class="text-caption text-medium-emphasis">{{ t('purchase_request_show.needed_by') }}</div>
                {{ formatDate(request.needed_by) }}
              </VCol>
              <VCol cols="6">
                <div class="text-caption text-medium-emphasis">{{ t('purchase_request_show.delivery_location') }}</div>
                {{ request.delivery_location ?? '—' }}
              </VCol>
              <VCol cols="6">
                <div class="text-caption text-medium-emphasis">{{ t('purchase_request_show.estimated_total') }}</div>
                {{ formatCurrency(request.estimated_total) }}
              </VCol>
              <VCol cols="6">
                <div class="text-caption text-medium-emphasis">{{ t('purchase_request_show.reserved_amount') }}</div>
                {{ formatCurrency(request.reserved_amount) }}
              </VCol>
              <VCol cols="12">
                <div class="text-caption text-medium-emphasis">{{ t('purchase_request_show.justification') }}</div>
                {{ request.justification ?? '—' }}
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <!-- Items -->
        <VCard border class="mb-4">
          <VCardTitle>{{ t('purchase_request_show.items') }}</VCardTitle>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('purchase_request_show.name') }}</th>
                <th>{{ t('purchase_request_show.type') }}</th>
                <th class="text-right">{{ t('purchase_request_show.quantity') }}</th>
                <th class="text-right">{{ t('purchase_request_show.unit_price') }}</th>
                <th class="text-right">{{ t('purchase_request_show.line_total') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!request.items.length">
                <td colspan="5" class="text-center text-medium-emphasis py-4">
                  {{ t('purchase_request_show.no_items') }}
                </td>
              </tr>
              <tr v-for="(it, i) in request.items" :key="i">
                <td class="font-weight-medium">{{ it.name }}</td>
                <td>{{ it.item_type }}</td>
                <td class="text-right">{{ it.quantity }}</td>
                <td class="text-right">{{ formatCurrency(it.unit_price) }}</td>
                <td class="text-right">{{ formatCurrency(it.line_total) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>

        <!-- Approval -->
        <VCard v-if="request.approval" border class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between">
            <span>{{ t('purchase_request_show.approval') }}</span>
            <span class="text-caption text-medium-emphasis">
              {{ t('purchase_request_show.step_count', {
                current: request.approval.current_step,
                total: request.approval.total_steps,
                status: getStatusLabel(request.approval.status)
              }) }}
            </span>
          </VCardTitle>
          <VCardText>
            <VTimeline side="end" density="compact" truncate-line="both">
              <VTimelineItem
                v-for="s in request.approval.steps"
                :key="s.step"
                size="x-small"
                :dot-color="stepColor[s.status] || 'grey'"
              >
                <div class="d-flex align-center gap-2">
                  <span class="font-weight-medium">{{ s.step }}. {{ s.name }}</span>
                  <VChip
                    size="x-small"
                    :color="stepColor[s.status] || 'grey'"
                    variant="tonal"
                  >
                    {{ getStepStatusLabel(s.status) }}
                  </VChip>
                </div>
                <div v-if="s.role" class="text-caption text-medium-emphasis">
                  {{ t('purchase_request_show.role') }}: {{ s.role }}
                </div>
                <div v-if="s.comment" class="text-caption">{{ s.comment }}</div>
                <div v-if="s.acted_at" class="text-caption text-medium-emphasis">
                  {{ formatDate(s.acted_at) }}
                </div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ── Right Column ────────────────────────────────────────────────── -->
      <VCol cols="12" md="5">
        <!-- Attachments -->
        <VCard border class="mb-4">
          <VCardTitle>{{ t('purchase_request_show.attachments') }}</VCardTitle>
          <VCardText>
            <VList v-if="request.attachments.length" density="compact">
              <VListItem
                v-for="a in request.attachments"
                :key="a.id"
                :title="a.file_name"
                prepend-icon="tabler-paperclip"
              >
                <template #append>
                  <VBtn
                    icon="tabler-download"
                    size="small"
                    variant="text"
                    @click="downloadAttachment(a)"
                  />
                </template>
              </VListItem>
            </VList>
            <div v-else class="text-medium-emphasis mb-3">
              {{ t('purchase_request_show.no_attachments') }}
            </div>
            <VFileInput
              v-model="file"
              :label="t('purchase_request_show.upload_attachment')"
              variant="outlined"
              density="compact"
              hide-details
              prepend-icon="tabler-paperclip"
              class="mb-2"
              @update:model-value="(f: any) => file = Array.isArray(f) ? f[0] : f"
            />
            <VBtn
              color="primary"
              block
              :loading="uploading"
              :disabled="!file"
              @click="uploadAttachment"
            >
              {{ t('purchase_request_show.upload') }}
            </VBtn>
          </VCardText>
        </VCard>

        <!-- History -->
        <VCard border>
          <VCardTitle>{{ t('purchase_request_show.history') }}</VCardTitle>
          <VCardText>
            <div v-if="!request.history.length" class="text-medium-emphasis">
              {{ t('purchase_request_show.no_history') }}
            </div>
            <VTimeline v-else side="end" density="compact" truncate-line="both">
              <VTimelineItem
                v-for="(h, i) in request.history"
                :key="i"
                size="x-small"
                :dot-color="statusColor[h.to_status ?? ''] || 'primary'"
              >
                <div class="font-weight-medium">{{ h.action }}</div>
                <div v-if="h.from_status || h.to_status" class="text-caption">
                  {{ h.from_status ? h.from_status + ' → ' : '' }}{{ h.to_status }}
                </div>
                <div v-if="h.note" class="text-caption">{{ h.note }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ h.performed_by ?? '' }}
                  <span v-if="h.performed_by && h.created_at"> · </span>
                  {{ formatDate(h.created_at) }}
                </div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
