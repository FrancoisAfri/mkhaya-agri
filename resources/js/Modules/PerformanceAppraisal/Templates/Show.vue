<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

type Question = {
  id: number
  question: string
  description: string | null
  question_type: 'rating' | 'text' | 'yes_no' | 'multiple_choice'
  options: string[] | null
  is_required: boolean
  weight: number
  sort_order: number
}
type Section = {
  id: number
  name: string
  description: string | null
  weight: number
  sort_order: number
  questions: Question[]
}
type Template = {
  id: number
  name: string
  description: string | null
  is_default: boolean
  is_active: boolean
  sections: Section[]
}

const props = defineProps<{ template: Template }>()

// ── Section state
const secDrawer  = ref(false)
const secEditing = ref<Partial<Section> | null>(null)

function openSec(section?: Section) {
  secEditing.value = section ? { ...section } : { name: '', description: null, weight: 0, sort_order: props.template.sections.length }
  secDrawer.value = true
}
function saveSec() {
  if (!secEditing.value) return
  const s = secEditing.value
  if (s.id) {
    router.patch(route('performance.templates.sections.update', [props.template.id, s.id]), s, { onSuccess: () => { secDrawer.value = false } })
  } else {
    router.post(route('performance.templates.sections.store', props.template.id), s, { onSuccess: () => { secDrawer.value = false } })
  }
}
function deleteSec(section: Section) {
  router.delete(route('performance.templates.sections.destroy', [props.template.id, section.id]), { preserveScroll: true })
}

// ── Question state
const qDrawer    = ref(false)
const qEditing   = ref<Partial<Question> | null>(null)
const qSection   = ref<Section | null>(null)

function openQ(section: Section, question?: Question) {
  qSection.value = section
  qEditing.value = question
    ? { ...question }
    : { question: '', description: null, question_type: 'rating', options: null, is_required: true, weight: 0, sort_order: section.questions.length }
  qDrawer.value = true
}
function saveQ() {
  if (!qEditing.value || !qSection.value) return
  const q = qEditing.value
  if (q.id) {
    router.patch(route('performance.templates.sections.questions.update', [props.template.id, qSection.value.id, q.id]), q, {
      onSuccess: () => { qDrawer.value = false },
    })
  } else {
    router.post(route('performance.templates.sections.questions.store', [props.template.id, qSection.value.id]), q, {
      onSuccess: () => { qDrawer.value = false },
    })
  }
}
function deleteQ(section: Section, question: Question) {
  router.delete(route('performance.templates.sections.questions.destroy', [props.template.id, section.id, question.id]), { preserveScroll: true })
}

const questionTypes = [
  { value: 'rating', title: 'Rating (numeric score)' },
  { value: 'text', title: 'Text response' },
  { value: 'yes_no', title: 'Yes / No' },
  { value: 'multiple_choice', title: 'Multiple choice' },
]

