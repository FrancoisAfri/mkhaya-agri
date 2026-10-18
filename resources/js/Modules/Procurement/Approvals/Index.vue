<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
type Pending = {
  id: number
  request_id: number
  request_number: string
  requester_id: number
  amount: number | null
  currency: string | null
  current_step: number
  total_steps: number
  step_name: string | null
  step_role: string | null
}

// ── Props ──────────────────────────────────────────────────────────────────────
defineProps<{ pending: Pending[] }>()

// ── State ──────────────────────────────────────────────────────────────────────
const dialog = ref(false)
const mode = ref<'approve' | 'reject' | 'escalate'>('approve')
const active = ref<Pending | null>(null)
const note = ref('')

// ── Constants ──────────────────────────────────────────────────────────────────
const titleMap = {
  approve: t('procurement_approvals.dialogs.approve.title'),
  reject: t('procurement_approvals.dialogs.reject.title'),
  escalate: t('procurement_approvals.dialogs.escalate.title'),
}

const confirmButtonMap = {
  approve: t('procurement_approvals.buttons.confirm_approve'),
  reject: t('procurement_approvals.buttons.confirm_reject'),
  escalate: t('procurement_approvals.buttons.confirm_escalate'),
}

const buttonColorMap = {
  approve: 'success',
  reject: 'error',
  escalate: 'warning',
}

// ── Helpers ────────────────────────────────────────────────────────────────────
function formatCurrency(value: number | null, currency: string | null): string {
  if (value == null) return '—'
  try {
    return new Intl.NumberFormat('en-ZA', {
      style: 'currency',
      currency: currency || 'ZAR',
      maximumFractionDigits: 0,
    }).format(value)
  } catch {
    return `${currency || ''} ${value}`.trim()
  }
}

// ── Methods ────────────────────────────────────────────────────────────────────
function open(p: Pending, m: 'approve' | 'reject' | 'escalate'): void {
  active.value = p
  mode.value = m
  note.value = ''
  dialog.value = true
}

function submit(): void {
  if (!active.value) return
  const id = active.value.id

  if (mode.value === 'escalate') {
    router.post(
      route('procurement.approvals.escalate', id),
      { reason: note.value },
      {
        preserveScroll: true,
        onSuccess: () => (dialog.value = false),
      }
    )
    return
  }

  const name = mode.value === 'approve'
    ? 'procurement.approvals.approve'
    : 'procurement.approvals.reject'

  router.post(
    route(name, id),
    { comment: note.value },
    {
      preserveScroll: true,
      onSuccess: () => (dialog.value = false),
    }
  )
}

// Addressed by uuid, not the row id.
function goToRequest(requestUuid: string): void {
  router.get(route('purchase-requests.show', requestUuid))
}

function getStepLabel(p: Pending): string {
  return `${p.current_step}/${p.total_steps} · ${p.step_name ?? '—'}`
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center gap-3 mb-4">
      <VAvatar color="warning" variant="tonal" size="48" rounded>
        <VIcon icon="tabler-checkbox" size="26" />
      </VAvatar>
      <div>
        <h4 class="text-h4 mb-0">{{ t('procurement_approvals.page_title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">
          {{ t('procurement_approvals.page_description') }}
        </p>
      </div>
    </div>

    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('procurement_approvals.table.request') }}</th>
            <th class="text-right">{{ t('procurement_approvals.table.amount') }}</th>
            <th>{{ t('procurement_approvals.table.step') }}</th>
            <th class="text-right">{{ t('procurement_approvals.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!pending.length">
            <td colspan="4" class="text-center text-medium-emphasis py-8">
              {{ t('procurement_approvals.nothing_pending') }}
            </td>
          </tr>
          <tr v-for="p in pending" :key="p.id">
            <td>
              <a
                class="font-weight-medium text-primary"
                style="cursor:pointer"
                @click="goToRequest(p.request_id)"
              >
                {{ p.request_number }}
              </a>
            </td>
            <td class="text-right">{{ formatCurrency(p.amount, p.currency) }}</td>
            <td>
              {{ getStepLabel(p) }}
              <span v-if="p.step_role" class="text-medium-emphasis">
                ({{ p.step_role }})
              </span>
            </td>
            <td class="text-right">
              <VBtn
                size="small"
                variant="tonal"
                color="success"
                class="me-2"
                @click="open(p, 'approve')"
              >
                {{ t('procurement_approvals.actions.approve') }}
              </VBtn>
              <VBtn
                size="small"
                variant="tonal"
                color="error"
                class="me-2"
                @click="open(p, 'reject')"
              >
                {{ t('procurement_approvals.actions.reject') }}
              </VBtn>
              <VBtn
                size="small"
                variant="tonal"
                color="warning"
                @click="open(p, 'escalate')"
              >
                {{ t('procurement_approvals.actions.escalate') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Dialog -->
    <VDialog v-model="dialog" max-width="480">
      <VCard>
        <VCardTitle>{{ titleMap[mode] }}</VCardTitle>
        <VCardText>
          <div v-if="active" class="text-body-2 text-medium-emphasis mb-3">
            {{ active.request_number }}
          </div>
          <VTextarea
            v-model="note"
            :label="mode === 'escalate'
              ? t('procurement_approvals.dialogs.escalate.reason_label')
              : t('procurement_approvals.dialogs.approve.comment_label')"
            variant="outlined"
            rows="3"
          />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">
            {{ t('procurement_approvals.buttons.cancel') }}
          </VBtn>
          <VBtn
            :color="buttonColorMap[mode]"
            @click="submit"
          >
            {{ confirmButtonMap[mode] }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
