<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Question = { id?: number; question: string; weight_percentage: number | null }
type Template = { id: number; name: string; status: string; questions: Question[] }
type Appraisal = { id: number; contract: string | null; template: string | null; status: string | null; total_score: number; appraisal_date: string | null }

const props = defineProps<{
  templates: Template[]
  appraisals: { data: Appraisal[]; links: any[] }
  contracts: { id: number; title: string }[]
}>()

const tab = ref('appraisals')
const templateDialog = ref(false)
const savingTemplate = ref(false)
const templateForm = ref<{ name: string; status: string; questions: Question[] }>({ name: '', status: 'active', questions: [{ question: '', weight_percentage: null }] })

function addQuestion() { templateForm.value.questions.push({ question: '', weight_percentage: null }) }
function removeQuestion(i: number) { templateForm.value.questions.splice(i, 1) }
function saveTemplate() {
  savingTemplate.value = true
  router.post(route('contracts.appraisals.templates.store'), templateForm.value as any, {
    preserveScroll: true,
    onSuccess: () => {
      templateDialog.value = false
      templateForm.value = { name: '', status: 'active', questions: [{ question: '', weight_percentage: null }] }
    },
    onFinish: () => (savingTemplate.value = false),
  })
}
// Holds the contract's uuid: the appraise route is keyed on it, not on the row id.
const appraiseContractUuid = ref<string | null>(null)
function startAppraisal() {
  if (appraiseContractUuid.value) router.get(route('contracts.appraisals.appraise', appraiseContractUuid.value))
}
</script>

<template>
  <div class="pa-4">
    <!-- Loading overlay -->
    <VOverlay v-if="savingTemplate" contained class="align-center justify-center" style="position: fixed; z-index: 9999;">
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <div class="d-flex align-center gap-3 mb-4">
      <VAvatar color="success" variant="tonal" size="48" rounded>
        <VIcon icon="tabler-star" size="26" />
      </VAvatar>
      <div>
        <h4 class="text-h4 mb-0">{{ t('contracts_appraisals.page_title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('contracts_appraisals.description') }}</p>
      </div>
    </div>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="appraisals">{{ t('contracts_appraisals.tabs.appraisals') }}</VTab>
      <VTab value="templates">{{ t('contracts_appraisals.tabs.templates') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem value="appraisals">
        <VCard border class="mb-4">
          <VCardText class="d-flex flex-wrap align-center gap-3">
            <VSelect
              v-model="appraiseContractUuid"
              :items="contracts"
              item-title="title"
              item-value="uuid"
              :label="t('contracts_appraisals.appraisals.select_contract')"
              variant="outlined"
              density="compact"
              hide-details
              style="max-width:360px"
            />
            <VBtn
              color="primary"
              prepend-icon="tabler-clipboard-check"
              :disabled="!appraiseContractUuid"
              @click="startAppraisal"
            >
              {{ t('contracts_appraisals.appraisals.appraise') }}
            </VBtn>
          </VCardText>
        </VCard>
        <VCard border>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('contracts_appraisals.appraisals.table.contract') }}</th>
                <th>{{ t('contracts_appraisals.appraisals.table.template') }}</th>
                <th>{{ t('contracts_appraisals.appraisals.table.status') }}</th>
                <th class="text-right">{{ t('contracts_appraisals.appraisals.table.score') }}</th>
                <th>{{ t('contracts_appraisals.appraisals.table.date') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!appraisals.data.length">
                <td colspan="5" class="text-center text-medium-emphasis py-8">{{ t('contracts_appraisals.appraisals.empty') }}</td>
              </tr>
              <tr v-for="appraisal in appraisals.data" :key="appraisal.id">
                <td>{{ appraisal.contract ?? '—' }}</td>
                <td>{{ appraisal.template ?? '—' }}</td>
                <td><VChip size="small" variant="tonal">{{ appraisal.status ?? '—' }}</VChip></td>
                <td class="text-right">{{ appraisal.total_score }}</td>
                <td>{{ appraisal.appraisal_date ?? '—' }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="templates">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" prepend-icon="tabler-plus" @click="templateDialog = true">
            {{ t('contracts_appraisals.templates.new_template') }}
          </VBtn>
        </div>
        <VRow>
          <VCol v-for="template in templates" :key="template.id" cols="12" md="6">
            <VCard border>
              <VCardTitle class="d-flex justify-space-between align-center">
                {{ template.name }}
                <VChip size="small" :color="template.status === 'active' ? 'success' : 'grey'" variant="tonal">
                  {{ template.status === 'active' ? t('contracts_appraisals.templates.active') : t('contracts_appraisals.templates.inactive') }}
                </VChip>
              </VCardTitle>
              <VCardText>
                <div v-if="!template.questions.length" class="text-medium-emphasis">{{ t('contracts_appraisals.templates.no_questions') }}</div>
                <ul v-else class="pl-4">
                  <li v-for="question in template.questions" :key="question.id">
                    {{ question.question }}
                    <span class="text-medium-emphasis">({{ t('contracts_appraisals.templates.weight', { weight: question.weight_percentage ?? 0 }) }})</span>
                  </li>
                </ul>
              </VCardText>
            </VCard>
          </VCol>
          <VCol v-if="!templates.length" cols="12">
            <VCard border>
              <VCardText class="text-center text-medium-emphasis py-8">{{ t('contracts_appraisals.templates.empty') }}</VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>
    </VWindow>

    <VDialog v-model="templateDialog" max-width="640" persistent>
      <VCard :loading="savingTemplate" :disabled="savingTemplate">
        <VCardTitle>{{ t('contracts_appraisals.dialog.title') }}</VCardTitle>
        <VCardText>
          <VTextField
            v-model="templateForm.name"
            :label="t('contracts_appraisals.dialog.template_name')"
            variant="outlined"
            class="mb-3"
            :disabled="savingTemplate"
          />
          <div class="d-flex justify-space-between align-center mb-2">
            <span class="text-subtitle-2">{{ t('contracts_appraisals.dialog.questions') }}</span>
            <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" :disabled="savingTemplate" @click="addQuestion">
              {{ t('contracts_appraisals.dialog.add') }}
            </VBtn>
          </div>
          <div v-for="(q, i) in templateForm.questions" :key="i" class="d-flex gap-2 mb-2">
            <VTextField
              v-model="q.question"
              :label="t('contracts_appraisals.dialog.question')"
              variant="outlined"
              density="compact"
              hide-details
              :disabled="savingTemplate"
            />
            <VTextField
              v-model.number="q.weight_percentage"
              type="number"
              :label="t('contracts_appraisals.dialog.weight')"
              variant="outlined"
              density="compact"
              hide-details
              style="max-width:120px"
              :disabled="savingTemplate"
            />
            <VBtn icon="tabler-trash" size="small" variant="text" color="error" :disabled="savingTemplate" @click="removeQuestion(i)" />
          </div>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" :disabled="savingTemplate" @click="templateDialog = false">
            {{ t('contracts_appraisals.dialog.cancel') }}
          </VBtn>
          <VBtn color="primary" :loading="savingTemplate" :disabled="!templateForm.name || savingTemplate" @click="saveTemplate">
            {{ t('contracts_appraisals.dialog.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
