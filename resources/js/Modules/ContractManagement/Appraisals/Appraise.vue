<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Question = { id: number; question: string; weight_percentage: number | null }
type Template = { id: number; name: string; questions: Question[] }

const props = defineProps<{
  contract: { id: number; title: string; contract_number: string }
  templates: Template[]
}>()

const selectedTemplateId = ref<number | null>(null)
const responses = ref<Record<number, { score: number | null; comment: string }>>({})

const selectedTemplate = computed(() => props.templates.find(t => t.id === selectedTemplateId.value) || null)

watch(selectedTemplateId, () => {
  responses.value = {}
  selectedTemplate.value?.questions.forEach(q => { responses.value[q.id] = { score: null, comment: '' } })
})

// Weighted total: (score/5)*100 * weight% / 100
const total = computed(() => {
  if (!selectedTemplate.value) return 0
  return selectedTemplate.value.questions.reduce((sum, q) => {
    const r = responses.value[q.id]
    if (!r || r.score == null) return sum
    const pct = (r.score / 5) * 100
    const weight = q.weight_percentage ?? 0
    return sum + (pct * weight) / 100
  }, 0)
})

const submitting = ref(false)
function submit() {
  if (!selectedTemplate.value) return
  submitting.value = true
  const payload = {
    template_id: selectedTemplateId.value,
    responses: selectedTemplate.value.questions.map(q => ({
      question_id: q.id,
      score: responses.value[q.id]?.score != null ? (responses.value[q.id].score! / 5) * 100 * (q.weight_percentage ?? 0) / 100 : null,
      response: responses.value[q.id]?.score != null ? String(responses.value[q.id].score) : null,
      comment: responses.value[q.id]?.comment ?? null,
    })),
  }
  router.post(route('contracts.appraisals.store', props.contract.uuid), payload as any, {
    onFinish: () => (submitting.value = false),
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon="tabler-arrow-left" variant="text" @click="router.get(route('contracts.appraisals.index'))" />
      <div>
        <h4 class="text-h4 mb-0">Appraise: {{ contract.title }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ contract.contract_number }}</p>
      </div>
    </div>

    <VCard border class="mb-4">
      <VCardText>
        <VSelect v-model="selectedTemplateId" :items="templates" item-title="name" item-value="id" label="Select appraisal template" variant="outlined" />
      </VCardText>
    </VCard>

    <VCard v-if="selectedTemplate" border>
      <VCardTitle class="d-flex justify-space-between align-center">
        {{ selectedTemplate.name }}
        <VChip color="primary" variant="tonal">Total: {{ total.toFixed(2) }}</VChip>
      </VCardTitle>
      <VCardText>
        <div v-for="q in selectedTemplate.questions" :key="q.id" class="mb-5">
          <div class="font-weight-medium mb-1">{{ q.question }} <span class="text-medium-emphasis">({{ q.weight_percentage ?? 0 }}%)</span></div>
          <VRating v-if="responses[q.id]" v-model="responses[q.id].score" length="5" color="amber" half-increments class="mb-2" />
          <VTextField v-if="responses[q.id]" v-model="responses[q.id].comment" label="Comment" variant="outlined" density="compact" hide-details />
        </div>
      </VCardText>
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="text" @click="router.get(route('contracts.appraisals.index'))">Cancel</VBtn>
        <VBtn color="primary" :loading="submitting" @click="submit">Submit Appraisal</VBtn>
      </VCardActions>
    </VCard>

    <VCard v-else border><VCardText class="text-center text-medium-emphasis py-8">Select a template to begin.</VCardText></VCard>
  </div>
</template>
