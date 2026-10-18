<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { label, when, statusColor } from './useWorkflow'
import RequestPanel from './components/RequestPanel.vue'

const props = defineProps<{ instance: any; values: any[]; record: any; graph: any; timeline: any[]; tasks: any[]; comments: any[]; attachments: any[]; can: any; people: any[] }>()
function back() { if (window.history.length > 1) window.history.back(); else router.get('/workflow/requests') }
const request = { instance: props.instance, values: props.values, record: props.record, graph: props.graph, timeline: props.timeline, tasks: props.tasks, comments: props.comments, attachments: props.attachments, can: props.can }
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-2" @click="back">Back</VBtn>
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ instance.reference }} · {{ instance.workflow }} · version {{ instance.version }}</div>
        <h4 class="text-h4">{{ instance.title }}</h4>
        <div class="text-body-2 text-medium-emphasis">Requested by {{ instance.requester ?? 'the system' }} on {{ when(instance.started_at) }}<span v-if="instance.completed_at"> · finished {{ when(instance.completed_at) }}</span></div>
      </div>
      <div class="d-flex gap-2">
        <VChip v-if="instance.confidential" color="warning" label prepend-icon="tabler-lock">Confidential</VChip>
        <VChip :color="statusColor[instance.status]" label size="large">{{ label(instance.status) }}{{ instance.outcome && instance.status === 'running' ? ` (${instance.outcome}, being recorded)` : '' }}</VChip>
      </div>
    </div>
    <RequestPanel :request="request" />
  </div>
</template>
