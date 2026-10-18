<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, when, due, statusColor, decisionLabel, decisionColor } from './useWorkflow'
import DynamicForm from './components/DynamicForm.vue'
import RequestPanel from './components/RequestPanel.vue'

const route = ziggyRoute
const props = defineProps<{
  task: any; request: any; fields: any[]; decisions: string[]; onBehalfOf: string | null
  can: { act: boolean; claim: boolean; release: boolean; delegate: boolean; reassign: boolean; summarise: boolean }
  people: Array<{ id: number; name: string }>
}>()

const values = ref<Record<string, any>>({})
const decideForm = useForm({ decision: '', comment: '', values: {} as Record<string, any> })
const chosen = ref<string | null>(null)
// Decisions that change the outcome ask for a reason; approving and completing take an optional note.
const needsReason = computed(() => ['rejected', 'returned', 'info_requested', 'cancelled'].includes(chosen.value ?? ''))
function choose(d: string) { chosen.value = d; decideForm.clearErrors() }
function decide() {
  decideForm.transform(() => ({ decision: chosen.value, comment: decideForm.comment, values: values.value })).post(route('workflow.tasks.decide', props.task.uuid))
}

const handForm = useForm({ user_id: null as number | null, reason: '', as_manager: false })
const handDialog = ref(false)
function openHand(asManager: boolean) { handForm.reset(); handForm.as_manager = asManager; handDialog.value = true }
function hand() { handForm.post(route('workflow.tasks.reassign', props.task.uuid), { onSuccess: () => { handDialog.value = false } }) }

