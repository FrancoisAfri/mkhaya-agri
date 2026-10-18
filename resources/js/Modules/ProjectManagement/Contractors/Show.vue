<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

/**
 * One subcontractor, everything about them.
 *
 * The client asked for this in one sentence: open a contractor and manage the lot — their work,
 * their contract, their invoices. So the page is organised by project engagement, because that is
 * the unit all three of those hang off, with the money summarised once at the top for whoever may
 * see it.
 */
const props = defineProps<{
  contractor: {
    id: string; name: string; email: string | null; source: string
    meta: Record<string, string>
    projects: Array<{
      member_id: string; project_id: string | null; code: string | null; name: string | null
      active: boolean; has_login: boolean
      contract: Record<string, any> | null
      tasks: Array<{ id: string; code: string; name: string; status: string; due: string | null }>
    }>
    invoices: Array<Record<string, any>>
    totals: { approved: string; billable: string; awaiting: number } | null
  }
  projects: Array<{ id: string; label: string }>
  costs: boolean
  can: { manage: boolean }
}>()

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

// PM's own contract lifecycle, not E-Sign's envelope states.
const CONTRACT_COLOUR: Record<string, string> = {
  draft: 'grey', in_review: 'info', approved: 'primary', out_for_signature: 'warning',
  signed: 'success', rejected: 'error', declined: 'error', cancelled: 'grey',
}

// ── Put them on another project ─────────────────────────────────────────────
const engageDialog = ref(false)
const engageForm = useForm({ project: '' })

