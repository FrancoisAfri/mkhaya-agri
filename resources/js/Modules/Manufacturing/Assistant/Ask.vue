<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t, te } = useI18n()
const route = ziggyRoute

type Rec = { id?: string; text: string; kind: string; subject_label: string | null; url: string | null; actions: string[] }
type Answer = {
  topic: string | null; title: string; refused: boolean; period: { from: string; to: string; label: string } | null
  columns: string[]; rows: Array<Record<string, any>>; sources: string[]; flags: Array<{ level: string; message: string }>
  recommendations: Rec[]; summary: string
}
type Asked = { id: string; question: string; answer: Answer; narration: string | null; narration_source: string | null; asked_at: string | null }

const props = defineProps<{
  questions: Array<{ topic: string; question: string; allowed: boolean }>
  recent: Asked[]
  people: Array<{ id: number; name: string }>
  can: { act: boolean }
}>()

const question = ref('')
const asking = ref(false)
const error = ref<string | null>(null)
const current = ref<Asked | null>(props.recent[0] ?? null)
const history = ref<Asked[]>([...props.recent])

const ask = async (q?: string) => {
  const text = (q ?? question.value).trim()
  if (text.length < 3) return
  asking.value = true
  error.value = null
  try {
    const { data } = await axios.post(route('manufacturing.assistant.ask'), { question: text })
    current.value = data
    history.value = [data, ...history.value].slice(0, 10)
    question.value = ''
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? t('manufacturing.assistant.failed')
  } finally {
    asking.value = false
  }
}

const kindColor = (k: string) => ({ ACTUAL: 'success', FORECAST: 'info', ESTIMATE: 'warning', RECOMMENDATION: 'primary' } as Record<string, string>)[k] ?? 'grey'
const flagType = (l: string) => (l === 'CONTRADICTORY' ? 'error' : l === 'MISSING' ? 'warning' : 'info') as 'error' | 'warning' | 'info'
const col = (c: string) => te(`manufacturing.assistant.col.${c}`) ? t(`manufacturing.assistant.col.${c}`) : c.replaceAll('_', ' ')
const cell = (v: any) => typeof v === 'number' ? v.toLocaleString(undefined, { maximumFractionDigits: 2 }) : (v ?? '—')

