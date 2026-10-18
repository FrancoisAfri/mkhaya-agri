<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, when, statusColor } from './useWorkflow'

const route = ziggyRoute
const props = defineProps<{ instances: { data: any[]; links: any[]; total?: number }; filters: Record<string, any>; workflows: Array<{ uuid: string; name: string }> }>()
const f = reactive({ status: props.filters.status ?? null, workflow: props.filters.workflow ?? null, sla: props.filters.sla ?? null, priority: props.filters.priority ?? null, search: props.filters.search ?? '' })
function apply() { router.get(route('workflow.instances.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true }) }
</script>

<template>
  <div class="pa-4">
    <h4 class="text-h4">Requests in the system</h4>
    <div class="text-body-2 text-medium-emphasis mb-4">Every request you are allowed to see, where it is and who it waits on. Confidential processes appear only to their named members and participants.</div>
    <VCard border>
      <VCardText class="d-flex flex-wrap gap-3 pb-0">
        <VTextField v-model="f.search" placeholder="Reference, title or record" prepend-inner-icon="tabler-search" density="compact" hide-details style="min-inline-size: 240px" @keydown.enter="apply" />
        <VSelect v-model="f.status" :items="['running', 'completed', 'rejected', 'cancelled', 'failed', 'suspended'].map(s => ({ title: label(s), value: s }))" label="Status" clearable density="compact" hide-details style="min-inline-size: 150px" @update:model-value="apply" />
        <VSelect v-model="f.workflow" :items="workflows" item-title="name" item-value="uuid" label="Workflow" clearable density="compact" hide-details style="min-inline-size: 200px" @update:model-value="apply" />
        <VSelect v-model="f.sla" :items="[{ title: 'Due soon', value: 'warning' }, { title: 'Overdue', value: 'breached' }]" label="SLA" clearable density="compact" hide-details style="min-inline-size: 140px" @update:model-value="apply" />
        <VSelect v-model="f.priority" :items="['high', 'normal', 'low']" label="Priority" clearable density="compact" hide-details style="min-inline-size: 130px" @update:model-value="apply" />
      </VCardText>
      <VTable density="comfortable">
        <thead><tr><th>Reference</th><th>Request</th><th>Status</th><th>Waiting on</th><th>Requester</th><th>Started</th></tr></thead>
        <tbody>
          <tr v-for="i in instances.data" :key="i.uuid" style="cursor: pointer" @click="router.get(route('workflow.instances.show', i.uuid))">
            <td class="text-no-wrap">{{ i.reference }}<VIcon v-if="i.confidential" icon="tabler-lock" size="14" color="warning" class="ms-1" /></td>
            <td><div>{{ i.title }}</div><div class="text-caption text-medium-emphasis">{{ i.workflow }}{{ i.subject_ref ? ` · ${i.subject_ref}` : '' }}</div></td>
            <td><VChip size="small" :color="statusColor[i.status]" label>{{ label(i.status) }}</VChip><VChip v-if="i.sla" size="x-small" :color="statusColor[i.sla]" class="ms-1" label>{{ i.sla === 'breached' ? 'overdue' : 'due soon' }}</VChip>
              <div v-if="i.error" class="text-caption text-error">{{ i.error }}</div></td>
            <td class="text-body-2">{{ i.waiting_on || '—' }}</td>
            <td class="text-body-2">{{ i.requester ?? 'system' }}</td>
            <td class="text-body-2 text-no-wrap">{{ when(i.started_at) }}</td>
          </tr>
          <tr v-if="!instances.data.length"><td colspan="6" class="text-medium-emphasis">Nothing matches.</td></tr>
        </tbody>
      </VTable>
      <VCardText v-if="instances.links.length > 3" class="d-flex gap-1 flex-wrap">
        <VBtn v-for="(l, i) in instances.links" :key="i" size="small" :variant="l.active ? 'tonal' : 'text'" :disabled="!l.url" @click="l.url && router.get(l.url)"><span v-html="l.label" /></VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
