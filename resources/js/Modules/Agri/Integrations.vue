<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from './Components/PageErrors.vue'
import { human } from './Components/agri'

/** Phase 2 §14: devices, every integration message, the failed queue, reconciliation. */
const props = defineProps<{ from: string; to: string; status: string | null; reconciliation: any[]; events: any[]; devices: any[] }>()
const f = ref({ from: props.from, to: props.to, status: props.status })
const apply = () => router.get(route('agri.integrations.index'), { ...f.value, status: f.value.status || undefined }, { preserveState: false })
const retry = (e: any) => router.post(route('agri.integrations.retry', e.uuid), {}, { preserveScroll: true })
const open = ref<any>(null)
const COLOR: Record<string, string> = { processed: 'success', failed: 'warning', dead: 'error', received: 'info' }
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">Integrations</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Every message is kept before it is acted on, processed once, and retried if it fails — a retry never duplicates a record.</p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <VTextField v-model="f.from" type="date" label="From" density="compact" hide-details style="max-inline-size: 160px" />
        <VTextField v-model="f.to" type="date" label="To" density="compact" hide-details style="max-inline-size: 160px" />
        <VSelect v-model="f.status" :items="['processed', 'failed', 'dead']" label="Status" clearable density="compact" hide-details style="max-inline-size: 150px" />
        <VBtn variant="tonal" @click="apply">Apply</VBtn>
      </div>
    </div>

    <VCard title="Reconciliation" class="mb-4"><VTable density="compact">
      <thead><tr><th>Integration</th><th class="text-end">Received</th><th class="text-end">Processed</th><th class="text-end">Failed</th><th class="text-end">Dead</th><th class="text-end">Repeats answered</th><th>Last</th></tr></thead>
      <tbody><tr v-for="r in reconciliation" :key="r.integration"><td>{{ human(r.integration) }}</td><td class="text-end">{{ r.received }}</td><td class="text-end">{{ r.processed }}</td>
        <td class="text-end" :class="r.failed ? 'text-warning' : ''">{{ r.failed }}</td><td class="text-end" :class="r.dead ? 'text-error' : ''">{{ r.dead }}</td><td class="text-end">{{ r.replays_answered }}</td><td>{{ r.last }}</td></tr>
        <tr v-if="!reconciliation.length"><td colspan="7" class="text-center text-medium-emphasis py-4">No messages in this period.</td></tr></tbody>
    </VTable></VCard>

    <VCard title="Messages" class="mb-4"><VTable density="compact">
      <thead><tr><th>When</th><th>Integration</th><th>Key</th><th>Status</th><th>Error</th><th /></tr></thead>
      <tbody><tr v-for="e in events" :key="e.uuid"><td class="text-no-wrap">{{ e.at }}</td><td>{{ human(e.integration) }} <span class="text-caption">{{ e.direction }}</span></td>
        <td class="text-caption">{{ e.idempotency_key }}</td>
        <td><VChip size="x-small" :color="COLOR[e.status]">{{ human(e.status) }}</VChip><div class="text-caption">{{ e.attempts }} attempt(s)<template v-if="e.replays"> · {{ e.replays }} repeat(s)</template></div></td>
        <td class="text-caption text-error">{{ e.error }}<div v-if="e.next_attempt_at && e.status === 'failed'" class="text-medium-emphasis">next try {{ e.next_attempt_at }}</div></td>
        <td class="text-end text-no-wrap"><VBtn size="x-small" variant="text" @click="open = e">View</VBtn><VBtn v-if="['failed', 'dead'].includes(e.status)" size="x-small" color="primary" variant="tonal" @click="retry(e)">Retry</VBtn></td></tr>
        <tr v-if="!events.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No messages.</td></tr></tbody>
    </VTable></VCard>

    <VCard title="Devices"><VTable density="compact">
      <thead><tr><th>Device</th><th>Kind</th><th>May send</th><th>Farm</th><th>Last seen</th><th class="text-end">Messages</th></tr></thead>
      <tbody><tr v-for="(d, i) in devices" :key="i"><td>{{ d.name }} <VChip v-if="!d.active" size="x-small">Off</VChip></td><td>{{ human(d.kind) }}</td><td class="text-caption">{{ d.scope }}</td>
        <td>{{ d.farm }}</td><td>{{ d.last_seen_at ?? 'never' }}</td><td class="text-end">{{ d.events }}</td></tr>
        <tr v-if="!devices.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No devices. Register them under Labour settings → Devices.</td></tr></tbody>
    </VTable></VCard>

    <VDialog :model-value="!!open" max-width="720" @update:model-value="v => { if (!v) open = null }">
      <VCard v-if="open" :title="`${human(open.integration)} · ${open.idempotency_key}`"><VCardText>
        <div class="text-subtitle-2">Payload</div><pre class="text-caption" style="white-space: pre-wrap">{{ JSON.stringify(open.payload, null, 2) }}</pre>
        <div class="text-subtitle-2 mt-2">Result</div><pre class="text-caption" style="white-space: pre-wrap">{{ JSON.stringify(open.result, null, 2) }}</pre>
      </VCardText></VCard>
    </VDialog>
  </div>
</template>
