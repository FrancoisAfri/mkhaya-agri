<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

type Category = 'basic' | 'advisor' | 'policy' | 'anomaly' | 'workforce' | 'executive'

type Message = {
  id:       number
  role:     'user' | 'assistant'
  content:  string
  category: Category
  ts:       string
}

const props = defineProps<{
  capabilities: Record<Category, string>
  quick_prompts: Record<Category, string[]>
  employee_id:  number | null
}>()

const messages   = ref<Message[]>([])
const input      = ref('')
const category   = ref<Category>('basic')
const loading    = ref(false)
const chatBottom = ref<HTMLElement | null>(null)
let   nextId     = 1

const categoryIcons: Record<Category, string> = {
  basic:     'tabler-robot-outline',
  advisor:   'tabler-lightbulb-on',
  policy:    'tabler-file-document-outline',
  anomaly:   'tabler-alert-circle-outline',
  workforce: 'tabler-account-group',
  executive: 'tabler-chart-line',
}

const categoryColors: Record<Category, string> = {
  basic:     'primary',
  advisor:   'deep-purple',
  policy:    'teal',
  anomaly:   'orange',
  workforce: 'blue',
  executive: 'red',
}

const availableCategories = computed(() =>
  Object.entries(props.capabilities) as [Category, string][]
)

const currentQuickPrompts = computed(() =>
  props.quick_prompts[category.value] ?? []
)

const greeting = computed(() => {
  const cats = availableCategories.value.map(([, label]) => label).join(', ')
  return `Hello! I'm your Leave Intelligence Assistant. I can help you with: **${cats}**. Select a category below and ask me anything.`
})

async function send(text?: string) {
  const msg = (text ?? input.value).trim()
  if (!msg || loading.value) return

  input.value = ''
  messages.value.push({
    id:       nextId++,
    role:     'user',
    content:  msg,
    category: category.value,
    ts:       new Date().toLocaleTimeString(),
  })

  await scrollToBottom()
  loading.value = true

  try {
    const res = await fetch('/leave-management/ai/chat', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept':       'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify({ message: msg, category: category.value }),
    })

    if (res.status === 403) {
      push('assistant', "You don't have permission to use this AI feature. Please contact your HR administrator.")
      return
    }

    const data = await res.json()

    if (data.error) {
      push('assistant', data.error)
    } else {
      push('assistant', data.response ?? 'No response received.')
    }
  } catch {
    push('assistant', 'The AI advisor is temporarily unavailable. Please try again shortly.')
  } finally {
    loading.value = false
    await scrollToBottom()
  }
}

function push(role: 'assistant' | 'user', content: string) {
  messages.value.push({
    id:       nextId++,
    role,
    content,
    category: category.value,
    ts:       new Date().toLocaleTimeString(),
  })
}

async function scrollToBottom() {
  await nextTick()
  chatBottom.value?.scrollIntoView({ behavior: 'smooth' })
}

function handleKey(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    send()
  }
}

