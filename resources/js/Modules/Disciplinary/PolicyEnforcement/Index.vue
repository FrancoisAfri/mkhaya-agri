<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Violation {
  id: number; rule_name: string; violation_type: string
  severity: string; severity_color: string; action_taken: string; action_color: string
  description: string; case_number: string|null; case_id: number|null
  hearing_id: number|null; actor: string; is_resolved: boolean; created_at: string
}
interface Exception {
  id: number; violation_id: number; rule_name: string|null
  case_id: number|null; case_number: string|null
  reason: string; status: string; status_color: string
  requested_by: string; reviewed_by: string
  approved_until: string|null; created_at: string
}
interface EnforcementLog {
  id: number; action_checked: string; rules_evaluated: number
  violations_found: number; was_blocked: boolean; required_escalation: boolean
  passed_clean: boolean; status_label: string; status_color: string
  case_number: string|null; case_id: number|null
  actor: string; checked_at: string
}
interface Stats {
  total_violations: number; open_violations: number; blocked_actions: number
  pending_exceptions: number; checks_today: number
  violations_by_type: Record<string, number>
}
interface Paginated<T> { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  violations: Paginated<Violation>
  exceptions: Paginated<Exception>
  logs:       Paginated<EnforcementLog>
  stats:      Stats
}>()

const tab = ref('violations')

const exceptionDialog = ref(false)
const exceptionViolationId = ref<number|null>(null)
const exceptionForm = useForm({ reason: '', justification: '', risk_acknowledged: '' })

function openExceptionDialog(violation: Violation) {
  exceptionViolationId.value = violation.id
  exceptionForm.reset()
  exceptionDialog.value = true
}
function submitException() {
  if (!exceptionViolationId.value) return
  exceptionForm.post(
    route('disciplinary.policy-enforcement.request-exception', { violation: exceptionViolationId.value }),
    { onSuccess: () => { exceptionDialog.value = false } }
  )
}

const reviewDialog = ref(false)
const reviewExceptionId = ref<number|null>(null)
const reviewForm = useForm({ decision: 'approved', review_note: '', approved_until: '' })

function openReviewDialog(ex: Exception) {
  reviewExceptionId.value = ex.id
  reviewForm.reset()
  reviewDialog.value = true
}
function submitReview() {
  if (!reviewExceptionId.value) return
  reviewForm.post(
    route('disciplinary.policy-enforcement.review-exception', { exception: reviewExceptionId.value }),
    { onSuccess: () => { reviewDialog.value = false } }
  )
}

const resolveDialog = ref(false)
const resolveViolationId = ref<number|null>(null)
const resolveForm = useForm({ resolution_note: '' })

function openResolveDialog(v: Violation) {
  resolveViolationId.value = v.id
  resolveForm.reset()
  resolveDialog.value = true
}
function submitResolve() {
  if (!resolveViolationId.value) return
  resolveForm.post(
    route('disciplinary.policy-enforcement.resolve-violation', { violation: resolveViolationId.value }),
    { onSuccess: () => { resolveDialog.value = false } }
  )
}

const violationTypeChart = computed(() =>
  Object.entries(props.stats.violations_by_type).map(([type, count]) => ({
    label: type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
    value: count,
  }))
)
</script>

