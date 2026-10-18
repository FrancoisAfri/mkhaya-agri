<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Template = {
  uuid: string; code: string; title: string; version: number; status: string
  scoring_mode: string; subject_type: string | null; is_shared: boolean
  basis: string | null
  question_count: number; critical_count: number
  sections: { title: string; questions: { id: string; text: string; type: string; critical?: boolean }[] }[]
}

const props = defineProps<{ templates: Template[]; canManage?: boolean; noCompany?: boolean }>()

const open = ref(false)
const search = ref('')
const showShared = ref(true)

/**
 * Twenty shipped templates plus a tenant's own is more than a flat list can carry, and the useful
 * split is "ours" versus "the standard library" — a practitioner looks for one or the other.
 */
const filtered = computed(() => props.templates.filter(t => {
  if (!showShared.value && t.is_shared) return false
  if (!search.value) return true
  const q = search.value.toLowerCase()

  return t.code.toLowerCase().includes(q)
    || t.title.toLowerCase().includes(q)
    || (t.basis ?? '').toLowerCase().includes(q)
}))
const expanded = ref<string | null>(null)

const blankQuestion = () => ({ section: 'General', text: '', type: 'PASS_FAIL', weight: 1, critical: false })

const form = useForm({
  code: '', title: '', subject_type: 'SITE', scoring_mode: 'PERCENTAGE',
  pass_threshold: 80, questions: [blankQuestion()] as any[],
})

const addQuestion = () => form.questions.push({ ...blankQuestion(),
  section: form.questions.at(-1)?.section ?? 'General' })

const removeQuestion = (i: number) => {
  if (form.questions.length > 1) form.questions.splice(i, 1)
}

const submit = () => form.post('/sheq/inspections/templates', {
  onSuccess: () => { open.value = false; form.reset(); form.questions = [blankQuestion()] },
})

