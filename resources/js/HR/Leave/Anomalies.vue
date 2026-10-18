<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

type Flag = {
  id: number; employee: string; employee_uuid: string | null; type: string; type_label: string; severity: string
  description: string; evidence: Record<string, any> | null; status: string
  detected_at: string | null; resolution: string | null; reversible: boolean
}

// Pretty labels for evidence keys (fallback: humanise the key).
const evidenceLabels: Record<string, string> = {
  dates: 'Dates', requests: 'Requests', leave_type_id: 'Leave type', balance_hours: 'Balance (hours)',
  balance_after: 'Balance after', date: 'Date', month: 'Month', transaction: 'Transaction',
  allocation_types: 'Leave types', sick_leave_type_id: 'Sick leave type', date_joined: 'Join date',
  date_of_birth: 'Date of birth', leave_profile: 'Leave profile',
}
function humanise(k: string) { return evidenceLabels[k] ?? k.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) }
function fmtVal(v: any): string {
  if (v === null || v === undefined || v === '') return '—'
  if (Array.isArray(v)) return v.length ? v.join(', ') : '—'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}
function evidenceRows(e: Record<string, any> | null) {
  return e ? Object.entries(e).map(([k, v]) => ({ label: humanise(k), value: fmtVal(v) })) : []
}
function goToProfile(f: Flag) {
  if (f.employee_uuid) router.get(route('settings.manager-user.edit', f.employee_uuid))
}
const props = defineProps<{ flags: Flag[]; summary: { open: number; high: number; resolved: number } }>()

const scanning = ref(false)
function scan() {
  scanning.value = true
  router.post(route('hr.leave.anomalies.scan'), {}, { preserveScroll: true, onFinish: () => (scanning.value = false) })
}

const sevColor: Record<string, string> = { critical: 'error', high: 'error', medium: 'warning', low: 'info' }
const statusColor: Record<string, string> = { open: 'error', under_review: 'warning', resolved: 'success', dismissed: 'grey' }

// View evidence
const viewing = ref<Flag | null>(null)

