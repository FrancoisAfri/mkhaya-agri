<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute

const props = defineProps<{
  queue: { pending: number; failed: number; failed24: number; oldestAge: string | null; recent: any[] }
  ai: any
  notifications: any
  database: { available: boolean; size?: string; top_tables?: any[]; error?: string }
  tenancy: { companies: number; active: number; trial: number; suspended: number; users: number }
  system: any
  generated_at: string
}>()

function refresh() { router.reload({ preserveScroll: true }) }
function retryFailed() { router.post(route('tenancy.system.queue.retry'), {}, { preserveScroll: true }) }
function flushFailed() { if (confirm('Clear the failed jobs table?')) router.post(route('tenancy.system.queue.flush'), {}, { preserveScroll: true }) }

function num(n: any) { return Number(n ?? 0).toLocaleString() }
const healthColor = (failed: number) => failed > 0 ? 'error' : 'success'
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex flex-wrap align-center justify-space-between mb-6 gap-3">
      <div>
        <h3 class="text-h5 font-weight-bold mb-1">System Monitoring</h3>
        <p class="text-body-2 text-medium-emphasis mb-0">Queues, AI usage, notifications, database and server health · as of {{ props.generated_at }}</p>
      </div>
      <div class="d-flex gap-2">
        <Link :href="route('tenancy.overview')"><VBtn variant="tonal" prepend-icon="tabler-arrow-left">Overview</VBtn></Link>
        <VBtn variant="tonal" prepend-icon="tabler-refresh" @click="refresh">Refresh</VBtn>
      </div>
    </div>

    <VRow class="mb-2">
      <VCol cols="6" md="3">
        <VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4">
          <div class="text-caption text-medium-emphasis mb-1">Pending jobs</div>
          <div class="text-h6 font-weight-bold">{{ num(props.queue.pending) }}</div>
          <div class="text-caption text-medium-emphasis">{{ props.queue.oldestAge ? 'oldest ' + props.queue.oldestAge : 'queue empty' }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4">
          <div class="text-caption text-medium-emphasis mb-1">Failed jobs</div>
          <div class="text-h6 font-weight-bold" :class="`text-${healthColor(props.queue.failed)}`">{{ num(props.queue.failed) }}</div>
          <div class="text-caption text-medium-emphasis">{{ num(props.queue.failed24) }} in last 24h</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4">
          <div class="text-caption text-medium-emphasis mb-1">Database size</div>
          <div class="text-h6 font-weight-bold">{{ props.database.size ?? '—' }}</div>
          <div class="text-caption text-medium-emphasis">{{ num(props.tenancy.companies) }} companies</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4">
          <div class="text-caption text-medium-emphasis mb-1">Server disk</div>
          <div class="text-h6 font-weight-bold" :class="props.system.disk_used_pct > 90 ? 'text-error' : ''">{{ props.system.disk_used_pct }}%</div>
          <div class="text-caption text-medium-emphasis">{{ props.system.disk_free }} free · app volume</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" lg="6">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardItem>
            <VCardTitle class="text-subtitle-1 d-flex align-center justify-space-between">
              <span><VIcon size="20" class="me-2">tabler-stack-2</VIcon>Queue</span>
              <span class="d-flex gap-2">
                <VBtn size="x-small" variant="tonal" color="warning" :disabled="!props.queue.failed" @click="retryFailed">Retry all</VBtn>
                <VBtn size="x-small" variant="tonal" color="error" :disabled="!props.queue.failed" @click="flushFailed">Flush</VBtn>
              </span>
            </VCardTitle>
          </VCardItem>
          <VTable density="compact">
            <thead><tr><th>Job</th><th>Queue</th><th>Error</th><th>Failed</th></tr></thead>
            <tbody>
              <tr v-for="j in props.queue.recent" :key="j.id">
                <td class="text-truncate" style="max-width:160px">{{ j.name }}</td>
                <td>{{ j.queue }}</td>
                <td class="text-error text-caption text-truncate" style="max-width:240px">{{ j.error }}</td>
                <td class="text-caption">{{ j.failed_at }}</td>
              </tr>
              <tr v-if="!props.queue.recent.length"><td colspan="4" class="text-center py-8 text-medium-emphasis">No failed jobs 🎉</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" lg="6">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardItem><VCardTitle class="text-subtitle-1"><VIcon size="20" class="me-2" color="primary">tabler-robot</VIcon>AI usage (30 days)</VCardTitle></VCardItem>
          <VCardText v-if="props.ai.available">
            <VRow class="mb-2">
              <VCol cols="6" sm="3"><div class="text-caption text-medium-emphasis">Calls today</div><div class="font-weight-bold">{{ num(props.ai.today) }}</div></VCol>
              <VCol cols="6" sm="3"><div class="text-caption text-medium-emphasis">Calls 30d</div><div class="font-weight-bold">{{ num(props.ai.calls30) }}</div></VCol>
              <VCol cols="6" sm="3"><div class="text-caption text-medium-emphasis">Tokens 30d</div><div class="font-weight-bold">{{ num(props.ai.tokens30) }}</div></VCol>
              <VCol cols="6" sm="3"><div class="text-caption text-medium-emphasis">Cost 30d</div><div class="font-weight-bold">${{ props.ai.cost30 }}</div></VCol>
            </VRow>
            <div class="d-flex gap-4 mb-3">
              <VChip size="small" :color="props.ai.success_rate >= 95 ? 'success' : 'warning'" variant="tonal" label>{{ props.ai.success_rate }}% success</VChip>
              <VChip size="small" variant="tonal" label>{{ num(props.ai.avg_latency_ms) }} ms avg</VChip>
            </div>
            <VTable density="compact">
              <thead><tr><th>Feature</th><th class="text-end">Calls</th><th class="text-end">Tokens</th></tr></thead>
              <tbody>
                <tr v-for="f in props.ai.by_feature" :key="f.feature"><td>{{ f.feature }}</td><td class="text-end">{{ num(f.calls) }}</td><td class="text-end">{{ num(f.tokens) }}</td></tr>
                <tr v-if="!props.ai.by_feature.length"><td colspan="3" class="text-center py-6 text-medium-emphasis">No AI activity yet.</td></tr>
              </tbody>
            </VTable>
          </VCardText>
          <VCardText v-else class="text-medium-emphasis">AI usage logging is not available.</VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" lg="6">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardItem><VCardTitle class="text-subtitle-1"><VIcon size="20" class="me-2">tabler-database</VIcon>Largest tables</VCardTitle></VCardItem>
          <VTable density="compact" v-if="props.database.available">
            <thead><tr><th>Table</th><th class="text-end">Size</th></tr></thead>
            <tbody>
              <tr v-for="t in props.database.top_tables" :key="t.name"><td>{{ t.name }}</td><td class="text-end">{{ t.size }}</td></tr>
            </tbody>
          </VTable>
          <VCardText v-else class="text-error">{{ props.database.error || 'Database stats unavailable.' }}</VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" lg="6">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardItem><VCardTitle class="text-subtitle-1"><VIcon size="20" class="me-2">tabler-mail</VIcon>Notifications (24h)</VCardTitle></VCardItem>
          <VCardText v-if="props.notifications.available">
            <div class="d-flex gap-4 mb-2">
              <VChip size="small" color="success" variant="tonal" label>{{ num(props.notifications.sent24) }} sent</VChip>
              <VChip size="small" :color="props.notifications.failed24 ? 'error' : 'secondary'" variant="tonal" label>{{ num(props.notifications.failed24) }} failed</VChip>
            </div>
            <div class="text-caption text-medium-emphasis">
              <span v-for="c in props.notifications.by_channel" :key="c.channel" class="me-3">{{ c.channel }}: {{ num(c.count) }}</span>
            </div>
          </VCardText>
          <VCardText v-else class="text-medium-emphasis">No notification log.</VCardText>
        </VCard>

        <VCard elevation="0" border rounded="lg">
          <VCardItem><VCardTitle class="text-subtitle-1"><VIcon size="20" class="me-2">tabler-server-cog</VIcon>Server</VCardTitle></VCardItem>
          <VCardText>
            <VRow dense class="text-body-2">
              <VCol cols="6">PHP <span class="font-weight-medium">{{ props.system.php_version }}</span></VCol>
              <VCol cols="6">Laravel <span class="font-weight-medium">{{ props.system.laravel_version }}</span></VCol>
              <VCol cols="6">Env <VChip size="x-small" label variant="tonal">{{ props.system.environment }}</VChip> <VChip v-if="props.system.debug" size="x-small" color="warning" label variant="tonal">debug</VChip></VCol>
              <VCol cols="6">Edition <VChip size="x-small" label variant="tonal">{{ props.system.edition }}</VChip></VCol>
              <VCol cols="6">Queue <span class="font-weight-medium">{{ props.system.queue_connection }}</span></VCol>
              <VCol cols="6">Cache <span class="font-weight-medium">{{ props.system.cache_driver }}</span></VCol>
              <VCol cols="6">Log size <span class="font-weight-medium">{{ props.system.log_size }}</span></VCol>
              <VCol cols="6">Log errors <span class="font-weight-medium" :class="props.system.recent_errors > 0 ? 'text-error' : ''">{{ num(props.system.recent_errors) }}</span></VCol>
              <VCol cols="6">Users <span class="font-weight-medium">{{ num(props.tenancy.users) }}</span></VCol>
              <VCol cols="6">Companies <span class="font-weight-medium">{{ num(props.tenancy.companies) }}</span></VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