const { t } = useI18n()
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <!-- Header -->
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div class="d-flex align-center gap-3">
              <VBtn icon variant="text" @click="router.visit('/leave-management')">
                <VIcon icon="tabler-arrow-left" />
              </VBtn>
              <div>
                <div class="text-overline text-medium-emphasis">{{ t('leave.ai_module') }}</div>
                <h4 class="text-h4">{{ t('leave.ai_advisor_title') }}</h4>
                <p class="mb-0 text-body-2 text-medium-emphasis">
                  Ask anything about leave, policy, workforce risk, and more — gated to your role.
                </p>
              </div>
            </div>
            <VChip color="primary" size="large" label prepend-icon="tabler-robot">
              {{ availableCategories.length }} Feature{{ availableCategories.length !== 1 ? 's' : '' }} Enabled
            </VChip>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Category selector + quick prompts sidebar -->
      <VCol cols="12" md="3">
        <VCard>
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">
            <VIcon icon="tabler-tune" class="me-2" />{{ t('leave.what_to_ask') }}
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-3">
            <VBtnToggle v-model="category" mandatory color="primary" density="compact" class="flex-column w-100">
              <VBtn
                v-for="[cat, label] in availableCategories"
                :key="cat"
                :value="cat"
                variant="text"
                class="justify-start mb-1"
                style="width:100%"
              >
                <VIcon :icon="categoryIcons[cat]" class="me-2" size="18" />
                <span class="text-body-2">{{ label }}</span>
              </VBtn>
            </VBtnToggle>
          </VCardText>
        </VCard>

        <!-- Quick prompts -->
        <VCard v-if="currentQuickPrompts.length" class="mt-4">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">
            <VIcon icon="tabler-lightning-bolt" class="me-2" />{{ t('leave.quick_questions') }}
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-3">
            <VChip
              v-for="prompt in currentQuickPrompts"
              :key="prompt"
              class="mb-2 d-flex cursor-pointer"
              :color="categoryColors[category]"
              variant="tonal"
              label
              size="small"
              style="white-space:normal;height:auto;min-height:32px"
              @click="send(prompt)"
            >
              {{ prompt }}
            </VChip>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Chat area -->
      <VCol cols="12" md="9">
        <VCard style="display:flex;flex-direction:column;height:75vh">
          <!-- Messages -->
          <VCardText class="flex-grow-1 overflow-y-auto pa-4" style="overflow-y:auto">

            <!-- Greeting -->
            <div class="d-flex align-start gap-3 mb-4">
              <VAvatar color="primary" size="36">
                <VIcon icon="tabler-robot" size="20" />
              </VAvatar>
              <VCard variant="tonal" color="primary" class="pa-3 flex-grow-1" style="max-width:80%">
                <p class="text-body-2 mb-0" v-html="greeting.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')" />
              </VCard>
            </div>

            <!-- Messages -->
            <template v-for="msg in messages" :key="msg.id">
              <!-- User -->
              <div v-if="msg.role === 'user'" class="d-flex justify-end align-start gap-3 mb-3">
                <VCard variant="flat" color="surface-variant" class="pa-3" style="max-width:75%">
                  <div class="d-flex align-center gap-2 mb-1">
                    <VChip size="x-small" :color="categoryColors[msg.category]" label>
                      {{ capabilities[msg.category] ?? msg.category }}
                    </VChip>
                    <span class="text-caption text-medium-emphasis">{{ msg.ts }}</span>
                  </div>
                  <p class="text-body-2 mb-0">{{ msg.content }}</p>
                </VCard>
                <VAvatar color="secondary" size="36">
                  <VIcon icon="tabler-account" size="20" />
                </VAvatar>
              </div>

              <!-- Assistant -->
              <div v-else class="d-flex align-start gap-3 mb-3">
                <VAvatar :color="categoryColors[msg.category]" size="36">
                  <VIcon :icon="categoryIcons[msg.category]" size="20" />
                </VAvatar>
                <VCard variant="tonal" :color="categoryColors[msg.category]" class="pa-3 flex-grow-1" style="max-width:80%">
                  <div class="d-flex align-center gap-2 mb-2">
                    <span class="text-caption font-weight-medium">{{ t('leave.ai_advisor') }}</span>
                    <span class="text-caption text-medium-emphasis">{{ msg.ts }}</span>
                  </div>
                  <p class="text-body-2 mb-0" style="white-space:pre-wrap">{{ msg.content }}</p>
                </VCard>
              </div>
            </template>

            <!-- Loading -->
            <div v-if="loading" class="d-flex align-start gap-3 mb-3">
              <VAvatar :color="categoryColors[category]" size="36">
                <VIcon :icon="categoryIcons[category]" size="20" />
              </VAvatar>
              <VCard variant="tonal" :color="categoryColors[category]" class="pa-3">
                <VProgressCircular indeterminate size="18" width="2" class="me-2" />
                <span class="text-caption">{{ t('leave.ai_thinking') }}</span>
              </VCard>
            </div>

            <div ref="chatBottom" />
          </VCardText>

          <VDivider />

          <!-- Input area -->
          <VCardActions class="pa-3 gap-2">
            <VSelect
              v-model="category"
              :items="availableCategories.map(([v, t]) => ({ value: v, title: t }))"
              item-value="value"
              item-title="title"
              density="compact"
              variant="outlined"
              hide-details
              style="max-width:200px;min-width:150px"
            />
            <VTextField
              v-model="input"
              :placeholder="t('leave.ai_placeholder')"
              variant="outlined"
              density="compact"
              hide-details
              class="flex-grow-1"
              :disabled="loading"
              @keydown="handleKey"
            />
            <VBtn
              :color="categoryColors[category]"
              :loading="loading"
              :disabled="!input.trim()"
              icon
              @click="send()"
            >
              <VIcon icon="tabler-send" />
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
