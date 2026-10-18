<script setup lang="ts">
import { computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { when } from './useWorkflow'

const route = ziggyRoute
const props = defineProps<{
  settings: Array<{ key: string; label: string; value: any; rule: string }>
  modules: Array<{ key: string; label: string; enabled: boolean; workflow: string | null }>
  actions: Array<{ key: string; label: string; risk: string; permitted: boolean }>
  health: Record<string, any>
}>()
const val = (k: string) => props.settings.find(s => s.key === k)?.value
const form = useForm({
  publication_requires_review: !!val('publication_requires_review'), self_approval: val('self_approval'), unassigned_policy: val('unassigned_policy'),
  deactivation_policy: val('deactivation_policy'), default_sla_hours: val('default_sla_hours'), ai_enabled: !!val('ai_enabled'), ai_may_read_data: !!val('ai_may_read_data'),
  max_nodes: val('max_nodes'), retention_days: val('retention_days'), email_notifications: !!val('email_notifications'),
  categories: [...(val('categories') ?? [])] as string[],
  permitted_actions: props.actions.filter(a => a.risk === 'material' && a.permitted).map(a => a.key),
  modules: Object.fromEntries(props.modules.map(m => [m.key, m.enabled])),
  reason: '',
})
const material = computed(() => props.actions.filter(a => a.risk === 'material'))
const healthy = computed(() => props.health.tick_last && Date.now() - new Date(props.health.tick_last).getTime() < 5 * 60 * 1000)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div><h4 class="text-h4">Workflow administration</h4><div class="text-body-2 text-medium-emphasis">This company's workflow policy. Every change is audited with its reason; no other company's settings are affected.</div></div>
      <div class="d-flex gap-2"><VBtn variant="tonal" prepend-icon="tabler-calendar-time" @click="router.get(route('workflow.calendars.index'))">Calendars</VBtn><VBtn variant="tonal" prepend-icon="tabler-shield-search" @click="router.get(route('workflow.audit'))">Audit trail</VBtn></div>
    </div>
    <VRow>
      <VCol cols="12" md="7">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">Controls</VCardTitle></VCardItem>
          <VCardText class="d-flex flex-column gap-2">
            <VSwitch v-model="form.publication_requires_review" label="A second person reviews a version before it is published" color="primary" inset hide-details />
            <VSelect v-model="form.self_approval" :items="[{ title: 'Never: nobody approves their own request', value: 'deny' }, { title: 'A step may allow it explicitly', value: 'node_decides' }]" label="Self-approval" />
            <VSelect v-model="form.unassigned_policy" :items="[{ title: 'The process owner', value: 'process_owner' }, { title: 'The workflow managers (exception queue)', value: 'exception' }]" label="When nobody can be assigned, the task goes to" />
            <VSelect v-model="form.deactivation_policy" :items="[{ title: 'Their line manager', value: 'line_manager' }, { title: 'The process owner', value: 'process_owner' }, { title: 'The exception queue', value: 'exception' }]" label="A deactivated person's open tasks go to" />
            <div class="d-flex gap-3">
              <VTextField v-model.number="form.default_sla_hours" type="number" label="Default task SLA (hours)" hint="Empty: no deadline unless a step sets one" persistent-hint />
              <VTextField v-model.number="form.retention_days" type="number" label="Keep finished requests (days)" hint="Empty: indefinitely" persistent-hint />
              <VTextField v-model.number="form.max_nodes" type="number" label="Most steps in a workflow" />
            </div>
            <VCombobox v-model="form.categories" label="Workflow categories" multiple chips closable-chips hint="The list designers choose a workflow's category from, and delegations are scoped by. Type a new one and press Enter." persistent-hint :error-messages="form.errors.categories" />
            <VSwitch v-model="form.email_notifications" label="Send email as well as in-app notifications" color="primary" inset hide-details />
          </VCardText>
        </VCard>
        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">Modules on the engine</VCardTitle><VCardSubtitle>Switched on, the module's submissions run through the workflow published for them; its own checks still apply.</VCardSubtitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="m in modules" :key="m.key">
              <VListItemTitle>{{ m.label }}</VListItemTitle>
              <VListItemSubtitle>{{ m.workflow ? `Uses “${m.workflow}”` : 'No published workflow for it yet — install its template under Design.' }}</VListItemSubtitle>
              <template #append><VSwitch v-model="form.modules[m.key]" :disabled="!m.workflow && !m.enabled" color="success" inset hide-details /></template>
            </VListItem>
          </VList>
        </VCard>
        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">Automated actions that change records</VCardTitle><VCardSubtitle>Ordinary actions are always available; these need your permission and an approval before them.</VCardSubtitle></VCardItem>
          <VCardText><VCheckbox v-for="a in material" :key="a.key" v-model="form.permitted_actions" :value="a.key" :label="a.label" density="compact" hide-details /></VCardText>
        </VCard>
        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">AI assistance</VCardTitle></VCardItem>
          <VCardText>
            <VSwitch v-model="form.ai_enabled" label="Offer AI drafting, explanations and reporting" color="primary" inset hide-details />
            <VSwitch v-model="form.ai_may_read_data" :disabled="!form.ai_enabled" label="AI may read request content (task summaries). Confidential workflows are never sent." color="warning" inset hide-details />
            <div class="text-caption text-medium-emphasis mt-1">AI never decides a task or publishes a workflow. Without it, every feature still works.</div>
          </VCardText>
        </VCard>
        <VCard border>
          <VCardText class="d-flex gap-3 align-center flex-wrap">
            <VTextField v-model="form.reason" label="Reason for the change" style="min-inline-size: 280px" :error-messages="form.errors.reason" />
            <VBtn color="primary" :loading="form.processing" :disabled="form.reason.length < 5" @click="form.post(route('workflow.admin.update'), { preserveScroll: true, onSuccess: () => (form.reason = '') })">Save</VBtn>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">Engine health</VCardTitle><VCardSubtitle>The workflow tick runs every minute from the scheduler</VCardSubtitle></VCardItem>
          <VList density="compact">
            <VListItem><VListItemTitle>Last tick</VListItemTitle><template #append><VChip size="small" :color="healthy ? 'success' : 'warning'" label>{{ health.tick_last ? when(health.tick_last) : 'not yet' }}</VChip></template></VListItem>
            <VListItem><VListItemTitle>Timers due now</VListItemTitle><template #append>{{ health.timers_due }}</template></VListItem>
            <VListItem><VListItemTitle>Automated steps retrying</VListItemTitle><template #append>{{ health.actions_pending }}</template></VListItem>
            <VListItem><VListItemTitle>Automated steps failed</VListItemTitle><template #append><span :class="{ 'text-error': health.actions_failed }">{{ health.actions_failed }}</span></template></VListItem>
            <VListItem><VListItemTitle>Events waiting</VListItemTitle><template #append>{{ health.events_pending }}</template></VListItem>
            <VListItem><VListItemTitle>Events failed</VListItemTitle><template #append><span :class="{ 'text-error': health.events_failed }">{{ health.events_failed }}</span></template></VListItem>
          </VList>
          <VCardText><VBtn variant="tonal" block @click="router.get(route('workflow.exceptions'))">Open exceptions</VBtn></VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
