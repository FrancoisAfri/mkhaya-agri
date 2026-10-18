<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { VCard } from 'vuetify/components'

const { t } = useI18n()
const route = ziggyRoute

/**
 * `flat` drops the panel's own card and heading.
 *
 * Used when the panel sits inside a tab, where the tab already names it — otherwise the page shows
 * a bordered card inside a bordered card with the word "Project chat" written twice.
 */
const props = defineProps<{ projectId: string; flat?: boolean }>()

type Message = { id: number; uuid: string; author: string; body: string; at: string; mine: boolean }

const messages = ref<Message[]>([])
const last = ref<number | null>(null)
const loading = ref(true)
const scroller = ref<HTMLElement | null>(null)

/**
 * Polling, not a socket.
 *
 * Ten seconds is a deliberate compromise: fast enough that a conversation feels live, slow enough
 * that a page left open on somebody's second monitor is not six requests a minute. The interval is
 * cleared on unmount, or a tab open for a day leaks one timer per visit.
 */
const POLL_MS = 10000
let timer: ReturnType<typeof setInterval> | null = null

async function fetchMessages(initial = false) {
  const url = route('project-management.chat', props.projectId)
    + (last.value && !initial ? `?after=${last.value}` : '')

  try {
    const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    if (!res.ok) return

    const body = await res.json()
    if (body.messages.length) {
      messages.value = initial ? body.messages : [...messages.value, ...body.messages]
      last.value = body.last
      await nextTick()
      scroller.value?.scrollTo({ top: scroller.value.scrollHeight })
    }
  } finally {
    loading.value = false
  }
}

const form = useForm({ body: '' })

function send() {
  if (!form.body.trim()) return
  form.post(route('project-management.chat.store', props.projectId), {
    preserveScroll: true,
    onSuccess: () => { form.reset(); fetchMessages() },
  })
}

function when(iso: string) {
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

onMounted(() => {
  fetchMessages(true)
  timer = setInterval(() => fetchMessages(), POLL_MS)
})

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <component :is="flat ? 'div' : VCard" :border="flat ? undefined : true">
    <template v-if="!flat">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.chat.title') }}</VCardTitle>
        <VCardSubtitle>{{ t('project_management.chat.subtitle') }}</VCardSubtitle>
      </VCardItem>
      <VDivider />
    </template>

    <div ref="scroller" class="pa-3 overflow-y-auto" style="max-height: 340px; min-height: 160px">
      <div v-if="loading" class="text-center py-6">
        <VProgressCircular indeterminate size="24" color="primary" />
      </div>
      <p v-else-if="!messages.length" class="text-center text-caption text-medium-emphasis py-6 mb-0">
        {{ t('project_management.chat.empty') }}
      </p>
      <div v-for="m in messages" :key="m.uuid" class="mb-3">
        <div class="text-caption text-medium-emphasis">
          <span class="font-weight-medium">{{ m.author }}</span> · {{ when(m.at) }}
        </div>
        <div class="text-body-2" style="white-space: pre-line">{{ m.body }}</div>
      </div>
    </div>

    <VDivider />
    <VCardText class="d-flex align-center gap-2">
      <VTextField
        v-model="form.body" :placeholder="t('project_management.chat.placeholder')"
        :error-messages="form.errors.body"
        variant="outlined" density="compact" hide-details
        @keyup.enter="send"
      />
      <VBtn icon="tabler-send" color="primary" :loading="form.processing"
            :disabled="!form.body.trim()" :aria-label="t('project_management.chat.send')" @click="send" />
    </VCardText>
  </component>
</template>