<template>
  <div class="pa-4">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ t('disiplinary_policy.policy_enforcement') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-0">{{ t('disiplinary_policy.policy_enforcement_subtitle') }}</p>
      </div>
      <VBtn variant="outlined" prepend-icon="tabler-layout-dashboard" :href="route('disciplinary.dashboard')">
        {{ t('disciplinary.dashboard') }}
      </VBtn>
    </div>

    <!-- Stat cards -->
    <VRow dense class="mb-4">
      <VCol cols="6" sm="4" md="2" class="flex-grow-1">
        <VCard variant="tonal" color="error" class="pa-3 text-center">
          <VIcon color="error" size="28" class="mb-1">tabler-shield-exclamation</VIcon>
          <div class="text-h5 font-weight-bold">{{ stats.open_violations }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('disciplinary.open_violations') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2" class="flex-grow-1">
        <VCard variant="tonal" color="deep-orange" class="pa-3 text-center">
          <VIcon color="deep-orange" size="28" class="mb-1">tabler-ban</VIcon>
          <div class="text-h5 font-weight-bold">{{ stats.blocked_actions }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('disciplinary.blocked_actions') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2" class="flex-grow-1">
        <VCard variant="tonal" color="warning" class="pa-3 text-center">
          <VIcon color="warning" size="28" class="mb-1">tabler-clock</VIcon>
          <div class="text-h5 font-weight-bold">{{ stats.pending_exceptions }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('disciplinary.pending_exceptions') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2" class="flex-grow-1">
        <VCard variant="tonal" color="primary" class="pa-3 text-center">
          <VIcon color="primary" size="28" class="mb-1">tabler-shield-check</VIcon>
          <div class="text-h5 font-weight-bold">{{ stats.total_violations }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('disciplinary.total_violations') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2" class="flex-grow-1">
        <VCard variant="tonal" color="teal" class="pa-3 text-center">
          <VIcon color="teal" size="28" class="mb-1">tabler-clipboard-check</VIcon>
          <div class="text-h5 font-weight-bold">{{ stats.checks_today }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('disciplinary.checks_today') }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Violation type breakdown -->
    <VCard v-if="violationTypeChart.length" class="mb-4 pa-4">
      <div class="text-caption text-medium-emphasis mb-3 font-weight-medium">{{ t('disiplinary_policy.violations_by_type') }}</div>
      <div class="d-flex flex-wrap ga-2">
        <VChip v-for="item in violationTypeChart" :key="item.label" size="small" variant="tonal" color="error">
          {{ item.label }}: <strong class="ml-1">{{ item.value }}</strong>
        </VChip>
      </div>
    </VCard>

    <!-- Main tabs -->
    <VCard>
      <VTabs v-model="tab" color="primary">
        <VTab value="violations">
          {{ t('disciplinary.violations') }}
          <VChip class="ml-2" size="x-small" color="error">{{ stats.open_violations }}</VChip>
        </VTab>
        <VTab value="exceptions">
          {{ t('disciplinary.exceptions') }}
          <VChip v-if="stats.pending_exceptions" class="ml-2" size="x-small" color="warning">{{ stats.pending_exceptions }}</VChip>
        </VTab>
        <VTab value="logs">{{ t('disciplinary.enforcement_logs') }}</VTab>
      </VTabs>
      <VDivider />

      <VTabsWindow v-model="tab">

        <!-- ── Violations ──────────────────────────────────────────────────── -->
        <VTabsWindowItem value="violations">
          <VCardText class="pa-0">
            <div v-if="!violations.data.length" class="text-center py-10 text-medium-emphasis">
              <VIcon size="48" class="mb-2">tabler-shield-check</VIcon>
              <p>{{ t('disciplinary.no_violations') }}</p>
            </div>
            <VExpansionPanels v-else flat>
              <VExpansionPanel v-for="v in violations.data" :key="v.id" rounded="0">
                <VExpansionPanelTitle>
                  <div class="d-flex align-center ga-3 flex-wrap">
                    <VChip :color="v.action_color" size="small">{{ v.action_taken.toUpperCase() }}</VChip>
                    <VChip :color="v.severity_color" size="x-small" variant="outlined">{{ v.severity }}</VChip>
                    <span class="font-weight-medium">{{ v.rule_name }}</span>
                    <span class="text-caption text-medium-emphasis">{{ v.violation_type }}</span>
                    <VChip v-if="v.is_resolved" size="x-small" color="success">{{ t('disiplinary_policy.resolved') }}</VChip>
                  </div>
                </VExpansionPanelTitle>
                <VExpansionPanelText>
                  <VRow dense class="mb-3">
                    <VCol cols="12">
                      <div class="text-caption text-medium-emphasis mb-1">{{ t('disiplinary_policy.table.description') }}</div>
                      <div class="text-body-2 pa-2 rounded bg-grey-lighten-5">{{ v.description }}</div>
                    </VCol>
                    <VCol v-if="v.case_number" cols="12" sm="4">
                      <div class="text-caption text-medium-emphasis">{{ t('disiplinary_policy.table.case') }}</div>
                      <VBtn size="x-small" variant="text" color="primary"
                        :href="route('disciplinary.cases.show', { case: v.case_id })">{{ v.case_number }}</VBtn>
                    </VCol>
                    <VCol cols="12" sm="4">
                      <div class="text-caption text-medium-emphasis">{{ t('disiplinary_policy.table.actor') }}</div>
                      <div class="text-body-2">{{ v.actor }}</div>
                    </VCol>
                    <VCol cols="12" sm="4">
                      <div class="text-caption text-medium-emphasis">{{ t('disiplinary_policy.table.date') }}</div>
                      <div class="text-body-2">{{ v.created_at }}</div>
                    </VCol>
                  </VRow>
                  <div class="d-flex ga-2 justify-end">
                    <VBtn v-if="!v.is_resolved" size="small" variant="outlined" color="warning"
                      prepend-icon="tabler-hand-stop" @click="openExceptionDialog(v)">
                      {{ t('disiplinary_policy.request_exception') }}
                    </VBtn>
                    <VBtn v-if="!v.is_resolved" size="small" variant="tonal" color="success"
                      prepend-icon="tabler-check" @click="openResolveDialog(v)">
                      {{ t('disiplinary_policy.resolve') }}
                    </VBtn>
                  </div>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── Exceptions ──────────────────────────────────────────────────── -->
        <VTabsWindowItem value="exceptions">
          <VCardText class="pa-0">
            <div v-if="!exceptions.data.length" class="text-center py-10 text-medium-emphasis">
              <VIcon size="48" class="mb-2">tabler-hand</VIcon>
              <p>{{ t('disiplinary_policy.no_exceptions') }}</p>
            </div>
            <VDataTable v-else
              :headers="[
                { title: t('disiplinary_policy.table.violation_rule'), key: 'rule_name' },
                { title: t('disiplinary_policy.table.case'), key: 'case_number' },
                { title: t('disiplinary_policy.table.reason'), key: 'reason' },
                { title: t('disiplinary_policy.table.requested_by'), key: 'requested_by' },
                { title: t('disiplinary_policy.table.status'), key: 'status' },
                { title: t('disiplinary_policy.table.date'), key: 'created_at' },
                { title: '', key: 'actions', sortable: false },
              ]"
              :items="exceptions.data"
              density="compact"
            >
              <template #item.status="{ item }">
                <VChip :color="item.status_color" size="x-small">{{ item.status }}</VChip>
              </template>
              <template #item.case_number="{ item }">
                <VBtn v-if="item.case_id" size="x-small" variant="text" color="primary"
                  :href="route('disciplinary.cases.show', { case: item.case_id })">{{ item.case_number }}</VBtn>
                <span v-else>—</span>
              </template>
              <template #item.actions="{ item }">
                <VBtn v-if="item.status === 'pending'" size="x-small" variant="tonal" color="primary"
                  @click="openReviewDialog(item)">{{ t('disiplinary_policy.review') }}</VBtn>
              </template>
            </VDataTable>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── Enforcement Logs ────────────────────────────────────────────── -->
        <VTabsWindowItem value="logs">
          <VCardText class="pa-0">
            <VDataTable
              :headers="[
                { title: t('disiplinary_policy.table.action'), key: 'action_checked' },
                { title: t('disiplinary_policy.table.case'), key: 'case_number' },
                { title: t('disiplinary_policy.table.rules'), key: 'rules_evaluated' },
                { title: t('disiplinary_policy.table.violations'), key: 'violations_found' },
                { title: t('disiplinary_policy.table.outcome'), key: 'status_label' },
                { title: t('disiplinary_policy.table.actor'), key: 'actor' },
                { title: t('disiplinary_policy.table.checked_at'), key: 'checked_at' },
              ]"
              :items="logs.data"
              density="compact"
            >
              <template #item.action_checked="{ item }">
                <code class="text-caption">{{ item.action_checked }}</code>
              </template>
              <template #item.case_number="{ item }">
                <VBtn v-if="item.case_id" size="x-small" variant="text" color="primary"
                  :href="route('disciplinary.cases.show', { case: item.case_id })">{{ item.case_number }}</VBtn>
                <span v-else>—</span>
              </template>
              <template #item.status_label="{ item }">
                <VChip :color="item.status_color" size="x-small">{{ item.status_label }}</VChip>
              </template>
            </VDataTable>
          </VCardText>
        </VTabsWindowItem>

      </VTabsWindow>
    </VCard>

    <!-- Request Exception Dialog -->
    <VDialog v-model="exceptionDialog" max-width="540">
      <VCard :title="t('disiplinary_policy.dialog.exception_title')">
        <VCardText class="d-flex flex-column ga-3">
          <VAlert type="warning" density="compact" variant="tonal">
            {{ t('disiplinary_policy.dialog.exception_alert') }}
          </VAlert>
          <VTextField v-model="exceptionForm.reason" :label="t('disiplinary_policy.dialog.short_reason')" variant="outlined" density="compact"
            :error-messages="exceptionForm.errors.reason" />
          <VTextarea v-model="exceptionForm.justification" :label="t('disiplinary_policy.dialog.business_justification')" variant="outlined"
            density="compact" rows="4" :error-messages="exceptionForm.errors.justification" />
          <VTextarea v-model="exceptionForm.risk_acknowledged" :label="t('disiplinary_policy.dialog.risk_acknowledgement')"
            variant="outlined" density="compact" rows="2" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="exceptionDialog = false">{{ t('disiplinary_policy.dialog.cancel') }}</VBtn>
          <VBtn color="warning" :loading="exceptionForm.processing" @click="submitException">{{ t('disiplinary_policy.dialog.submit') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Review Exception Dialog -->
    <VDialog v-model="reviewDialog" max-width="480">
      <VCard :title="t('disiplinary_policy.dialog.review_title')">
        <VCardText class="d-flex flex-column ga-3">
          <VSelect v-model="reviewForm.decision" :items="['approved','rejected']" :label="t('disiplinary_policy.dialog.decision')"
            variant="outlined" density="compact" />
          <VTextField v-if="reviewForm.decision === 'approved'" v-model="reviewForm.approved_until"
            :label="t('disiplinary_policy.dialog.approved_until')" type="date" variant="outlined" density="compact" />
          <VTextarea v-model="reviewForm.review_note" :label="t('disiplinary_policy.dialog.review_note')" variant="outlined"
            density="compact" rows="3" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="reviewDialog = false">{{ t('disiplinary_policy.dialog.cancel') }}</VBtn>
          <VBtn :color="reviewForm.decision === 'approved' ? 'success' : 'error'"
            :loading="reviewForm.processing" @click="submitReview">{{ t('disiplinary_policy.dialog.submit') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Resolve Violation Dialog -->
    <VDialog v-model="resolveDialog" max-width="440">
      <VCard :title="t('disiplinary_policy.dialog.resolve_title')">
        <VCardText>
          <VTextarea v-model="resolveForm.resolution_note" :label="t('disiplinary_policy.dialog.resolution_note')" variant="outlined"
            density="compact" rows="3" :error-messages="resolveForm.errors.resolution_note" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="resolveDialog = false">{{ t('disiplinary_policy.dialog.cancel') }}</VBtn>
          <VBtn color="success" :loading="resolveForm.processing" @click="submitResolve">{{ t('disiplinary_policy.mark_resolved') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </div>
</template>