const QUESTION_TYPES = [
  { title: 'Pass / fail', value: 'PASS_FAIL' },
  { title: 'Yes / no', value: 'YES_NO' },
  { title: 'Scale 1–5', value: 'SCALE' },
  { title: 'Number', value: 'NUMBER' },
  { title: 'Free text', value: 'TEXT' },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.inspections.templates_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.inspections.templates_subtitle') }}</p>
      </div>
      <VBtn v-if="canManage" color="primary" prepend-icon="mdi-plus" @click="open = true">
        {{ t('sheq.inspections.new_template') }}
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VRow class="mb-2">
      <VCol cols="12" sm="6" md="5">
        <VTextField
          v-model="search" :label="t('sheq.inspections.search_templates')"
          density="compact" variant="outlined" prepend-inner-icon="mdi-magnify"
          hide-details clearable
        />
      </VCol>
      <VCol cols="12" sm="6" md="4" class="d-flex align-center">
        <VSwitch
          v-model="showShared" color="primary" hide-details density="compact"
          :label="t('sheq.inspections.show_library')"
        />
      </VCol>
    </VRow>

    <VCard elevation="0" border rounded="lg">
      <div v-if="!templates.length" class="text-center py-10 text-medium-emphasis">
        <VIcon icon="mdi-clipboard-list-outline" size="32" class="mb-2" />
        <div class="text-body-2">{{ t('sheq.inspections.no_templates') }}</div>
        <div class="text-caption mt-2">{{ t('sheq.inspections.install_library') }}</div>
      </div>

      <VTable v-else density="comfortable">
        <thead>
          <tr>
            <th scope="col" style="width: 40px" />
            <th scope="col">{{ t('sheq.registers.col.code') }}</th>
            <th scope="col">{{ t('sheq.registers.col.title') }}</th>
            <th scope="col">{{ t('sheq.inspections.questions') }}</th>
            <th scope="col">{{ t('sheq.inspections.basis') }}</th>
            <th scope="col">{{ t('sheq.registers.col.status') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="tpl in filtered" :key="tpl.uuid">
            <tr>
              <td>
                <VBtn
                  variant="text" size="x-small" icon
                  :aria-expanded="expanded === tpl.uuid"
                  :aria-label="t('sheq.inspections.show_questions', { code: tpl.code })"
                  @click="expanded = expanded === tpl.uuid ? null : tpl.uuid"
                >
                  <VIcon :icon="expanded === tpl.uuid ? 'mdi-chevron-down' : 'mdi-chevron-right'" size="18" />
                </VBtn>
              </td>
              <td class="text-body-2 font-weight-medium">
                {{ tpl.code }}
                <VChip v-if="tpl.is_shared" size="x-small" variant="tonal" class="ms-1">
                  {{ t('sheq.inspections.from_pack') }}
                </VChip>
              </td>
              <td class="text-body-2">{{ tpl.title }} <span class="text-medium-emphasis">v{{ tpl.version }}</span></td>
              <td class="text-body-2">
                {{ tpl.question_count }}
                <VChip v-if="tpl.critical_count" size="x-small" color="error" variant="tonal" class="ms-1">
                  {{ t('sheq.inspections.n_critical', { count: tpl.critical_count }) }}
                </VChip>
              </td>
              <td class="text-body-2 text-medium-emphasis" style="max-inline-size: 260px">
                {{ tpl.basis ?? '—' }}
              </td>
              <td>
                <VChip :color="tpl.status === 'PUBLISHED' ? 'success' : 'grey'" size="x-small" variant="tonal">
                  {{ tpl.status }}
                </VChip>
              </td>
            </tr>
            <tr v-if="expanded === tpl.uuid">
              <td colspan="6" class="pa-0">
                <div class="pa-4 bg-surface-variant">
                  <div v-for="section in tpl.sections" :key="section.title" class="mb-3">
                    <div class="text-subtitle-2 mb-1">{{ section.title }}</div>
                    <ol class="text-body-2 ps-5">
                      <li v-for="q in section.questions" :key="q.id" class="mb-1">
                        {{ q.text }}
                        <VChip size="x-small" variant="tonal" class="ms-1">{{ q.type }}</VChip>
                        <VChip v-if="q.critical" size="x-small" color="error" variant="tonal" class="ms-1">
                          {{ t('sheq.inspections.critical') }}
                        </VChip>
                      </li>
                    </ol>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </VTable>
    </VCard>

    <!-- ── Template builder ─────────────────────────────────────────────────────────────── -->
    <VDialog v-model="open" max-width="1100" scrollable>
      <VCard>
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.inspections.new_template') }}
        </VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="12" md="3">
              <!--
                Uppercased as it is typed rather than validated after the fact. Vuetify replaces a
                hint with the error message, so somebody who typed lowercase lost the only
                explanation of the format at the moment they needed it.
              -->
              <VTextField
                :model-value="form.code"
                :label="t('sheq.registers.col.code')"
                variant="outlined" density="comfortable" :error-messages="form.errors.code"
                :hint="t('sheq.inspections.code_hint')" persistent-hint
                @update:model-value="(v) => form.code = String(v ?? '').toUpperCase()"
              />
            </VCol>
            <VCol cols="12" md="5">
              <VTextField
                v-model="form.title" :label="t('sheq.registers.col.title')"
                variant="outlined" density="comfortable" :error-messages="form.errors.title"
              />
            </VCol>
            <VCol cols="6" md="2">
              <VSelect
                v-model="form.scoring_mode" :label="t('sheq.inspections.scoring')"
                :items="[
                  { title: 'None', value: 'NONE' },
                  { title: 'Percentage', value: 'PERCENTAGE' },
                  { title: 'Weighted', value: 'WEIGHTED' },
                ]"
                variant="outlined" density="comfortable" :error-messages="form.errors.scoring_mode"
              />
            </VCol>
            <VCol cols="6" md="2">
              <VTextField
                v-model.number="form.pass_threshold" type="number"
                :label="t('sheq.inspections.pass_mark')"
                variant="outlined" density="comfortable"
                :disabled="form.scoring_mode === 'NONE'"
                :error-messages="form.errors.pass_threshold"
              />
            </VCol>
          </VRow>

          <VDivider class="my-4" />

          <div class="d-flex align-center justify-space-between mb-2">
            <span class="text-body-2 font-weight-medium">{{ t('sheq.inspections.questions') }}</span>
            <span class="text-caption text-medium-emphasis">
              {{ t('sheq.inspections.question_count', { count: form.questions.length }) }}
            </span>
          </div>

          <!-- A flat repeater rather than nested sections: a nested repeater on a phone is how a
               practitioner abandons a 200-question checklist halfway through. -->
          <div
            v-for="(q, i) in form.questions" :key="i"
            class="d-flex align-start gap-2 py-2 question-row"
          >
            <VTextField
              v-model="q.section" :label="t('sheq.inspections.section')"
              variant="outlined" density="compact" hide-details style="max-inline-size: 150px"
            />
            <VTextField
              v-model="q.text" :label="t('sheq.inspections.question')"
              variant="outlined" density="compact"
              :error-messages="form.errors[`questions.${i}.text`]"
            />
            <VSelect
              v-model="q.type" :items="QUESTION_TYPES" :label="t('sheq.registers.col.type')"
              variant="outlined" density="compact" hide-details style="max-inline-size: 150px"
            />
            <VCheckbox
              v-model="q.critical" :label="t('sheq.inspections.critical')"
              density="compact" hide-details color="error"
            />
            <VBtn
              variant="text" size="small" icon color="error" class="mt-1"
              :disabled="form.questions.length === 1"
              :aria-label="t('sheq.inspections.remove_question', { n: i + 1 })"
              @click="removeQuestion(i)"
            >
              <VIcon icon="mdi-trash-can-outline" size="18" />
            </VBtn>
          </div>

          <VBtn variant="tonal" size="small" prepend-icon="mdi-plus" class="mt-2" @click="addQuestion">
            {{ t('sheq.inspections.add_question') }}
          </VBtn>

          <p class="text-caption text-medium-emphasis mt-4 mb-0">
            {{ t('sheq.inspections.critical_hint') }}
          </p>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="open = false">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing"
                :disabled="form.processing" @click="submit">
            {{ t('sheq.inspections.publish') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.question-row + .question-row {
  border-block-start: 1px solid rgba(var(--v-theme-on-surface), .08);
}

@media (max-width: 960px) {
  .question-row { flex-wrap: wrap; }
}
</style>
