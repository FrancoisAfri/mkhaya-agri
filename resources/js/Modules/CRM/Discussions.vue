<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Comment { id: number; entity_type: string; entity_id: number; entity_label: string | null; body: string; author: string; mine: boolean; at: string | null; replies?: Comment[] }
type RecordOption = { value: number; title: string }

const props = defineProps<{ threads: Comment[]; entityTypes: string[]; relatedOptions: Record<string, RecordOption[]>; users: { id: number; name: string }[] }>()

const dialog = ref(false); const replyTo = ref<number | null>(null); const confirm = ref()
const form = useForm({ entity_type: 'deal', entity_id: null as number | null, entity_label: '', parent_id: null as number | null, body: '', mentioned: [] as number[] })
const replyForm = useForm({ entity_type: '', entity_id: null as number | null, parent_id: null as number | null, body: '', mentioned: [] as number[] })

const recordChoices = computed<RecordOption[]>(() => props.relatedOptions[form.entity_type] ?? [])
function onEntityTypeChange() { form.entity_id = null; form.entity_label = '' }
function onRecordPick(id: number | null) { form.entity_label = recordChoices.value.find(r => r.value === id)?.title ?? '' }

function openNew() { form.reset(); dialog.value = true }
function postThread() { form.post(route('crm.discussions.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset() } }) }
function openReply(c: Comment) { replyTo.value = c.id; replyForm.reset(); replyForm.entity_type = c.entity_type; replyForm.entity_id = c.entity_id; replyForm.parent_id = c.id }
function postReply() { replyForm.post(route('crm.discussions.store'), { preserveScroll: true, onSuccess: () => { replyTo.value = null; replyForm.reset() } }) }
function remove(c: Comment) { confirm.value.open({ title: t('crm_discussions_index.delete.title'), message: t('crm_discussions_index.delete.message'), confirmText: t('crm_discussions_index.delete.confirm'), confirmColor: 'error' }, () => useForm({}).delete(route('crm.discussions.destroy', c.id), { preserveScroll: true })) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3"><div><h5 class="text-h5 font-weight-semibold">{{ t('crm_discussions_index.page_title') }}</h5><p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_discussions_index.description') }}</p></div><VBtn color="primary" prepend-icon="tabler-message-plus" @click="openNew">{{ t('crm_discussions_index.new_discussion') }}</VBtn></div>
    <VCard v-for="tp in threads" :key="tp.id" class="mb-3" border elevation="0"><VCardText><div class="d-flex justify-space-between align-start"><div class="d-flex gap-3"><VAvatar color="primary" variant="tonal" size="36"><VIcon icon="tabler-user" size="18" /></VAvatar><div><div class="text-body-2"><strong>{{ tp.author }}</strong> <span class="text-caption text-medium-emphasis">· {{ tp.at }}</span></div><div class="text-caption text-primary">{{ tp.entity_label || `${tp.entity_type} #${tp.entity_id}` }}</div><p class="text-body-2 mt-1 mb-0" style="white-space: pre-wrap">{{ tp.body }}</p></div></div><VBtn v-if="tp.mine" size="x-small" variant="text" icon="tabler-trash" color="error" @click="remove(tp)" /></div><div v-if="tp.replies && tp.replies.length" class="mt-3 ms-8"><div v-for="r in tp.replies" :key="r.id" class="d-flex justify-space-between align-start mb-2"><div class="d-flex gap-2"><VAvatar color="blue-grey" variant="tonal" size="28"><VIcon icon="tabler-corner-down-right" size="14" /></VAvatar><div><div class="text-caption"><strong>{{ r.author }}</strong> · {{ r.at }}</div><p class="text-body-2 mb-0" style="white-space: pre-wrap">{{ r.body }}</p></div></div><VBtn v-if="r.mine" size="x-small" variant="text" icon="tabler-trash" color="error" @click="remove(r)" /></div></div><div class="mt-2 ms-8"><template v-if="replyTo === tp.id"><VTextarea v-model="replyForm.body" :label="t('crm_discussions_index.reply')" rows="2" density="compact" hide-details class="mb-2" /><VSelect v-model="replyForm.mentioned" :label="t('crm_discussions_index.mention')" multiple chips closable-chips density="compact" hide-details class="mb-2" :items="users.map(u => ({ title: u.name, value: u.id }))" /><div class="d-flex gap-2"><VBtn size="small" color="primary" :loading="replyForm.processing" :disabled="!replyForm.body" @click="postReply">{{ t('crm_discussions_index.post') }}</VBtn><VBtn size="small" variant="text" @click="replyTo = null">{{ t('crm_discussions_index.cancel') }}</VBtn></div></template><VBtn v-else size="x-small" variant="text" prepend-icon="tabler-arrow-back-up" @click="openReply(tp)">{{ t('crm_discussions_index.reply') }}</VBtn></div></VCardText></VCard>
    <VAlert v-if="!threads.length" type="info" variant="tonal">{{ t('crm_discussions_index.no_discussions') }}</VAlert>

    <VDialog v-model="dialog" max-width="560" persistent>
      <VCard><VCardTitle>{{ t('crm_discussions_index.dialog.title') }}</VCardTitle><VCardText><VRow><VCol cols="4"><VSelect v-model="form.entity_type" :label="t('crm_discussions_index.dialog.on')" :items="entityTypes" class="text-capitalize" @update:model-value="onEntityTypeChange" /></VCol><VCol cols="8"><VAutocomplete v-model="form.entity_id" :label="t('crm_discussions_index.dialog.which_record', { entity: form.entity_type })" :items="recordChoices" :no-data-text="t('crm_discussions_index.dialog.no_records')" clearable @update:model-value="onRecordPick" /></VCol><VCol cols="12"><VTextarea v-model="form.body" :label="t('crm_discussions_index.dialog.message')" rows="3" /></VCol><VCol cols="12"><VSelect v-model="form.mentioned" :label="t('crm_discussions_index.dialog.mention')" multiple chips closable-chips :items="users.map(u => ({ title: u.name, value: u.id }))" /></VCol></VRow></VCardText><VDivider /><VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">{{ t('crm_discussions_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.body || !form.entity_id" @click="postThread">{{ t('crm_discussions_index.dialog.post') }}</VBtn></VCardActions></VCard>
    </VDialog>
    <ConfirmDialog ref="confirm" />
  </div>
</template>
