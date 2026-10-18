<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

/**
 * One project's subcontractors: their contract, and what they have claimed against it.
 *
 * A contractor with no contract is normal and is shown as such rather than as a gap to fill —
 * invoicing works without one, and the contract is the optional half.
 */
const props = defineProps<{
  project: { id: string; code: string; name: string }
  contractors: Array<Record<string, any>>
  templates: Array<{ id: string; name: string }>
  costs: boolean
  can: { manageTemplates: boolean }
}>()

function openContractPage(contractId: string) {
  router.visit(route('project-management.contracts.show', contractId))
}

function money(amount: string | null | undefined) {
  if (amount === null || amount === undefined || amount === '') return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZAR' }).format(Number(amount))
  } catch {
    return String(amount)
  }
}

// ── Draft a contract ─────────────────────────────────────────────────────────
const contractDialog = ref(false)
const active = ref<Record<string, any> | null>(null)
const contractForm = useForm({
  project: '', member: '', template: '', title: '', contract_value: '', start_date: '', end_date: '',
})

function openContract(contractor: Record<string, any>) {
  active.value = contractor
  contractForm.reset()
  contractForm.project = props.project.id
  contractForm.member = contractor.id
  contractForm.title = `${contractor.name} — ${props.project.name}`
  contractDialog.value = true
}

function draftContract() {
  if (!active.value) return
  contractForm
    .transform(data => ({ ...data, template: data.template || null, member: data.member || null }))
    // The register owns drafting now, and it redirects to the new contract — so the manager lands
    // on the wording rather than back here wondering whether it worked.
    .post(route('project-management.contracts.store'), {
      onSuccess: () => { contractDialog.value = false; active.value = null },
    })
}

const STATUS_COLOUR: Record<string, string> = {
  draft: 'grey', in_review: 'info', approved: 'primary', out_for_signature: 'warning',
  signed: 'success', rejected: 'error', declined: 'error', cancelled: 'grey',
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('project_management.contractors.project_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ props.project.code }} — {{ props.project.name }}
      </p>
    </div>

    <VCard v-if="!props.contractors.length" border>
      <VCardText class="text-center text-medium-emphasis py-8">
        {{ t('project_management.contractors.no_contractors') }}
      </VCardText>
    </VCard>

    <VRow v-else dense>
      <VCol v-for="contractor in props.contractors" :key="contractor.id" cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ contractor.name }}</VCardTitle>
            <VCardSubtitle>{{ contractor.email ?? '—' }}</VCardSubtitle>
            <template #append>
              <VChip
                v-if="contractor.submitted_count" color="warning" size="small" variant="tonal"
              >
                {{ t('project_management.contractors.awaiting', { count: contractor.submitted_count }) }}
              </VChip>
            </template>
          </VCardItem>

          <VCardText>
            <!-- ── The contract, where there is one ──────────────────────── -->
            <template v-if="contractor.contract">
              <div class="d-flex align-center gap-2 mb-2">
                <span class="text-body-2 font-weight-medium">{{ contractor.contract.number }}</span>
                <VChip
                  :color="STATUS_COLOUR[contractor.contract.status] ?? 'grey'"
                  size="x-small" variant="tonal"
                >
                  {{ t('project_management.contracts.status_' + (contractor.contract.status ?? 'draft')) }}
                </VChip>
                <VChip v-if="contractor.contract.version > 1" size="x-small" variant="outlined">
                  {{ t('project_management.contracts.version_short', { no: contractor.contract.version }) }}
                </VChip>
              </div>
              <div class="text-body-2 text-medium-emphasis mb-3">{{ contractor.contract.title }}</div>

              <!--
                Opens the contract rather than sending it from here. Sending asks who signs for
                us, and that question belongs on the page where the terms can be read first.
              -->
              <VBtn
                size="small" variant="tonal" prepend-icon="tabler-file-text" class="mb-3"
                @click="openContractPage(contractor.contract.id)"
              >
                {{ t('project_management.contractors.open_contract') }}
              </VBtn>
            </template>

            <div v-else class="text-body-2 text-medium-emphasis mb-3">
              {{ t('project_management.contractors.no_contract') }}
              <VBtn
                v-if="props.costs" size="small" variant="text" color="primary"
                @click="openContract(contractor)"
              >
                {{ t('project_management.contractors.draft_contract') }}
              </VBtn>
            </div>

            <!-- ── What they have claimed ───────────────────────────────── -->
            <VTable v-if="props.costs && contractor.position" density="compact">
              <tbody>
                <tr>
                  <td>{{ t('project_management.contractors.approved_to_date') }}</td>
                  <td class="text-end figure">{{ money(contractor.position.approved) }}</td>
                </tr>
                <tr>
                  <td>{{ t('project_management.contractors.billable_to_date') }}</td>
                  <td class="text-end figure">{{ money(contractor.position.billable) }}</td>
                </tr>
                <tr v-if="contractor.position.contract_value !== null">
                  <td>{{ t('project_management.contractors.contract_value') }}</td>
                  <td class="text-end figure">{{ money(contractor.position.contract_value) }}</td>
                </tr>
                <tr v-if="contractor.position.remaining !== null" class="font-weight-medium">
                  <td>{{ t('project_management.contractors.remaining') }}</td>
                  <td class="text-end figure">{{ money(contractor.position.remaining) }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Draft a contract ───────────────────────────────────────────────── -->
    <VDialog v-model="contractDialog" max-width="640">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.contractors.draft_contract') }}</VCardTitle>
          <VCardSubtitle v-if="active">{{ active.name }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VTextField
                v-model="contractForm.title"
                :label="t('project_management.contractors.contract_title')"
                :error-messages="contractForm.errors.title"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="contractForm.template"
                :items="props.templates.map(t2 => ({ title: t2.name, value: t2.id }))"
                :label="t('project_management.contractors.template')"
                :hint="t('project_management.contracts.template_hint')" persistent-hint
                variant="outlined" density="compact" clearable
                :no-data-text="t('project_management.contracts.no_templates')"
              />
              <!--
                This module's own library, so the dialog can say where the list came from and how
                to add to it. Only offered to somebody who may actually manage them.
              -->
              <div v-if="props.can.manageTemplates" class="text-caption text-medium-emphasis mt-2">
                {{ t('project_management.contract_templates.kept_here') }}
                <a
                  href="javascript:void(0)" class="text-primary"
                  @click="router.visit(route('project-management.contracts.templates.index'))"
                >{{ t('project_management.contractors.open_template_library') }}</a>
              </div>
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="contractForm.contract_value" inputmode="decimal"
                :label="t('project_management.contractors.contract_value')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="contractForm.start_date" type="date"
                :label="t('project_management.contractors.start_date')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="contractForm.end_date" type="date"
                :label="t('project_management.contractors.end_date')"
                :error-messages="contractForm.errors.end_date"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="contractDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="contractForm.processing" @click="draftContract">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.figure { font-variant-numeric: tabular-nums; }
</style>
