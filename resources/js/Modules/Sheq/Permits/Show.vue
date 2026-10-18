<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Check = { type: string; detail: string }
type Worker = { id: number; name: string; worker_role: string | null; competency_snapshot: any }
type Approval = {
  id: number; stage: number; approver_role: string | null
  status: string; decided_at: string | null; comments: string | null
}

const props = defineProps<{
  permit: Record<string, any>
  workers: Worker[]
  approvals: Approval[]
  /** Run on view, never cached: a ticket lapses after the request was made. */
  checks: { blocking: Check[]; warnings: Check[] }
  can: { issue: boolean; approve: boolean; close: boolean; override: boolean }
}>()

const status = computed(() => String(props.permit.status ?? ''))
const isLive = computed(() => ['ISSUED', 'ACTIVE'].includes(status.value))
const isSuspended = computed(() => status.value === 'SUSPENDED')
const isClosed = computed(() => ['CLOSED', 'EXPIRED'].includes(status.value))
const blocking = computed(() => props.checks?.blocking ?? [])
const warnings = computed(() => props.checks?.warnings ?? [])

/** Issue is refused while anything blocking stands, unless a competency gap was overridden. */
const competencyOnly = computed(() =>
  blocking.value.length > 0 && blocking.value.every(b => b.type.startsWith('COMPETENCY')))
const mayIssue = computed(() =>
  props.can.issue && !isLive.value && !isClosed.value && blocking.value.length === 0)

const statusColour = computed(() => ({
  DRAFT: 'default', SUBMITTED: 'info', APPROVED: 'info',
  ISSUED: 'success', ACTIVE: 'success',
  SUSPENDED: 'warning', CLOSED: 'default', EXPIRED: 'default',
}[status.value] ?? 'default'))

function post(url: string, data: Record<string, any> = {}) {
  router.post(url, data, { preserveScroll: true })
}

const base = computed(() => `/sheq/permits/${props.permit.uuid}`)

const showSuspend = ref(false)
const suspendForm = useForm({ reason: '' })
function submitSuspend() {
  suspendForm.post(`${base.value}/suspend`, {
    preserveScroll: true,
    onSuccess: () => { showSuspend.value = false; suspendForm.reset() },
  })
}

const showOverride = ref(false)
const overrideForm = useForm({ reason: '' })
function submitOverride() {
  overrideForm.post(`${base.value}/override-competency`, {
    preserveScroll: true,
    onSuccess: () => { showOverride.value = false; overrideForm.reset() },
  })
}

/**
 * Closing is a checklist, not a button.
 *
 * Hot work cannot be closed during its fire watch, and the closure checks are what the issuer is
 * attesting to — so they are ticked here rather than assumed by pressing Close.
 */
const showClose = ref(false)
const closureChecks = ref<Record<string, boolean>>({})
const closureItems = computed<string[]>(() => {
  const declared = props.permit.closure_checks
  if (Array.isArray(declared) && declared.length) return declared.map(String)
  return Object.keys(declared ?? {})
})
function openClose() {
  closureChecks.value = Object.fromEntries(closureItems.value.map(k => [k, false]))
  showClose.value = true
}
const allChecked = computed(() =>
  closureItems.value.length === 0 || closureItems.value.every(k => closureChecks.value[k]))
function submitClose() {
  post(`${base.value}/close`, { closure_checks: closureChecks.value })
  showClose.value = false
}

