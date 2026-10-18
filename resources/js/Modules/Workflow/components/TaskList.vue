<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, due, statusColor } from '../useWorkflow'

const route = ziggyRoute
defineProps<{ tasks: any[]; empty: string; showAssignee?: boolean }>()
</script>

<template>
  <VList v-if="tasks.length" density="compact" lines="two" class="py-0">
    <VListItem v-for="t in tasks" :key="t.uuid" @click="router.get(route('workflow.tasks.show', t.uuid))">
      <template #prepend>
        <VAvatar size="34" :color="t.type === 'approval' ? 'success' : t.type === 'exception' ? 'error' : 'primary'" variant="tonal">
          <VIcon :icon="t.type === 'approval' ? 'tabler-circle-check' : t.type === 'exception' ? 'tabler-alert-octagon' : t.type === 'review' ? 'tabler-eye-check' : 'tabler-user-check'" size="18" />
        </VAvatar>
      </template>
      <VListItemTitle class="text-body-2 font-weight-medium">{{ t.name }}<span v-if="t.priority === 'high'" class="text-error"> · high priority</span></VListItemTitle>
      <VListItemSubtitle>
        <span v-if="t.instance">{{ t.instance.reference }} — {{ t.instance.confidential ? 'Confidential' : t.instance.title }}</span>
        <span v-if="showAssignee"> · {{ t.queue ? 'queue' : t.assignee }}</span>
      </VListItemSubtitle>
      <template #append>
        <div class="d-flex flex-column align-end gap-1">
          <VChip v-if="t.status === 'open'" size="x-small" :color="t.sla_state === 'paused' ? 'grey' : due(t.due_at).color" label>{{ t.sla_state === 'paused' ? 'paused — waiting for information' : due(t.due_at).text }}</VChip>
          <VChip v-else size="x-small" :color="statusColor[t.decision ?? t.status]" label>{{ label(t.decision ?? t.status) }}</VChip>
          <span v-if="t.queue" class="text-caption text-medium-emphasis">queue — claim it</span>
        </div>
      </template>
    </VListItem>
  </VList>
  <VCardText v-else class="text-body-2 text-medium-emphasis">{{ empty }}</VCardText>
</template>
