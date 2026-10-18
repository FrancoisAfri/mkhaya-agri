<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface CallAnalysis { talk_ratio: number | null; objections: string[]; commitments: string[]; next_steps: string[]; coaching: string[] }
interface Meeting { id: number; title: string; related_type: string | null; related_id: number | null; status: string; status_message: string | null; summary: string | null; sentiment: string | null; action_items: string[]; call_score: number | null; call_analysis: CallAnalysis | null; transcript: string | null; followup_suggested: boolean; followup_reason: string | null; owner: string | null; created_at: string | null }
type RecordOption = { value: number; title: string }

const props = defineProps<{ meetings: Meeting[]; aiEnabled: boolean; recordOptions: Record<string, RecordOption[]> }>()

const uploadOpen = ref(false); const expanded = ref<number | null>(null); const confirm = ref()
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })

const form = useForm({ title: '', audio: null as File | null, related_type: 'deal', related_id: null as number | null })
const fuForm = useForm({ subject: '', due_at: '' }); const fuTarget = ref<Meeting | null>(null); const fuOpen = ref(false)

const recordChoices = computed<RecordOption[]>(() => props.recordOptions[form.related_type] ?? [])

function submit() { form.post(route('crm.meetings.store'), { preserveScroll: true, forceFormData: true, onSuccess: () => { uploadOpen.value = false; form.reset(); snack.value = { show: true, text: t('crm_meetings_index.toast_uploaded'), color: 'success' } } }) }
function openFollowup(m: Meeting) { fuTarget.value = m; fuForm.reset(); fuForm.subject = t('crm_meetings_index.follow_up') + ': ' + m.title; fuOpen.value = true }
function saveFollowup() { if (!fuTarget.value) return; fuForm.post(route('crm.meetings.followup', fuTarget.value.id), { preserveScroll: true, onSuccess: () => { fuOpen.value = false; snack.value = { show: true, text: t('crm_meetings_index.toast_followup'), color: 'success' } } }) }
function retry(m: Meeting) { router.post(route('crm.meetings.retry', m.id), {}, { preserveScroll: true }) }
function scoreCall(m: Meeting) { router.post(route('crm.meetings.score', m.id), {}, { preserveScroll: true }) }
const scoreColor = (s: number | null) => s == null ? 'grey' : s >= 75 ? 'success' : s >= 50 ? 'warning' : 'error'
function remove(m: Meeting) { confirm.value.open({ title: t('crm_meetings_index.delete.title'), message: t('crm_meetings_index.delete.message', { title: m.title }), confirmText: t('crm_meetings_index.delete.confirm'), confirmColor: 'error' }, () => useForm({}).delete(route('crm.meetings.destroy', m.id), { preserveScroll: true })) }

