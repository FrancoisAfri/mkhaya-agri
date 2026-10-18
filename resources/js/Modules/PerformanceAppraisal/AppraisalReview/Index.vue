<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

type AppraisalSnapshot = {
  id: number
  employee_name: string
  employee_id: number
  cycle_name: string | null
  cycle_id: number | null
  snapshot_date: string | null
  overall_score: number | null       // KPI auto-score
  employee_score: number | null
  manager_score: number | null
  score_360: number | null
  final_score: number | null
  appraisal_status: string
  employee_comments: string | null
  manager_comments: string | null
  submitted_at: string | null
  approved_at: string | null
  approved_by: string | null
}

const props = defineProps<{
  pending:  AppraisalSnapshot[]
  approved: AppraisalSnapshot[]
  mySnapshots?: AppraisalSnapshot[]
  isManager?: boolean
}>()

// ── Tabs ─────────────────────────────────────────────────────────────────────
const tab = ref<'pending' | 'approved' | 'self'>('pending')

// ── Manager: score dialog ─────────────────────────────────────────────────────
const scoreOpen     = ref(false)
const scoreTarget   = ref<AppraisalSnapshot | null>(null)
const scoreForm     = ref({ manager_score: '' as number | string, score_360: '' as number | string, manager_comments: '' })
const scoreLoading  = ref(false)

function openScore(snap: AppraisalSnapshot) {
  scoreTarget.value = snap
  scoreForm.value   = {
    manager_score:    snap.manager_score ?? '',
    score_360:        snap.score_360 ?? '',
    manager_comments: snap.manager_comments ?? '',
  }
  scoreOpen.value   = true
}

function submitScore() {
  if (!scoreTarget.value) return
  scoreLoading.value = true
  router.patch(
    route('performance.appraisal-review.manager-score', scoreTarget.value.id),
    {
      manager_score:    scoreForm.value.manager_score,
      score_360:        scoreForm.value.score_360 === '' ? null : scoreForm.value.score_360,
      manager_comments: scoreForm.value.manager_comments,
    },
    {
      preserveScroll: true,
      onFinish: () => { scoreLoading.value = false; scoreOpen.value = false },
    }
  )
}

// Calculated preview
const previewFinal = computed(() => {
  if (!scoreTarget.value) return null
  const kpi = scoreTarget.value.overall_score ?? 0
  const mgr = Number(scoreForm.value.manager_score)
  const s360 = scoreForm.value.score_360 !== '' ? Number(scoreForm.value.score_360) : null
  if (isNaN(mgr)) return null
  return s360 !== null && !isNaN(s360)
    ? Math.round((kpi * 0.50 + mgr * 0.30 + s360 * 0.20) * 100) / 100
    : Math.round((kpi * 0.60 + mgr * 0.40) * 100) / 100
})

// ── Manager: approve ──────────────────────────────────────────────────────────
const approveTarget  = ref<AppraisalSnapshot | null>(null)
const approveOpen    = ref(false)
const approveLoading = ref(false)

function openApprove(snap: AppraisalSnapshot) {
  approveTarget.value = snap
  approveOpen.value   = true
}

function submitApprove() {
  if (!approveTarget.value) return
  approveLoading.value = true
  router.post(
    route('performance.appraisal-review.approve', approveTarget.value.id),
    {},
    {
      preserveScroll: true,
      onFinish: () => { approveLoading.value = false; approveOpen.value = false },
    }
  )
}

// ── Manager: reject ───────────────────────────────────────────────────────────
const rejectOpen    = ref(false)
const rejectTarget  = ref<AppraisalSnapshot | null>(null)
const rejectComment = ref('')
const rejectLoading = ref(false)

function openReject(snap: AppraisalSnapshot) {
  rejectTarget.value  = snap
  rejectComment.value = ''
  rejectOpen.value    = true
}

function submitReject() {
  if (!rejectTarget.value) return
  rejectLoading.value = true
  router.post(
    route('performance.appraisal-review.reject', rejectTarget.value.id),
    { manager_comments: rejectComment.value },
    {
      preserveScroll: true,
      onFinish: () => { rejectLoading.value = false; rejectOpen.value = false },
    }
  )
}