const draftFor = ref<Rec | null>(null)
const draftKind = ref('')
const draftForm = ref({ owner_id: null as number | null, due_date: '', notes: '' })
const openDraft = (r: Rec, kind: string) => { draftFor.value = r; draftKind.value = kind; draftForm.value = { owner_id: null, due_date: '', notes: '' } }
const submitDraft = () => {
  if (!draftFor.value?.id) return
  router.post(route('manufacturing.assistant.draft', draftFor.value.id), { kind: draftKind.value, ...draftForm.value }, { preserveScroll: true, preserveState: true, onSuccess: () => { draftFor.value = null } })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.assistant.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.assistant.subtitle') }}</p>
      </div>
      <VBtn variant="tonal" :href="route('manufacturing.assistant.recommendations')">{{ t('manufacturing.assistant.recommendations') }}</VBtn>
    </div>

    <VCard border class="mb-4">
      <VCardText>
        <div class="d-flex gap-2">
          <VTextField v-model="question" :placeholder="t('manufacturing.assistant.placeholder')" hide-details :disabled="asking" @keyup.enter="ask()" />
          <VBtn color="primary" :loading="asking" @click="ask()">{{ t('manufacturing.assistant.ask') }}</VBtn>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
          <VChip v-for="q in questions" :key="q.topic" size="small" :disabled="!q.allowed || asking" @click="ask(q.question)">{{ q.question }}</VChip>
        </div>
        <VAlert v-if="error" type="error" variant="tonal" density="compact" class="mt-3">{{ error }}</VAlert>
      </VCardText>
    </VCard>

    <VRow>
      <VCol cols="12" lg="9">
        <VCard v-if="current" border>
          <VCardItem>
            <VCardTitle>{{ current.answer.title }}</VCardTitle>
            <VCardSubtitle>“{{ current.question }}”<span v-if="current.answer.period"> · {{ current.answer.period.from }} – {{ current.answer.period.to }} ({{ current.answer.period.label }})</span></VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VAlert :type="current.answer.refused ? 'warning' : 'info'" variant="tonal" class="mb-3">
              <div style="white-space: pre-line">{{ current.narration ?? current.answer.summary }}</div>
              <div class="text-caption mt-1">{{ current.narration_source === 'MODEL' ? t('manufacturing.assistant.worded_by_ai') : t('manufacturing.assistant.worded_plainly') }}</div>
            </VAlert>
            <VAlert v-for="(f, i) in current.answer.flags" :key="i" :type="flagType(f.level)" variant="tonal" density="compact" class="mb-2">
              <span class="font-weight-medium">{{ t(`manufacturing.assistant.flag.${f.level}`) }}:</span> {{ f.message }}
            </VAlert>
          </VCardText>

          <VTable v-if="current.answer.rows.length" density="compact">
            <thead><tr><th v-for="c in current.answer.columns" :key="c">{{ col(c) }}</th><th>{{ t('manufacturing.assistant.kind') }}</th><th>{{ t('manufacturing.assistant.records') }}</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in current.answer.rows" :key="i">
                <td v-for="(c, j) in current.answer.columns" :key="c"><a v-if="j === 0 && r.url" :href="r.url">{{ cell(r[c]) }}</a><span v-else>{{ cell(r[c]) }}</span></td>
                <td><VChip size="x-small" label :color="kindColor(r.kind)">{{ t(`manufacturing.assistant.kinds.${r.kind}`) }}</VChip></td>
                <td class="text-caption">{{ (r.records ?? []).slice(0, 5).join(', ') }}</td>
              </tr>
            </tbody>
          </VTable>

          <VCardText>
            <div class="text-caption text-medium-emphasis mb-3"><span class="font-weight-medium">{{ t('manufacturing.assistant.sources') }}:</span> {{ current.answer.sources.join('; ') || '—' }}</div>
            <div v-if="current.answer.recommendations.length">
              <div class="text-subtitle-1 mb-2">{{ t('manufacturing.assistant.recommended') }}</div>
              <VCard v-for="(r, i) in current.answer.recommendations" :key="i" variant="outlined" class="mb-2">
                <VCardText class="d-flex align-center flex-wrap gap-2">
                  <VChip size="x-small" label color="primary">{{ t('manufacturing.assistant.kinds.RECOMMENDATION') }}</VChip>
                  <span class="flex-grow-1">{{ r.text }} <a v-if="r.url" :href="r.url">{{ r.subject_label }}</a></span>
                  <template v-if="can.act && r.id">
                    <VBtn v-for="a in r.actions" :key="a" size="small" variant="tonal" @click="openDraft(r, a)">{{ t(`manufacturing.assistant.action.${a}`) }}</VBtn>
                  </template>
                </VCardText>
              </VCard>
            </div>
          </VCardText>
        </VCard>
        <VCard v-else border><VCardText class="text-medium-emphasis">{{ t('manufacturing.assistant.start') }}</VCardText></VCard>
      </VCol>
      <VCol cols="12" lg="3">
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.assistant.recent') }}</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="h in history" :key="h.id" :active="current?.id === h.id" @click="current = h">
              <VListItemTitle class="text-wrap">{{ h.question }}</VListItemTitle>
              <VListItemSubtitle>{{ h.asked_at?.slice(0, 16).replace('T', ' ') }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog :model-value="!!draftFor" max-width="560" @update:model-value="v => { if (!v) draftFor = null }">
      <VCard v-if="draftFor">
        <VCardItem><VCardTitle>{{ t(`manufacturing.assistant.action.${draftKind}`) }}</VCardTitle><VCardSubtitle>{{ draftFor.text }}</VCardSubtitle></VCardItem>
        <VCardText>
          <VAlert v-if="['PURCHASE_REQUEST', 'PRIORITISE_ORDER'].includes(draftKind)" type="warning" variant="tonal" density="compact" class="mb-3">{{ t('manufacturing.assistant.needs_approval') }}</VAlert>
          <VAutocomplete v-model="draftForm.owner_id" :items="people" item-title="name" item-value="id" clearable :label="t('manufacturing.assistant.owner')" class="mb-2" />
          <VTextField v-model="draftForm.due_date" type="date" :label="t('manufacturing.assistant.due')" class="mb-2" />
          <VTextarea v-model="draftForm.notes" rows="2" :label="t('manufacturing.assistant.notes')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="draftFor = null">{{ t('manufacturing.assistant.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" @click="submitDraft">{{ t('manufacturing.assistant.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
