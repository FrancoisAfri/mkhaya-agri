<script lang="ts" setup>
import { ref, nextTick } from 'vue'
import axios from 'axios'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Reference { type: string; id: number; uuid?: string | null; label: string }
interface Action { type: string; deal_id?: number; stage_name?: string; to?: string; subject?: string; body?: string; related_type?: string; related_id?: number; due_at?: string }
interface Msg { role: 'user' | 'ai'; text: string; references?: Reference[]; action?: Action; actioned?: string }

defineProps<{ aiEnabled: boolean; examples: string[] }>()

const messages = ref<Msg[]>([])
const input = ref('')
const busy = ref(false)
const feed = ref<HTMLElement | null>(null)

function csrf() { return (document.querySelector('meta[name=csrf-token]') as HTMLMetaElement)?.content ?? '' }
async function scroll() { await nextTick(); feed.value?.scrollTo({ top: feed.value.scrollHeight, behavior: 'smooth' }) }

async function send(q?: string) {
  const question = (q ?? input.value).trim()
  if (!question || busy.value) return
  messages.value.push({ role: 'user', text: question })
  input.value = ''
  busy.value = true
  scroll()
  try {
    const { data } = await axios.post(route('crm.copilot.ask'), { question }, { headers: { 'X-CSRF-TOKEN': csrf() } })
    messages.value.push({ role: 'ai', text: data.answer || t('crm_copilot_index.no_answer'), references: data.references ?? [], action: data.action?.type !== 'none' ? data.action : undefined })
  } catch (e: any) {
    messages.value.push({ role: 'ai', text: e?.response?.data?.error ?? t('crm_copilot_index.error_default') })
  } finally { busy.value = false; scroll() }
}

async function runAction(m: Msg) {
  if (!m.action) return
  busy.value = true
  try {
    const { data } = await axios.post(route('crm.copilot.act'), m.action, { headers: { 'X-CSRF-TOKEN': csrf() } })
    m.actioned = data.message
  } finally { busy.value = false }
}

const refLink = (r: Reference) => {
  const map: Record<string, string> = { deal: 'crm.deals', lead: 'crm.leads', account: 'crm.accounts.show', contact: 'crm.contacts', activity: 'crm.activities' }
  try { return r.type === 'account' ? (r.uuid ? route('crm.accounts.show', r.uuid) : '#') : route(map[r.type] ?? 'crm.dashboard') } catch { return '#' }
}
const actionLabel = (a: Action) => ({
  update_deal_stage: t('crm_copilot_index.move_deal', { id: a.deal_id, stage: a.stage_name }),
  create_task: t('crm_copilot_index.create_task', { subject: a.subject }),
  draft_email: t('crm_copilot_index.draft_email', { to: a.to }),
  draft_whatsapp: t('crm_copilot_index.draft_whatsapp', { to: a.to }),
} as Record<string, string>)[a.type] ?? a.type
const isExecutable = (a: Action) => a.type === 'update_deal_stage' || a.type === 'create_task'
</script>

<template>
  <div class="pa-4 d-flex flex-column" style="height: calc(100vh - 180px)">
    <div class="mb-3">
      <h5 class="text-h5 font-weight-semibold mb-0"><VIcon icon="tabler-sparkles" color="primary" class="me-1" />{{ t('crm_copilot_index.page_title') }}</h5>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_copilot_index.description') }}</p>
    </div>

    <VAlert v-if="!aiEnabled" type="warning" variant="tonal" class="mb-3">{{ t('crm_copilot_index.ai_disabled') }}</VAlert>

    <VCard class="flex-grow-1 d-flex flex-column" style="overflow: hidden">
      <div ref="feed" class="flex-grow-1 overflow-y-auto pa-4">
        <div v-if="!messages.length" class="text-center text-medium-emphasis py-6">
          <VIcon icon="tabler-message-chatbot" size="48" class="mb-2" />
          <p class="mb-3">{{ t('crm_copilot_index.try_examples') }}</p>
          <div class="d-flex flex-wrap justify-center ga-2">
            <VChip v-for="ex in examples" :key="ex" variant="tonal" color="primary" @click="send(ex)">{{ ex }}</VChip>
          </div>
        </div>

        <div v-for="(m, i) in messages" :key="i" class="mb-4 d-flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
          <div :style="{ maxWidth: '80%' }">
            <VCard :color="m.role === 'user' ? 'primary' : undefined" :variant="m.role === 'user' ? 'flat' : 'tonal'">
              <VCardText class="py-2 px-3" style="white-space: pre-wrap">{{ m.text }}</VCardText>
            </VCard>
            <div v-if="m.references && m.references.length" class="d-flex flex-wrap ga-1 mt-1">
              <VChip v-for="r in m.references" :key="r.type + r.id" size="x-small" variant="outlined" :href="refLink(r)" target="_blank">
                {{ r.type }}: {{ r.label }}
              </VChip>
            </div>
            <VCard v-if="m.action" variant="outlined" class="mt-2">
              <VCardText class="py-2 px-3">
                <div class="d-flex align-center justify-space-between ga-2">
                  <span class="text-body-2"><VIcon icon="tabler-bolt" size="16" class="me-1" />{{ actionLabel(m.action) }}</span>
                  <VBtn v-if="isExecutable(m.action) && !m.actioned" size="x-small" color="primary" :loading="busy" @click="runAction(m)">{{ t('crm_copilot_index.confirm') }}</VBtn>
                  <VChip v-else-if="m.actioned" size="x-small" color="success" label>{{ m.actioned }}</VChip>
                </div>
                <div v-if="m.action.body" class="text-body-2 mt-2" style="white-space: pre-wrap">
                  <div v-if="m.action.subject" class="font-weight-medium">{{ m.action.subject }}</div>
                  {{ m.action.body }}
                </div>
              </VCardText>
            </VCard>
          </div>
        </div>

        <div v-if="busy" class="d-flex justify-start mb-2"><VProgressCircular indeterminate size="20" color="primary" /></div>
      </div>

      <VDivider />
      <div class="pa-3 d-flex ga-2">
        <VTextField v-model="input" :placeholder="t('crm_copilot_index.placeholder')" density="comfortable" hide-details variant="solo-filled" flat
          :disabled="!aiEnabled || busy" @keyup.enter="send()" />
        <VBtn icon="tabler-send" color="primary" :loading="busy" :disabled="!aiEnabled || !input.trim()" @click="send()" />
      </div>
    </VCard>
  </div>
</template>
