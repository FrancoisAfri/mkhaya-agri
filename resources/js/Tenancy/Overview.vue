<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute

const props = defineProps<{
  edition: string
  revenue: { mrr: number; arr: number; outstanding: number; new_customers_30d: number; churned_30d: number; churn_rate: number }
  companies: { total: number; active: number; trial: number; suspended: number; users: number; active_users: number; modules: number }
  usage: { ai_tokens_month: number; ai_rand_month: number; sms_month: number; pending_requests: number }
  breakdown: { id: number; name: string; plan: string; status: string; users: number; mrr: number; outstanding: number; storage_used: number; storage_quota: number }[]
  tools: { label: string; url: string; icon: string }[]
  generated_at: string
}>()

function money(n: any) { return 'R ' + Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 }) }
function num(n: any) { return Number(n ?? 0).toLocaleString() }
function gb(bytes: any) {
  const b = Number(bytes ?? 0)
  if (b >= 1073741824) return (b / 1073741824).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' GB'
  if (b >= 1048576) return (b / 1048576).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' MB'
  if (b >= 1024) return (b / 1024).toLocaleString(undefined, { maximumFractionDigits: 0 }) + ' KB'
  return b + ' B'
}
function storagePct(c: { storage_used: number; storage_quota: number }) {
  return c.storage_quota > 0 ? Math.min(999, Math.round(c.storage_used / c.storage_quota * 100)) : 0
}
const statusColor: Record<string, string> = { active: 'success', trial: 'info', suspended: 'error', cancelled: 'secondary', pending: 'warning' }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex flex-wrap align-center justify-space-between mb-6 gap-3">
      <div>
        <h3 class="text-h5 font-weight-bold mb-1">Platform Overview</h3>
        <p class="text-body-2 text-medium-emphasis mb-0">Global roll-up across all companies · as of {{ props.generated_at }}</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <Link :href="route('tenancy.companies.index')"><VBtn variant="tonal" prepend-icon="tabler-building">Companies</VBtn></Link>
        <Link :href="route('tenancy.billing.index')"><VBtn variant="tonal" prepend-icon="tabler-cash">Billing</VBtn></Link>
        <Link :href="route('tenancy.plans.index')"><VBtn variant="tonal" prepend-icon="tabler-package">Plans</VBtn></Link>
        <Link :href="route('tenancy.resellers.index')"><VBtn variant="tonal" prepend-icon="tabler-users-group">Resellers</VBtn></Link>
        <Link :href="route('tenancy.system.index')"><VBtn variant="tonal" prepend-icon="tabler-activity-heartbeat">System</VBtn></Link>
        <!--
          These two were built and left off this row, and this row is the only way into the
          platform area: the user menu links /platform/overview and nothing else. So Modules &
          Pricing and Payment Settings could only be opened by typing the URL.
        -->
        <Link :href="route('tenancy.modules.index')"><VBtn variant="tonal" prepend-icon="tabler-apps">Modules &amp; Pricing</VBtn></Link>
        <Link :href="route('tenancy.payment-settings.index')"><VBtn variant="tonal" prepend-icon="tabler-credit-card">Payment Settings</VBtn></Link>
      </div>
    </div>

    <!-- Mkhaya-only platform tools -->
    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardText class="d-flex flex-wrap align-center gap-2 py-3">
        <span class="text-overline text-medium-emphasis me-2">Platform tools (Mkhaya only)</span>
        <a v-for="t in props.tools" :key="t.label" :href="t.url"><VBtn size="small" variant="tonal" :prepend-icon="t.icon">{{ t.label }}</VBtn></a>
      </VCardText>
    </VCard>

    <!-- Revenue -->
    <div class="text-overline text-medium-emphasis mb-2">Revenue</div>
    <VRow class="mb-2">
      <VCol cols="6" md="2"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4">
        <div class="text-caption text-medium-emphasis mb-1">MRR</div><div class="text-h6 font-weight-bold text-primary">{{ money(props.revenue.mrr) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="2"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4">
        <div class="text-caption text-medium-emphasis mb-1">ARR</div><div class="text-h6 font-weight-bold text-success">{{ money(props.revenue.arr) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="2"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4">
        <div class="text-caption text-medium-emphasis mb-1">Outstanding</div><div class="text-h6 font-weight-bold text-error">{{ money(props.revenue.outstanding) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="2"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4">
        <div class="text-caption text-medium-emphasis mb-1">New (30d)</div><div class="text-h6 font-weight-bold">{{ num(props.revenue.new_customers_30d) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="2"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4">
        <div class="text-caption text-medium-emphasis mb-1">Churned (30d)</div><div class="text-h6 font-weight-bold">{{ num(props.revenue.churned_30d) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="2"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4">
        <div class="text-caption text-medium-emphasis mb-1">Churn rate</div><div class="text-h6 font-weight-bold" :class="props.revenue.churn_rate > 5 ? 'text-error' : ''">{{ props.revenue.churn_rate }}%</div></VCardText></VCard></VCol>
    </VRow>

    <!-- Companies / Users / Modules -->
    <VRow class="mb-2">
      <VCol cols="12" md="4">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardItem><VCardTitle class="text-subtitle-1"><VIcon size="20" class="me-2">tabler-building-community</VIcon>Companies</VCardTitle></VCardItem>
          <VCardText>
            <div class="d-flex flex-wrap gap-2">
              <VChip color="success" variant="tonal" label>{{ props.companies.active }} active</VChip>
              <VChip color="info" variant="tonal" label>{{ props.companies.trial }} trial</VChip>
              <VChip color="error" variant="tonal" label>{{ props.companies.suspended }} suspended</VChip>
              <VChip variant="tonal" label>{{ props.companies.total }} total</VChip>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardItem><VCardTitle class="text-subtitle-1"><VIcon size="20" class="me-2">tabler-users</VIcon>Users</VCardTitle></VCardItem>
          <VCardText>
            <VRow dense class="text-body-2">
              <VCol cols="6">Total <span class="font-weight-bold">{{ num(props.companies.users) }}</span></VCol>
              <VCol cols="6">Active <span class="font-weight-bold text-success">{{ num(props.companies.active_users) }}</span></VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardItem><VCardTitle class="text-subtitle-1"><VIcon size="20" class="me-2">tabler-package</VIcon>Platform</VCardTitle></VCardItem>
          <VCardText>
            <VRow dense class="text-body-2">
              <VCol cols="6">Modules <span class="font-weight-bold">{{ num(props.companies.modules) }}</span></VCol>
              <VCol cols="6">Edition <VChip size="x-small" label variant="tonal">{{ props.edition }}</VChip></VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- AI & SMS usage (platform-wide, this month) -->
    <div class="text-overline text-medium-emphasis mb-2">AI &amp; SMS usage (this month)</div>
    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4">
        <div class="text-caption text-medium-emphasis mb-1">AI tokens</div><div class="text-h6 font-weight-bold">{{ num(props.usage.ai_tokens_month) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4">
        <div class="text-caption text-medium-emphasis mb-1">AI spend (est.)</div><div class="text-h6 font-weight-bold text-primary">{{ money(props.usage.ai_rand_month) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4">
        <div class="text-caption text-medium-emphasis mb-1">SMS sent</div><div class="text-h6 font-weight-bold">{{ num(props.usage.sms_month) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><a :href="route('tenancy.token-requests.index')" class="text-decoration-none"><VCard elevation="0" border rounded="lg" :color="props.usage.pending_requests ? 'warning' : undefined" :variant="props.usage.pending_requests ? 'tonal' : 'elevated'"><VCardText class="text-center py-4">
        <div class="text-caption text-medium-emphasis mb-1">Pending top-up requests</div><div class="text-h6 font-weight-bold">{{ num(props.usage.pending_requests) }}</div></VCardText></VCard></a></VCol>
    </VRow>

    <!-- Per-company breakdown -->
    <VCard elevation="0" border rounded="lg">
      <VCardItem><VCardTitle class="text-subtitle-1">Companies breakdown</VCardTitle></VCardItem>
      <VTable hover density="compact">
        <thead>
          <tr><th>Company</th><th>Plan</th><th>Status</th><th class="text-end">Users</th><th class="text-end">MRR</th><th class="text-end">Outstanding</th><th style="min-width:150px">Storage</th></tr>
        </thead>
        <tbody>
          <tr v-for="c in props.breakdown" :key="c.id">
            <td class="font-weight-medium">{{ c.name }}</td>
            <td>{{ c.plan || '—' }}</td>
            <td><VChip size="small" :color="statusColor[c.status] ?? 'secondary'" variant="tonal" label>{{ c.status }}</VChip></td>
            <td class="text-end">{{ num(c.users) }}</td>
            <td class="text-end">{{ money(c.mrr) }}</td>
            <td class="text-end" :class="c.outstanding > 0 ? 'text-error' : ''">{{ money(c.outstanding) }}</td>
            <td>
              <template v-if="c.storage_quota > 0">
                <div class="d-flex align-center justify-space-between text-caption mb-1">
                  <span>{{ gb(c.storage_used) }} / {{ gb(c.storage_quota) }}</span>
                  <span :class="storagePct(c) >= 100 ? 'text-error' : (storagePct(c) >= 80 ? 'text-warning' : 'text-medium-emphasis')">{{ storagePct(c) }}%</span>
                </div>
                <VProgressLinear :model-value="storagePct(c)" :color="storagePct(c) >= 100 ? 'error' : (storagePct(c) >= 80 ? 'warning' : 'success')" height="6" rounded />
              </template>
              <span v-else class="text-caption text-medium-emphasis">{{ gb(c.storage_used) }} · unlimited</span>
            </td>
          </tr>
          <tr v-if="!props.breakdown.length"><td colspan="7" class="text-center py-10 text-medium-emphasis">No companies yet.</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </VContainer>
</template>
