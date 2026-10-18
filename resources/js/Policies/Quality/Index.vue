<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { formatDateTime } from '@/utils/policyFormatters'

// ── Types ──────────────────────────────────────────────────────────────────
interface Policy {
  id: number
  name: string
  status?: string
}

interface Conflict {
  id: number
  policy_a: string
  policy_b: string
  rule_a?: string | null
  rule_b?: string | null
  conflict_type: string
  description: string
  status: 'open' | 'acknowledged'
  created_at: string
}

interface Suggestion {
  type: 'ambiguous_clause' | 'better_wording' | 'missing_rule' | 'redundant_content' | 'weak_enforcement'
  severity: 'low' | 'medium' | 'high'
  clause_reference: string
  description: string
  suggested_text: string
}

interface Analysis {
  id: number
  policy_name: string
  status: string
  issue_count: number
  summary: string
  suggestions: Suggestion[]
  completed_at: string | null
}

const props = defineProps<{
  policies: Policy[]
  conflicts: Conflict[]
  analyses: Analysis[]
}>()

// ── Active tab ─────────────────────────────────────────────────────────────
const tab = ref<'conflicts' | 'quality'>('conflicts')

// ── Conflict detection form ────────────────────────────────────────────────
const conflictForm = useForm({ policy_id: null as number | null })
const runningDetect = ref(false)

function detectConflicts() {
  runningDetect.value = true
  conflictForm.post(route('policy-quality.detect'), {
    onFinish: () => { runningDetect.value = false },
  })
}

// ── Quality analysis form ──────────────────────────────────────────────────
const qualityForm = useForm({ policy_id: null as number | null })
const runningSuggest = ref(false)

function runQualityAnalysis() {
  runningSuggest.value = true
  qualityForm.post(route('policy-quality.suggest'), {
    onFinish: () => { runningSuggest.value = false },
  })
}

// ── Conflict actions ───────────────────────────────────────────────────────
const ackForm      = useForm({})
const resolveForm  = useForm({})
const fpForm       = useForm({})

function acknowledge(conflict: Conflict) {
  ackForm.patch(route('policy-quality.conflicts.acknowledge', conflict.id))
}
function resolveConflict(conflict: Conflict) {
  resolveForm.patch(route('policy-quality.conflicts.resolve', conflict.id))
}
function markFalsePositive(conflict: Conflict) {
  fpForm.patch(route('policy-quality.conflicts.false-positive', conflict.id))
}

// ── Selected analysis result (expanded view) ───────────────────────────────
const selectedAnalysis = ref<Analysis | null>(null)

// ── Label maps ─────────────────────────────────────────────────────────────
const conflictTypeLabel: Record<string, string> = {
  duplicate_rule        : 'Duplicate Rule',
  contradicting_clause  : 'Contradicting Clause',
  overlapping_scope     : 'Overlapping Scope',
  ambiguous_threshold   : 'Ambiguous Threshold',
}
const conflictTypeColor: Record<string, string> = {
  duplicate_rule        : 'warning',
  contradicting_clause  : 'error',
  overlapping_scope     : 'info',
  ambiguous_threshold   : 'secondary',
}
const suggestionTypeLabel: Record<string, string> = {
  ambiguous_clause   : 'Ambiguous Clause',
  better_wording     : 'Better Wording',
  missing_rule       : 'Missing Rule',
  redundant_content  : 'Redundant Content',
  weak_enforcement   : 'Weak Enforcement',
}
const suggestionTypeColor: Record<string, string> = {
  ambiguous_clause   : 'warning',
  better_wording     : 'info',
  missing_rule       : 'error',
  redundant_content  : 'secondary',
  weak_enforcement   : 'deep-orange',
}
const severityColor: Record<string, string> = {
  high   : 'error',
  medium : 'warning',
  low    : 'success',
}

// ── Stats ──────────────────────────────────────────────────────────────────
const openConflicts = computed(() => props.conflicts.filter(c => c.status === 'open').length)
const ackConflicts  = computed(() => props.conflicts.filter(c => c.status === 'acknowledged').length)
const totalIssues   = computed(() => props.analyses.reduce((sum, a) => sum + a.issue_count, 0))
</script>

