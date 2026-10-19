<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import { route } from 'ziggy-js'
import PageErrors from './Components/PageErrors.vue'
import { human } from './Components/agri'

defineProps<{ suggestions: Array<{ topic: string; question: string }> }>()

type Answer = { topic: string | null; title: string; refused: boolean; reply: string; reply_source: string; columns: string[]; rows: any[]; sources: string[]; flags: Array<{ level: string; message: string }>; recommendations: Array<{ text: string; url?: string }>; farms?: string[]; as_of?: string }
const question = ref('')
const loading = ref(false)
const thread = ref<Array<{ q: string; a?: Answer; error?: string }>>([])

async function ask(q?: string, topic?: string) {
  const text = (q ?? question.value).trim()
  if (!text) return
  const entry = { q: text } as { q: string; a?: Answer; error?: string }
  thread.value.unshift(entry)
  question.value = ''
  loading.value = true
  try {
    const { data } = await axios.post(route('agri.assistant.ask'), { question: text, topic })
    entry.a = data
  } catch (e: any) {
    entry.error = e?.response?.data?.message ?? 'The assistant could not answer.'
  } finally {
    loading.value = false
    thread.value = [...thread.value]
  }
}
const cell = (v: any) => (v === null || v === undefined ? '—' : typeof v === 'number' ? new Intl.NumberFormat('en-ZA', { maximumFractionDigits: 2 }).format(v) : v)
</script>

<template>
  <div>
    <PageErrors />
    <div class="mb-6">
      <h4 class="text-h4 mb-1">Farm Assistant</h4>
      <p class="text-body-1 text-medium-emphasis mb-0">Ask about your farms. Answers come from your own records, only for the farms and modules you can open — the assistant reads, it never changes anything.</p>
    </div>
    <VCard class="mb-4">
      <VCardText>
        <VTextField v-model="question" placeholder="e.g. Which animals cannot be sold yet?" prepend-inner-icon="tabler-message-question" :loading="loading"
          append-inner-icon="tabler-send" hide-details @keyup.enter="ask()" @click:append-inner="ask()" />
        <div class="d-flex flex-wrap gap-2 mt-3">
          <VChip v-for="s in suggestions" :key="s.topic" size="small" variant="tonal" @click="ask(s.question, s.topic)">{{ s.question }}</VChip>
        </div>
      </VCardText>
    </VCard>

    <VCard v-for="(t, i) in thread" :key="i" class="mb-4">
      <VCardItem>
        <template #prepend><VAvatar color="primary" variant="tonal" size="32"><VIcon icon="tabler-user" /></VAvatar></template>
        <VCardTitle class="text-wrap text-body-1 font-weight-medium">{{ t.q }}</VCardTitle>
      </VCardItem>
      <VCardText v-if="!t.a && !t.error"><VProgressLinear indeterminate /></VCardText>
      <VCardText v-else-if="t.error"><VAlert type="error" variant="tonal">{{ t.error }}</VAlert></VCardText>
      <VCardText v-else-if="t.a">
        <div class="d-flex gap-3 mb-3">
          <VAvatar color="success" variant="tonal" size="32"><VIcon icon="tabler-robot" /></VAvatar>
          <div>
            <VAlert v-if="t.a.refused" type="warning" variant="tonal" density="compact">{{ t.a.reply }}</VAlert>
            <p v-else class="text-body-1 mb-1">{{ t.a.reply }}</p>
            <div class="text-caption text-medium-emphasis">
              {{ t.a.reply_source === 'MODEL' ? 'Worded by AI from the figures below' : 'Computed from your records' }}<template v-if="t.a.sources?.length"> · Source: {{ t.a.sources.join('; ') }}</template><template v-if="t.a.as_of"> · as of {{ t.a.as_of }}</template>
            </div>
          </div>
        </div>
        <VAlert v-for="(fl, k) in t.a.flags" :key="k" :type="fl.level === 'WARNING' ? 'warning' : 'info'" variant="tonal" density="compact" class="mb-2">{{ fl.message }}</VAlert>
        <VTable v-if="t.a.rows?.length" density="compact" class="text-no-wrap">
          <thead><tr><th v-for="c in t.a.columns" :key="c">{{ human(c) }}</th></tr></thead>
          <tbody><tr v-for="(r, j) in t.a.rows" :key="j"><td v-for="c in t.a.columns" :key="c"><a v-if="j >= 0 && c === t.a.columns[1] && r.url" :href="r.url">{{ cell(r[c]) }}</a><template v-else>{{ cell(r[c]) }}</template></td></tr></tbody>
        </VTable>
        <div v-for="(r, k) in t.a.recommendations" :key="k" class="mt-2 text-body-2"><VIcon icon="tabler-bulb" color="warning" size="16" /> Recommendation: {{ r.text }} <a v-if="r.url" :href="r.url">Open</a></div>
      </VCardText>
    </VCard>
  </div>
</template>
