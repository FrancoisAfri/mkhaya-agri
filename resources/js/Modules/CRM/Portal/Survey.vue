<script lang="ts" setup>
import { ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

defineOptions({ layout: null })

interface Question { id: number; type: string; label: string; options?: string[]; required: boolean }
const props = defineProps<{ company: string; logo: string | null; token: string; completed: boolean; open: boolean; survey: { name: string; description: string | null; questions: Question[]; thank_you: string | null } | null; recipient_name: string | null }>()

const page = usePage()
const done = ref(props.completed)
const form = useForm({ answers: {} as Record<number, any> })

function toggleMulti(qid: number, opt: string) { const cur = (form.answers[qid] as string[]) ?? []; form.answers[qid] = cur.includes(opt) ? cur.filter(o => o !== opt) : [...cur, opt] }
function submit() { form.post(route('crm.survey.submit', props.token), { preserveScroll: true, onSuccess: () => { done.value = true } }) }

const npsScale = Array.from({ length: 11 }, (_, i) => i)
const csatScale = [1, 2, 3, 4, 5]
const ratingScale = [1, 2, 3, 4, 5]
const ces = [1, 2, 3, 4, 5, 6, 7]
</script>

<template>
  <VApp>
    <VMain style="min-height: 100vh; background: linear-gradient(135deg,#f5f5f7,#ececf4)">
      <VContainer class="py-10" style="max-width: 720px">
        <div class="text-center mb-6"><img v-if="logo" :src="logo" :alt="company" style="max-height: 60px; max-width: 220px" class="mb-3" /><h4 class="text-h5 font-weight-bold">{{ company }}</h4></div>

        <VCard><VCardText class="pa-6">
          <template v-if="done"><div class="text-center py-6"><VIcon icon="tabler-circle-check" color="success" size="56" class="mb-3" /><h5 class="text-h5 mb-1">{{ t('crm_survey_index.thank_you_title') }}</h5><p class="text-body-1 text-medium-emphasis">{{ survey?.thank_you ?? (page.props.flash as any)?.success ?? t('crm_survey_index.thank_you_default') }}</p></div></template>
          <template v-else-if="!open || !survey"><VAlert type="warning" variant="tonal">{{ t('crm_survey_index.survey_unavailable') }}</VAlert></template>
          <template v-else>
            <h5 class="text-h5 font-weight-bold mb-1">{{ survey.name }}</h5>
            <p v-if="survey.description" class="text-body-2 text-medium-emphasis mb-4">{{ survey.description }}</p>
            <p v-if="recipient_name" class="text-caption text-medium-emphasis mb-4">{{ t('crm_survey_index.responding_as') }} {{ recipient_name }}</p>

            <div v-for="(q, idx) in survey.questions" :key="q.id" class="mb-6">
              <div class="font-weight-medium mb-2">{{ idx + 1 }}. {{ q.label }} <span v-if="q.required" class="text-error">{{ t('crm_survey_index.required') }}</span></div>
              <div v-if="q.type === 'nps'" class="d-flex flex-wrap ga-1"><VBtn v-for="n in npsScale" :key="n" size="small" :variant="form.answers[q.id] === n ? 'flat' : 'outlined'" :color="form.answers[q.id] === n ? 'primary' : undefined" @click="form.answers[q.id] = n">{{ n }}</VBtn><div class="w-100 d-flex justify-space-between text-caption text-medium-emphasis mt-1"><span>{{ t('crm_survey_index.scales.nps_not_likely') }}</span><span>{{ t('crm_survey_index.scales.nps_very_likely') }}</span></div></div>
              <div v-else-if="q.type === 'csat'" class="d-flex ga-2"><VBtn v-for="n in csatScale" :key="n" :variant="form.answers[q.id] === n ? 'flat' : 'outlined'" :color="form.answers[q.id] === n ? 'primary' : undefined" icon @click="form.answers[q.id] = n"><VIcon :icon="form.answers[q.id] >= n ? 'tabler-star-filled' : 'tabler-star'" /></VBtn></div>
              <div v-else-if="q.type === 'rating'" class="d-flex ga-1"><VBtn v-for="n in ratingScale" :key="n" size="small" :variant="form.answers[q.id] === n ? 'flat' : 'outlined'" :color="form.answers[q.id] === n ? 'primary' : undefined" @click="form.answers[q.id] = n">{{ n }}</VBtn></div>
              <div v-else-if="q.type === 'scale'" class="d-flex flex-wrap ga-1"><VBtn v-for="n in ces" :key="n" size="small" :variant="form.answers[q.id] === n ? 'flat' : 'outlined'" :color="form.answers[q.id] === n ? 'primary' : undefined" @click="form.answers[q.id] = n">{{ n }}</VBtn><div class="w-100 d-flex justify-space-between text-caption text-medium-emphasis mt-1"><span>{{ t('crm_survey_index.scales.ces_difficult') }}</span><span>{{ t('crm_survey_index.scales.ces_easy') }}</span></div></div>
              <VRadioGroup v-else-if="q.type === 'choice'" v-model="form.answers[q.id]" hide-details><VRadio v-for="opt in q.options" :key="opt" :label="opt" :value="opt" /></VRadioGroup>
              <div v-else-if="q.type === 'multichoice'"><VCheckbox v-for="opt in q.options" :key="opt" :label="opt" hide-details density="compact" :model-value="(form.answers[q.id] ?? []).includes(opt)" @update:model-value="toggleMulti(q.id, opt)" /></div>
              <VTextarea v-else v-model="form.answers[q.id]" rows="2" auto-grow variant="outlined" density="comfortable" :placeholder="t('crm_survey_index.scales.your_answer')" hide-details />
            </div>

            <VBtn block color="primary" size="large" :loading="form.processing" @click="submit">{{ t('crm_survey_index.submit') }}</VBtn>
          </template>
        </VCardText></VCard>
        <p class="text-center text-caption text-medium-emphasis mt-4">{{ t('crm_survey_index.powered_by') }} {{ company }}</p>
      </VContainer>
    </VMain>
  </VApp>
</template>