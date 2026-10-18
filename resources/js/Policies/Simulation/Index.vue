<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { formatDateTime } from '@/utils/policyFormatters'

// ── Props from Inertia controller ──────────────────────────────────────────
interface Policy        { id: number; name: string; status?: string }
interface Department    { id: number; name: string; type: string }
interface SimulationRow {
  id: number
  policy_name: string
  run_by: string
  status: 'queued' | 'running' | 'completed' | 'failed'
  projected_violations: number | null
  impacted_count: number
  date_from: string | null
  date_to: string | null
  completed_at: string | null
  created_at: string
}
interface DeptResult {
  id: number | null
  name: string
  count: number
  severities: Record<string, number>
}
interface RuleResult {
  rule_id: number
  name: string
  count: number
  severity: Record<string, number>
}
interface MonthlyBucket { month: string; count: number }
interface SimResults {
  total_violations: number
  unique_users: number
  date_from: string
  date_to: string
  by_severity: Record<string, number>
  by_outcome: Record<string, number>
  by_status: Record<string, number>
  by_department: DeptResult[]
  top_rules: RuleResult[]
  monthly_trend: MonthlyBucket[]
}
interface ActiveResult {
  id: number
  policy_id: number
  policy_name: string
  run_by: string
  status: string
  config: { date_from: string; date_to: string; department_ids: number[] }
  results: SimResults | null
  projected_violations: number | null
  impacted_departments: DeptResult[] | null
  completed_at: string | null
}

const props = defineProps<{
  policies: Policy[]
  departments: Department[]
  simulations: SimulationRow[]
  activeResult?: ActiveResult
}>()

// ── Form state ────────────────────────────────────────────────────────────
const form = useForm({
  policy_id:      props.activeResult?.policy_id ?? null as number | null,
  date_from:      props.activeResult?.config?.date_from ?? '',
  date_to:        props.activeResult?.config?.date_to ?? '',
  department_ids: props.activeResult?.config?.department_ids ?? [] as number[],
})

const running = ref(false)

function runSimulation() {
  running.value = true
  form.post(route('policy-simulation.store'), {
    onFinish: () => { running.value = false },
  })
}

// ── Result helpers ────────────────────────────────────────────────────────
const result = computed<SimResults | null>(() => props.activeResult?.results ?? null)

const severityColor: Record<string, string> = {
  critical : 'error',
  high     : 'warning',
  medium   : 'info',
  low      : 'success',
}
const outcomeColor: Record<string, string> = {
  blocked  : 'error',
  warned   : 'warning',
  escalated: 'secondary',
  logged   : 'primary',
}
const statusColor: Record<string, string> = {
  completed : 'success',
  running   : 'info',
  queued    : 'warning',
  failed    : 'error',
}

function viewSimulation(id: number) {
  router.get(route('policy-simulation.show', id))
}

// monthly trend max for bar width
const trendMax = computed(() => {
  const trend = result.value?.monthly_trend ?? []
  return trend.length ? Math.max(...trend.map(t => t.count), 1) : 1
})
</script>