// Multiple choice option management
const newOption = ref('')
function addOption() {
  if (!qEditing.value || !newOption.value.trim()) return
  if (!qEditing.value.options) qEditing.value.options = []
  qEditing.value.options.push(newOption.value.trim())
  newOption.value = ''
}
function removeOption(index: number) {
  qEditing.value?.options?.splice(index, 1)
}
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center mb-4">
      <VBtn variant="text" icon="ri-arrow-left-line" @click="router.visit(route('performance.templates.index'))" class="mr-2" />
      <div>
        <h1 class="text-h5 font-weight-bold">{{ props.template.name }}</h1>
        <p class="text-body-2 text-medium-emphasis" v-if="props.template.description">{{ props.template.description }}</p>
      </div>
      <VSpacer />
      <VBtn color="primary" prepend-icon="ri-add-line" @click="openSec()">{{ t('pa.templates.add_section') }}</VBtn>
    </div>

    <VAlert v-if="props.template.sections.length === 0" type="info" variant="tonal" class="mb-4">
      {{ t('pa.templates.no_sections') }}
    </VAlert>

    <!-- Sections -->
    <VExpansionPanels variant="accordion" class="mb-4" multiple>
      <VExpansionPanel v-for="section in props.template.sections" :key="section.id">
        <VExpansionPanelTitle>
          <div class="d-flex align-center gap-2">
            <VIcon icon="ri-layout-5-line" size="18" />
            <span class="font-weight-medium">{{ section.name }}</span>
            <VChip size="x-small" variant="tonal" class="ml-1">{{ section.questions.length }} question{{ section.questions.length !== 1 ? 's' : '' }}</VChip>
            <span v-if="section.weight > 0" class="text-caption text-medium-emphasis ml-1">({{ section.weight }}%)</span>
          </div>
        </VExpansionPanelTitle>
        <VExpansionPanelText>
          <!-- Section action bar -->
          <div class="d-flex align-center mb-3">
            <p v-if="section.description" class="text-body-2 text-medium-emphasis mb-0 flex-1-1">{{ section.description }}</p>
            <VSpacer v-else />
            <div class="d-flex gap-1 ms-2 flex-shrink-0">
              <VBtn
                size="small"
                variant="tonal"
                color="primary"
                prepend-icon="ri-edit-line"
                @click="openSec(section)"
              >{{ t('pa.templates.edit_section') }}</VBtn>
              <VBtn
                size="small"
                variant="tonal"
                color="error"
                prepend-icon="ri-delete-bin-line"
                @click="deleteSec(section)"
              >{{ t('pa.common.delete') }}</VBtn>
            </div>
          </div>

          <VDivider class="mb-3" />

          <!-- Questions list -->
          <div class="mb-3">
            <p v-if="section.questions.length === 0" class="text-body-2 text-medium-emphasis text-center py-3">
              {{ t('pa.templates.no_questions') }}
            </p>
            <VCard
              v-for="(q, idx) in section.questions"
              :key="q.id"
              variant="outlined"
              class="mb-2"
            >
              <VCardText class="py-2 px-3">
                <div class="d-flex align-center gap-3">
                  <span class="text-caption text-medium-emphasis font-weight-medium" style="min-width:20px">{{ idx + 1 }}.</span>
                  <div class="flex-grow-1 min-width-0">
                    <div class="text-body-2 font-weight-medium mb-1">{{ q.question }}</div>
                    <div class="d-flex flex-wrap gap-1">
                      <VChip size="x-small" variant="tonal">{{ q.question_type.replace('_', ' ') }}</VChip>
                      <VChip v-if="q.is_required" size="x-small" color="error" variant="tonal">{{ t('pa.templates.required') }}</VChip>
                      <VChip v-if="q.weight > 0" size="x-small" color="success" variant="tonal">{{ q.weight }}% {{ t('pa.templates.weight') }}</VChip>
                    </div>
                  </div>
                  <div class="d-flex gap-1 flex-shrink-0 ms-2">
                    <VBtn
                      size="x-small"
                      variant="tonal"
                      color="primary"
                      prepend-icon="ri-edit-line"
                      @click="openQ(section, q)"
                    >{{ t('pa.common.edit') }}</VBtn>
                    <VBtn
                      size="x-small"
                      variant="tonal"
                      color="error"
                      prepend-icon="ri-delete-bin-line"
                      @click="deleteQ(section, q)"
                    >{{ t('pa.common.delete') }}</VBtn>
                  </div>
                </div>
              </VCardText>
            </VCard>
          </div>

          <VBtn size="small" variant="tonal" color="primary" prepend-icon="ri-add-line" @click="openQ(section)">
            {{ t('pa.templates.add_question') }}
          </VBtn>
        </VExpansionPanelText>
      </VExpansionPanel>
    </VExpansionPanels>

    <!-- Section Drawer -->
    <VNavigationDrawer v-model="secDrawer" location="right" temporary width="420">
      <div class="pa-4 d-flex align-center border-b">
        <span class="text-h6">{{ secEditing?.id ? t('pa.templates.edit_section') : t('pa.templates.new_section') }}</span>
        <VSpacer />
        <VBtn icon="ri-close-line" variant="text" size="small" @click="secDrawer = false" />
      </div>
      <div class="pa-4" v-if="secEditing">
        <VTextField v-model="secEditing.name" :label="t('pa.templates.section_name')" variant="outlined" density="comfortable" class="mb-3" />
        <VTextField v-model="secEditing.description" :label="t('pa.templates.description_optional')" variant="outlined" density="comfortable" class="mb-3" />
        <VTextField v-model.number="secEditing.weight" :label="t('pa.templates.weight_pct')" type="number" min="0" max="100" variant="outlined" density="comfortable" :hint="t('pa.templates.weight_hint')" persistent-hint class="mb-3" />
        <VTextField v-model.number="secEditing.sort_order" :label="t('pa.templates.section.sort_order')" type="number" variant="outlined" density="comfortable" class="mb-4" />
        <div class="d-flex justify-end gap-2">
          <VBtn variant="text" @click="secDrawer = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="saveSec">{{ secEditing?.id ? t('pa.templates.update_section') : t('pa.templates.add_section') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>

    <!-- Question Drawer -->
    <VNavigationDrawer v-model="qDrawer" location="right" temporary width="480">
      <div class="pa-4 d-flex align-center border-b">
        <span class="text-h6">{{ qEditing?.id ? t('pa.templates.edit_question') : t('pa.templates.new_question') }}</span>
        <VSpacer />
        <VBtn icon="ri-close-line" variant="text" size="small" @click="qDrawer = false" />
      </div>
      <div class="pa-4" v-if="qEditing">
        <VTextField v-model="qEditing.question" :label="t('pa.templates.question')" variant="outlined" density="comfortable" class="mb-3" />
        <VTextField v-model="qEditing.description" :label="t('pa.templates.guidance_optional')" variant="outlined" density="comfortable" class="mb-3" />
        <VSelect v-model="qEditing.question_type" :items="questionTypes" item-title="title" item-value="value" :label="t('pa.templates.question_type')" variant="outlined" density="comfortable" class="mb-3" />

        <!-- Multiple choice options -->
        <div v-if="qEditing.question_type === 'multiple_choice'" class="mb-3">
          <p class="text-caption text-medium-emphasis mb-2">{{ t('pa.templates.options') }}</p>
          <div class="d-flex gap-2 mb-2">
            <VTextField v-model="newOption" :label="t('pa.templates.add_option')" variant="outlined" density="compact" @keyup.enter="addOption" />
            <VBtn icon="ri-add-line" variant="tonal" @click="addOption" />
          </div>
          <VChip
            v-for="(opt, i) in qEditing.options ?? []"
            :key="i"
            closable size="small" class="mr-1 mb-1"
            @click:close="removeOption(i)"
          >{{ opt }}</VChip>
        </div>

        <VTextField v-model.number="qEditing.weight" :label="t('pa.templates.weight_pct')" type="number" min="0" max="100" variant="outlined" density="comfortable" :hint="t('pa.templates.weight_zero_hint')" persistent-hint class="mb-3" />
        <VTextField v-model.number="qEditing.sort_order" :label="t('pa.templates.section.sort_order')" type="number" variant="outlined" density="comfortable" class="mb-2" />
        <VSwitch v-model="qEditing.is_required" :label="t('pa.templates.required')" inset color="error" class="mb-4" />
        <div class="d-flex justify-end gap-2">
          <VBtn variant="text" @click="qDrawer = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="saveQ">{{ qEditing?.id ? t('pa.templates.update_question') : t('pa.templates.add_question') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>
  </div>
</template>
