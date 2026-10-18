<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'

const { t } = useI18n()
const route = ziggyRoute
const { smAndDown } = useDisplay()

type Invoice = {
  id: string
  invoice_number: string
  account_number: number
  project: string | null
  project_code: string | null
  client_name: string | null
  invoice_date: string | null
  status: string
  currency: string
  amount_now_due: string
}

const props = defineProps<{
  invoices: { data: Invoice[]; meta: { current_page: number; last_page: number; total: number } }
  filters: { status?: string | null; project?: string | null; search?: string | null }
  statuses: string[]
  projects: Array<{ id: string; code: string; name: string }>
  can: { create: boolean }
  /** Set when the register was opened from a project rather than from the hub. */
  scopedTo?: { id: string; code: string; name: string } | null
  /**
   * What the client actually owes on this job, from the ledger.
   *
   * Only present on the project-scoped register — the hub view spans projects and a single
   * debtor figure across all of them would answer a question nobody asked. `available` is false
   * when no account here has reached Accounting, which is a different thing from owing nothing.
   */
  ledger?: {
    available: boolean
    currency: string | null
    invoiced: number
    paid: number
    outstanding: number
    overdue: number
    oldest_unpaid_days: number | null
    invoices: number
  } | null
}>()

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? null)
const project = ref(props.filters.project ?? null)

let debounce: ReturnType<typeof setTimeout>
watch([search, status, project], () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    router.get(
      route('project-management.invoicing.index'),
      { filter: { search: search.value || undefined, status: status.value || undefined, project: project.value || undefined } },
      { preserveState: true, replace: true },
    )
  }, 300)
})

/** Money arrives as a decimal string and is formatted for display only — never parsed for maths. */
function money(amount: string | null | undefined, currency: string | null | undefined) {
  if (amount === null || amount === undefined) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZAR' }).format(Number(amount))
  } catch {
    return `${currency ?? ''} ${amount}`.trim()
  }
}

const statusColour: Record<string, string> = {
  draft: 'grey',
  generated: 'primary',
  sent: 'success',
  cancelled: 'error',
}

// Opening an account needs a project; the rest carries forward from the previous account.
const dialog = ref(false)
const form = useForm({ project: props.scopedTo?.id ?? null as string | null, invoice_date: '', description: '' })

const chosenProject = computed(() => props.projects.find(p => p.id === form.project))

