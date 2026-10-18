<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Activity {
  id: number; type: string; type_label: string; type_icon: string; subject: string; description: string | null
  related_type: string; related_id: number; related_label: string | null; status: string; due_at: string | null; completed_at: string | null
  ai_meta: { summary?: string; sentiment?: string; action_items?: string[] } | null; owner: string | null; created_at: string | null
}
type RecordOption = { value: number; title: string }

const props = defineProps<{
  activities: Activity[]
  types: Record<string, { label: string; icon: string }>
  aiEnabled: boolean
  relatedOptions: Record<string, RecordOption[]>
}>()

const typeFilter = ref('all')
const dialog = ref(false)
const confirm = ref()
const form = useForm({ type: 'call', subject: '', description: '', related_type: 'deal' as string | null, related_id: null as number | null, due_at: '' })

// Optional follow-up task/reminder created alongside the activity.
const addFollowUp = ref(false)
const followUp = ref({ kind: 'task', due_at: '', note: '' })

const recordChoices = computed<RecordOption[]>(() => props.relatedOptions[form.related_type] ?? [])
const relatedLabels: Record<string, string> = { lead: 'lead', contact: 'contact', account: 'account', deal: 'deal' }
function onRelatedTypeChange() { form.related_id = null }

const filtered = computed(() => typeFilter.value === 'all' ? props.activities : props.activities.filter(a => a.type === typeFilter.value))

function save() {
  form.transform(d => ({ ...d, follow_up: addFollowUp.value ? followUp.value : null }))
    .post(route('crm.activities.store'), {
      preserveScroll: true,
      onSuccess: () => { dialog.value = false; form.reset(); addFollowUp.value = false; followUp.value = { kind: 'task', due_at: '', note: '' } },
    })
}
function complete(a: Activity) { useForm({}).post(route('crm.activities.complete', a.id), { preserveScroll: true }) }
function summarise(a: Activity) { useForm({}).post(route('crm.activities.summarise', a.id), { preserveScroll: true }) }
function remove(a: Activity) {
  confirm.value.open(
    { title: t('crm_activities_index.delete_title'), message: t('crm_activities_index.delete_message', { subject: a.subject }), confirmText: t('crm_activities_index.delete_confirm'), confirmColor: 'error' },
    () => useForm({}).delete(route('crm.activities.destroy', a.id), { preserveScroll: true }),
  )
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_activities_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_activities_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('crm_activities_index.log_activity') }}</VBtn>
    </div>

    <VCard>
      <VCardText>
        <VChipGroup v-model="typeFilter" mandatory>
          <VChip value="all" filter>{{ t('crm_activities_index.all') }}</VChip>
          <VChip v-for="(tp, k) in types" :key="k" :value="k" filter>{{ tp.label }}</VChip>
        </VChipGroup>
      </VCardText>
      <VList lines="two">
        <template v-for="a in filtered" :key="a.id">
          <VListItem>
            <template #prepend>
              <VAvatar :color="a.status === 'done' ? 'success' : 'primary'" variant="tonal" size="38">
                <VIcon :icon="`tabler-${a.type_icon}`" size="20" />
              </VAvatar>
            </template>
            <VListItemTitle class="font-weight-medium">{{ a.subject }}</VListItemTitle>
            <VListItemSubtitle>
              {{ a.type_label }} · {{ a.related_label ?? `${a.related_type} #${a.related_id}` }} · {{ a.created_at }}
              <span v-if="a.due_at && a.status !== 'done'"> · {{ t('crm_activities_index.due') }} {{ a.due_at }}</span>
            </VListItemSubtitle>
            <div v-if="a.ai_meta?.summary" class="mt-1">
              <VChip size="x-small" :color="a.ai_meta.sentiment === 'positive' ? 'success' : a.ai_meta.sentiment === 'negative' ? 'error' : 'blue-grey'" label class="mr-1">
                {{ a.ai_meta.sentiment }}
              </VChip>
              <span class="text-caption text-medium-emphasis">{{ a.ai_meta.summary }}</span>
            </div>
            <template #append>
              <div class="d-flex gap-1">
                <VBtn v-if="aiEnabled && (a.description || a.ai_meta)" size="x-small" variant="tonal" color="primary" @click="summarise(a)">{{ t('crm_activities_index.actions.ai_summary') }}</VBtn>
                <VBtn v-if="a.status !== 'done'" size="x-small" variant="tonal" color="success" @click="complete(a)">{{ t('crm_activities_index.actions.done') }}</VBtn>
                <VBtn size="x-small" variant="text" icon="tabler-trash" color="error" :title="t('crm_activities_index.actions.delete_tip')" @click="remove(a)" />
              </div>
            </template>
          </VListItem>
          <VDivider />
        </template>
        <VListItem v-if="!filtered.length" :title="t('crm_activities_index.no_activities')" />
      </VList>
    </VCard>

    <VDialog v-model="dialog" max-width="520" persistent>
      <VCard>
        <VCardTitle>{{ t('crm_activities_index.dialog.title') }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="6"><VSelect v-model="form.type" label="Type" :items="Object.entries(types).map(([v, t]) => ({ title: t.label, value: v }))" /></VCol>
            <VCol cols="6"><AppDateTimePicker v-model="form.due_at" label="Due (optional)" placeholder="Select date & time" :config="{ enableTime: true, dateFormat: 'Y-m-d H:i', altInput: true, altFormat: 'd M Y, H:i' }" /></VCol>
            <VCol cols="12"><VTextField v-model="form.subject" label="Subject" /></VCol>
            <VCol cols="6">
              <VSelect v-model="form.related_type" label="Related to" :items="['lead','contact','account','deal']"
                clearable placeholder="None (personal)" @update:model-value="onRelatedTypeChange" />
            </VCol>
            <VCol cols="6">
              <VAutocomplete
                v-if="form.related_type"
                v-model="form.related_id"
                :label="t('crm_activities_index.dialog.which_record', { record: relatedLabels[form.related_type] })"
                :items="recordChoices"
                :error-messages="form.errors.related_id"
                :no-data-text="t('crm_activities_index.dialog.no_records')"
                clearable
              />
              <span v-else class="text-caption text-medium-emphasis">Leave 'Related to' empty for a personal task/reminder.</span>
            </VCol>
            <VCol cols="12"><VTextarea v-model="form.description" label="Notes / transcript" rows="3" /></VCol>

            <!-- Optional follow-up task/reminder -->
            <VCol cols="12" class="pt-0">
              <VCheckbox v-model="addFollowUp" label="Create a follow-up task / reminder" density="compact" hide-details />
            </VCol>
            <template v-if="addFollowUp">
              <VCol cols="6"><VSelect v-model="followUp.kind" label="Follow-up type" :items="[{ title: 'Task', value: 'task' }, { title: 'Reminder', value: 'reminder' }]" /></VCol>
              <VCol cols="6"><AppDateTimePicker v-model="followUp.due_at" label="Remind me on" placeholder="Select date & time" :config="{ enableTime: true, dateFormat: 'Y-m-d H:i', altInput: true, altFormat: 'd M Y, H:i', minDate: 'today' }" /></VCol>
              <VCol cols="12"><VTextField v-model="followUp.note" label="Follow-up note (optional)" placeholder="e.g. Call back to confirm quote" /></VCol>
            </template>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">{{ t('crm_activities_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('crm_activities_index.dialog.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
