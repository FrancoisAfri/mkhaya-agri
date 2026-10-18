<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import StatusChip from '../Partials/StatusChip.vue'
import { count, date, humanise, money, score } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  plan: any
  items: any[]
  budgetPosition: any | null
  history: any[]
  nextStatuses: string[]
  approval: any | null
  approvalSteps: any[]
  officers: { id: number; name: string }[]
  departments: { id: number; name: string }[]
  budgets: { id: number; code: string | null; name: string; available: number }[]
  budgetModule: boolean
  methods: string[]
  can: { edit: boolean; submit: boolean; approve: boolean; activate: boolean }
}>()

const showLineDialog = ref(false)
const transitionTarget = ref<string | null>(null)

const lineForm = useForm({
  description: '',
  business_justification: '',
  category: props.plan.category ?? '',
  estimated_value: 0,
  currency: props.plan.currency ?? 'ZAR',
  budget_id: props.plan.budget_id ?? null,
  procurement_method: null as string | null,
  planned_start_date: '',
  planned_tender_date: '',
  expected_award_date: '',
  expected_contract_start: '',
  expected_contract_end: '',
  responsible_officer_id: null as number | null,
  company_unit_id: props.plan.company_unit_id ?? null,
  priority: 'MEDIUM',
  notes: '',
})

const transitionForm = useForm({ status: '', comment: '' })

const editable = computed(() => ['DRAFT', 'DEPARTMENT_REVIEW'].includes(props.plan.status))

function addLine() {
  lineForm.post(`/procurement-governance/plans/${props.plan.uuid}/items`, {
    preserveScroll: true,
    onSuccess: () => { showLineDialog.value = false; lineForm.reset() },
  })
}

function moveTo(status: string) {
  transitionForm.status = status
  transitionTarget.value = status
}

function confirmTransition() {
  transitionForm.post(`/procurement-governance/plans/${props.plan.uuid}/transition`, {
    preserveScroll: true,
    onSuccess: () => { transitionTarget.value = null; transitionForm.reset() },
  })
}

function activate(itemUuid: string) {
  router.post(
    `/procurement-governance/plans/${props.plan.uuid}/items/${itemUuid}/activate`,
    {},
    { preserveScroll: true },
  )
}

function refreshFinancials() {
  router.post(`/procurement-governance/plans/${props.plan.uuid}/refresh-financials`, {}, { preserveScroll: true })
}

