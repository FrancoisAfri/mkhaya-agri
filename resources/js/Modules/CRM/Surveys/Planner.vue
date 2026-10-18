<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Plan {
  id: number; name: string; category: string; cadence: string; recurring: boolean; questions: number
  audience: string; deadline_days: number; next_run_at: string | null; last_run_at: string | null
  runs_count: number; active: boolean
}
interface Template { key: string; name: string; category: string; description: string; questions: any[] }

const props = defineProps<{
  plans: Plan[]
  upcoming: { name: string; cadence: string; date: string }[]
  templates: Template[]
  industries: string[]
  accounts: { id: number; name: string }[]
  cadences: string[]
}>()

const drawer = ref(false)
const editing = ref<Plan | null>(null)
const confirm = ref()
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })

const form = useForm({
  name: '', description: '', category: 'general', template_key: '', questions: [] as any[],
  audience: { mode: 'all', industries: [] as string[], min_spend: null as number | null, account_ids: [] as number[] },
  cadence: 'monthly', next_run_at: '', deadline_days: 14, thank_you: '', active: true,
})

const cadenceLabel = computed<Record<string, string>>(() => ({
  once: t('crm_survey_plans.cadences.once'),
  weekly: t('crm_survey_plans.cadences.weekly'),
  monthly: t('crm_survey_plans.cadences.monthly'),
  quarterly: t('crm_survey_plans.cadences.quarterly'),
  yearly: t('crm_survey_plans.cadences.yearly'),
}))
const cadenceColor: Record<string, string> = { once: 'blue-grey', weekly: 'info', monthly: 'primary', quarterly: 'deep-purple', yearly: 'teal' }

const audienceModes = computed(() => [
  { title: t('crm_survey_plans.drawer.modes.all'), value: 'all' },
  { title: t('crm_survey_plans.drawer.modes.industry'), value: 'industry' },
  { title: t('crm_survey_plans.drawer.modes.spend'), value: 'spend' },
  { title: t('crm_survey_plans.drawer.modes.specific'), value: 'specific' },
])

function openNew() { editing.value = null; form.reset(); form.cadence = 'monthly'; form.deadline_days = 14; drawer.value = true }
function pickTemplate(key: string) {
  const t = props.templates.find(x => x.key === key)
  if (t) { form.template_key = t.key; form.questions = t.questions; form.category = t.category; if (!form.name) form.name = t.name }
}
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { drawer.value = false; snack.value = { show: true, text: t('crm_survey_plans.drawer.saved'), color: 'success' } } }
  if (editing.value) form.patch(route('crm.survey-plans.update', editing.value.id), opts)
  else form.post(route('crm.survey-plans.store'), opts)
}
function toggle(p: Plan) { router.post(route('crm.survey-plans.toggle', p.id), {}, { preserveScroll: true }) }
function runNow(p: Plan) {
  confirm.value.open(
    { title: t('crm_survey_plans.send_now_dialog.title'), message: t('crm_survey_plans.send_now_dialog.message', { name: p.name }), confirmText: t('crm_survey_plans.send_now_dialog.confirm_text'), confirmColor: 'primary' },
    () => router.post(route('crm.survey-plans.run', p.id), {}),
  )
}
function remove(p: Plan) {
  confirm.value.open(
    { title: t('crm_survey_plans.delete_dialog.title'), message: t('crm_survey_plans.delete_dialog.message', { name: p.name }), confirmText: t('crm_survey_plans.delete_dialog.confirm_text'), confirmColor: 'error' },
    () => router.delete(route('crm.survey-plans.destroy', p.id), { preserveScroll: true }),
  )
}

