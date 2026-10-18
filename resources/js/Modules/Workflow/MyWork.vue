<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { when } from './useWorkflow'
import TaskList from './components/TaskList.vue'

const route = ziggyRoute
const props = defineProps<{
  mine: any[]; queue: any[]; delegatedToMe: any[]; waitingOnMe: any[]; recent: any[]
  myDelegations: Array<{ uuid: string; to: string; ends_at: string }>
  counts: { mine: number; approvals: number; overdue: number; due_soon: number; queue: number; delegated: number }
  can: { start: boolean }
}>()
const tab = ref(props.mine.length ? 'mine' : props.queue.length ? 'queue' : props.delegatedToMe.length ? 'delegated' : 'mine')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">My work</h4>
        <div class="text-body-2 text-medium-emphasis">Everything waiting for you across Mkhaya Hub's workflows, the most urgent first.</div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-file-description" @click="router.get(route('workflow.requests'))">My requests</VBtn>
        <VBtn v-if="can.start" color="primary" prepend-icon="tabler-circle-plus" @click="router.get(route('workflow.start'))">Start a request</VBtn>
      </div>
    </div>

    <VRow class="mb-1">
      <VCol v-for="k in [
        { key: 'mine', label: 'Assigned to me', value: counts.mine, color: 'primary', icon: 'tabler-checklist' },
        { key: 'mine', label: 'Approvals', value: counts.approvals, color: 'success', icon: 'tabler-circle-check' },
        { key: 'mine', label: 'Due within a day', value: counts.due_soon, color: 'warning', icon: 'tabler-clock' },
        { key: 'mine', label: 'Overdue', value: counts.overdue, color: 'error', icon: 'tabler-alarm' },
        { key: 'queue', label: 'In my queues', value: counts.queue, color: 'info', icon: 'tabler-users-group' },
        { key: 'delegated', label: 'Delegated to me', value: counts.delegated, color: 'teal', icon: 'tabler-user-share' },
      ]" :key="k.label" cols="6" md="2">
        <VCard border class="h-100" @click="tab = k.key">
          <VCardText class="d-flex align-center gap-3">
            <VAvatar :color="k.color" variant="tonal" rounded size="38"><VIcon :icon="k.icon" /></VAvatar>
            <div><div class="text-h5">{{ k.value }}</div><div class="text-caption text-medium-emphasis">{{ k.label }}</div></div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VAlert v-for="i in waitingOnMe" :key="i.uuid" type="info" variant="tonal" density="compact" class="mb-2">
      An approver needs more information on <a :href="route('workflow.instances.show', i.uuid)">{{ i.reference }} — {{ i.title }}</a>.
    </VAlert>
    <VAlert v-if="myDelegations.length" type="success" variant="tonal" density="compact" class="mb-3">
      Your tasks can also be handled by {{ myDelegations.map(d => `${d.to} (until ${when(d.ends_at, false)})`).join(', ') }}.
      <a :href="route('workflow.delegations.index')">Manage delegations</a>
    </VAlert>

    <VRow>
      <VCol cols="12" md="8">
        <VCard border>
          <VTabs v-model="tab" density="compact">
            <VTab value="mine">Assigned to me ({{ mine.length }})</VTab>
            <VTab value="queue">My queues ({{ queue.length }})</VTab>
            <VTab value="delegated">Delegated to me ({{ delegatedToMe.length }})</VTab>
          </VTabs>
          <VDivider />
          <VWindow v-model="tab">
            <VWindowItem value="mine"><TaskList :tasks="mine" empty="Nothing is waiting for you. Tasks and approvals appear here as soon as a request reaches you." /></VWindowItem>
            <VWindowItem value="queue"><TaskList :tasks="queue" empty="No unclaimed work in your queues." /></VWindowItem>
            <VWindowItem value="delegated"><TaskList :tasks="delegatedToMe" show-assignee empty="Nobody has delegated work to you right now." /></VWindowItem>
          </VWindow>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">Recently completed by me</VCardTitle></VCardItem>
          <TaskList :tasks="recent" empty="Your decisions will be listed here." />
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
