<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, when, statusColor } from './useWorkflow'
import TaskList from './components/TaskList.vue'

const route = ziggyRoute
defineProps<{ actions: any[]; tasks: any[]; instances: any[]; events: any[] }>()
const skipTarget = ref<any>(null)
const skip = useForm({ resolution: '' })
</script>

<template>
  <div class="pa-4">
    <h4 class="text-h4">Exceptions</h4>
    <div class="text-body-2 text-medium-emphasis mb-4">Work that cannot continue on its own: failed automated steps, steps nobody could be assigned to, overdue tasks and undelivered events. Retrying uses the same idempotency key, so nothing is done twice.</div>
    <VRow>
      <VCol cols="12" md="7">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">Automated steps ({{ actions.length }})</VCardTitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>Request</th><th>Action</th><th>Attempts</th><th>Error</th><th /></tr></thead>
            <tbody>
              <tr v-for="a in actions" :key="a.id">
                <td><a :href="route('workflow.instances.show', a.instance.uuid)">{{ a.instance.reference }}</a><div class="text-caption text-medium-emphasis">{{ a.instance.workflow }}</div></td>
                <td>{{ a.action === 'provider.outcome' ? 'Hand the outcome to the module' : label(a.action) }}<div><VChip size="x-small" :color="statusColor[a.status]" label>{{ a.status === 'pending' ? `retrying ${when(a.next)}` : label(a.status) }}</VChip></div></td>
                <td>{{ a.attempts }} / {{ a.max }}</td>
                <td class="text-caption" style="max-inline-size: 260px">{{ a.error }}</td>
                <td class="text-no-wrap">
                  <VBtn size="small" variant="tonal" @click="router.post(route('workflow.actions.retry', a.id), {}, { preserveScroll: true })">Retry now</VBtn>
                  <VBtn size="small" variant="text" @click="skipTarget = a; skip.reset()">Resolve by hand</VBtn>
                </td>
              </tr>
              <tr v-if="!actions.length"><td colspan="5" class="text-medium-emphasis">No failing steps.</td></tr>
            </tbody>
          </VTable>
        </VCard>
        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">Requests with problems ({{ instances.length }})</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="i in instances" :key="i.uuid" :href="route('workflow.instances.show', i.uuid)">
              <VListItemTitle class="text-body-2">{{ i.reference }} — {{ i.title }}</VListItemTitle><VListItemSubtitle class="text-error">{{ i.error }}</VListItemSubtitle>
              <template #append><VChip size="x-small" :color="statusColor[i.status]" label>{{ label(i.status) }}</VChip></template>
            </VListItem>
            <VListItem v-if="!instances.length"><VListItemSubtitle>None.</VListItemSubtitle></VListItem>
          </VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">Tasks needing attention ({{ tasks.length }})</VCardTitle><VCardSubtitle>Unassignable steps and overdue tasks</VCardSubtitle></VCardItem>
          <TaskList :tasks="tasks" show-assignee empty="None." />
        </VCard>
        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">Undelivered events ({{ events.length }})</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="e in events" :key="e.id">
              <VListItemTitle class="text-body-2">{{ e.name }}</VListItemTitle><VListItemSubtitle class="text-caption">{{ e.attempts }} attempt(s) · {{ e.last_error }}</VListItemSubtitle>
              <template #append><VBtn size="small" variant="tonal" @click="router.post(route('workflow.events.retry', e.id), {}, { preserveScroll: true })">Retry</VBtn></template>
            </VListItem>
            <VListItem v-if="!events.length"><VListItemSubtitle>All events delivered.</VListItemSubtitle></VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>
    <VDialog :model-value="!!skipTarget" max-width="500" @update:model-value="skipTarget = null">
      <VCard title="Resolve by hand">
        <VCardText>
          <p class="text-body-2 mb-2">{{ skipTarget?.action === 'provider.outcome' ? 'Confirm the outcome was applied in the module by hand; the request is then shown as finished.' : 'The step is marked skipped and the request continues to the next step.' }}</p>
          <VTextarea v-model="skip.resolution" label="What was done" rows="2" auto-grow />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="skipTarget = null">Cancel</VBtn>
          <VBtn color="primary" :disabled="skip.resolution.length < 10" @click="skip.post(route('workflow.actions.skip', skipTarget.id), { onSuccess: () => (skipTarget = null) })">Resolve</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
