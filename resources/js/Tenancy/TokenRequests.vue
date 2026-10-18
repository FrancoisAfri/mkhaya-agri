<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute

interface Req { id: number; company: string | null; company_id: number; type: string; amount: number; mode: string; total_price: number | null; status: string; note: string | null; created_at: string; decided_at: string | null }
const props = defineProps<{ requests: Req[]; enforcement: string; grace_percent: number }>()

const num = (n: any) => Number(n ?? 0).toLocaleString()
const money = (n: any) => 'R ' + Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusColor: Record<string, string> = { pending: 'warning', approved: 'success', granted: 'success', rejected: 'error' }
const typeLabel: Record<string, string> = { ai: 'AI tokens', sms: 'SMS', storage: 'Storage (GB)' }

const confirmRef = ref()
function approve(r: Req) {
  confirmRef.value.open(
    { title: 'Approve request?', message: `Grant ${num(r.amount)} ${typeLabel[r.type] ?? r.type} to ${r.company} for this month?`, confirmText: 'Approve & grant', confirmColor: 'success', icon: 'tabler-check' },
    () => router.post(route('tenancy.token-requests.approve', r.id), {}, { preserveScroll: true }),
  )
}
function reject(r: Req) {
  confirmRef.value.open(
    { title: 'Reject request?', message: `Reject ${r.company}'s request for ${num(r.amount)} ${typeLabel[r.type] ?? r.type}?`, confirmText: 'Reject', confirmColor: 'error' },
    () => router.post(route('tenancy.token-requests.reject', r.id), {}, { preserveScroll: true }),
  )
}

const mode = ref(props.enforcement)
function setMode(m: string) {
  if (m === mode.value) return
  confirmRef.value.open(
    { title: 'Change enforcement mode?', message: `Set AI & SMS enforcement to "${m}"? This affects how all companies are treated when they hit their limit.`, confirmText: 'Change mode', confirmColor: 'primary', icon: 'tabler-shield-cog' },
    () => { mode.value = m; useForm({ mode: m }).post(route('tenancy.token-requests.enforcement'), { preserveScroll: true }) },
  )
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex flex-wrap align-center justify-space-between mb-6 gap-3">
      <div>
        <h3 class="text-h5 font-weight-bold mb-1">Token &amp; SMS Requests</h3>
        <p class="text-body-2 text-medium-emphasis mb-0">Approve or reject company top-up requests, and set AI/SMS enforcement.</p>
      </div>
      <Link :href="route('tenancy.overview')"><VBtn variant="tonal" prepend-icon="tabler-arrow-left">Overview</VBtn></Link>
    </div>

    <!-- Enforcement toggle -->
    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardText class="d-flex flex-wrap align-center gap-3 py-3">
        <span class="text-subtitle-2 font-weight-bold me-2">Enforcement when a company hits its limit:</span>
        <VBtnToggle :model-value="mode" mandatory density="comfortable" color="primary" variant="outlined">
          <VBtn value="strict" @click="setMode('strict')">Strict — block at 100%</VBtn>
          <VBtn value="grace" @click="setMode('grace')">Grace — allow {{ props.grace_percent }}% over, then block</VBtn>
        </VBtnToggle>
        <span class="text-caption text-medium-emphasis">Applies to AI &amp; SMS. Low-balance and big-request warnings show in both modes.</span>
      </VCardText>
    </VCard>

    <VCard elevation="0" border rounded="lg">
      <VTable hover>
        <thead>
          <tr><th>When</th><th>Company</th><th>Type</th><th class="text-end">Amount</th><th>Kind</th><th class="text-end">Price</th><th>Status</th><th>Note</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
          <tr v-for="r in props.requests" :key="r.id">
            <td class="text-caption">{{ r.created_at }}</td>
            <td class="font-weight-medium">{{ r.company }}</td>
            <td>{{ typeLabel[r.type] ?? r.type }}</td>
            <td class="text-end">{{ num(r.amount) }}</td>
            <td>{{ r.mode }}</td>
            <td class="text-end">{{ r.total_price !== null ? money(r.total_price) : '—' }}</td>
            <td><VChip size="small" :color="statusColor[r.status] ?? 'secondary'" variant="tonal" label>{{ r.status }}</VChip></td>
            <td class="text-caption text-medium-emphasis" style="max-width:200px">{{ r.note }}</td>
            <td class="text-end">
              <template v-if="r.status === 'pending'">
                <VBtn size="small" color="success" variant="tonal" class="me-1" @click="approve(r)">Approve</VBtn>
                <VBtn size="small" color="error" variant="text" @click="reject(r)">Reject</VBtn>
              </template>
              <span v-else class="text-caption text-medium-emphasis">{{ r.decided_at }}</span>
            </td>
          </tr>
          <tr v-if="!props.requests.length"><td colspan="9" class="text-center py-12 text-medium-emphasis">No requests yet.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <ConfirmDialog ref="confirmRef" />
  </VContainer>
</template>