// ── Employee: self-submit dialog ──────────────────────────────────────────────
const selfOpen     = ref(false)
const selfTarget   = ref<AppraisalSnapshot | null>(null)
const selfForm     = ref({ employee_score: '' as number | string, employee_comments: '' })
const selfLoading  = ref(false)

function openSelf(snap: AppraisalSnapshot) {
  selfTarget.value = snap
  selfForm.value   = {
    employee_score:    snap.employee_score ?? '',
    employee_comments: snap.employee_comments ?? '',
  }
  selfOpen.value = true
}

function submitSelf() {
  if (!selfTarget.value) return
  selfLoading.value = true
  router.post(
    route('performance.appraisal-review.submit', selfTarget.value.id),
    {
      employee_score:    selfForm.value.employee_score,
      employee_comments: selfForm.value.employee_comments,
    },
    {
      preserveScroll: true,
      onFinish: () => { selfLoading.value = false; selfOpen.value = false },
    }
  )
}

// ── Detail drawer ─────────────────────────────────────────────────────────────
const detailOpen   = ref(false)
const detailSnap   = ref<AppraisalSnapshot | null>(null)

function openDetail(snap: AppraisalSnapshot) {
  detailSnap.value = snap
  detailOpen.value = true
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const statusColor: Record<string, string> = {
  draft:              'grey',
  employee_submitted: 'blue',
  manager_review:     'orange',
  approved:           'green',
  rejected:           'red',
}

const statusLabel: Record<string, string> = {
  draft:              'Draft',
  employee_submitted: 'Submitted',
  manager_review:     'Under Review',
  approved:           'Approved',
  rejected:           'Returned',
}

function scoreColor(score: number | null) {
  if (score === null) return 'grey'
  if (score >= 80) return 'green'
  if (score >= 60) return 'orange'
  return 'red'
}

function fmt(v: number | null) {
  return v !== null && v !== undefined ? v.toFixed(1) + '%' : '—'
}
</script>

<template>
  <VContainer fluid>
    <!-- Header -->
    <VRow class="mb-4" align="center">
      <VCol>
        <div class="text-h5 font-weight-bold">Appraisal Review</div>
        <div class="text-body-2 text-medium-emphasis">
          Self-assessment submission, manager scoring &amp; final approval
        </div>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VCard>
      <VTabs v-model="tab" color="primary">
        <VTab value="pending">
          Pending Approval
          <VChip v-if="pending.length" class="ml-2" size="x-small" color="orange">{{ pending.length }}</VChip>
        </VTab>
        <VTab value="approved">Approved</VTab>
        <VTab v-if="mySnapshots?.length" value="self">My Appraisals</VTab>
      </VTabs>
      <VDivider />

      <!-- ── PENDING TAB ─────────────────────────────────────────────────── -->
      <VTabsWindow v-model="tab">
        <VTabsWindowItem value="pending">
          <VCardText>
            <VAlert v-if="!pending.length" type="info" variant="tonal" class="mb-0">
              No appraisals pending review.
            </VAlert>

            <VTable v-else hover>
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Cycle</th>
                  <th>Snapshot</th>
                  <th class="text-center">KPI Score</th>
                  <th class="text-center">Self Score</th>
                  <th class="text-center">Mgr Score</th>
                  <th class="text-center">360 Score</th>
                  <th class="text-center">Final Score</th>
                  <th class="text-center">Status</th>
                  <th class="text-center">Submitted</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="snap in pending" :key="snap.id">
                  <td class="font-weight-medium">{{ snap.employee_name }}</td>
                  <td>{{ snap.cycle_name ?? '—' }}</td>
                  <td>{{ snap.snapshot_date ?? '—' }}</td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.overall_score)" variant="tonal">
                      {{ fmt(snap.overall_score) }}
                    </VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.employee_score)" variant="tonal">
                      {{ fmt(snap.employee_score) }}
                    </VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.manager_score)" variant="tonal">
                      {{ fmt(snap.manager_score) }}
                    </VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.score_360)" variant="tonal">
                      {{ fmt(snap.score_360) }}
                    </VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.final_score)" variant="flat">
                      <strong>{{ fmt(snap.final_score) }}</strong>
                    </VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="statusColor[snap.appraisal_status] ?? 'grey'" variant="tonal">
                      {{ statusLabel[snap.appraisal_status] ?? snap.appraisal_status }}
                    </VChip>
                  </td>
                  <td class="text-caption text-medium-emphasis">{{ snap.submitted_at ?? '—' }}</td>
                  <td>
                    <div class="d-flex gap-1 justify-end">
                      <VBtn icon size="x-small" variant="text" @click="openDetail(snap)" title="View details">
                        <i class="ri-eye-line"></i>
                      </VBtn>
                      <VBtn
                        icon size="x-small" variant="text" color="primary"
                        @click="openScore(snap)"
                        title="Enter manager score"
                      >
                        <i class="ri-edit-line"></i>
                      </VBtn>
                      <VBtn
                        icon size="x-small" variant="text" color="success"
                        :disabled="snap.manager_score === null"
                        @click="openApprove(snap)"
                        title="Approve appraisal"
                      >
                        <i class="ri-check-double-line"></i>
                      </VBtn>
                      <VBtn
                        icon size="x-small" variant="text" color="error"
                        @click="openReject(snap)"
                        title="Return to employee"
                      >
                        <i class="ri-arrow-go-back-line"></i>
                      </VBtn>
                    </div>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── APPROVED TAB ──────────────────────────────────────────────── -->
        <VTabsWindowItem value="approved">
          <VCardText>
            <VAlert v-if="!approved.length" type="info" variant="tonal" class="mb-0">
              No approved appraisals yet.
            </VAlert>

            <VTable v-else hover>
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Cycle</th>
                  <th class="text-center">KPI Score</th>
                  <th class="text-center">Self Score</th>
                  <th class="text-center">Mgr Score</th>
                  <th class="text-center">360 Score</th>
                  <th class="text-center">Final Score</th>
                  <th>Approved By</th>
                  <th>Approved At</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="snap in approved" :key="snap.id">
                  <td class="font-weight-medium">{{ snap.employee_name }}</td>
                  <td>{{ snap.cycle_name ?? '—' }}</td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.overall_score)" variant="tonal">{{ fmt(snap.overall_score) }}</VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.employee_score)" variant="tonal">{{ fmt(snap.employee_score) }}</VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.manager_score)" variant="tonal">{{ fmt(snap.manager_score) }}</VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.score_360)" variant="tonal">{{ fmt(snap.score_360) }}</VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.final_score)" variant="flat">
                      <strong>{{ fmt(snap.final_score) }}</strong>
                    </VChip>
                  </td>
                  <td class="text-caption">{{ snap.approved_by ?? '—' }}</td>
                  <td class="text-caption text-medium-emphasis">{{ snap.approved_at ?? '—' }}</td>
                  <td>
                    <VBtn icon size="x-small" variant="text" @click="openDetail(snap)">
                      <i class="ri-eye-line"></i>
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── MY APPRAISALS TAB (employee self-submit) ──────────────────── -->
        <VTabsWindowItem value="self">
          <VCardText>
            <VAlert v-if="!mySnapshots?.length" type="info" variant="tonal" class="mb-0">
              No performance snapshots found for your profile.
            </VAlert>

            <VTable v-else hover>
              <thead>
                <tr>
                  <th>Cycle</th>
                  <th>Snapshot Date</th>
                  <th class="text-center">KPI Score</th>
                  <th class="text-center">Self Score</th>
                  <th class="text-center">Mgr Score</th>
                  <th class="text-center">360 Score</th>
                  <th class="text-center">Final Score</th>
                  <th class="text-center">Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="snap in mySnapshots" :key="snap.id">
                  <td>{{ snap.cycle_name ?? '—' }}</td>
                  <td>{{ snap.snapshot_date ?? '—' }}</td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.overall_score)" variant="tonal">{{ fmt(snap.overall_score) }}</VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.employee_score)" variant="tonal">{{ fmt(snap.employee_score) }}</VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.manager_score)" variant="tonal">{{ fmt(snap.manager_score) }}</VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.score_360)" variant="tonal">{{ fmt(snap.score_360) }}</VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="scoreColor(snap.final_score)" variant="flat">
                      <strong>{{ fmt(snap.final_score) }}</strong>
                    </VChip>
                  </td>
                  <td class="text-center">
                    <VChip size="x-small" :color="statusColor[snap.appraisal_status] ?? 'grey'" variant="tonal">
                      {{ statusLabel[snap.appraisal_status] ?? snap.appraisal_status }}
                    </VChip>
                  </td>
                  <td>
                    <div class="d-flex gap-1 justify-end">
                      <VBtn icon size="x-small" variant="text" @click="openDetail(snap)" title="View">
                        <i class="ri-eye-line"></i>
                      </VBtn>
                      <VBtn
                        v-if="['draft', 'rejected'].includes(snap.appraisal_status)"
                        icon size="x-small" variant="text" color="primary"
                        @click="openSelf(snap)"
                        title="Submit self-assessment"
                      >
                        <i class="ri-send-plane-line"></i>
                      </VBtn>
                    </div>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VTabsWindowItem>
      </VTabsWindow>
    </VCard>
  </VContainer>

  <!-- ── Manager: Score dialog ────────────────────────────────────────────── -->
  <VDialog v-model="scoreOpen" max-width="560">
    <VCard v-if="scoreTarget">
      <VToolbar color="primary" density="compact">
        <VToolbarTitle>Manager Score — {{ scoreTarget.employee_name }}</VToolbarTitle>
        <VBtn icon @click="scoreOpen = false"><i class="ri-close-line"></i></VBtn>
      </VToolbar>
      <VCardText class="pt-4">
        <!-- Score summary strip -->
        <VRow dense class="mb-4">
          <VCol cols="4" class="text-center">
            <div class="text-caption text-medium-emphasis">KPI Auto-Score</div>
            <div class="text-h6 font-weight-bold" :class="`text-${scoreColor(scoreTarget.overall_score)}`">
              {{ fmt(scoreTarget.overall_score) }}
            </div>
          </VCol>
          <VCol cols="4" class="text-center">
            <div class="text-caption text-medium-emphasis">Self-Score</div>
            <div class="text-h6 font-weight-bold" :class="`text-${scoreColor(scoreTarget.employee_score)}`">
              {{ fmt(scoreTarget.employee_score) }}
            </div>
          </VCol>
          <VCol cols="4" class="text-center">
            <div class="text-caption text-medium-emphasis">Projected Final</div>
            <div class="text-h6 font-weight-bold" :class="`text-${scoreColor(previewFinal)}`">
              {{ previewFinal !== null ? previewFinal.toFixed(1) + '%' : '—' }}
            </div>
          </VCol>
        </VRow>

        <VDivider class="mb-4" />

        <!-- Employee comments read-only -->
        <div v-if="scoreTarget.employee_comments" class="mb-4">
          <div class="text-caption text-medium-emphasis mb-1">Employee Comments</div>
          <VSheet class="pa-3 bg-grey-lighten-4 text-body-2">
            {{ scoreTarget.employee_comments }}
          </VSheet>
        </div>

        <VTextField
          v-model.number="scoreForm.manager_score"
          label="Manager Score (%)"
          type="number" min="0" max="100" step="0.5"
          :rules="[v => (v !== '' && v >= 0 && v <= 100) || 'Required, 0–100']"
          variant="outlined" density="compact" class="mb-3"
        />
        <VTextField
          v-model.number="scoreForm.score_360"
          label="360 Score (%) — optional, leave blank to skip"
          type="number" min="0" max="100" step="0.5"
          variant="outlined" density="compact" class="mb-3"
        />
        <VTextarea
          v-model="scoreForm.manager_comments"
          label="Manager Comments (optional)"
          rows="3" variant="outlined" density="compact"
        />

        <!-- Weighting note -->
        <VAlert type="info" variant="tonal" density="compact" class="mt-2 text-caption">
          Final score = KPI 50% + Manager 30% + 360 20% (or KPI 60% + Manager 40% if no 360)
        </VAlert>
      </VCardText>
      <VCardActions class="justify-end pa-3">
        <VBtn variant="text" @click="scoreOpen = false">Cancel</VBtn>
        <VBtn
          color="primary" variant="flat" :loading="scoreLoading"
          :disabled="scoreForm.manager_score === '' || Number(scoreForm.manager_score) < 0 || Number(scoreForm.manager_score) > 100"
          @click="submitScore"
        >
          Save Score
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Approve confirmation ──────────────────────────────────────────────── -->
  <VDialog v-model="approveOpen" max-width="440">
    <VCard v-if="approveTarget">
      <VToolbar color="success" density="compact">
        <VToolbarTitle>Approve Appraisal</VToolbarTitle>
        <VBtn icon @click="approveOpen = false"><i class="ri-close-line"></i></VBtn>
      </VToolbar>
      <VCardText class="pt-4">
        <p>You are about to <strong>approve</strong> the appraisal for:</p>
        <p class="mt-2 font-weight-medium">{{ approveTarget.employee_name }}</p>

        <VRow dense class="mt-4">
          <VCol cols="6" class="text-center">
            <div class="text-caption text-medium-emphasis">Manager Score</div>
            <div class="text-h6" :class="`text-${scoreColor(approveTarget.manager_score)}`">
              {{ fmt(approveTarget.manager_score) }}
            </div>
          </VCol>
          <VCol cols="6" class="text-center">
            <div class="text-caption text-medium-emphasis">Final Score</div>
            <div class="text-h5 font-weight-bold" :class="`text-${scoreColor(approveTarget.final_score)}`">
              {{ fmt(approveTarget.final_score) }}
            </div>
          </VCol>
        </VRow>

        <VAlert type="warning" variant="tonal" density="compact" class="mt-4 text-caption">
          This action is final. The employee will be notified.
        </VAlert>
      </VCardText>
      <VCardActions class="justify-end pa-3">
        <VBtn variant="text" @click="approveOpen = false">Cancel</VBtn>
        <VBtn color="success" variant="flat" :loading="approveLoading" @click="submitApprove">
          Confirm Approve
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Reject / Return dialog ────────────────────────────────────────────── -->
  <VDialog v-model="rejectOpen" max-width="480">
    <VCard v-if="rejectTarget">
      <VToolbar color="error" density="compact">
        <VToolbarTitle>Return to Employee — {{ rejectTarget.employee_name }}</VToolbarTitle>
        <VBtn icon @click="rejectOpen = false"><i class="ri-close-line"></i></VBtn>
      </VToolbar>
      <VCardText class="pt-4">
        <p class="mb-3">Provide feedback explaining why the appraisal is being returned.</p>
        <VTextarea
          v-model="rejectComment"
          label="Reason / Feedback *"
          rows="4" variant="outlined"
          :rules="[v => !!v || 'Feedback is required']"
        />
      </VCardText>
      <VCardActions class="justify-end pa-3">
        <VBtn variant="text" @click="rejectOpen = false">Cancel</VBtn>
        <VBtn
          color="error" variant="flat" :loading="rejectLoading"
          :disabled="!rejectComment.trim()"
          @click="submitReject"
        >
          Return to Employee
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Self-Assessment dialog (employee) ─────────────────────────────────── -->
  <VDialog v-model="selfOpen" max-width="520">
    <VCard v-if="selfTarget">
      <VToolbar color="primary" density="compact">
        <VToolbarTitle>Submit Self-Assessment</VToolbarTitle>
        <VBtn icon @click="selfOpen = false"><i class="ri-close-line"></i></VBtn>
      </VToolbar>
      <VCardText class="pt-4">
        <VAlert type="info" variant="tonal" density="compact" class="mb-4 text-caption">
          Cycle: {{ selfTarget.cycle_name ?? '—' }} &nbsp;|&nbsp;
          KPI auto-score: <strong>{{ fmt(selfTarget.overall_score) }}</strong>
        </VAlert>

        <VTextField
          v-model.number="selfForm.employee_score"
          label="Your Overall Score (%)"
          type="number" min="0" max="100" step="0.5"
          :rules="[v => (v !== '' && v >= 0 && v <= 100) || 'Required, 0–100']"
          variant="outlined" density="compact" class="mb-3"
        />
        <VTextarea
          v-model="selfForm.employee_comments"
          label="Your Comments / Reflection (optional)"
          rows="4" variant="outlined" density="compact"
        />
      </VCardText>
      <VCardActions class="justify-end pa-3">
        <VBtn variant="text" @click="selfOpen = false">Cancel</VBtn>
        <VBtn
          color="primary" variant="flat" :loading="selfLoading"
          :disabled="selfForm.employee_score === '' || Number(selfForm.employee_score) < 0 || Number(selfForm.employee_score) > 100"
          @click="submitSelf"
        >
          Submit for Manager Review
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Detail drawer ─────────────────────────────────────────────────────── -->
  <VNavigationDrawer v-model="detailOpen" location="right" width="420" temporary>
    <VToolbar density="compact" color="surface">
      <VToolbarTitle>Appraisal Detail</VToolbarTitle>
      <VBtn icon @click="detailOpen = false"><i class="ri-close-line"></i></VBtn>
    </VToolbar>

    <div v-if="detailSnap" class="pa-4">
      <div class="text-h6 mb-1">{{ detailSnap.employee_name }}</div>
      <div class="text-caption text-medium-emphasis mb-4">{{ detailSnap.cycle_name ?? '—' }} · {{ detailSnap.snapshot_date ?? '—' }}</div>

      <VChip
        :color="statusColor[detailSnap.appraisal_status] ?? 'grey'"
        variant="tonal" class="mb-4"
      >
        {{ statusLabel[detailSnap.appraisal_status] ?? detailSnap.appraisal_status }}
      </VChip>

      <!-- Score breakdown -->
      <VCard variant="outlined" class="mb-4">
        <VCardTitle class="text-body-2 font-weight-bold pa-3 pb-1">Score Breakdown</VCardTitle>
        <VList density="compact">
          <VListItem>
            <template #title><span class="text-caption">KPI Auto-Score</span></template>
            <template #append>
              <VChip size="x-small" :color="scoreColor(detailSnap.overall_score)" variant="tonal">
                {{ fmt(detailSnap.overall_score) }}
              </VChip>
            </template>
          </VListItem>
          <VListItem>
            <template #title><span class="text-caption">Self-Assessment Score</span></template>
            <template #append>
              <VChip size="x-small" :color="scoreColor(detailSnap.employee_score)" variant="tonal">
                {{ fmt(detailSnap.employee_score) }}
              </VChip>
            </template>
          </VListItem>
          <VListItem>
            <template #title><span class="text-caption">Manager Score</span></template>
            <template #append>
              <VChip size="x-small" :color="scoreColor(detailSnap.manager_score)" variant="tonal">
                {{ fmt(detailSnap.manager_score) }}
              </VChip>
            </template>
          </VListItem>
          <VListItem>
            <template #title><span class="text-caption">360 Score</span></template>
            <template #append>
              <VChip size="x-small" :color="scoreColor(detailSnap.score_360)" variant="tonal">
                {{ fmt(detailSnap.score_360) }}
              </VChip>
            </template>
          </VListItem>
          <VDivider />
          <VListItem>
            <template #title><span class="text-body-2 font-weight-bold">Final Score</span></template>
            <template #append>
              <VChip :color="scoreColor(detailSnap.final_score)" variant="flat">
                <strong>{{ fmt(detailSnap.final_score) }}</strong>
              </VChip>
            </template>
          </VListItem>
        </VList>
      </VCard>

      <!-- Comments -->
      <div v-if="detailSnap.employee_comments" class="mb-4">
        <div class="text-caption font-weight-bold mb-1">Employee Comments</div>
        <VSheet class="pa-3 bg-grey-lighten-4 text-body-2">
          {{ detailSnap.employee_comments }}
        </VSheet>
      </div>
      <div v-if="detailSnap.manager_comments" class="mb-4">
        <div class="text-caption font-weight-bold mb-1">Manager Comments</div>
        <VSheet class="pa-3 bg-blue-lighten-5 text-body-2">
          {{ detailSnap.manager_comments }}
        </VSheet>
      </div>

      <!-- Timeline -->
      <div class="text-caption font-weight-bold mb-2">Timeline</div>
      <div v-if="detailSnap.submitted_at" class="d-flex align-center gap-2 mb-2">
        <i class="ri-send-plane-line text-primary"></i>
        <span class="text-caption">Submitted: {{ detailSnap.submitted_at }}</span>
      </div>
      <div v-if="detailSnap.approved_at" class="d-flex align-center gap-2 mb-2">
        <i class="ri-check-double-line text-success"></i>
        <span class="text-caption">Approved: {{ detailSnap.approved_at }} by {{ detailSnap.approved_by }}</span>
      </div>
    </div>
  </VNavigationDrawer>
</template>
