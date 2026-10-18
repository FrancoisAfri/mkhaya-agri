<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

/**
 * The subcontractor invoice queue.
 *
 * Opens on what is waiting for a decision, because that is the daily job — a register of
 * everything ever submitted is something you go looking for, not something you should have to
 * filter away before you can work.
 *
 * Money only appears for viewers holding pm.costs.view; the server omits it entirely rather than
 * this page hiding it, so a site manager can work the queue without learning the margin.
 */
const props = defineProps<{
  invoices: Array<Record<string, any>>
  filters: { status: string; project: string | null }
  statuses: string[]
  projects: Array<{ id: string; label: string }>
  costs: boolean
}>()

const status = ref(props.filters.status)
const project = ref(props.filters.project)

let debounce: ReturnType<typeof setTimeout>
watch([status, project], () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    router.get(route('project-management.contractors.index'), {
      status: status.value || undefined,
      project: project.value || undefined,
    }, { preserveState: true, replace: true })
  }, 250)
})

function money(amount: string | null | undefined, currency = 'ZAR') {
  if (amount === null || amount === undefined || amount === '') return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(Number(amount))
  } catch {
    return String(amount)
  }
}

const STATUS_COLOUR: Record<string, string> = {
  draft: 'grey', submitted: 'warning', approved: 'success', rejected: 'error',
}

// ── Approving, with the markup for this one invoice ──────────────────────────
const approveDialog = ref(false)
const active = ref<Record<string, any> | null>(null)
const approveForm = useForm({ markup_rate: '' })

function openApprove(invoice: Record<string, any>) {
  active.value = invoice
  // Whatever markup was already on it, as a percentage. Usually nothing.
  approveForm.markup_rate = invoice.markup_rate ? String(Number(invoice.markup_rate) * 100) : ''
  approveDialog.value = true
}

function approve() {
  if (!active.value) return
  router.post(
    route('project-management.contractors.invoices.approve', active.value.id),
    { markup_rate: approveForm.markup_rate === '' ? '' : Number(approveForm.markup_rate) / 100 },
    { preserveScroll: true, onSuccess: () => { approveDialog.value = false; active.value = null } },
  )
}

// ── Rejecting, which requires a reason the contractor can act on ─────────────
const rejectDialog = ref(false)
const rejectForm = useForm({ reason: '' })

function openReject(invoice: Record<string, any>) {
  active.value = invoice
  rejectForm.reset()
  rejectDialog.value = true
}

function reject() {
  if (!active.value) return
  rejectForm.post(route('project-management.contractors.invoices.reject', active.value.id), {
    preserveScroll: true,
    onSuccess: () => { rejectDialog.value = false; active.value = null },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('project_management.contractors.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ t('project_management.contractors.subtitle') }}
      </p>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VSelect
          v-model="status"
          :items="props.statuses.map(s => ({ title: t('project_management.contractors.status_' + s), value: s }))"
          :label="t('project_management.contractors.filter_status')"
          density="compact" variant="outlined" hide-details clearable style="max-width: 260px"
        />
        <VAutocomplete
          v-model="project" :items="props.projects.map(p => ({ title: p.label, value: p.id }))"
          :label="t('project_management.contractors.filter_project')"
          density="compact" variant="outlined" hide-details clearable style="max-width: 340px"
        />
      </VCardText>
    </VCard>

    <VCard border>
      <div class="table-scroll">
        <VTable v-if="props.invoices.length" density="comfortable">
          <thead>
            <tr>
              <th>{{ t('project_management.contractors.col_contractor') }}</th>
              <th>{{ t('project_management.contractors.col_project') }}</th>
              <th class="text-no-wrap">{{ t('project_management.contractors.col_reference') }}</th>
              <th class="text-no-wrap">{{ t('project_management.contractors.col_period') }}</th>
              <th v-if="props.costs" class="text-end text-no-wrap">
                {{ t('project_management.contractors.col_total') }}
              </th>
              <th v-if="props.costs" class="text-end text-no-wrap">
                {{ t('project_management.contractors.col_billable') }}
              </th>
              <th class="text-no-wrap">{{ t('project_management.contractors.col_status') }}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="invoice in props.invoices" :key="invoice.id">
              <td>
                <div>{{ invoice.contractor ?? '—' }}</div>
                <div v-if="invoice.description" class="text-caption text-medium-emphasis text-truncate" style="max-inline-size: 22rem">
                  {{ invoice.description }}
                </div>
              </td>
              <td>
                <div>{{ invoice.project ?? '—' }}</div>
                <div class="text-caption text-medium-emphasis">{{ invoice.project_code }}</div>
              </td>
              <td class="text-no-wrap">{{ invoice.reference ?? '—' }}</td>
              <td class="text-no-wrap">{{ invoice.period_end ?? invoice.invoice_date ?? '—' }}</td>
              <td v-if="props.costs" class="text-end figure">{{ money(invoice.total, invoice.currency) }}</td>
              <td v-if="props.costs" class="text-end figure">
                {{ money(invoice.billable_total, invoice.currency) }}
                <VChip v-if="invoice.markup_rate" size="x-small" variant="tonal" color="info" class="ms-1">
                  +{{ (Number(invoice.markup_rate) * 100).toFixed(1) }}%
                </VChip>
              </td>
              <td>
                <VChip :color="STATUS_COLOUR[invoice.status]" size="small" variant="tonal">
                  {{ t('project_management.contractors.status_' + invoice.status) }}
                </VChip>
              </td>
              <td class="text-end text-no-wrap">
                <VBtn
                  v-if="invoice.has_document" icon="tabler-paperclip" size="x-small" variant="text"
                  :href="route('project-management.contractors.invoices.document', invoice.id)"
                  :title="t('project_management.contractors.their_document')"
                />
                <template v-if="props.costs && invoice.status === 'submitted'">
                  <VBtn size="x-small" variant="tonal" color="success" class="ms-1" @click="openApprove(invoice)">
                    {{ t('project_management.contractors.approve') }}
                  </VBtn>
                  <VBtn size="x-small" variant="text" color="error" class="ms-1" @click="openReject(invoice)">
                    {{ t('project_management.contractors.reject') }}
                  </VBtn>
                </template>
              </td>
            </tr>
          </tbody>
        </VTable>
        <VCardText v-else class="text-center text-medium-emphasis py-8">
          {{ t('project_management.contractors.none') }}
        </VCardText>
      </div>
    </VCard>

    <!-- ── Approve ────────────────────────────────────────────────────────── -->
    <VDialog v-model="approveDialog" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.contractors.approve_title') }}</VCardTitle>
          <VCardSubtitle v-if="active">
            {{ active.contractor }} · {{ money(active.total, active.currency) }}
          </VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VTextField
            v-model="approveForm.markup_rate" inputmode="decimal" suffix="%"
            :label="t('project_management.contractors.markup')"
            :hint="t('project_management.contractors.markup_hint')" persistent-hint
            variant="outlined" density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="approveDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="success" @click="approve">{{ t('project_management.contractors.approve') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Reject ─────────────────────────────────────────────────────────── -->
    <VDialog v-model="rejectDialog" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.contractors.reject_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.contractors.reject_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VTextarea
            v-model="rejectForm.reason" rows="3" auto-grow
            :label="t('project_management.contractors.reject_reason')"
            :error-messages="rejectForm.errors.reason"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="rejectDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" @click="reject">
            {{ t('project_management.contractors.reject') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.figure { font-variant-numeric: tabular-nums; }
.table-scroll { overflow-x: auto; }
</style>