function engage() {
  engageForm.post(route('project-management.contractors.engage', props.contractor.id), {
    preserveScroll: true,
    onSuccess: () => { engageDialog.value = false; engageForm.reset() },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-start justify-space-between gap-3 mb-4">
      <div>
        <div class="text-caption text-medium-emphasis">
          <a :href="route('project-management.contractors.register')">
            {{ t('project_management.contractors.register_title') }}
          </a>
        </div>
        <h4 class="text-h4">{{ props.contractor.name }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ props.contractor.email ?? t('project_management.contractors.no_email_short') }}
        </p>
      </div>
      <VBtn
        v-if="props.can.manage" variant="tonal" prepend-icon="tabler-folder-plus"
        @click="engageDialog = true"
      >
        {{ t('project_management.contractors.engage') }}
      </VBtn>
    </div>

    <!-- ── Who they are, and the money, once ──────────────────────────────── -->
    <VRow dense class="mb-2">
      <VCol cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.contractors.details') }}</VCardTitle>
          </VCardItem>
          <VCardText>
            <div class="d-flex flex-wrap gap-2">
              <VChip v-if="props.contractor.meta.phone" size="small" variant="tonal" prepend-icon="tabler-phone">
                {{ props.contractor.meta.phone }}
              </VChip>
              <VChip v-if="props.contractor.meta.registration_number" size="small" variant="tonal">
                {{ t('project_management.contractors.field_registration') }}
                {{ props.contractor.meta.registration_number }}
              </VChip>
              <VChip v-if="props.contractor.meta.vat_number" size="small" variant="tonal">
                {{ t('project_management.contractors.vat_short') }} {{ props.contractor.meta.vat_number }}
              </VChip>
              <VChip v-if="props.contractor.meta.bbbee_level" size="small" variant="tonal" color="teal">
                B-BBEE {{ props.contractor.meta.bbbee_level }}
              </VChip>
            </div>
            <p v-if="props.contractor.meta.notes" class="text-body-2 text-medium-emphasis mt-3 mb-0">
              {{ props.contractor.meta.notes }}
            </p>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-if="props.costs && props.contractor.totals" cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.contractors.position') }}</VCardTitle>
          </VCardItem>
          <VCardText>
            <VTable density="compact">
              <tbody>
                <tr>
                  <td>{{ t('project_management.contractors.approved_to_date') }}</td>
                  <td class="text-end figure">{{ money(props.contractor.totals.approved) }}</td>
                </tr>
                <tr>
                  <td>{{ t('project_management.contractors.billable_to_date') }}</td>
                  <td class="text-end figure">{{ money(props.contractor.totals.billable) }}</td>
                </tr>
                <tr v-if="props.contractor.totals.awaiting">
                  <td>{{ t('project_management.contractors.status_submitted') }}</td>
                  <td class="text-end figure">{{ props.contractor.totals.awaiting }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── One panel per project they are on ──────────────────────────────── -->
    <VCard v-if="!props.contractor.projects.length" border class="mb-4">
      <VCardText class="text-center text-medium-emphasis py-8">
        {{ t('project_management.contractors.not_on_any_project') }}
      </VCardText>
    </VCard>

    <VExpansionPanels v-else variant="accordion" class="mb-4">
      <VExpansionPanel v-for="p in props.contractor.projects" :key="p.member_id">
        <VExpansionPanelTitle>
          <div class="d-flex align-center flex-wrap gap-2" style="inline-size: 100%">
            <span class="text-body-2 font-weight-medium">{{ p.code }}</span>
            <span class="text-body-2">{{ p.name }}</span>
            <VChip v-if="!p.has_login" size="x-small" variant="tonal" color="grey">
              {{ t('project_management.contractors.no_login') }}
            </VChip>
            <VSpacer />
            <VChip
              v-if="p.contract" size="x-small" variant="tonal"
              :color="CONTRACT_COLOUR[p.contract.status] ?? 'grey'"
            >
              {{ t('project_management.contracts.status_' + (p.contract.status ?? 'draft')) }}
            </VChip>
            <VChip v-else size="x-small" variant="tonal" color="grey">
              {{ t('project_management.contractors.no_contract') }}
            </VChip>
          </div>
        </VExpansionPanelTitle>
        <VExpansionPanelText>
          <div class="d-flex flex-wrap gap-2 mb-4">
            <VBtn
              v-if="p.project_id" size="small" variant="tonal" prepend-icon="tabler-file-text"
              :href="route('project-management.contractors.project', p.project_id)"
            >
              {{ p.contract
                ? t('project_management.contractors.manage_contract')
                : t('project_management.contractors.draft_contract') }}
            </VBtn>
            <VBtn
              v-if="p.project_id" size="small" variant="text" prepend-icon="tabler-checklist"
              :href="route('project-management.delivery', p.project_id)"
            >
              {{ t('project_management.contractors.assign_work') }}
            </VBtn>
          </div>

          <div class="text-body-2 font-weight-medium mb-2">
            {{ t('project_management.contractors.their_work') }}
          </div>
          <VTable v-if="p.tasks.length" density="compact" class="mb-2">
            <tbody>
              <tr v-for="task in p.tasks" :key="task.id">
                <td class="text-no-wrap">{{ task.code }}</td>
                <td>{{ task.name }}</td>
                <td class="text-no-wrap">{{ task.due ?? '—' }}</td>
                <td class="text-no-wrap">{{ task.status }}</td>
              </tr>
            </tbody>
          </VTable>
          <p v-else class="text-body-2 text-medium-emphasis">
            {{ t('project_management.contractors.no_work') }}
          </p>
        </VExpansionPanelText>
      </VExpansionPanel>
    </VExpansionPanels>

    <!-- ── Their invoices, across every project ───────────────────────────── -->
    <VCard border>
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.contractors.their_invoices') }}</VCardTitle>
        <VCardSubtitle>{{ t('project_management.contractors.their_invoices_sub') }}</VCardSubtitle>
        <template #append>
          <VBtn size="small" variant="text" :href="route('project-management.contractors.index')">
            {{ t('project_management.contractors.open_queue') }}
          </VBtn>
        </template>
      </VCardItem>
      <VDivider />
      <div class="table-scroll">
        <VTable v-if="props.contractor.invoices.length" density="comfortable">
          <thead>
            <tr>
              <th class="text-no-wrap">{{ t('project_management.contractors.col_reference') }}</th>
              <th class="text-no-wrap">{{ t('project_management.contractors.col_project') }}</th>
              <th class="text-no-wrap">{{ t('project_management.contractors.col_period') }}</th>
              <th v-if="props.costs" class="text-no-wrap">{{ t('project_management.contractors.col_total') }}</th>
              <th v-if="props.costs" class="text-no-wrap">{{ t('project_management.contractors.col_billable') }}</th>
              <th class="text-no-wrap">{{ t('project_management.contractors.col_status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="i in props.contractor.invoices" :key="i.id">
              <td class="text-no-wrap">{{ i.reference ?? '—' }}</td>
              <td class="text-no-wrap">{{ i.project ?? '—' }}</td>
              <td class="text-no-wrap">{{ i.invoice_date ?? '—' }}</td>
              <td v-if="props.costs" class="figure text-no-wrap">{{ money(i.total, i.currency) }}</td>
              <td v-if="props.costs" class="figure text-no-wrap">
                {{ money(i.billable_total, i.currency) }}
                <VChip v-if="i.markup_rate" size="x-small" variant="tonal" color="info" class="ms-1">
                  +{{ (Number(i.markup_rate) * 100).toFixed(1) }}%
                </VChip>
              </td>
              <td>
                <VChip :color="STATUS_COLOUR[i.status]" size="small" variant="tonal">
                  {{ t('project_management.contractors.status_' + i.status) }}
                </VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
        <VCardText v-else class="text-center text-medium-emphasis py-8">
          {{ t('project_management.contractors.no_invoices') }}
        </VCardText>
      </div>
    </VCard>

    <!-- ── Engage on another project ──────────────────────────────────────── -->
    <VDialog v-model="engageDialog" max-width="560">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.contractors.engage') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.contractors.engage_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VAutocomplete
            v-model="engageForm.project"
            :items="props.projects.map(p => ({ title: p.label, value: p.id }))"
            :label="t('project_management.contractors.field_project')"
            :error-messages="engageForm.errors.project"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="engageDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="engageForm.processing" @click="engage">
            {{ t('project_management.actions.save') }}
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