<template>
  <VRow>
    <!-- ── Header Ribbon ───────────────────────────────────────────────── -->
    <VCol cols="12">
      <VCard style="background: linear-gradient(135deg,#d53f8c 0%,#805ad5 100%); border-radius:12px; overflow:hidden;">
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
          <div>
            <div class="text-overline text-white opacity-90">Phase 13 — Policy Intelligence</div>
            <h4 class="text-h4 text-white mb-1">Policy Quality &amp; Conflict Detection</h4>
            <p class="mb-0 text-white opacity-90">
              Detect duplicate rules, contradicting clauses, scope overlaps — and get AI-powered improvement suggestions.
            </p>
          </div>
          <div class="d-flex gap-3 flex-wrap">
            <VChip color="white" class="text-pink-darken-2" label>Conflict Engine</VChip>
            <VChip color="white" class="text-deep-purple" label>Quality AI</VChip>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <!-- ── KPI bar ─────────────────────────────────────────────────────── -->
    <VCol cols="6" sm="3">
      <VCard color="error" variant="tonal" class="text-center py-3">
        <div class="text-h4 font-weight-bold text-error">{{ openConflicts }}</div>
        <div class="text-caption text-medium-emphasis">Open Conflicts</div>
      </VCard>
    </VCol>
    <VCol cols="6" sm="3">
      <VCard color="warning" variant="tonal" class="text-center py-3">
        <div class="text-h4 font-weight-bold text-warning">{{ ackConflicts }}</div>
        <div class="text-caption text-medium-emphasis">Acknowledged</div>
      </VCard>
    </VCol>
    <VCol cols="6" sm="3">
      <VCard color="purple" variant="tonal" class="text-center py-3">
        <div class="text-h4 font-weight-bold text-purple">{{ analyses.length }}</div>
        <div class="text-caption text-medium-emphasis">AI Analyses Run</div>
      </VCard>
    </VCol>
    <VCol cols="6" sm="3">
      <VCard color="info" variant="tonal" class="text-center py-3">
        <div class="text-h4 font-weight-bold text-info">{{ totalIssues }}</div>
        <div class="text-caption text-medium-emphasis">Total AI Issues</div>
      </VCard>
    </VCol>

    <!-- ── Flash message ────────────────────────────────────────────────── -->
    <VCol cols="12" v-if="$page.props.flash?.success || $page.props.flash?.error">
      <VAlert
        :type="$page.props.flash?.error ? 'error' : 'success'"
        closable
      >
        {{ $page.props.flash?.success ?? $page.props.flash?.error }}
      </VAlert>
    </VCol>

    <!-- ── Main tab card ────────────────────────────────────────────────── -->
    <VCol cols="12">
      <VCard>
        <VTabs v-model="tab" color="pink-darken-2">
          <VTab value="conflicts">
            <VIcon start icon="ri-link-unlink" />
            Conflict Detection
            <VChip v-if="openConflicts" size="x-small" color="error" class="ml-2">{{ openConflicts }}</VChip>
          </VTab>
          <VTab value="quality">
            <VIcon start icon="ri-magic-line" />
            AI Quality Suggestions
          </VTab>
        </VTabs>

        <VDivider />

        <VWindow v-model="tab">
          <!-- ═══════════════════════════════════════════════════════════════
               TAB 1: CONFLICT DETECTION
          ════════════════════════════════════════════════════════════════ -->
          <VWindowItem value="conflicts">
            <VCardText>
              <!-- Run detection form -->
              <VRow align="end" dense class="mb-4">
                <VCol cols="12" sm="6" md="4">
                  <VSelect
                    v-model="conflictForm.policy_id"
                    :items="policies"
                    item-title="name"
                    item-value="id"
                    label="Select policy to scan"
                    density="comfortable"
                    :error-messages="conflictForm.errors.policy_id"
                    clearable
                  />
                </VCol>
                <VCol cols="12" sm="6" md="3">
                  <VBtn
                    color="pink-darken-2"
                    :loading="runningDetect || conflictForm.processing"
                    :disabled="!conflictForm.policy_id"
                    prepend-icon="ri-scan-line"
                    @click="detectConflicts"
                  >
                    Scan for Conflicts
                  </VBtn>
                </VCol>
              </VRow>

              <!-- How detection works info -->
              <VAlert type="info" variant="tonal" class="mb-4" density="compact">
                <strong>Detection checks:</strong> duplicate rules (name + severity similarity ≥ 75%), contradicting clauses (same type, opposing actions), overlapping scope (same category + department), and ambiguous thresholds (same field, different numeric limits) — compared against all active policies.
              </VAlert>

              <!-- Conflicts table -->
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>Type</th>
                    <th>Policy A</th>
                    <th>Policy B</th>
                    <th>Description</th>
                    <th class="text-center">Status</th>
                    <th>Detected</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="c in conflicts" :key="c.id">
                    <td>
                      <VChip
                        :color="conflictTypeColor[c.conflict_type] ?? 'default'"
                        size="small"
                        label
                      >
                        {{ conflictTypeLabel[c.conflict_type] ?? c.conflict_type }}
                      </VChip>
                    </td>
                    <td>
                      <div class="font-weight-medium">{{ c.policy_a }}</div>
                      <div v-if="c.rule_a" class="text-caption text-medium-emphasis">Rule: {{ c.rule_a }}</div>
                    </td>
                    <td>
                      <div class="font-weight-medium">{{ c.policy_b }}</div>
                      <div v-if="c.rule_b" class="text-caption text-medium-emphasis">Rule: {{ c.rule_b }}</div>
                    </td>
                    <td style="max-width:320px">
                      <div class="text-body-2" style="white-space:normal">{{ c.description }}</div>
                    </td>
                    <td class="text-center">
                      <VChip
                        :color="c.status === 'open' ? 'error' : 'warning'"
                        size="small"
                        label
                        class="text-capitalize"
                      >
                        {{ c.status }}
                      </VChip>
                    </td>
                    <td class="text-no-wrap text-caption text-medium-emphasis">{{ formatDateTime(c.created_at) }}</td>
                    <td class="text-no-wrap">
                      <VBtn
                        v-if="c.status === 'open'"
                        size="small"
                        variant="text"
                        color="warning"
                        @click="acknowledge(c)"
                      >
                        Acknowledge
                      </VBtn>
                      <VBtn
                        size="small"
                        variant="text"
                        color="success"
                        @click="resolveConflict(c)"
                      >
                        Resolve
                      </VBtn>
                      <VBtn
                        size="small"
                        variant="text"
                        color="secondary"
                        @click="markFalsePositive(c)"
                      >
                        False Positive
                      </VBtn>
                    </td>
                  </tr>
                  <tr v-if="!conflicts.length">
                    <td colspan="7" class="text-center text-medium-emphasis py-8">
                      <VIcon icon="ri-shield-check-line" size="40" color="success" class="d-block mx-auto mb-2" />
                      No open conflicts. Run a scan above to check your policies.
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VWindowItem>

          <!-- ═══════════════════════════════════════════════════════════════
               TAB 2: AI QUALITY SUGGESTIONS
          ════════════════════════════════════════════════════════════════ -->
          <VWindowItem value="quality">
            <VCardText>
              <!-- Run analysis form -->
              <VRow align="end" dense class="mb-4">
                <VCol cols="12" sm="6" md="4">
                  <VSelect
                    v-model="qualityForm.policy_id"
                    :items="policies"
                    item-title="name"
                    item-value="id"
                    label="Select policy to analyse"
                    density="comfortable"
                    :error-messages="qualityForm.errors.policy_id"
                    clearable
                  />
                </VCol>
                <VCol cols="12" sm="6" md="4">
                  <VBtn
                    color="deep-purple"
                    :loading="runningSuggest || qualityForm.processing"
                    :disabled="!qualityForm.policy_id"
                    prepend-icon="ri-magic-line"
                    @click="runQualityAnalysis"
                  >
                    Run AI Analysis
                  </VBtn>
                </VCol>
              </VRow>

              <VAlert type="info" variant="tonal" class="mb-4" density="compact">
                <strong>AI checks:</strong> ambiguous clauses, better wording opportunities, missing enforcement rules, redundant content, and weak breach actions — based on the policy's extracted clauses and defined rules.
              </VAlert>

              <!-- Past analyses list -->
              <div v-if="!analyses.length" class="text-center text-medium-emphasis py-10">
                <VIcon icon="ri-magic-line" size="48" color="grey-300" class="d-block mx-auto mb-2" />
                <div>No analyses run yet. Select a policy and click <strong>Run AI Analysis</strong>.</div>
              </div>

              <div v-else class="d-flex flex-column gap-4">
                <VCard
                  v-for="analysis in analyses"
                  :key="analysis.id"
                  variant="outlined"
                >
                  <!-- Analysis header -->
                  <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2 pt-4 px-4">
                    <span>{{ analysis.policy_name }}</span>
                    <div class="d-flex align-center gap-2">
                      <VChip
                        :color="analysis.issue_count === 0 ? 'success' : analysis.issue_count < 5 ? 'warning' : 'error'"
                        size="small"
                        label
                      >
                        {{ analysis.issue_count }} issue{{ analysis.issue_count !== 1 ? 's' : '' }}
                      </VChip>
                      <VBtn
                        variant="text"
                        size="small"
                        :append-icon="selectedAnalysis?.id === analysis.id ? 'ri-arrow-up-s-line' : 'ri-arrow-down-s-line'"
                        @click="selectedAnalysis = selectedAnalysis?.id === analysis.id ? null : analysis"
                      >
                        {{ selectedAnalysis?.id === analysis.id ? 'Collapse' : 'View Details' }}
                      </VBtn>
                    </div>
                  </VCardTitle>

                  <!-- Summary -->
                  <VCardText class="pt-0">
                    <p class="text-body-2 text-medium-emphasis mb-2">{{ analysis.summary }}</p>
                    <div class="text-caption text-disabled">Analysed: {{ formatDateTime(analysis.completed_at) }}</div>
                  </VCardText>

                  <!-- Suggestions detail -->
                  <VExpandTransition>
                    <div v-if="selectedAnalysis?.id === analysis.id">
                      <VDivider />
                      <VCardText>
                        <div class="d-flex flex-column gap-3">
                          <VCard
                            v-for="(sug, idx) in analysis.suggestions"
                            :key="idx"
                            variant="tonal"
                            :color="suggestionTypeColor[sug.type] ?? 'default'"
                          >
                            <VCardText class="py-3">
                              <div class="d-flex flex-wrap align-center gap-2 mb-2">
                                <VChip
                                  :color="suggestionTypeColor[sug.type] ?? 'default'"
                                  size="small"
                                  label
                                >
                                  {{ suggestionTypeLabel[sug.type] ?? sug.type }}
                                </VChip>
                                <VChip
                                  :color="severityColor[sug.severity] ?? 'default'"
                                  size="small"
                                  label
                                  class="text-capitalize"
                                >
                                  {{ sug.severity }} severity
                                </VChip>
                                <span v-if="sug.clause_reference" class="text-caption text-medium-emphasis">
                                  Ref: {{ sug.clause_reference }}
                                </span>
                              </div>

                              <p class="text-body-2 mb-2">{{ sug.description }}</p>

                              <!-- Suggested text diff -->
                              <template v-if="sug.suggested_text">
                                <div class="text-caption text-medium-emphasis mb-1 font-weight-medium">
                                  SUGGESTED TEXT
                                </div>
                                <div
                                  class="pa-3 rounded text-body-2"
                                  style="background:rgba(0,0,0,0.06); font-family:monospace; white-space:pre-wrap; line-height:1.6"
                                >{{ sug.suggested_text }}</div>
                              </template>
                            </VCardText>
                          </VCard>

                          <div v-if="!analysis.suggestions.length" class="text-center text-medium-emphasis py-4">
                            No specific suggestions — this policy looks good!
                          </div>
                        </div>
                      </VCardText>
                    </div>
                  </VExpandTransition>
                </VCard>
              </div>
            </VCardText>
          </VWindowItem>
        </VWindow>
      </VCard>
    </VCol>
  </VRow>
</template>
