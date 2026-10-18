<script lang="ts" setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Survey {
  id: number; name: string; category: string; status: string; status_label: string; status_color: string
  questions: number; recipients: number; responses: number; deadline: string | null; ai_ready: boolean; created_at: string | null
}
interface Template { id: number; key: string; name: string; category: string; description: string; questions: any[] }

defineProps<{ surveys: Survey[]; templates: Template[]; industries: string[]; accounts: any[] }>()

const dialog = ref(false)
const confirm = ref()
const form = useForm({ name: '', description: '', category: 'general', template_key: '', questions: [] as any[] })
const picked = ref<Template | null>(null)

function pick(t: Template) {
  picked.value = t
  form.template_key = t.key
  form.category = t.category
  form.questions = t.questions
  if (!form.name) form.name = t.name
}
function blank() { picked.value = null; form.template_key = ''; form.questions = []; form.category = 'general' }
function create() {
  form.post(route('crm.surveys.store'), { onSuccess: () => { dialog.value = false; form.reset() } })
}
function remove(s: Survey) {
  confirm.value.open(
    { title: t('crm_surveys_index.delete_dialog.title'), message: t('crm_surveys_index.delete_dialog.message', { name: s.name }), confirmText: t('crm_surveys_index.delete_dialog.confirm_text'), confirmColor: 'error' },
    () => useForm({}).delete(route('crm.surveys.destroy', s.id), { preserveScroll: true }),
  )
}

const headers = computed(() => [
  { title: t('crm_surveys_index.table.survey'), key: 'name' },
  { title: t('crm_surveys_index.table.status'), key: 'status' },
  { title: t('crm_surveys_index.table.questions'), key: 'questions', align: 'end' as const },
  { title: t('crm_surveys_index.table.recipients'), key: 'recipients', align: 'end' as const },
  { title: t('crm_surveys_index.table.responses'), key: 'responses', align: 'end' as const },
  { title: t('crm_surveys_index.table.deadline'), key: 'deadline' },
  { title: '', key: 'actions', sortable: false },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_surveys_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_surveys_index.description') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="tabler-calendar-repeat" :href="route('crm.survey-plans')">{{ t('crm_surveys_index.planner') }}</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-bulb" :href="route('crm.surveys.recommendations')">{{ t('crm_surveys_index.survey_ai') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('crm_surveys_index.new_survey') }}</VBtn>
      </div>
    </div>

    <VCard>
      <VDataTable :headers="headers" :items="surveys" :items-per-page="25" density="comfortable">
        <template #item.name="{ item }">
          <a class="text-primary cursor-pointer font-weight-medium" @click="router.get(route('crm.surveys.show', item.id))">{{ item.name }}</a>
          <VChip v-if="item.ai_ready" size="x-small" color="info" variant="tonal" class="ms-1" prepend-icon="tabler-sparkles">{{ t('crm_surveys_index.ai_chip') }}</VChip>
        </template>
        <template #item.status="{ item }"><VChip :color="item.status_color" size="small" label>{{ item.status_label }}</VChip></template>
        <template #item.deadline="{ item }">{{ item.deadline ?? '—' }}</template>
        <template #item.actions="{ item }">
          <TipBtn size="x-small" variant="text" icon="tabler-pencil" :tip="t('crm_surveys_index.open_build')" @click="router.get(route('crm.surveys.show', item.id))" />
          <TipBtn size="x-small" variant="text" icon="tabler-trash" color="error" :tip="t('crm_surveys_index.delete')" @click="remove(item)" />
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="dialog" max-width="820">
      <VCard>
        <VCardTitle>{{ t('crm_surveys_index.dialog.title') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="form.name" :label="t('crm_surveys_index.dialog.name')" class="mb-3" :error-messages="form.errors.name" />
          <div class="text-overline text-medium-emphasis mb-2">{{ t('crm_surveys_index.dialog.template_pick') }}</div>
          <VRow>
            <VCol cols="12" sm="6" md="4">
              <VCard :variant="picked === null ? 'flat' : 'outlined'" :color="picked === null ? 'primary' : undefined" class="h-100 cursor-pointer" @click="blank">
                <VCardText><div class="font-weight-bold mb-1"><VIcon icon="tabler-file" class="me-1" />{{ t('crm_surveys_index.dialog.blank') }}</div><div class="text-caption">{{ t('crm_surveys_index.dialog.blank_desc') }}</div></VCardText>
              </VCard>
            </VCol>
            <VCol v-for="tpl in templates" :key="tpl.id" cols="12" sm="6" md="4">
              <VCard :variant="picked?.id === tpl.id ? 'flat' : 'outlined'" :color="picked?.id === tpl.id ? 'primary' : undefined" class="h-100 cursor-pointer" @click="pick(tpl)">
                <VCardText>
                  <div class="font-weight-bold mb-1">{{ tpl.name }}</div>
                  <VChip size="x-small" variant="tonal" class="mb-1 text-capitalize">{{ tpl.category }}</VChip>
                  <div class="text-caption text-medium-emphasis">{{ tpl.description }}</div>
                  <div class="text-caption text-disabled mt-1">{{ t('crm_surveys_index.dialog.questions_count', { count: tpl.questions.length }) }}</div>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">{{ t('crm_surveys_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="create">{{ t('crm_surveys_index.dialog.create_build') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>