function approve(approval: Approval) {
  post(`${base.value}/approve`, { approval_id: approval.id })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center flex-wrap gap-3 mb-1">
      <h1 class="text-h5 font-weight-bold mb-0">{{ permit.reference }}</h1>
      <VChip :color="statusColour" size="small" variant="flat">{{ status }}</VChip>
      <VChip size="small" variant="tonal">{{ permit.permit_type }}</VChip>
      <VChip v-if="permit.competency_overridden" size="small" variant="tonal" color="warning">
        {{ t('sheq.permit.competency_overridden') }}
      </VChip>
    </div>
    <p class="text-body-2 text-medium-emphasis mb-5">
      {{ permit.work_description }}
      <span v-if="permit.site_name"> · {{ permit.site_name }}</span>
      <span v-if="permit.location_text"> · {{ permit.location_text }}</span>
    </p>

    <!--
      The gate first, because it is the question the issuer is answering: may this work start,
      and if not, what is stopping it.
    -->
    <VAlert v-if="blocking.length" type="error" variant="tonal" class="mb-4">
      <div class="font-weight-semibold mb-1">{{ t('sheq.permit.blocked_title') }}</div>
      <ul class="ps-4 mb-0">
        <li v-for="(b, i) in blocking" :key="i">{{ b.detail }}</li>
      </ul>
    </VAlert>
    <VAlert v-else-if="!isClosed" type="success" variant="tonal" class="mb-4">
      {{ t('sheq.permit.clear_to_issue') }}
    </VAlert>

    <!-- A warning, never a block: the issuer may know the two crews are separated. -->
    <VAlert v-if="warnings.length" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-semibold mb-1">{{ t('sheq.permit.warnings_title') }}</div>
      <ul class="ps-4 mb-0">
        <li v-for="(w, i) in warnings" :key="i">{{ w.detail }}</li>
      </ul>
    </VAlert>

    <VAlert v-if="isSuspended" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-semibold">{{ t('sheq.permit.suspended_title') }}</div>
      <div>{{ permit.suspension_reason }}</div>
    </VAlert>

    <!-- What can be done now, in the order the work actually goes. -->
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VBtn v-if="mayIssue" color="success" prepend-icon="mdi-check-decagram"
            @click="post(`${base}/issue`)">
        {{ t('sheq.permit.issue') }}
      </VBtn>
      <VBtn v-if="can.override && competencyOnly && !isLive && !isClosed"
            color="warning" variant="tonal" prepend-icon="mdi-shield-alert"
            @click="showOverride = true">
        {{ t('sheq.permit.override_competency') }}
      </VBtn>
      <VBtn v-if="can.close && isLive" color="warning" variant="tonal" prepend-icon="mdi-pause-octagon"
            @click="showSuspend = true">
        {{ t('sheq.permit.suspend') }}
      </VBtn>
      <VBtn v-if="can.close && isSuspended" color="primary" variant="tonal" prepend-icon="mdi-play"
            @click="post(`${base}/reinstate`)">
        {{ t('sheq.permit.reinstate') }}
      </VBtn>
      <VBtn v-if="can.close && (isLive || isSuspended)" variant="tonal" prepend-icon="mdi-archive-check"
            @click="openClose">
        {{ t('sheq.permit.close') }}
      </VBtn>
    </div>

    <VRow>
      <VCol cols="12" md="7">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardText>
            <div class="text-body-2 font-weight-semibold mb-2">{{ t('sheq.permit.workers') }}</div>
            <VTable v-if="workers.length" density="compact">
              <thead>
                <tr>
                  <th scope="col">{{ t('sheq.permit.worker') }}</th>
                  <th scope="col">{{ t('sheq.permit.role') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="w in workers" :key="w.id">
                  <td>{{ w.name }}</td>
                  <td class="text-medium-emphasis">{{ w.worker_role ?? '—' }}</td>
                </tr>
              </tbody>
            </VTable>
            <p v-else class="text-medium-emphasis mb-0">{{ t('sheq.permit.no_workers') }}</p>
          </VCardText>
        </VCard>

        <VCard elevation="0" border rounded="lg">
          <VCardText>
            <div class="text-body-2 font-weight-semibold mb-2">{{ t('sheq.permit.approvals') }}</div>
            <VTable v-if="approvals.length" density="compact">
              <thead>
                <tr>
                  <th scope="col">{{ t('sheq.permit.stage') }}</th>
                  <th scope="col">{{ t('sheq.permit.approver_role') }}</th>
                  <th scope="col">{{ t('sheq.permit.status') }}</th>
                  <th scope="col" class="text-end"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in approvals" :key="a.id">
                  <td>{{ a.stage }}</td>
                  <td class="text-medium-emphasis">{{ a.approver_role ?? '—' }}</td>
                  <td>
                    <VChip size="x-small" variant="tonal"
                           :color="a.status === 'APPROVED' ? 'success' : 'default'">
                      {{ a.status }}
                    </VChip>
                  </td>
                  <td class="text-end">
                    <VBtn v-if="can.approve && a.status !== 'APPROVED'"
                          size="x-small" variant="tonal" color="primary" @click="approve(a)">
                      {{ t('sheq.permit.approve') }}
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
            <p v-else class="text-medium-emphasis mb-0">{{ t('sheq.permit.no_approvals') }}</p>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard elevation="0" border rounded="lg">
          <VCardText>
            <div class="text-body-2 font-weight-semibold mb-3">{{ t('sheq.permit.detail') }}</div>
            <VTable density="compact">
              <tbody>
                <tr>
                  <td class="text-medium-emphasis">{{ t('sheq.permit.valid_from') }}</td>
                  <td>{{ permit.valid_from ?? '—' }}</td>
                </tr>
                <tr>
                  <td class="text-medium-emphasis">{{ t('sheq.permit.valid_to') }}</td>
                  <td>{{ permit.valid_to ?? '—' }}</td>
                </tr>
                <tr v-if="permit.fire_watch_minutes">
                  <td class="text-medium-emphasis">{{ t('sheq.permit.fire_watch') }}</td>
                  <td>{{ permit.fire_watch_minutes }} min</td>
                </tr>
                <tr>
                  <td class="text-medium-emphasis">{{ t('sheq.permit.risk_assessment') }}</td>
                  <td>
                    <a v-if="permit.risk_assessment_uuid"
                       :href="`/sheq/risk-assessments/${permit.risk_assessment_uuid}`">
                      {{ permit.risk_assessment_reference }}
                    </a>
                    <span v-else class="text-medium-emphasis">{{ t('sheq.permit.none') }}</span>
                  </td>
                </tr>
                <tr v-if="permit.competency_overridden">
                  <td class="text-medium-emphasis">{{ t('sheq.permit.override_reason') }}</td>
                  <td>{{ permit.competency_override_reason }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="showSuspend" max-width="620">
      <VCard>
        <VCardTitle>{{ t('sheq.permit.suspend_title') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ t('sheq.permit.suspend_hint') }}</p>
          <VTextarea v-model="suspendForm.reason" :label="t('sheq.permit.reason')"
                     rows="3" variant="outlined" :error-messages="suspendForm.errors.reason" />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showSuspend = false">{{ t('sheq.permit.cancel') }}</VBtn>
          <VBtn color="warning" :loading="suspendForm.processing"
                :disabled="suspendForm.reason.trim().length < 3" @click="submitSuspend">
            {{ t('sheq.permit.suspend') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="showOverride" max-width="640">
      <VCard>
        <VCardTitle>{{ t('sheq.permit.override_title') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VAlert type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('sheq.permit.override_warning') }}
          </VAlert>
          <VTextarea v-model="overrideForm.reason" :label="t('sheq.permit.reason')"
                     rows="3" variant="outlined" :error-messages="overrideForm.errors.reason" />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showOverride = false">{{ t('sheq.permit.cancel') }}</VBtn>
          <VBtn color="warning" :loading="overrideForm.processing"
                :disabled="overrideForm.reason.trim().length < 10" @click="submitOverride">
            {{ t('sheq.permit.override_confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="showClose" max-width="640">
      <VCard>
        <VCardTitle>{{ t('sheq.permit.close_title') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <p v-if="!closureItems.length" class="text-body-2 text-medium-emphasis mb-0">
            {{ t('sheq.permit.no_closure_checks') }}
          </p>
          <VCheckbox v-for="item in closureItems" :key="item"
                     v-model="closureChecks[item]" :label="item" hide-details density="compact" />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showClose = false">{{ t('sheq.permit.cancel') }}</VBtn>
          <VBtn color="primary" :disabled="!allChecked" @click="submitClose">
            {{ t('sheq.permit.close') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
