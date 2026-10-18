<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Violation = {
  id: number
  employee: string
  policy_rule: string | null
  category_label: string
  severity: string
  severity_color: string
  status: string
  status_color: string
  period_start: string
  period_end: string
  created_at: string
}

type PolicyRule = {
  id: number
  code: string
  name: string
  category: string
  category_label: string
  threshold: number
  threshold_unit: string
  period_days: number
  severity: string
  severity_color: string
  action: string
  action_label: string
  auto_escalate: boolean
  active: boolean
}

const props = defineProps<{
  recentViolations: Violation[]
  rules: PolicyRule[]
  summary: { open: number; escalated: number; resolved: number; blocked: number }
}>()

const scanDialog = ref(false)
const scanFrom   = ref('')
const scanTo     = ref('')
const scanning   = ref(false)

function runScan() {
  scanning.value = true
  router.post(route('attendance.policy.scan'), { period_start: scanFrom.value, period_end: scanTo.value }, {
    onFinish: () => { scanning.value = false; scanDialog.value = false },
  })
}

function toggleRule(id: number) { router.post(route('attendance.policy.rules.toggle', { attendancePolicyRule: id })) }

const severityColor = (s: string) => s === 'critical' ? 'error' : s === 'high' ? 'warning' : s === 'medium' ? 'info' : 'secondary'
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
        <div>
          <h4 class="text-h4">Attendance Policy</h4>
          <p class="text-medium-emphasis mb-0">Policy rules, violations and compliance</p>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn variant="tonal" color="default" @click="router.visit(route('attendance.policy.violations'))">All Violations</VBtn>
        <VBtn variant="tonal" color="default" @click="router.visit(route('attendance.policy.compliance'))">Compliance</VBtn>
        <VBtn variant="tonal" color="default" @click="router.visit(route('attendance.policy.exceptions'))">Exceptions</VBtn>
        <VBtn color="warning" variant="tonal" prepend-icon="tabler-scan" @click="scanDialog = true">Run Scan</VBtn>
      </div>
    </div>

    <!-- Stats -->
    <VRow class="mb-5">
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-warning">{{ summary.open }}</h6><p class="text-caption text-medium-emphasis mb-0">Open</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-error">{{ summary.escalated }}</h6><p class="text-caption text-medium-emphasis mb-0">Escalated</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-success">{{ summary.resolved }}</h6><p class="text-caption text-medium-emphasis mb-0">Resolved</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-error">{{ summary.blocked }}</h6><p class="text-caption text-medium-emphasis mb-0">Blocked</p></VCardText></VCard></VCol>
    </VRow>

    <VRow>
      <!-- Recent violations -->
      <VCol cols="12" lg="7">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Recent Open Violations</VCardTitle>
          <VDivider />
          <VList v-if="recentViolations.length" density="compact">
            <VListItem v-for="v in recentViolations" :key="v.id">
              <template #prepend>
                <VAvatar :color="v.severity_color" variant="tonal" size="32" rounded class="me-3">
                  <VIcon icon="tabler-alert-triangle" size="16" />
                </VAvatar>
              </template>
              <template #title>
                <span class="text-body-2 font-weight-semibold">{{ v.employee }}</span>
              </template>
              <template #subtitle>
                <span class="text-caption text-medium-emphasis">{{ v.policy_rule }} · {{ v.category_label }}</span>
              </template>
              <template #append>
                <VChip :color="v.status_color" size="x-small" variant="tonal" class="text-capitalize">{{ v.status }}</VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-8 text-medium-emphasis">No open violations.</VCardText>
        </VCard>
      </VCol>

      <!-- Policy rules -->
      <VCol cols="12" lg="5">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Policy Rules</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="r in rules" :key="r.id">
              <template #title>
                <span class="text-body-2">{{ r.name }}</span>
                <VChip :color="r.severity_color" size="x-small" variant="tonal" class="ms-2 text-capitalize">{{ r.severity }}</VChip>
              </template>
              <template #subtitle>
                <span class="text-caption text-medium-emphasis">{{ r.action_label }} · {{ r.category_label }}</span>
              </template>
              <template #append>
                <VSwitch :model-value="r.active" density="compact" hide-details color="primary" @update:model-value="toggleRule(r.id)" />
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <!-- Scan dialog -->
    <VDialog v-model="scanDialog" max-width="440" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">Run Policy Scan</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="scanFrom" label="Period Start *" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VTextField v-model="scanTo" label="Period End *" type="date" variant="outlined" density="comfortable" :min="scanFrom" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="scanDialog = false">Cancel</VBtn>
          <VBtn color="warning" variant="flat" :loading="scanning" :disabled="!scanFrom || !scanTo" @click="runScan">Scan</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