// Fix with AI
const fixDialog = ref(false)
const fixFlag = ref<Flag | null>(null)
const proposal = ref<any>(null)
const proposing = ref(false)
const applying = ref(false)
const fixError = ref<string | null>(null)
async function openFix(f: Flag) {
  fixFlag.value = f; proposal.value = null; fixError.value = null; fixDialog.value = true; proposing.value = true
  try {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
    const { data } = await axios.post(route('hr.leave.anomalies.propose-fix', { flag: f.id }), {}, { headers: { 'X-CSRF-TOKEN': csrf } })
    proposal.value = data
  } catch (e: any) {
    fixError.value = e?.response?.data?.error ?? t('leave_anomalies.ai_unavailable')
  } finally { proposing.value = false }
}
function applyFix() {
  if (!fixFlag.value) return
  applying.value = true
  router.post(route('hr.leave.anomalies.apply-fix', { flag: fixFlag.value.id }), {}, {
    preserveScroll: true, onFinish: () => { applying.value = false; fixDialog.value = false },
  })
}
function reverse(f: Flag) { router.post(route('hr.leave.anomalies.reverse', { flag: f.id }), {}, { preserveScroll: true }) }
function dismiss(f: Flag) { router.post(route('hr.leave.anomalies.dismiss', { flag: f.id }), {}, { preserveScroll: true }) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center gap-3 mb-4">
      <div class="flex-grow-1">
        <div class="text-overline text-medium-emphasis">{{ t('leave_anomalies.breadcrumb') }}</div>
        <h2 class="text-h5 mb-0">{{ t('leave_anomalies.title') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('leave_anomalies.subtitle') }}</div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-radar" :loading="scanning" @click="scan">{{ t('leave_anomalies.scan_now') }}</VBtn>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
      <VChip color="error" variant="tonal" prepend-icon="tabler-alert-triangle">{{ t('leave_anomalies.open_count', { count: summary.open }) }}</VChip>
      <VChip color="error" variant="tonal">{{ t('leave_anomalies.high_count', { count: summary.high }) }}</VChip>
      <VChip color="success" variant="tonal" prepend-icon="tabler-check">{{ t('leave_anomalies.resolved_count', { count: summary.resolved }) }}</VChip>
    </div>

    <VCard border>
      <VTable>
        <thead><tr>
          <th>{{ t('leave_anomalies.employee') }}</th>
          <th>{{ t('leave_anomalies.anomaly') }}</th>
          <th>{{ t('leave_anomalies.severity') }}</th>
          <th>{{ t('leave_anomalies.status') }}</th>
          <th>{{ t('leave_anomalies.detected') }}</th>
          <th class="text-right"></th>
        </tr></thead>
        <tbody>
          <tr v-if="!flags.length"><td colspan="6" class="text-center text-medium-emphasis py-8">{{ t('leave_anomalies.none') }}</td></tr>
          <tr v-for="f in flags" :key="f.id">
            <td class="font-weight-medium">{{ f.employee }}</td>
            <td>
              <div>{{ f.type_label }}</div>
              <div class="text-caption text-medium-emphasis">{{ f.description }}</div>
            </td>
            <td><VChip size="x-small" :color="sevColor[f.severity]" variant="tonal">{{ f.severity }}</VChip></td>
            <td><VChip size="x-small" :color="statusColor[f.status]" variant="tonal">{{ f.status }}</VChip></td>
            <td class="text-caption text-medium-emphasis">{{ f.detected_at }}</td>
            <td class="text-right" style="white-space: nowrap">
              <VBtn size="x-small" variant="text" prepend-icon="tabler-eye" @click="viewing = f">{{ t('leave_anomalies.view') }}</VBtn>
              <VBtn v-if="['open','under_review'].includes(f.status)" size="x-small" color="primary" variant="text" prepend-icon="tabler-sparkles" @click="openFix(f)">{{ t('leave_anomalies.fix_with_ai') }}</VBtn>
              <VBtn v-if="f.reversible" size="x-small" variant="text" prepend-icon="tabler-arrow-back-up" @click="reverse(f)">{{ t('leave_anomalies.reverse') }}</VBtn>
              <VBtn v-if="['open','under_review'].includes(f.status)" size="x-small" color="grey" variant="text" @click="dismiss(f)">{{ t('leave_anomalies.dismiss') }}</VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Evidence dialog -->
    <VDialog :model-value="!!viewing" max-width="560" @update:model-value="v => { if (!v) viewing = null }">
      <VCard v-if="viewing">
        <VCardTitle class="d-flex align-center gap-2">
          <VChip size="small" :color="sevColor[viewing.severity]" variant="tonal">{{ viewing.severity }}</VChip>
          {{ viewing.type_label }}
        </VCardTitle>
        <VCardSubtitle class="d-flex align-center gap-1">
          <VIcon icon="tabler-user" size="14" /> {{ viewing.employee }}
        </VCardSubtitle>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ viewing.description }}</VAlert>

          <div v-if="evidenceRows(viewing.evidence).length">
            <div class="text-caption text-medium-emphasis mb-2">{{ t('leave_anomalies.evidence') }}</div>
            <VTable density="compact" class="border rounded">
              <tbody>
                <tr v-for="row in evidenceRows(viewing.evidence)" :key="row.label">
                  <td class="text-medium-emphasis" style="width:40%">{{ row.label }}</td>
                  <td class="font-weight-medium">{{ row.value }}</td>
                </tr>
              </tbody>
            </VTable>
          </div>

          <div v-if="viewing.resolution" class="mt-4">
            <div class="text-caption text-medium-emphasis">{{ t('leave_anomalies.resolution') }}</div>
            <div class="text-body-2">{{ viewing.resolution }}</div>
          </div>
        </VCardText>
        <VCardActions class="pa-4">
          <VBtn v-if="viewing.employee_uuid" color="primary" variant="tonal" prepend-icon="tabler-user-cog" @click="goToProfile(viewing)">{{ t('leave_anomalies.go_to_profile') }}</VBtn>
          <VSpacer />
          <VBtn variant="text" @click="viewing = null">{{ t('leave_anomalies.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Fix with AI dialog -->
    <VDialog v-model="fixDialog" max-width="620">
      <VCard v-if="fixFlag">
        <VCardTitle class="d-flex align-center gap-2"><VIcon icon="tabler-sparkles" color="primary" size="20" /> {{ t('leave_anomalies.ai_fix_title') }}</VCardTitle>
        <VCardSubtitle>{{ fixFlag.type_label }} — {{ fixFlag.employee }}</VCardSubtitle>
        <VCardText>
          <div v-if="proposing" class="text-center py-6"><VProgressCircular indeterminate color="primary" /><div class="text-caption mt-2">{{ t('leave_anomalies.ai_thinking') }}</div></div>
          <VAlert v-else-if="fixError" type="error" variant="tonal" density="compact">{{ fixError }}</VAlert>
          <div v-else-if="proposal">
            <div class="mb-3"><div class="text-caption text-medium-emphasis">{{ t('leave_anomalies.why') }}</div><div class="text-body-2">{{ proposal.explanation }}</div></div>
            <div class="mb-3"><div class="text-caption text-medium-emphasis">{{ t('leave_anomalies.what_changes') }}</div><div class="text-body-2">{{ proposal.what_changes }}</div></div>
            <div class="mb-3"><div class="text-caption text-medium-emphasis">{{ t('leave_anomalies.how') }}</div><div class="text-body-2">{{ proposal.how }}</div></div>
            <VTable v-if="proposal.affected && proposal.affected.length" density="compact" class="border rounded mb-2">
              <thead><tr><th>{{ t('leave_anomalies.field') }}</th><th>{{ t('leave_anomalies.current') }}</th><th>{{ t('leave_anomalies.proposed') }}</th></tr></thead>
              <tbody><tr v-for="(a, i) in proposal.affected" :key="i"><td>{{ a.field }}</td><td class="text-medium-emphasis">{{ a.current ?? '—' }}</td><td class="font-weight-medium">{{ a.proposed ?? '—' }}</td></tr></tbody>
            </VTable>
            <VAlert v-if="proposal.fix_kind === 'capture_data'" type="info" variant="tonal" density="compact" class="mt-2">{{ t('leave_anomalies.capture_hint') }}</VAlert>
          </div>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="fixDialog = false">{{ t('leave_anomalies.cancel') }}</VBtn>
          <VBtn v-if="proposal" color="primary" :loading="applying" prepend-icon="tabler-check" @click="applyFix">{{ proposal.fix_kind === 'capture_data' ? t('leave_anomalies.acknowledge') : t('leave_anomalies.approve_apply') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.evidence { background: rgba(0,0,0,.04); border-radius: 6px; padding: 10px; font-size: 12px; overflow-x: auto; white-space: pre-wrap; }
</style>
