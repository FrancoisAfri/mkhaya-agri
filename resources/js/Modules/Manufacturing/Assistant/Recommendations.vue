<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  recommendations: Array<{ id: string; topic: string; text: string; subject: string | null; url: string | null; status: string; metric: string | null
    baseline: number | null; measured: number | null; outcome: string | null; question: string | null; made_at: string | null }>
  actions: Array<{ id: string; kind: string; status: string; summary: string; owner: string | null; created_by: string | null; due_date: string | null
    requires: string | null; result: string | null; decision_notes: string | null; can_approve: boolean; can_complete: boolean }>
}>()

const tab = ref('actions')
const notes = ref<Record<string, string>>({})
const decide = (id: string, decision: string) => router.post(route('manufacturing.assistant.decide', { uuid: id, decision }), { notes: notes.value[id] || null }, { preserveScroll: true })
const statusColor = (s: string) => ({ DRAFT: 'warning', APPROVED: 'info', EXECUTED: 'success', DONE: 'success', REJECTED: 'grey', PROPOSED: 'warning', ACTED: 'primary', DISMISSED: 'grey' } as Record<string, string>)[s] ?? 'grey'
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.assistant.recommendations') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.assistant.recommendations_subtitle') }}</p>
    </div>
    <VTabs v-model="tab" class="mb-3">
      <VTab value="actions">{{ t('manufacturing.assistant.actions_tab') }}</VTab>
      <VTab value="recommendations">{{ t('manufacturing.assistant.recommendations_tab') }}</VTab>
    </VTabs>
    <VWindow v-model="tab">
      <VWindowItem value="actions">
        <VCard border>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.assistant.what') }}</th><th>{{ t('manufacturing.assistant.owner') }}</th><th>{{ t('manufacturing.assistant.due') }}</th>
              <th>{{ t('manufacturing.assistant.state') }}</th><th /></tr></thead>
            <tbody>
              <tr v-for="a in actions" :key="a.id">
                <td><VChip size="x-small" label class="me-1">{{ t(`manufacturing.assistant.action.${a.kind}`) }}</VChip>{{ a.summary }}
                  <div class="text-caption text-medium-emphasis">{{ t('manufacturing.assistant.raised_by', { name: a.created_by ?? '—' }) }}<span v-if="a.requires"> · {{ t('manufacturing.assistant.approval_by', { permission: a.requires }) }}</span>
                    <span v-if="a.result"> · {{ a.result }}</span><span v-if="a.decision_notes"> · {{ a.decision_notes }}</span></div></td>
                <td>{{ a.owner ?? '—' }}</td><td>{{ a.due_date ?? '—' }}</td>
                <td><VChip size="x-small" label :color="statusColor(a.status)">{{ t(`manufacturing.assistant.status.${a.status}`) }}</VChip></td>
                <td class="text-end text-no-wrap">
                  <template v-if="a.can_approve || a.can_complete">
                    <VTextField v-model="notes[a.id]" density="compact" hide-details :placeholder="t('manufacturing.assistant.notes')" style="min-width: 160px" class="mb-1" />
                    <VBtn v-if="a.can_approve" size="small" color="success" variant="tonal" @click="decide(a.id, 'approve')">{{ t('manufacturing.assistant.approve') }}</VBtn>
                    <VBtn v-if="a.can_approve" size="small" color="error" variant="text" :disabled="!notes[a.id]" @click="decide(a.id, 'reject')">{{ t('manufacturing.assistant.reject') }}</VBtn>
                    <VBtn v-if="a.can_complete" size="small" variant="tonal" @click="decide(a.id, 'complete')">{{ t('manufacturing.assistant.complete') }}</VBtn>
                  </template>
                </td>
              </tr>
              <tr v-if="!actions.length"><td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.assistant.no_actions') }}</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>
      <VWindowItem value="recommendations">
        <VCard border>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.assistant.recommendation') }}</th><th>{{ t('manufacturing.assistant.measure') }}</th><th>{{ t('manufacturing.assistant.outcome') }}</th><th>{{ t('manufacturing.assistant.state') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in recommendations" :key="r.id">
                <td>{{ r.text }} <a v-if="r.url" :href="r.url">{{ r.subject }}</a><div class="text-caption text-medium-emphasis">{{ r.question }} · {{ r.made_at?.slice(0, 10) }}</div></td>
                <td class="text-caption">{{ r.metric ?? '—' }}<span v-if="r.baseline !== null">: {{ r.baseline }}</span></td>
                <td class="text-caption">{{ r.outcome ?? t('manufacturing.assistant.not_measured') }}</td>
                <td><VChip size="x-small" label :color="statusColor(r.status)">{{ t(`manufacturing.assistant.status.${r.status}`) }}</VChip></td>
              </tr>
              <tr v-if="!recommendations.length"><td colspan="4" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.assistant.no_recommendations') }}</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>
    </VWindow>
  </div>
</template>