const headers = computed(() => [
  { title: t('crm_survey_plans.table.survey'), key: 'name' },
  { title: t('crm_survey_plans.table.cadence'), key: 'cadence' },
  { title: t('crm_survey_plans.table.audience'), key: 'audience' },
  { title: t('crm_survey_plans.table.next_send'), key: 'next_run_at' },
  { title: t('crm_survey_plans.table.runs'), key: 'runs_count', align: 'end' as const },
  { title: t('crm_survey_plans.table.active'), key: 'active' },
  { title: '', key: 'actions', sortable: false },
])
const hasUpcoming = computed(() => props.upcoming.length > 0)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div class="d-flex align-center ga-2">
        <TipBtn icon="tabler-arrow-left" variant="text" :tip="t('crm_survey_plans.back')" :href="route('crm.surveys')" />
        <div>
          <h5 class="text-h5 font-weight-semibold mb-0">{{ t('crm_survey_plans.page_title') }}</h5>
          <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_survey_plans.description') }}</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-calendar-plus" @click="openNew">{{ t('crm_survey_plans.schedule_survey') }}</VBtn>
    </div>

    <VCard v-if="hasUpcoming" class="mb-4" :title="t('crm_survey_plans.upcoming_title')">
      <VCardText>
        <div class="d-flex flex-wrap ga-2">
          <VChip v-for="(u, i) in upcoming" :key="i" :color="cadenceColor[u.cadence]" variant="tonal" prepend-icon="tabler-calendar">
            {{ u.date }} · {{ u.name }}
          </VChip>
        </div>
      </VCardText>
    </VCard>

    <VCard>
      <VDataTable :headers="headers" :items="plans" :items-per-page="25" density="comfortable">
        <template #item.cadence="{ item }"><VChip :color="cadenceColor[item.cadence]" size="small" label>{{ cadenceLabel[item.cadence] }}</VChip></template>
        <template #item.audience="{ item }"><span class="text-capitalize">{{ item.audience }}</span></template>
        <template #item.next_run_at="{ item }">{{ item.next_run_at ?? '—' }}</template>
        <template #item.active="{ item }">
          <VSwitch :model-value="item.active" color="primary" density="compact" hide-details @change="toggle(item)" />
        </template>
        <template #item.actions="{ item }">
          <TipBtn size="x-small" variant="text" icon="tabler-send" color="primary" :tip="t('crm_survey_plans.send_now')" @click="runNow(item)" />
          <TipBtn size="x-small" variant="text" icon="tabler-trash" color="error" :tip="t('crm_survey_plans.delete')" @click="remove(item)" />
        </template>
        <template #no-data><div class="pa-6 text-center text-medium-emphasis">{{ t('crm_survey_plans.no_data') }}</div></template>
      </VDataTable>
    </VCard>

    <VNavigationDrawer v-model="drawer" temporary location="right" width="540">
      <VForm @submit.prevent="save" class="h-100 d-flex flex-column">
        <div class="pa-5 border-b d-flex align-center ga-2">
          <VIcon icon="tabler-calendar-repeat" color="primary" />
          <h5 class="text-h5">{{ t('crm_survey_plans.drawer.title') }}</h5>
        </div>
        <div class="flex-grow-1 overflow-y-auto pa-5">
          <VTextField v-model="form.name" :label="t('crm_survey_plans.drawer.survey_name')" class="mb-2" :error-messages="form.errors.name" />
          <VTextarea v-model="form.description" :label="t('crm_survey_plans.drawer.intro')" rows="2" class="mb-2" />
          <VSelect :model-value="form.template_key" :items="templates.map(t => ({ title: `${t.name} (${t.questions.length}q)`, value: t.key }))"
            :label="t('crm_survey_plans.drawer.template_label')" class="mb-3" clearable @update:model-value="pickTemplate" />

          <div class="text-overline text-medium-emphasis mb-1">{{ t('crm_survey_plans.drawer.when') }}</div>
          <VRow>
            <VCol cols="7"><VSelect v-model="form.cadence" :items="cadences.map(c => ({ title: cadenceLabel[c], value: c }))" :label="t('crm_survey_plans.drawer.cadence')" /></VCol>
            <VCol cols="5"><VTextField v-model.number="form.deadline_days" type="number" min="1" :label="t('crm_survey_plans.drawer.deadline_days')" /></VCol>
          </VRow>
          <VTextField v-model="form.next_run_at" type="datetime-local" :label="form.cadence === 'once' ? t('crm_survey_plans.drawer.send_on') : t('crm_survey_plans.drawer.first_send')" :error-messages="form.errors.next_run_at" class="mb-3" />

          <div class="text-overline text-medium-emphasis mb-1">{{ t('crm_survey_plans.drawer.audience_title') }}</div>
          <VSelect v-model="form.audience.mode" :label="t('crm_survey_plans.drawer.send_to')" class="mb-2" :items="audienceModes" />
          <VSelect v-if="form.audience.mode === 'industry'" v-model="form.audience.industries" :items="industries" :label="t('crm_survey_plans.drawer.industries')" multiple chips closable-chips class="mb-2" />
          <VTextField v-if="form.audience.mode === 'spend'" v-model.number="form.audience.min_spend" type="number" :label="t('crm_survey_plans.drawer.min_spend')" :prefix="t('crm_survey_plans.drawer.min_spend_prefix')" class="mb-2" />
          <VAutocomplete v-if="form.audience.mode === 'specific'" v-model="form.audience.account_ids" :items="accounts" item-title="name" item-value="id" :label="t('crm_survey_plans.drawer.accounts')" multiple chips closable-chips class="mb-2" />

          <VTextField v-model="form.thank_you" :label="t('crm_survey_plans.drawer.thank_you')" class="mt-2" />
          <VSwitch v-model="form.active" :label="t('crm_survey_plans.drawer.active')" color="primary" hide-details class="mt-2" />
          <VAlert type="info" variant="tonal" density="compact" class="text-caption mt-3">
            {{ t('crm_survey_plans.drawer.info') }}
          </VAlert>
        </div>
        <div class="pa-5 border-t d-flex ga-3 justify-end">
          <VBtn variant="tonal" @click="drawer = false">{{ t('crm_survey_plans.drawer.cancel') }}</VBtn>
          <VBtn type="submit" color="primary" :loading="form.processing" :disabled="!form.name || !form.next_run_at">{{ t('crm_survey_plans.drawer.schedule') }}</VBtn>
        </div>
      </VForm>
    </VNavigationDrawer>

    <ConfirmDialog ref="confirm" />
    <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">{{ snack.text }}</VSnackbar>
  </div>
</template>