const statusColor: Record<string, string> = { uploaded: 'info', transcribing: 'warning', done: 'success', failed: 'error' }
const sentimentColor = (s: string | null) => s === 'positive' ? 'success' : s === 'negative' ? 'error' : 'blue-grey'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3"><div><h5 class="text-h5 font-weight-semibold">{{ t('crm_meetings_index.page_title') }}</h5><p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_meetings_index.description') }}</p></div><VBtn color="primary" prepend-icon="tabler-upload" @click="uploadOpen = true">{{ t('crm_meetings_index.upload_recording') }}</VBtn></div>
    <VAlert v-if="!aiEnabled" type="warning" variant="tonal" class="mb-4">{{ t('crm_meetings_index.ai_disabled') }}</VAlert>

    <VRow><VCol v-for="m in meetings" :key="m.id" cols="12" md="6">
      <VCard class="h-100">
        <VCardTitle class="d-flex align-center ga-2 pb-1"><VIcon icon="tabler-microphone" color="primary" /><span class="text-body-1 font-weight-bold text-truncate">{{ m.title }}</span><VSpacer /><VChip :color="statusColor[m.status] ?? 'grey'" size="x-small" label><VProgressCircular v-if="m.status === 'transcribing'" indeterminate size="11" width="2" class="me-1" />{{ m.status }}</VChip></VCardTitle>
        <VCardText class="pt-1">
          <div class="text-caption text-medium-emphasis mb-2">{{ m.owner ?? '—' }} · {{ m.created_at }}<span v-if="m.related_type"> · {{ m.related_type }} #{{ m.related_id }}</span></div>
          <VAlert v-if="m.status === 'failed'" type="error" variant="tonal" density="compact" class="mb-2 text-caption">{{ m.status_message }}<template #append><VBtn size="x-small" variant="text" @click="retry(m)">{{ t('crm_meetings_index.retry') }}</VBtn></template></VAlert>
          <template v-if="m.summary">
            <div class="d-flex align-center ga-2 mb-1"><span class="text-overline text-medium-emphasis">{{ t('crm_meetings_index.ai_summary') }}</span><VChip v-if="m.sentiment" :color="sentimentColor(m.sentiment)" size="x-small" label>{{ m.sentiment }}</VChip></div>
            <p class="text-body-2 mb-2">{{ m.summary }}</p>
            <div v-if="m.action_items.length" class="mb-2"><div class="text-overline text-medium-emphasis">{{ t('crm_meetings_index.action_items') }}</div><ul class="text-body-2 ps-4"><li v-for="(a, i) in m.action_items" :key="i">{{ a }}</li></ul></div>
            <VCard v-if="m.call_analysis" variant="tonal" class="my-2"><VCardText class="py-2">
              <div class="d-flex align-center ga-2 mb-2"><span class="text-overline text-medium-emphasis">{{ t('crm_meetings_index.call_intelligence') }}</span><VChip v-if="m.call_score != null" :color="scoreColor(m.call_score)" size="x-small" label>{{ t('crm_meetings_index.score_label') }} {{ m.call_score }}/100</VChip><VChip v-if="m.call_analysis.talk_ratio != null" size="x-small" variant="outlined">{{ t('crm_meetings_index.rep_talk') }} {{ m.call_analysis.talk_ratio }}%</VChip></div>
              <div v-if="m.call_analysis.objections.length" class="mb-1"><span class="text-caption font-weight-medium">{{ t('crm_meetings_index.objections') }}:</span> <span class="text-caption text-medium-emphasis">{{ m.call_analysis.objections.join('; ') }}</span></div>
              <div v-if="m.call_analysis.commitments.length" class="mb-1"><span class="text-caption font-weight-medium">{{ t('crm_meetings_index.commitments') }}:</span> <span class="text-caption text-medium-emphasis">{{ m.call_analysis.commitments.join('; ') }}</span></div>
              <div v-if="m.call_analysis.next_steps.length" class="mb-1"><span class="text-caption font-weight-medium">{{ t('crm_meetings_index.next_steps') }}:</span> <span class="text-caption text-medium-emphasis">{{ m.call_analysis.next_steps.join('; ') }}</span></div>
              <div v-if="m.call_analysis.coaching.length" class="mt-1"><span class="text-caption font-weight-medium"><VIcon icon="tabler-school" size="13" /> {{ t('crm_meetings_index.coaching') }}:</span><ul class="text-caption text-medium-emphasis ps-4"><li v-for="(c, i) in m.call_analysis.coaching" :key="i">{{ c }}</li></ul></div>
            </VCardText></VCard>
            <div class="d-flex ga-2 align-center"><VBtn v-if="m.transcript" size="x-small" variant="text" @click="expanded = expanded === m.id ? null : m.id">{{ expanded === m.id ? t('crm_meetings_index.hide_transcript') : t('crm_meetings_index.show_transcript') }}</VBtn><VBtn v-if="m.transcript && !m.call_analysis" size="x-small" variant="tonal" color="primary" prepend-icon="tabler-headphones" @click="scoreCall(m)">{{ t('crm_meetings_index.score_call') }}</VBtn></div>
            <div v-if="expanded === m.id" class="text-caption text-medium-emphasis mt-2" style="white-space: pre-wrap; max-height: 240px; overflow-y: auto">{{ m.transcript }}</div>
          </template>
          <p v-else-if="m.status === 'transcribing'" class="text-body-2 text-medium-emphasis">{{ t('crm_meetings_index.transcribing') }}</p>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-2"><VBtn v-if="m.followup_suggested" size="small" variant="tonal" color="primary" prepend-icon="tabler-calendar-plus" @click="openFollowup(m)">{{ t('crm_meetings_index.schedule_follow') }}</VBtn><VBtn v-else size="small" variant="text" prepend-icon="tabler-calendar-plus" @click="openFollowup(m)">{{ t('crm_meetings_index.follow_up') }}</VBtn><VSpacer /><VBtn size="small" variant="text" icon="tabler-trash" color="error" :title="t('crm_meetings_index.delete_tip')" @click="remove(m)" /></VCardActions>
      </VCard>
    </VCol>
    <VCol v-if="!meetings.length" cols="12"><VAlert type="info" variant="tonal">{{ t('crm_meetings_index.no_meetings') }}</VAlert></VCol></VRow>

    <VDialog v-model="uploadOpen" max-width="520" persistent><VCard><VCardTitle>{{ t('crm_meetings_index.upload_dialog.title') }}</VCardTitle><VCardText><VTextField v-model="form.title" :label="t('crm_meetings_index.upload_dialog.meeting_title')" class="mb-2" :error-messages="form.errors.title" /><VFileInput :label="t('crm_meetings_index.upload_dialog.audio_file')" accept="audio/*,video/mp4,video/webm" prepend-icon="tabler-microphone" @update:model-value="(f:any) => form.audio = Array.isArray(f) ? f[0] : f" :error-messages="form.errors.audio" class="mb-2" /><VRow><VCol cols="5"><VSelect v-model="form.related_type" :label="t('crm_meetings_index.upload_dialog.link_to')" :items="['lead','contact','account','deal']" @update:model-value="form.related_id = null" /></VCol><VCol cols="7"><VAutocomplete v-model="form.related_id" :label="t('crm_meetings_index.upload_dialog.which_record', { record: form.related_type })" :items="recordChoices" clearable :no-data-text="t('crm_meetings_index.upload_dialog.no_records')" /></VCol></VRow><p class="text-caption text-medium-emphasis">{{ t('crm_meetings_index.upload_dialog.file_hint') }}</p></VCardText><VDivider /><VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="uploadOpen = false">{{ t('crm_meetings_index.upload_dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.title || !form.audio" @click="submit">{{ t('crm_meetings_index.upload_dialog.upload') }}</VBtn></VCardActions></VCard></VDialog>

    <VDialog v-model="fuOpen" max-width="460" persistent><VCard><VCardTitle>{{ t('crm_meetings_index.followup_dialog.title') }}</VCardTitle><VCardText><VTextField v-model="fuForm.subject" :label="t('crm_meetings_index.followup_dialog.subject')" class="mb-2" /><VTextField v-model="fuForm.due_at" type="datetime-local" :label="t('crm_meetings_index.followup_dialog.when')" /></VCardText><VDivider /><VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="fuOpen = false">{{ t('crm_meetings_index.followup_dialog.cancel') }}</VBtn><VBtn color="primary" :loading="fuForm.processing" :disabled="!fuForm.subject || !fuForm.due_at" @click="saveFollowup">{{ t('crm_meetings_index.followup_dialog.schedule') }}</VBtn></VCardActions></VCard></VDialog>

    <ConfirmDialog ref="confirm" />
    <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">{{ snack.text }}</VSnackbar>
  </div>
</template>