const summary = ref<{ text: string; source: string } | null>(null)
const summarising = ref(false)
async function summarise() {
  summarising.value = true
  const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
  try {
    const r = await fetch(route('workflow.tasks.summary', props.task.uuid), { method: 'POST', headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' }, credentials: 'same-origin' })
    summary.value = await r.json()
  } finally { summarising.value = false }
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-2" @click="router.get(route('workflow.my_work'))">My work</VBtn>
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ request.instance.reference }} · {{ request.instance.workflow }}</div>
        <h4 class="text-h4">{{ task.name }}</h4>
        <div class="text-body-2 text-medium-emphasis">{{ request.instance.title }} · requested by {{ request.instance.requester }}</div>
      </div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <VChip v-if="task.status === 'open' && task.due_at" :color="task.sla_state === 'paused' ? 'grey' : due(task.due_at).color" label>{{ task.sla_state === 'paused' ? 'Clock paused' : due(task.due_at).text }}</VChip>
        <VChip :color="statusColor[task.decision ?? task.status]" label>{{ label(task.decision ?? task.status) }}</VChip>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="5" order-md="2">
        <VCard border class="mb-3">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ task.status === 'open' ? 'Your decision' : 'Decision' }}</VCardTitle>
            <VCardSubtitle v-if="task.assignee || task.queue">{{ task.queue ? 'In a queue — claim it to work on it' : `Assigned to ${task.assignee}` }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VAlert v-if="task.type === 'exception'" type="error" variant="tonal" density="compact" class="mb-3">{{ task.comment }}
              <div v-if="task.node_type === 'approval'" class="mt-1">Hand this approval to a person who may approve it (Reassign), or cancel the request.</div>
            </VAlert>
            <div v-if="task.instructions" class="text-body-2 mb-3" style="white-space: pre-wrap">{{ task.instructions }}</div>
            <VAlert v-if="onBehalfOf" type="info" variant="tonal" density="compact" class="mb-3">You are acting for {{ onBehalfOf }} under their delegation. The record shows both names.</VAlert>
            <VAlert v-if="task.decision === 'info_requested' && task.status === 'open'" type="warning" variant="tonal" density="compact" class="mb-3">Waiting for the requester to answer your question.</VAlert>

            <template v-if="task.status !== 'open'">
              <div class="text-body-2">{{ label(task.decision) }} by {{ task.decided_by ?? '—' }}<span v-if="task.on_behalf_of"> for {{ task.on_behalf_of }}</span> · {{ when(task.completed_at) }}</div>
              <div v-if="task.comment" class="text-body-2 mt-2" style="white-space: pre-wrap">“{{ task.comment }}”</div>
            </template>

            <template v-else-if="can.act">
              <DynamicForm v-if="fields.length" v-model="values" :fields="fields" :errors="decideForm.errors" :people="people" :context="{ record: request.record }" class="mb-3" />
              <div class="d-flex flex-wrap gap-2 mb-3">
                <VBtn v-for="d in decisions" :key="d" :color="decisionColor[d]" :variant="chosen === d ? 'flat' : 'tonal'" @click="choose(d)">{{ decisionLabel[d] }}</VBtn>
              </div>
              <template v-if="chosen">
                <VTextarea v-model="decideForm.comment" :label="needsReason ? (chosen === 'info_requested' ? 'What do you need to know?' : 'Reason (required)') : 'Comment (optional)'"
                           rows="3" auto-grow :error-messages="decideForm.errors.comment" />
                <VBtn :color="decisionColor[chosen]" block :loading="decideForm.processing" :disabled="needsReason && decideForm.comment.trim().length < 3" @click="decide">{{ decisionLabel[chosen] }}</VBtn>
              </template>
            </template>

            <template v-else-if="can.claim">
              <p class="text-body-2 mb-3">This task waits in a queue you belong to. Claim it to work on it; others in the queue then no longer see it as open.</p>
              <VBtn color="primary" block prepend-icon="tabler-hand-grab" @click="router.post(route('workflow.tasks.claim', task.uuid), {}, { preserveScroll: true })">Claim</VBtn>
            </template>
            <div v-else class="text-body-2 text-medium-emphasis">You can see this task but it is not yours to decide.</div>
          </VCardText>
          <VCardActions v-if="task.status === 'open' && (can.release || can.delegate || can.reassign)" class="flex-wrap">
            <VBtn v-if="can.release" size="small" variant="text" @click="router.post(route('workflow.tasks.release', task.uuid), {}, { preserveScroll: true })">Return to queue</VBtn>
            <VBtn v-if="can.delegate" size="small" variant="text" prepend-icon="tabler-user-share" @click="openHand(false)">Delegate</VBtn>
            <VBtn v-if="can.reassign" size="small" variant="text" prepend-icon="tabler-switch-horizontal" @click="openHand(true)">Reassign</VBtn>
          </VCardActions>
        </VCard>

        <VCard v-if="can.summarise" border class="mb-3">
          <VCardItem><VCardTitle class="text-body-1">Summary</VCardTitle>
            <template #append><VBtn size="small" variant="tonal" prepend-icon="tabler-sparkles" :loading="summarising" @click="summarise">Summarise</VBtn></template>
          </VCardItem>
          <VCardText v-if="summary">
            <div class="text-body-2" style="white-space: pre-wrap">{{ summary.text }}</div>
            <div class="text-caption text-medium-emphasis mt-1">{{ summary.source === 'ai' ? 'Written by AI from what you can see; check it against the request.' : 'Compiled from the request history.' }}</div>
          </VCardText>
        </VCard>

        <VCard v-if="task.history.length" border>
          <VCardItem><VCardTitle class="text-body-1">Assignment history</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="(h, i) in task.history" :key="i">
              <VListItemTitle class="text-body-2">{{ label(h.action) }}{{ h.to ? ` to ${h.to}` : '' }}{{ h.from ? ` from ${h.from}` : '' }}</VListItemTitle>
              <VListItemSubtitle>{{ when(h.at) }}{{ h.by ? ` · by ${h.by}` : '' }}{{ h.reason ? ` · ${h.reason}` : '' }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="7" order-md="1">
        <RequestPanel :request="request" :show-actions="false" />
      </VCol>
    </VRow>

    <VDialog v-model="handDialog" max-width="480">
      <VCard :title="handForm.as_manager ? 'Reassign this task' : 'Delegate this task'">
        <VCardText>
          <p class="text-body-2 mb-3">{{ handForm.as_manager ? 'As a process manager you can move the task to anyone who may act on it.' : 'The task moves to the person you choose. You remain on its history.' }}</p>
          <VAutocomplete v-model="handForm.user_id" :items="people" item-title="name" item-value="id" label="Person" :error-messages="handForm.errors.user_id" />
          <VTextarea v-model="handForm.reason" label="Reason" rows="2" auto-grow :error-messages="handForm.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="handDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="handForm.processing" :disabled="!handForm.user_id || handForm.reason.trim().length < 3" @click="hand">{{ handForm.as_manager ? 'Reassign' : 'Delegate' }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
