<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
const route = ziggyRoute
const { t } = useI18n()

interface Seq { id: number; name: string; steps: number; active: boolean; enrollments: number; active_count: number; replied_count: number }
const props = defineProps<{ sequences: Seq[]; aiEnabled: boolean; templates: any[] }>()

function applyTemplate(t: any) {
  useForm({ name: t.name, goal: t.description ?? '', goal_event: t.goal_event ?? '', steps: t.steps ?? [] })
    .post(route('crm.sequences.store'))
}

const dialog = ref(false)
const confirm = ref()
const drafting = ref(false)
const form = useForm({ name: '', goal: '', channel: 'email', steps: [] as any[] })

async function draftSteps() {
  if (!form.goal) return
  drafting.value = true
  try {
    const { data } = await axios.post(route('crm.sequences.draft'), { goal: form.goal, channel: form.channel },
      { headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') as HTMLMetaElement)?.content ?? '' } })
    if (data.steps) form.steps = data.steps
  } finally { drafting.value = false }
}
function create() { form.post(route('crm.sequences.store'), { onSuccess: () => { dialog.value = false; form.reset() } }) }
function remove(s: Seq) {
  confirm.value.open({
    title: t('crm_sequences_index.delete_dialog.title'),
    message: t('crm_sequences_index.delete_dialog.message', { name: s.name }),
    confirmText: t('crm_sequences_index.delete_dialog.confirm_text'),
    confirmColor: 'error'
  }, () => useForm({}).delete(route('crm.sequences.destroy', s.id), { preserveScroll: true }))
}

const headers = computed(() => [
  { title: t('crm_sequences_index.table.sequence'), key: 'name' },
  { title: t('crm_sequences_index.table.steps'), key: 'steps', align: 'end' as const },
  { title: t('crm_sequences_index.table.enrolled'), key: 'enrollments', align: 'end' as const },
  { title: t('crm_sequences_index.table.active'), key: 'active_count', align: 'end' as const },
  { title: t('crm_sequences_index.table.replied'), key: 'replied_count', align: 'end' as const },
  { title: '', key: 'actions', sortable: false },
])

const channelOptions = computed(() => [
  { title: t('crm_sequences_index.channel_email'), value: 'email' },
  { title: t('crm_sequences_index.channel_whatsapp'), value: 'whatsapp' },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_sequences_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_sequences_index.description') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VMenu>
          <template #activator="{ props: menu }">
            <VBtn v-bind="menu" variant="tonal" prepend-icon="tabler-template" append-icon="tabler-chevron-down">Start from template</VBtn>
          </template>
          <VList density="compact" max-width="420">
            <VListItem v-for="t in templates" :key="t.name" :title="t.name" :subtitle="t.description" @click="applyTemplate(t)" />
          </VList>
        </VMenu>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">New sequence</VBtn>
      </div>
    </div>

    <VCard>
      <VDataTable :headers="headers" :items="sequences" :items-per-page="25" density="comfortable">
        <template #item.name="{ item }">
          <a class="text-primary font-weight-medium" @click="router.get(route('crm.sequences.show', item.id))">{{ item.name }}</a>
          <VChip v-if="!item.active" size="x-small" color="warning" label class="ms-1">{{ t('crm_sequences_index.paused') }}</VChip>
        </template>
        <template #item.actions="{ item }">
          <TipBtn size="x-small" variant="text" icon="tabler-pencil" :tip="t('crm_sequences_index.open')" @click="router.get(route('crm.sequences.show', item.id))" />
          <TipBtn size="x-small" variant="text" icon="tabler-trash" color="error" :tip="t('crm_sequences_index.delete')" @click="remove(item)" />
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="dialog" max-width="640">
      <VCard>
        <VCardTitle>{{ t('crm_sequences_index.dialog.title') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="form.name" :label="t('crm_sequences_index.dialog.name')" class="mb-2" :error-messages="form.errors.name" />
          <VTextarea v-model="form.goal" :label="t('crm_sequences_index.dialog.goal')" rows="2" :placeholder="t('crm_sequences_index.dialog.goal_placeholder')" class="mb-2" />
          <div class="d-flex ga-2 align-center mb-2">
            <VSelect v-model="form.channel" :items="channelOptions" :label="t('crm_sequences_index.dialog.default_channel')" density="compact" hide-details style="max-width:180px" />
            <VBtn variant="tonal" prepend-icon="tabler-sparkles" :loading="drafting" :disabled="!aiEnabled || !form.goal" @click="draftSteps">{{ t('crm_sequences_index.dialog.ai_draft') }}</VBtn>
          </div>
          <VAlert v-if="form.steps.length" type="success" variant="tonal" density="compact" class="text-caption">
            {{ t('crm_sequences_index.dialog.steps_drafted', { count: form.steps.length }) }}
          </VAlert>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">{{ t('crm_sequences_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="create">{{ t('crm_sequences_index.dialog.create_build') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>