function submit() {
  if (!form.project) return
  form
    .transform(data => ({ invoice_date: data.invoice_date || null, description: data.description || null }))
    .post(route('project-management.invoicing.store', form.project), {
      onSuccess: () => { dialog.value = false; form.reset() },
    })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.cards.invoices.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ props.scopedTo
            ? `${props.scopedTo.code} — ${props.scopedTo.name}`
            : t('project_management.cards.invoices.subtitle') }}
        </p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn
          v-if="props.scopedTo"
          variant="tonal"
          prepend-icon="tabler-arrow-left"
          @click="router.get(route('project-management.projects.show', props.scopedTo.id))"
        >
          {{ t('project_management.invoicing.back_to_project') }}
        </VBtn>
        <VBtn v-if="props.can.create" color="primary" prepend-icon="tabler-plus" @click="dialog = true">
          {{ t('project_management.invoicing.new_account') }}
        </VBtn>
      </div>
    </div>

    <!--
      What has come back, as opposed to what was billed.
      
      The table below says what was invoiced; until invoices reached the ledger there was no way to
      say what had been paid, so "sent" was doing duty for a financial state it never had. Overdue
      is separated from merely outstanding because they are different conversations — one is a
      forecast, the other is a telephone call.
    -->
    <VCard v-if="props.ledger?.available" border class="mb-4">
      <VCardText class="d-flex flex-wrap ga-6">
        <div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.invoicing.ledger_invoiced') }}</div>
          <div class="text-h6 font-weight-medium">{{ money(String(props.ledger.invoiced), props.ledger.currency) }}</div>
        </div>
        <div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.invoicing.ledger_paid') }}</div>
          <div class="text-h6 font-weight-medium text-success">{{ money(String(props.ledger.paid), props.ledger.currency) }}</div>
        </div>
        <div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.invoicing.ledger_outstanding') }}</div>
          <div class="text-h6 font-weight-medium">{{ money(String(props.ledger.outstanding), props.ledger.currency) }}</div>
        </div>
        <div v-if="props.ledger.overdue > 0">
          <div class="text-caption text-medium-emphasis">{{ t('project_management.invoicing.ledger_overdue') }}</div>
          <div class="text-h6 font-weight-medium text-error">{{ money(String(props.ledger.overdue), props.ledger.currency) }}</div>
          <div v-if="props.ledger.oldest_unpaid_days" class="text-caption text-error">
            {{ t('project_management.invoicing.ledger_oldest', { days: props.ledger.oldest_unpaid_days }) }}
          </div>
        </div>
      </VCardText>
    </VCard>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VTextField
          v-model="search"
          :label="t('project_management.invoicing.search')"
          prepend-inner-icon="tabler-search"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 280px"
        />
        <VSelect
          v-model="status"
          :items="props.statuses.map(s => ({ title: t(`project_management.invoicing.status.${s}`), value: s }))"
          :label="t('project_management.invoicing.field_status')"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 220px"
        />
        <VAutocomplete
          v-if="!props.scopedTo"
          v-model="project"
          :items="props.projects.map(p => ({ title: `${p.code} — ${p.name}`, value: p.id }))"
          :label="t('project_management.invoicing.field_project')"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 320px"
        />
      </VCardText>
    </VCard>

    <VCard border>
      <VTable v-if="props.invoices.data.length" density="comfortable">
        <thead>
          <tr>
            <th class="text-no-wrap">{{ t('project_management.invoicing.col_invoice_number') }}</th>
            <th class="text-no-wrap">{{ t('project_management.invoicing.col_account') }}</th>
            <th>{{ t('project_management.invoicing.col_project') }}</th>
            <th>{{ t('project_management.invoicing.col_client') }}</th>
            <th class="text-no-wrap">{{ t('project_management.invoicing.col_date') }}</th>
            <th>{{ t('project_management.invoicing.field_status') }}</th>
            <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_amount_due') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="invoice in props.invoices.data"
            :key="invoice.id"
            style="cursor: pointer"
            @click="router.get(route('project-management.invoicing.show', invoice.id))"
          >
            <td class="text-no-wrap">{{ invoice.invoice_number }}</td>
            <td class="text-no-wrap">{{ invoice.account_number }}</td>
            <td>
              <div>{{ invoice.project }}</div>
              <div class="text-caption text-medium-emphasis">{{ invoice.project_code }}</div>
            </td>
            <td>{{ invoice.client_name ?? '—' }}</td>
            <td class="text-no-wrap">{{ invoice.invoice_date ?? '—' }}</td>
            <td>
              <VChip size="small" :color="statusColour[invoice.status] ?? 'grey'" variant="tonal">
                {{ t(`project_management.invoicing.status.${invoice.status}`) }}
              </VChip>
            </td>
            <td class="text-end text-no-wrap">{{ money(invoice.amount_now_due, invoice.currency) }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.invoicing.empty') }}
        <div class="text-caption mt-2">{{ t('project_management.invoicing.empty_hint') }}</div>
      </VCardText>
    </VCard>

    <!-- ── Open the next account ────────────────────────────────────────── -->
    <VDialog v-model="dialog" :fullscreen="smAndDown" max-width="620">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.invoicing.new_account') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.invoicing.new_account_hint') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VAutocomplete
                v-model="form.project"
                :items="props.projects.map(p => ({ title: `${p.code} — ${p.name}`, value: p.id }))"
                :label="t('project_management.invoicing.field_project')"
                :error-messages="form.errors.project"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.invoice_date"
                type="date"
                :label="t('project_management.invoicing.col_date')"
                :error-messages="form.errors.invoice_date"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.description"
                rows="2"
                :label="t('project_management.invoicing.field_description')"
                :hint="t('project_management.invoicing.field_description_hint')"
                persistent-hint
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>
          <VAlert v-if="chosenProject" type="info" variant="tonal" density="comfortable" class="mt-4">
            {{ t('project_management.invoicing.carry_forward_note') }}
          </VAlert>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.project" @click="submit">
            {{ t('project_management.invoicing.open_account') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