<template>
  <VRow>
    <!-- ── Header Ribbon ───────────────────────────────────────────────── -->
    <VCol cols="12">
      <VCard class="simulation-ribbon" style="background: linear-gradient(135deg,#5a67d8 0%,#6b46c1 100%);">
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
          <div>
            <div class="text-overline text-white opacity-90">Phase 12 — Unique Feature</div>
            <h4 class="text-h4 text-white mb-1">Policy Simulation Engine</h4>
            <p class="mb-0 text-white opacity-90">
              Run a policy against historical violation data to project violation counts and identify impacted departments.
            </p>
          </div>
          <VChip color="white" class="text-deep-purple" label>
            What-If Analysis
          </VChip>
        </VCardText>
      </VCard>
    </VCol>

    <!-- ── Left: Configuration Form ───────────────────────────────────── -->
    <VCol cols="12" lg="4">
      <VCard title="Simulation Configuration">
        <VCardText>
          <VForm @submit.prevent="runSimulation">
            <VRow dense>
              <!-- Policy selector -->
              <VCol cols="12">
                <VSelect
                  v-model="form.policy_id"
                  :items="policies"
                  item-title="name"
                  item-value="id"
                  label="Select Policy"
                  :error-messages="form.errors.policy_id"
                  density="comfortable"
                  clearable
                />
              </VCol>

              <!-- Date range -->
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="form.date_from"
                  label="Date From"
                  type="date"
                  :error-messages="form.errors.date_from"
                  density="comfortable"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="form.date_to"
                  label="Date To"
                  type="date"
                  :error-messages="form.errors.date_to"
                  density="comfortable"
                />
              </VCol>

              <!-- Department filter (optional) -->
              <VCol cols="12">
                <VSelect
                  v-model="form.department_ids"
                  :items="departments"
                  item-title="name"
                  item-value="id"
                  label="Filter by Departments (optional)"
                  multiple
                  chips
                  closable-chips
                  density="comfortable"
                  :hint="`Leave empty to include all departments`"
                  persistent-hint
                />
              </VCol>

              <VCol cols="12" class="pt-2">
                <VBtn
                  type="submit"
                  color="deep-purple"
                  block
                  :loading="running || form.processing"
                  prepend-icon="ri-flask-line"
                >
                  Run Simulation
                </VBtn>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>

      <!-- Flash success -->
      <VAlert
        v-if="$page.props.flash?.success"
        type="success"
        class="mt-4"
        closable
      >
        {{ $page.props.flash.success }}
      </VAlert>
    </VCol>

    <!-- ── Right: Results Panel ────────────────────────────────────────── -->
    <VCol cols="12" lg="8">
      <!-- No result yet -->
      <VCard v-if="!result" class="d-flex align-center justify-center" min-height="220">
        <VCardText class="text-center text-medium-emphasis py-12">
          <VIcon size="64" color="grey-300" icon="ri-flask-line" class="mb-4 d-block mx-auto" />
          <div class="text-h6 mb-1">No Simulation Results Yet</div>
          <p>Configure the parameters on the left and click <strong>Run Simulation</strong>.</p>
        </VCardText>
      </VCard>

      <!-- Results -->
      <template v-else>
        <!-- Summary stats -->
        <VRow dense>
          <VCol cols="6" sm="3">
            <VCard color="deep-purple" variant="tonal" class="text-center py-4">
              <div class="text-h4 font-weight-bold text-deep-purple">{{ result.total_violations.toLocaleString() }}</div>
              <div class="text-caption text-medium-emphasis">Total Violations</div>
            </VCard>
          </VCol>
          <VCol cols="6" sm="3">
            <VCard color="error" variant="tonal" class="text-center py-4">
              <div class="text-h4 font-weight-bold text-error">{{ result.by_severity?.critical ?? 0 }}</div>
              <div class="text-caption text-medium-emphasis">Critical</div>
            </VCard>
          </VCol>
          <VCol cols="6" sm="3">
            <VCard color="warning" variant="tonal" class="text-center py-4">
              <div class="text-h4 font-weight-bold text-warning">{{ result.by_department.length }}</div>
              <div class="text-caption text-medium-emphasis">Impacted Depts</div>
            </VCard>
          </VCol>
          <VCol cols="6" sm="3">
            <VCard color="info" variant="tonal" class="text-center py-4">
              <div class="text-h4 font-weight-bold text-info">{{ result.unique_users }}</div>
              <div class="text-caption text-medium-emphasis">Unique Users</div>
            </VCard>
          </VCol>
        </VRow>

        <!-- Severity + Outcome breakdown -->
        <VRow dense class="mt-0">
          <VCol cols="12" sm="6">
            <VCard title="Severity Breakdown" class="mt-3">
              <VCardText>
                <div
                  v-for="(count, sev) in result.by_severity"
                  :key="sev"
                  class="d-flex align-center gap-3 mb-2"
                >
                  <VChip :color="severityColor[sev] ?? 'default'" size="small" label class="text-capitalize" style="min-width:80px">
                    {{ sev }}
                  </VChip>
                  <VProgressLinear
                    :model-value="result.total_violations ? Math.round((count / result.total_violations) * 100) : 0"
                    :color="severityColor[sev] ?? 'default'"
                    rounded
                    height="8"
                    class="flex-grow-1"
                  />
                  <span class="text-body-2 text-no-wrap" style="min-width:36px;text-align:right">{{ count }}</span>
                </div>
                <div v-if="!Object.keys(result.by_severity).length" class="text-caption text-medium-emphasis">
                  No violations in range.
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <VCol cols="12" sm="6">
            <VCard title="Outcome Breakdown" class="mt-3">
              <VCardText>
                <div
                  v-for="(count, outcome) in result.by_outcome"
                  :key="outcome"
                  class="d-flex align-center gap-3 mb-2"
                >
                  <VChip :color="outcomeColor[outcome] ?? 'default'" size="small" label class="text-capitalize" style="min-width:80px">
                    {{ outcome }}
                  </VChip>
                  <VProgressLinear
                    :model-value="result.total_violations ? Math.round((count / result.total_violations) * 100) : 0"
                    :color="outcomeColor[outcome] ?? 'default'"
                    rounded
                    height="8"
                    class="flex-grow-1"
                  />
                  <span class="text-body-2 text-no-wrap" style="min-width:36px;text-align:right">{{ count }}</span>
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <!-- Impacted Departments -->
        <VCard class="mt-3" title="Impacted Departments">
          <VTable density="compact">
            <thead>
              <tr>
                <th>Department</th>
                <th class="text-center">Violations</th>
                <th>Severity Breakdown</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="dept in result.by_department" :key="dept.id ?? 'none'">
                <td class="font-weight-medium">{{ dept.name }}</td>
                <td class="text-center">
                  <VChip size="small" color="deep-purple" variant="tonal">{{ dept.count }}</VChip>
                </td>
                <td>
                  <div class="d-flex flex-wrap gap-1 py-1">
                    <VChip
                      v-for="(cnt, sev) in dept.severities"
                      :key="sev"
                      :color="severityColor[sev] ?? 'default'"
                      size="x-small"
                      label
                    >
                      {{ sev }}: {{ cnt }}
                    </VChip>
                  </div>
                </td>
              </tr>
              <tr v-if="!result.by_department.length">
                <td colspan="3" class="text-center text-medium-emphasis py-4">No department data available.</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>

        <!-- Top Rules Triggered -->
        <VCard class="mt-3" title="Top Rules Triggered">
          <VTable density="compact">
            <thead>
              <tr>
                <th>#</th>
                <th>Rule</th>
                <th class="text-center">Violations</th>
                <th>Severity</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(rule, idx) in result.top_rules" :key="rule.rule_id">
                <td class="text-medium-emphasis">{{ idx + 1 }}</td>
                <td>{{ rule.name }}</td>
                <td class="text-center">
                  <VChip size="small" color="primary" variant="tonal">{{ rule.count }}</VChip>
                </td>
                <td>
                  <div class="d-flex flex-wrap gap-1 py-1">
                    <VChip
                      v-for="(cnt, sev) in rule.severity"
                      :key="sev"
                      :color="severityColor[sev] ?? 'default'"
                      size="x-small"
                      label
                    >
                      {{ sev }}: {{ cnt }}
                    </VChip>
                  </div>
                </td>
              </tr>
              <tr v-if="!result.top_rules.length">
                <td colspan="4" class="text-center text-medium-emphasis py-4">No rules triggered in this range.</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>

        <!-- Monthly Trend -->
        <VCard class="mt-3" title="Monthly Violation Trend">
          <VCardText>
            <div v-if="result.monthly_trend.length">
              <div
                v-for="bucket in result.monthly_trend"
                :key="bucket.month"
                class="d-flex align-center gap-3 mb-2"
              >
                <span class="text-body-2 text-no-wrap" style="min-width:72px">{{ bucket.month }}</span>
                <VProgressLinear
                  :model-value="Math.round((bucket.count / trendMax) * 100)"
                  color="deep-purple"
                  rounded
                  height="10"
                  class="flex-grow-1"
                />
                <span class="text-body-2 text-no-wrap" style="min-width:36px;text-align:right">{{ bucket.count }}</span>
              </div>
            </div>
            <div v-else class="text-caption text-medium-emphasis">No monthly data to display.</div>
          </VCardText>
        </VCard>
      </template>
    </VCol>

    <!-- ── Past Simulations Table ──────────────────────────────────────── -->
    <VCol cols="12">
      <VCard title="Simulation History">
        <VTable density="compact">
          <thead>
            <tr>
              <th>#</th>
              <th>Policy</th>
              <th>Run By</th>
              <th class="text-center">Status</th>
              <th class="text-center">Projected Violations</th>
              <th class="text-center">Impacted Depts</th>
              <th>Date Range</th>
              <th>Completed</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="sim in simulations" :key="sim.id">
              <td class="text-medium-emphasis">{{ sim.id }}</td>
              <td class="font-weight-medium">{{ sim.policy_name }}</td>
              <td>{{ sim.run_by }}</td>
              <td class="text-center">
                <VChip :color="statusColor[sim.status] ?? 'default'" size="small" label class="text-capitalize">
                  {{ sim.status }}
                </VChip>
              </td>
              <td class="text-center">
                <span v-if="sim.projected_violations !== null">{{ sim.projected_violations.toLocaleString() }}</span>
                <span v-else class="text-medium-emphasis">—</span>
              </td>
              <td class="text-center">{{ sim.impacted_count }}</td>
              <td>
                <span v-if="sim.date_from && sim.date_to">{{ sim.date_from }} → {{ sim.date_to }}</span>
                <span v-else class="text-medium-emphasis">—</span>
              </td>
              <td>{{ formatDateTime(sim.completed_at) }}</td>
              <td>
                <VBtn
                  icon
                  variant="text"
                  size="small"
                  color="primary"
                  :disabled="sim.status !== 'completed'"
                  @click="viewSimulation(sim.id)"
                >
                  <VIcon icon="ri-eye-line" />
                  <VTooltip activator="parent">View Results</VTooltip>
                </VBtn>
              </td>
            </tr>
            <tr v-if="!simulations.length">
              <td colspan="9" class="text-center text-medium-emphasis py-6">No simulations have been run yet.</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </VCol>
  </VRow>
</template>

<style scoped>
.simulation-ribbon {
  border-radius: 12px;
  overflow: hidden;
}
</style>
