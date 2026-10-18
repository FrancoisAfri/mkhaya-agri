<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
const route = ziggyRoute
const { t } = useI18n()

interface Question { id: number; type: string; label: string; options?: string[]; required: boolean }
interface Audience { mode: string; industries?: string[]; min_spend?: number | null; account_ids?: number[] }
interface QResult { id: number; label: string; type: string; count: number; average?: number | null; options?: Record<string, number>; samples?: string[] }

const props = defineProps<{
  survey: { id: number; name: string; description: string | null; category: string; questions: Question[]; audience: Audience; status: string; thank_you: string | null; scheduled_at: string | null; deadline: string | null }
  results: { recipients: number; completed: number; response_rate: number; nps: number | null; csat_avg: number | null; questions: QResult[] }
  industries: string[]
  accounts: { id: number; name: string; industry: string | null; annual_revenue: number | null }[]
}>()

const tab = ref('build')
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })
const preview = ref<{ count: number; sample: any[] } | null>(null)
const analyzing = ref(false)

const qTypes = computed(() => [
  { title: t('crm_surveys_show.build.question_types.nps'), value: 'nps' },
  { title: t('crm_surveys_show.build.question_types.csat'), value: 'csat' },
  { title: t('crm_surveys_show.build.question_types.rating'), value: 'rating' },
  { title: t('crm_surveys_show.build.question_types.scale'), value: 'scale' },
  { title: t('crm_surveys_show.build.question_types.choice'), value: 'choice' },
  { title: t('crm_surveys_show.build.question_types.multichoice'), value: 'multichoice' },
  { title: t('crm_surveys_show.build.question_types.text'), value: 'text' },
])

const audienceModes = computed(() => [
  { title: t('crm_surveys_show.audience.modes.all'), value: 'all' },
  { title: t('crm_surveys_show.audience.modes.industry'), value: 'industry' },
  { title: t('crm_surveys_show.audience.modes.spend'), value: 'spend' },
  { title: t('crm_surveys_show.audience.modes.specific'), value: 'specific' },
])

const form = useForm({
  name: props.survey.name,
  description: props.survey.description ?? '',
  questions: JSON.parse(JSON.stringify(props.survey.questions)) as Question[],
  audience: {
    mode: props.survey.audience?.mode ?? 'all',
    industries: props.survey.audience?.industries ?? [],
    min_spend: props.survey.audience?.min_spend ?? null,
    account_ids: props.survey.audience?.account_ids ?? [],
  } as Audience,
  thank_you: props.survey.thank_you ?? '',
  scheduled_at: props.survey.scheduled_at ?? '',
  deadline: props.survey.deadline ?? '',
})

const nextId = computed(() => Math.max(0, ...form.questions.map(q => q.id)) + 1)
function addQuestion() { form.questions.push({ id: nextId.value, type: 'rating', label: '', required: true, options: [] }) }
function removeQuestion(i: number) { form.questions.splice(i, 1) }
function needsOptions(t: string) { return t === 'choice' || t === 'multichoice' }

function save() {
  form.patch(route('crm.surveys.update', props.survey.id), { preserveScroll: true, onSuccess: () => snack.value = { show: true, text: t('crm_surveys_show.saved'), color: 'success' } })
}
async function previewAudience() {
  const { data } = await axios.post(route('crm.surveys.audience-preview'), form.audience, {
    headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') as HTMLMetaElement)?.content ?? '' },
  })
  preview.value = data
}
function send() {
  router.post(route('crm.surveys.send', props.survey.id), {}, { preserveScroll: true, onSuccess: () => snack.value = { show: true, text: t('crm_surveys_show.survey_sent'), color: 'success' } })
}
function close() { router.post(route('crm.surveys.close', props.survey.id), {}, { preserveScroll: true }) }
function analyze() {
  analyzing.value = true
  router.post(route('crm.surveys.analyze', props.survey.id), {}, { preserveScroll: true, onFinish: () => { analyzing.value = false; router.reload() } })
}