/** §2.4: a line is delayed when a planned date has passed and it has not moved on. */
function isDelayed(item: any): boolean {
  if (['COMPLETED', 'CANCELLED', 'AWARDED'].includes(item.status)) return false

  const due = item.expected_award_date ?? item.planned_tender_date
  return !!due && new Date(due) < new Date()
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-start justify-space-between ga-3 mb-4">
      <div class="min-width-0">
        <div class="d-flex align-center ga-2 mb-1 flex-wrap">
          <span class="text-caption font-weight-bold text-medium-emphasis">{{ plan.reference }}</span>
          <StatusChip :status="plan.status" />
        </div>
        <h1 class="text-h5 font-weight-bold">{{ plan.title }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ plan.financial_year_label }}
          <template v-if="plan.category"> · {{ plan.category }}</template>
        </p>
      </div>

      <div class="d-flex flex-wrap ga-2">
        <VBtn
          v-for="status in nextStatuses" :key="status"
          :color="status === 'CANCELLED' ? 'error' : 'primary'"
          :variant="status === 'CANCELLED' ? 'outlined' : 'flat'"
          size="small"
          @click="moveTo(status)"
        >
          {{ t('procurement_governance.planning.move_to', { status: humanise(status) }) }}
        </VBtn>
      </div>
    </div>

    <!-- §2.3: budget position, with the honest "not available" case. -->
    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardTitle class="d-flex align-center justify-space-between text-body-1 font-weight-bold py-3">
        {{ t('procurement_governance.planning.budget_position') }}
        <VBtn
          v-if="budgetModule" size="small" variant="text"
          prepend-icon="mdi-refresh" @click="refreshFinancials"
        >
          {{ t('procurement_governance.common.refresh') }}
        </VBtn>
      </VCardTitle>
      <VCardText class="pt-0">
        <VAlert
          v-if="!budgetPosition?.budget_available"
          type="info" variant="tonal" density="compact" class="text-body-2 mb-0"
        >
          {{ t('procurement_governance.planning.no_budget_position') }}
        </VAlert>

        <template v-else>
          <VAlert
            v-if="budgetPosition.over_budget" type="error" variant="tonal"
            density="compact" class="mb-3 text-body-2" icon="mdi-alert-circle-outline"
          >
            {{ t('procurement_governance.planning.over_budget', {
              amount: money(Math.abs(budgetPosition.variance), plan.currency),
            }) }}
          </VAlert>
          <VAlert
            v-else-if="budgetPosition.warning" type="warning" variant="tonal"
            density="compact" class="mb-3 text-body-2" icon="mdi-alert-outline"
          >
            {{ t('procurement_governance.planning.budget_warning') }}
          </VAlert>

          <VRow dense>
            <VCol v-for="key in ['allocated', 'committed', 'actual', 'available', 'planned', 'variance']"
                  :key="key" cols="6" md="4" lg="2">
              <div class="pa-2">
                <div class="text-caption text-medium-emphasis text-uppercase">
                  {{ t(`procurement_governance.planning.budget_${key}`) }}
                </div>
                <div
                  class="text-body-1 font-weight-bold"
                  :class="{ 'text-error': key === 'variance' && budgetPosition.variance < 0 }"
                >
                  {{ money(budgetPosition[key], plan.currency) }}
                </div>
              </div>
            </VCol>
          </VRow>
        </template>
      </VCardText>
    </VCard>

    <VRow>
      <VCol cols="12" lg="8">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap ga-2 text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.planning.requirements') }}
            <VBtn
              v-if="can.edit && editable" size="small" color="primary" variant="tonal"
              prepend-icon="mdi-plus" @click="showLineDialog = true"
            >
              {{ t('procurement_governance.planning.add_line') }}
            </VBtn>
          </VCardTitle>

          <div v-if="!items.length" class="text-center py-10 px-4 text-medium-emphasis">
            <VIcon icon="mdi-clipboard-text-outline" size="32" class="mb-2" />
            <div class="text-body-2">{{ t('procurement_governance.planning.no_lines') }}</div>
          </div>

          <div v-else class="procgov-scroll">
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th scope="col">{{ t('procurement_governance.planning.reference') }}</th>
                  <th scope="col">{{ t('procurement_governance.common.description') }}</th>
                  <th scope="col" class="d-none d-md-table-cell">{{ t('procurement_governance.planning.method') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.planning.estimated') }}</th>
                  <th scope="col" class="d-none d-lg-table-cell">{{ t('procurement_governance.planning.tender_date') }}</th>
                  <th scope="col" class="d-none d-lg-table-cell">{{ t('procurement_governance.common.officer') }}</th>
                  <th scope="col">{{ t('procurement_governance.common.status') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.common.actions') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in items" :key="item.uuid" :class="{ 'procgov-flagged': isDelayed(item) }">
                  <td class="text-no-wrap">
                    <span class="text-caption font-weight-medium">{{ item.reference }}</span>
                  </td>
                  <td>
                    <div class="text-body-2">{{ item.description }}</div>
                    <a
                      v-if="item.record_uuid"
                      :href="`/procurement-governance/records/${item.record_uuid}`"
                      class="text-caption text-primary procgov-link"
                    >{{ item.record_reference }} · {{ humanise(item.record_stage) }}</a>
                  </td>
                  <td class="d-none d-md-table-cell text-body-2">{{ humanise(item.procurement_method) }}</td>
                  <td class="text-end text-body-2">{{ money(item.estimated_value, item.currency) }}</td>
                  <td class="d-none d-lg-table-cell text-body-2">{{ date(item.planned_tender_date) }}</td>
                  <td class="d-none d-lg-table-cell text-body-2">{{ item.officer ?? '—' }}</td>
                  <td><StatusChip :status="item.status" /></td>
                  <td class="text-end">
                    <VBtn
                      v-if="can.activate && !item.record_id && ['APPROVED'].includes(item.status)"
                      size="x-small" variant="tonal" color="primary"
                      @click="activate(item.uuid)"
                    >
                      {{ t('procurement_governance.planning.take_live') }}
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard v-if="approval" elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.common.approval') }}
          </VCardTitle>
          <VCardText class="pt-0">
            <StatusChip :status="approval.status" class="mb-3" />
            <VTimeline density="compact" side="end" truncate-line="both" class="mt-2">
              <VTimelineItem
                v-for="step in approvalSteps" :key="step.id"
                :dot-color="step.status === 'APPROVED' ? 'success' : step.status === 'REJECTED' ? 'error' : 'grey'"
                size="x-small"
              >
                <div class="text-body-2 font-weight-medium">{{ step.name }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ humanise(step.status) }}
                  <template v-if="step.decided_at"> · {{ date(step.decided_at) }}</template>
                </div>
                <div v-if="step.comment" class="text-caption mt-1">{{ step.comment }}</div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>

        <!-- §2.2: every transition is logged, and the log is read here rather than from the ledger. -->
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.common.history') }}
          </VCardTitle>
          <VCardText class="pt-0">
            <VTimeline density="compact" side="end" truncate-line="both">
              <VTimelineItem
                v-for="(entry, i) in history" :key="i"
                dot-color="primary" size="x-small"
              >
                <div class="text-body-2 font-weight-medium">
                  {{ humanise(entry.from_status) }} → {{ humanise(entry.to_status) }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ date(entry.occurred_at) }}
                  <template v-if="entry.actor_label"> · {{ entry.actor_label }}</template>
                  <template v-if="entry.seconds_in_from_status">
                    · {{ t('procurement_governance.common.days_in_stage', {
                      n: score(entry.seconds_in_from_status / 86400),
                    }) }}
                  </template>
                </div>
                <div v-if="entry.comment" class="text-caption mt-1">{{ entry.comment }}</div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Add a requirement (§2.1) -->
    <VDialog v-model="showLineDialog" max-width="820" scrollable>
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">
          {{ t('procurement_governance.planning.add_line') }}
        </VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VTextField
                v-model="lineForm.description" :label="t('procurement_governance.common.description')"
                :error-messages="lineForm.errors.description" variant="outlined" density="comfortable" required
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="lineForm.business_justification"
                :label="t('procurement_governance.planning.justification')"
                variant="outlined" rows="2" auto-grow
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="lineForm.estimated_value" type="number" min="0" step="0.01"
                :label="t('procurement_governance.planning.estimated')"
                :error-messages="lineForm.errors.estimated_value" variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="lineForm.procurement_method"
                :items="methods.map(m => ({ title: humanise(m), value: m }))"
                :label="t('procurement_governance.planning.method')"
                variant="outlined" density="comfortable" clearable
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="lineForm.responsible_officer_id"
                :items="officers.map(o => ({ title: o.name, value: o.id }))"
                :label="t('procurement_governance.common.officer')"
                variant="outlined" density="comfortable" clearable
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="lineForm.priority"
                :items="['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'].map(p => ({ title: humanise(p), value: p }))"
                :label="t('procurement_governance.common.priority')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol v-for="field in [
              'planned_start_date', 'planned_tender_date', 'expected_award_date',
              'expected_contract_start', 'expected_contract_end',
            ]" :key="field" cols="12" md="4">
              <VTextField
                v-model="(lineForm as any)[field]" type="date"
                :label="t(`procurement_governance.planning.${field}`)"
                :error-messages="(lineForm.errors as any)[field]"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol v-if="budgetModule" cols="12" md="6">
              <VSelect
                v-model="lineForm.budget_id"
                :items="budgets.map(b => ({ title: `${b.code ? b.code + ' — ' : ''}${b.name}`, value: b.id }))"
                :label="t('procurement_governance.planning.budget')"
                variant="outlined" density="comfortable" clearable
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="showLineDialog = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="lineForm.processing" :disabled="lineForm.processing"
            @click="addLine"
          >
            {{ t('procurement_governance.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Transition, with a reason where one is warranted -->
    <VDialog :model-value="!!transitionTarget" max-width="520" @update:model-value="transitionTarget = null">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">
          {{ t('procurement_governance.planning.move_to', { status: humanise(transitionTarget) }) }}
        </VCardTitle>
        <VCardText>
          <VTextarea
            v-model="transitionForm.comment"
            :label="t('procurement_governance.common.comment')"
            :hint="transitionTarget === 'CANCELLED'
              ? t('procurement_governance.planning.cancel_hint')
              : t('procurement_governance.common.comment_optional')"
            persistent-hint variant="outlined" rows="3" auto-grow
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="transitionTarget = null">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn
            :color="transitionTarget === 'CANCELLED' ? 'error' : 'primary'"
            :loading="transitionForm.processing" :disabled="transitionForm.processing"
            @click="confirmTransition"
          >
            {{ t('procurement_governance.common.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.procgov-scroll { overflow-x: auto; }
.min-width-0 { min-inline-size: 0; }
.procgov-flagged td:first-child { box-shadow: inset 3px 0 0 0 rgb(var(--v-theme-warning)); }
.procgov-link { text-decoration: none; cursor: pointer; }
.procgov-link:hover { text-decoration: underline; }

/* WCAG 2.2 target size. A 20px row action is comfortable with a mouse and unusable with a thumb,
   so coarse pointers get a real 44px target while desktop keeps the density a register needs. */
@media (pointer: coarse) {
  :deep(.v-btn--size-x-small) { min-block-size: 44px; min-inline-size: 44px; }
}
</style>
