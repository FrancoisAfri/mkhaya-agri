<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  suggestions: string[]
  answer: string | null
  question: string | null
}>()

type Msg = { role: 'user' | 'assistant'; content: string }
const messages = ref<Msg[]>([])
const input = ref('')
const loading = ref(false)
const thread = ref<HTMLElement | null>(null)

async function scrollDown() {
  await nextTick()
  thread.value?.scrollTo({ top: thread.value.scrollHeight, behavior: 'smooth' })
}

function ask(questionText?: string) {
  const question = (questionText ?? input.value).trim()
  if (!question || loading.value) return

  const history = messages.value.map(message => ({ role: message.role, content: message.content }))
  messages.value.push({ role: 'user', content: question })
  input.value = ''
  loading.value = true
  scrollDown()

  router.post(route('contracts.copilot.ask'), { question, history }, {
    preserveState: true,
    preserveScroll: true,
    only: ['answer', 'question'],
    onSuccess: (page: any) => {
      const a = page.props.answer as string | null
      if (a) messages.value.push({ role: 'assistant', content: a })
      scrollDown()
    },
    onFinish: () => (loading.value = false),
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center gap-3 mb-4">
      <VAvatar color="purple" variant="tonal" size="48" rounded>
        <VIcon icon="tabler-sparkles" size="26" />
      </VAvatar>
      <div>
        <h4 class="text-h4 mb-0">{{ t('procurement_copilot.page_title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('procurement_copilot.description') }}</p>
      </div>
    </div>

    <VCard border class="d-flex flex-column" style="height: calc(100vh - 220px)">
      <div ref="thread" class="flex-grow-1 overflow-y-auto pa-4">
        <div v-if="!messages.length" class="text-medium-emphasis">
          <p class="mb-3">{{ t('procurement_copilot.try_suggestions') }}</p>
          <div class="d-flex flex-column gap-2" style="max-width:640px">
            <VBtn
              v-for="(suggestion, i) in suggestions"
              :key="i"
              variant="tonal"
              color="purple"
              class="text-none justify-start"
              @click="ask(suggestion)"
            >
              {{ suggestion }}
            </VBtn>
          </div>
        </div>

        <div
          v-for="(message, i) in messages"
          :key="i"
          class="mb-4 d-flex"
          :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
        >
          <div
            :class="message.role === 'user' ? 'bg-primary text-white' : 'bg-grey-lighten-4'"
            class="pa-3 rounded-lg"
            style="max-width: 80%; white-space: pre-wrap"
          >
            {{ message.content }}
          </div>
        </div>

        <div v-if="loading" class="d-flex align-center gap-2 text-medium-emphasis">
          <VProgressCircular indeterminate size="18" width="2" color="purple" />
          {{ t('procurement_copilot.thinking') }}
        </div>
      </div>

      <VDivider />
      <div class="pa-3 d-flex gap-2">
        <VTextField
          v-model="input"
          :placeholder="t('procurement_copilot.placeholder')"
          variant="outlined"
          density="compact"
          hide-details
          :disabled="loading"
          @keyup.enter="ask()"
        />
        <VBtn
          color="purple"
          :loading="loading"
          :disabled="!input.trim() || loading"
          @click="ask()"
        >
          {{ t('procurement_copilot.send') }}
        </VBtn>
      </div>
    </VCard>
    <p class="text-caption text-medium-emphasis mt-2">
      {{ t('procurement_copilot.disclaimer') }}
    </p>
  </div>
</template>