const money = (n: number | null) => n == null ? '—' : 'R ' + n.toLocaleString('en-ZA', { maximumFractionDigits: 0 })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div class="d-flex align-center ga-2">
        <TipBtn icon="tabler-arrow-left" variant="text" :tip="t('crm_surveys_show.back')" :href="route('crm.surveys')" />
        <div>
          <h5 class="text-h5 font-weight-semibold mb-0">{{ form.name }}</h5>
          <VChip size="x-small" :color="survey.status === 'active' ? 'success' : 'blue-grey'" label class="text-capitalize">{{ survey.status }}</VChip>
        </div>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="tabler-device-floppy" :loading="form.processing" @click="save">{{ t('crm_surveys_show.save') }}</VBtn>
        <VBtn v-if="survey.status !== 'active'" color="primary" prepend-icon="tabler-send" @click="send">{{ t('crm_surveys_show.send_now') }}</VBtn>
        <VBtn v-else variant="tonal" color="warning" @click="close">{{ t('crm_surveys_show.close') }}</VBtn>
      </div>
    </div>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="build">{{ t('crm_surveys_show.tabs.build') }}</VTab>
      <VTab value="audience">{{ t('crm_surveys_show.tabs.audience') }}</VTab>
      <VTab value="results">{{ t('crm_surveys_show.tabs.results') }} ({{ results.completed }})</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem value="build">
        <VCard class="mb-4"><VCardText>
          <VTextField v-model="form.name" :label="t('crm_surveys_show.build.survey_name')" class="mb-2" />
          <VTextarea v-model="form.description" :label="t('crm_surveys_show.build.intro_description')" rows="2" class="mb-2" />
          <VTextField v-model="form.thank_you" :label="t('crm_surveys_show.build.thank_you')" :placeholder="t('crm_surveys_show.build.thank_you_placeholder')" />
        </VCardText></VCard>

        <VCard>
          <VCardTitle class="d-flex align-center justify-space-between"><span>{{ t('crm_surveys_show.build.questions') }}</span><VBtn size="small" prepend-icon="tabler-plus" @click="addQuestion">{{ t('crm_surveys_show.build.add_question') }}</VBtn></VCardTitle>
          <VCardText>
            <VCard v-for="(q, i) in form.questions" :key="q.id" variant="outlined" class="mb-3"><VCardText>
              <div class="d-flex ga-2">
                <span class="text-h6 text-disabled">{{ i + 1 }}</span>
                <div class="flex-grow-1">
                  <VTextField v-model="q.label" :label="t('crm_surveys_show.build.question_label')" density="comfortable" class="mb-2" />
                  <div class="d-flex ga-2 flex-wrap">
                    <VSelect v-model="q.type" :items="qTypes" :label="t('crm_surveys_show.build.type')" density="compact" hide-details style="max-width: 200px" />
                    <VCombobox v-if="needsOptions(q.type)" v-model="q.options" :label="t('crm_surveys_show.build.options')" multiple chips closable-chips density="compact" hide-details style="min-width: 280px" />
                    <VSwitch v-model="q.required" :label="t('crm_surveys_show.build.required')" color="primary" density="compact" hide-details />
                  </div>
                </div>
                <TipBtn icon="tabler-trash" variant="text" color="error" size="small" :tip="t('crm_surveys_show.build.remove')" @click="removeQuestion(i)" />
              </div>
            </VCardText></VCard>
            <VAlert v-if="!form.questions.length" type="info" variant="tonal">{{ t('crm_surveys_show.build.no_questions') }}</VAlert>
          </VCardText>
        </VCard>
      </VWindowItem>

      <VWindowItem value="audience">
        <VCard max-width="720"><VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">{{ t('crm_surveys_show.audience.description') }}</p>
          <VSelect v-model="form.audience.mode" :label="t('crm_surveys_show.audience.send_to')" class="mb-3" :items="audienceModes" />

          <VSelect v-if="form.audience.mode === 'industry'" v-model="form.audience.industries" :items="industries" :label="t('crm_surveys_show.audience.industries')" multiple chips closable-chips class="mb-3" />
          <VTextField v-if="form.audience.mode === 'spend'" v-model.number="form.audience.min_spend" type="number" :label="t('crm_surveys_show.audience.min_spend')" :prefix="t('crm_surveys_show.audience.min_spend_prefix')" class="mb-3" />
          <VAutocomplete v-if="form.audience.mode === 'specific'" v-model="form.audience.account_ids" :items="accounts" item-title="name" item-value="id" :label="t('crm_surveys_show.audience.accounts')" multiple chips closable-chips class="mb-3" />

          <VBtn variant="tonal" prepend-icon="tabler-users" @click="previewAudience">{{ t('crm_surveys_show.audience.preview') }}</VBtn>
          <VAlert v-if="preview" type="info" variant="tonal" class="mt-3">
            <strong>{{ t('crm_surveys_show.audience.preview_count', { count: preview.count }) }}</strong>
            <div v-if="preview.sample.length" class="text-caption mt-1">
              {{ t('crm_surveys_show.audience.preview_example') }} <span v-for="(s, i) in preview.sample" :key="i">{{ s.name || s.email }}<span v-if="i < preview.sample.length - 1">, </span></span>
            </div>
          </VAlert>

          <VDivider class="my-4" />
          <div class="text-overline text-medium-emphasis mb-2">{{ t('crm_surveys_show.audience.schedule') }}</div>
          <VRow>
            <VCol cols="12" sm="6"><VTextField v-model="form.scheduled_at" type="datetime-local" :label="t('crm_surveys_show.audience.send_at')" :hint="t('crm_surveys_show.audience.send_at_hint')" persistent-hint /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.deadline" type="datetime-local" :label="t('crm_surveys_show.audience.deadline')" :hint="t('crm_surveys_show.audience.deadline_hint')" persistent-hint /></VCol>
          </VRow>
        </VCardText></VCard>
      </VWindowItem>

      <VWindowItem value="results">
        <VRow class="mb-1">
          <VCol cols="6" md="3"><VCard><VCardText><div class="text-overline text-medium-emphasis">{{ t('crm_surveys_show.results.response_rate') }}</div><div class="text-h5 font-weight-bold">{{ results.response_rate }}%</div><div class="text-caption text-medium-emphasis">{{ results.completed }}/{{ results.recipients }}</div></VCardText></VCard></VCol>
          <VCol cols="6" md="3"><VCard><VCardText><div class="text-overline text-medium-emphasis">{{ t('crm_surveys_show.results.nps') }}</div><div class="text-h5 font-weight-bold">{{ results.nps ?? '—' }}</div></VCardText></VCard></VCol>
          <VCol cols="6" md="3"><VCard><VCardText><div class="text-overline text-medium-emphasis">{{ t('crm_surveys_show.results.csat_avg') }}</div><div class="text-h5 font-weight-bold">{{ results.csat_avg ?? '—' }}</div></VCardText></VCard></VCol>
          <VCol cols="6" md="3"><VCard><VCardText class="d-flex align-center h-100"><VBtn block color="primary" variant="tonal" prepend-icon="tabler-sparkles" :loading="analyzing" :disabled="!results.completed" @click="analyze">{{ t('crm_surveys_show.results.ai_analyse') }}</VBtn></VCardText></VCard></VCol>
        </VRow>

        <VCard v-if="!results.completed"><VCardText class="text-medium-emphasis">{{ t('crm_surveys_show.results.no_responses') }}</VCardText></VCard>
        <VCard v-else>
          <VList lines="two">
            <template v-for="q in results.questions" :key="q.id">
              <VListItem>
                <VListItemTitle class="font-weight-medium">{{ q.label }}</VListItemTitle>
                <VListItemSubtitle>
                  <span v-if="q.average != null">{{ t('crm_surveys_show.results.average') }}: <strong>{{ q.average }}</strong> · {{ t('crm_surveys_show.results.responses_count', { count: q.count }) }}</span>
                  <span v-else-if="q.options"><span v-for="(c, opt) in q.options" :key="opt" class="me-2">{{ opt }}: <strong>{{ c }}</strong></span></span>
                  <span v-else-if="q.samples">{{ t('crm_surveys_show.results.text_responses', { count: q.count }) }}</span>
                </VListItemSubtitle>
                <div v-if="q.samples && q.samples.length" class="text-caption text-medium-emphasis mt-1">
                  <div v-for="(s, i) in q.samples.slice(0, 4)" :key="i">"{{ s }}"</div>
                </div>
              </VListItem>
              <VDivider />
            </template>
          </VList>
        </VCard>
      </VWindowItem>
    </VWindow>

    <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">{{ snack.text }}</VSnackbar>
  </div>
</template>