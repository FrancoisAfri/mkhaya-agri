<script setup lang="ts">
import axios from 'axios'
import { ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Run { id: number; uuid: string; ref_number: string; name: string; period_start: string }

interface Message {
  role: 'user' | 'assistant'
  content: string
}

const props = defineProps<{
  runs: Run[]
  userRole: 'hr' | 'finance' | 'executive' | 'employee'
  quickPrompts: string[]
  defaultRunId: number | null
}>()

const messages  = ref<Message[]>([])
const question  = ref('')
const runId     = ref<number | null>(props.defaultRunId)
const asking    = ref(false)
const chatError = ref('')

async function ask() {
  const q = question.value.trim()
  if (!q) return

  messages.value.push({ role: 'user', content: q })
  question.value  = ''
  asking.value    = true
  chatError.value = ''

  try {
    const res = await axios.post(route('payroll.ai-assistant.ask'), {
      question: q,
      run_id:   runId.value,
    })
    messages.value.push({ role: 'assistant', content: res.data.answer })
  } catch {
    chatError.value = t('payroll_ai_assistant.error_message')
    messages.value.pop()
  } finally {
    asking.value = false
  }
}

function usePrompt(prompt: string) {
  question.value = prompt
}

function clearChat() {
  messages.value  = []
  chatError.value = ''
}

const roleLabel = t(`payroll_ai_assistant.roles.${props.userRole}`)

const roleColor = {
  executive: 'purple',
  finance:   'teal',
  hr:        'primary',
  employee:  'grey',
}[props.userRole] ?? 'grey'

const roleIcon = {
  executive: 'tabler-briefcase',
  finance:   'tabler-currency-dollar',
  hr:        'tabler-users',
  employee:  'tabler-user',
}[props.userRole] ?? 'tabler-user'
</script>

<template>
  <div class="pa-4 d-flex flex-column" style="max-width: 1300px; margin: 0 auto; min-height: calc(100vh - 80px);">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold d-flex align-center ga-2">
          <VIcon color="primary" size="24">tabler-robot</VIcon>
          {{ t('payroll_ai_assistant.page_title') }}
        </h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('payroll_ai_assistant.description') }}</p>
      </div>
      <div class="d-flex align-center ga-2">
        <VChip :color="roleColor" variant="tonal" :prepend-icon="roleIcon" size="small">
          {{ t('payroll_ai_assistant.role_view', { role: roleLabel }) }}
        </VChip>
        <VBtn v-if="messages.length" size="small" variant="text" color="grey" @click="clearChat">
          {{ t('payroll_ai_assistant.clear_chat') }}
        </VBtn>
      </div>
    </div>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense align="center">
          <VCol cols="12" sm="5">
            <VSelect v-model="runId" :label="t('payroll_ai_assistant.context_run')"
              :items="[{ id: null, ref_number: t('payroll_ai_assistant.latest_run'), name: '' }, ...runs]"
              item-title="ref_number" item-value="id"
              density="compact" variant="outlined" hide-details>
              <template #item="{ item, props: p }">
                <VListItem v-bind="p">
                  <template v-if="item.raw.name" #subtitle>{{ item.raw.name }}</template>
                </VListItem>
              </template>
            </VSelect>
          </VCol>
          <VCol cols="12" sm="7">
            <p class="text-caption text-medium-emphasis">{{ t('payroll_ai_assistant.context_hint') }}</p>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <div class="mb-4">
      <div class="text-caption text-medium-emphasis mb-2 font-weight-semibold text-uppercase">{{ t('payroll_ai_assistant.quick_prompts') }}</div>
      <div class="d-flex flex-wrap ga-2">
        <VChip v-for="prompt in quickPrompts" :key="prompt"
          size="small" :color="roleColor" variant="outlined" clickable
          @click="usePrompt(prompt)">
          {{ prompt }}
        </VChip>
      </div>
    </div>

    <VCard elevation="0" border class="flex-grow-1 mb-4" style="overflow-y: auto; min-height: 260px;">
      <div v-if="!messages.length" class="d-flex flex-column align-center justify-center text-center pa-8"
        style="min-height: 260px;">
        <VIcon :color="roleColor" size="48" class="mb-3 opacity-40">tabler-message-question</VIcon>
        <p class="text-body-2 text-medium-emphasis">{{ t('payroll_ai_assistant.empty_chat_title') }}</p>
        <p class="text-caption text-medium-emphasis mt-1">{{ t('payroll_ai_assistant.empty_chat_subtitle') }}</p>
      </div>

      <div v-else class="pa-4 d-flex flex-column ga-3">
        <div v-for="(msg, i) in messages" :key="i"
          :class="msg.role === 'user' ? 'align-self-end' : 'align-self-start'"
          style="max-width: 82%;">
          <div v-if="msg.role === 'user'" class="d-flex align-end justify-end ga-2">
            <VCard :color="roleColor" class="px-4 py-2" elevation="0">
              <span class="text-body-2 text-white">{{ msg.content }}</span>
            </VCard>
            <VAvatar size="28" :color="roleColor">
              <VIcon size="16">{{ roleIcon }}</VIcon>
            </VAvatar>
          </div>
          <div v-else class="d-flex align-start ga-2">
            <VAvatar size="28" color="primary">
              <VIcon size="16">tabler-robot</VIcon>
            </VAvatar>
            <VCard color="surface-variant" class="px-4 py-2" elevation="0">
              <span class="text-body-2" style="line-height: 1.7; white-space: pre-line;">{{ msg.content }}</span>
            </VCard>
          </div>
        </div>

        <div v-if="asking" class="d-flex align-start ga-2 align-self-start">
          <VAvatar size="28" color="primary">
            <VIcon size="16">tabler-robot</VIcon>
          </VAvatar>
          <VCard color="surface-variant" class="px-4 py-3" elevation="0">
            <div class="d-flex ga-1">
              <VIcon size="8" class="typing-dot">tabler-circle-filled</VIcon>
              <VIcon size="8" class="typing-dot" style="animation-delay: .2s">tabler-circle-filled</VIcon>
              <VIcon size="8" class="typing-dot" style="animation-delay: .4s">tabler-circle-filled</VIcon>
            </div>
          </VCard>
        </div>
      </div>
    </VCard>

    <VAlert v-if="chatError" type="error" variant="tonal" density="compact" closable
      class="mb-3" @click:close="chatError = ''">
      {{ chatError }}
    </VAlert>

    <VCard elevation="0" border>
      <VCardText class="pa-3">
        <div class="d-flex ga-2 align-end">
          <VTextarea v-model="question" :label="t('payroll_ai_assistant.input_label')" variant="outlined" density="compact"
            rows="2" hide-details auto-grow style="flex: 1;"
            @keydown.enter.prevent.exact="ask" />
          <VBtn :color="roleColor" :loading="asking" :disabled="!question.trim()" height="56" @click="ask">
            <VIcon>tabler-send</VIcon>
          </VBtn>
        </div>
        <div class="text-caption text-medium-emphasis mt-1 text-right">
          {{ t('payroll_ai_assistant.input_hint') }}
        </div>
      </VCardText>
    </VCard>
  </div>
</template>

<style scoped>
.typing-dot {
  animation: bounce 1s infinite;
  color: rgb(var(--v-theme-on-surface-variant));
  opacity: 0.6;
}
@keyframes bounce {
  0%, 60%, 100% { transform: translateY(0); }
  30%            { transform: translateY(-4px); }
}
</style>
