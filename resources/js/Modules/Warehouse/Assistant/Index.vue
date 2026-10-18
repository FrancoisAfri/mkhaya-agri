<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Rec = { text: string; basis: string; reason: string; assumptions: string[]; impact: string; next: string; approval: string; actions: string[] }
type Answer = { topic: string | null; refused: boolean; title: string | null; period: { from: string; to: string; label: string } | null; warehouse: string | null;
  columns: string[]; rows: Array<Record<string, any>>; sources: string[]; flags: Array<{ level: string; message: string }>; summary: string; recommendations: Rec[] }
type Q = { id: string; question: string; answer: Answer; narration: string; source: string; at: string; drafts: Array<{ uuid: string; rec_index: number; kind: string; status: string; result_reference: string | null }> }
defineProps<{ history: Q[]; suggestions: string[]; can: { quality: boolean } }>()

const form = useForm({ question: '' })
const ask = () => form.post(route('warehouse.assistant.ask'), { preserveScroll: true, onSuccess: () => form.reset() })
const flagging = ref<{ q: Q; rec: number | null } | null>(null)
const note = ref('')
const feedback = (q: Q, verdict: string, rec: number | null = null, n: string | null = null) =>
  router.post(route('warehouse.assistant.feedback', q.id), { verdict, rec, note: n }, { preserveScroll: true, onSuccess: () => { flagging.value = null; note.value = '' } })
const draft = (q: Q, rec: number, kind: string) => router.post(route('warehouse.assistant.draft'), { query: q.id, rec, kind }, { preserveScroll: true })
const drafted = (q: Q, rec: number, kind: string) => q.drafts.find(d => d.rec_index === rec && d.kind === kind && d.status !== 'CANCELLED')
</script>

<template>
  <div class="pa-4" style="max-width: 1100px">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.assistant.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.assistant.subtitle') }}</p>
      </div>
      <VBtn v-if="can.quality" variant="tonal" :href="route('warehouse.assistant.quality')">{{ t('warehouse_management.assistant.quality') }}</VBtn>
    </div>
    <RefusalAlert />
    <VCard border class="mb-4">
      <VCardText class="d-flex gap-2">
        <VTextField v-model="form.question" :label="t('warehouse_management.assistant.ask')" hide-details :disabled="form.processing" @keyup.enter="ask" />
        <VBtn color="primary" :loading="form.processing" @click="ask">{{ t('warehouse_management.assistant.send') }}</VBtn>
      </VCardText>
      <VCardText class="pt-0 d-flex gap-2 flex-wrap">
        <VChip v-for="s in suggestions" :key="s" size="small" variant="outlined" @click="form.question = s; ask()">{{ s }}</VChip>
      </VCardText>
    </VCard>

    <VCard v-for="q in history" :key="q.id" border class="mb-4">
      <VCardItem>
        <VCardTitle class="text-wrap">{{ q.question }}</VCardTitle>
        <VCardSubtitle>{{ new Date(q.at).toLocaleString() }}<span v-if="q.answer.period"> · {{ q.answer.period.label }} ({{ q.answer.period.from }} – {{ q.answer.period.to }})</span><span v-if="q.answer.warehouse"> · {{ q.answer.warehouse }}</span></VCardSubtitle>
      </VCardItem>
      <VCardText>
        <p class="text-body-1">{{ q.narration }}</p>
        <VAlert v-for="f in q.answer.flags" :key="f.message" :type="f.level === 'WARNING' ? 'warning' : 'info'" variant="tonal" density="compact" class="mb-2">{{ f.message }}</VAlert>
        <div v-if="q.answer.rows.length" class="overflow-x-auto mb-2">
          <VTable density="compact">
            <thead><tr><th v-for="c in q.answer.columns" :key="c">{{ t(`warehouse_management.assistant_col.${c}`) }}</th></tr></thead>
            <tbody><tr v-for="(r, i) in q.answer.rows.slice(0, 15)" :key="i"><td v-for="c in q.answer.columns" :key="c">
              <a v-if="r.url && c === q.answer.columns[0]" :href="r.url">{{ r[c] }}</a><template v-else>{{ r[c] ?? '—' }}</template></td></tr></tbody>
          </VTable>
        </div>
        <div v-if="q.answer.sources.length" class="text-caption text-medium-emphasis mb-2">{{ t('warehouse_management.assistant.sources') }}: {{ q.answer.sources.join('; ') }}</div>
        <VCard v-for="(r, i) in q.answer.recommendations" :key="i" variant="tonal" color="primary" class="mb-2">
          <VCardText>
            <div class="text-body-1 font-weight-medium mb-1">{{ r.text }}</div>
            <div class="text-caption"><strong>{{ t('warehouse_management.assistant.based_on') }}:</strong> {{ r.basis }}</div>
            <div class="text-caption"><strong>{{ t('warehouse_management.assistant.why') }}:</strong> {{ r.reason }}</div>
            <div v-if="r.assumptions.length" class="text-caption"><strong>{{ t('warehouse_management.assistant.assumes') }}:</strong> {{ r.assumptions.join(' ') }}</div>
            <div class="text-caption"><strong>{{ t('warehouse_management.assistant.impact') }}:</strong> {{ r.impact }}</div>
            <div class="text-caption"><strong>{{ t('warehouse_management.assistant.next') }}:</strong> {{ r.next }}</div>
            <div class="text-caption"><strong>{{ t('warehouse_management.assistant.approval') }}:</strong> {{ r.approval }}</div>
            <div class="d-flex gap-2 mt-2 flex-wrap">
              <template v-for="k in r.actions.filter(a => a !== 'TASK')" :key="k">
                <VChip v-if="drafted(q, i, k)" size="small" color="success">{{ t(`warehouse_management.assistant_action.${k}`) }} · {{ drafted(q, i, k)?.result_reference }}</VChip>
                <VBtn v-else size="small" variant="flat" color="primary" @click="draft(q, i, k)">{{ t(`warehouse_management.assistant_action.${k}`) }}</VBtn>
              </template>
              <VBtn size="small" variant="text" @click="feedback(q, 'USEFUL', i)">{{ t('warehouse_management.assistant.useful') }}</VBtn>
              <VBtn size="small" variant="text" @click="feedback(q, 'NOT_USEFUL', i)">{{ t('warehouse_management.assistant.not_useful') }}</VBtn>
            </div>
          </VCardText>
        </VCard>
        <div class="d-flex gap-2 align-center">
          <span class="text-caption text-medium-emphasis">{{ q.source === 'MODEL' ? t('warehouse_management.assistant.worded_by_ai') : t('warehouse_management.assistant.worded_plainly') }}</span>
          <VSpacer />
          <VBtn size="x-small" variant="text" @click="feedback(q, 'CORRECT')">{{ t('warehouse_management.assistant.correct') }}</VBtn>
          <VBtn size="x-small" variant="text" color="error" @click="flagging = { q, rec: null }">{{ t('warehouse_management.assistant.wrong') }}</VBtn>
        </div>
      </VCardText>
    </VCard>
    <VDialog :model-value="flagging !== null" max-width="440" @update:model-value="v => !v && (flagging = null)">
      <VCard v-if="flagging" :title="t('warehouse_management.assistant.wrong')">
        <VCardText><VTextarea v-model="note" rows="3" :label="t('warehouse_management.assistant.what_wrong')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="flagging = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="error" @click="feedback(flagging.q, 'INCORRECT', flagging.rec, note)">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
