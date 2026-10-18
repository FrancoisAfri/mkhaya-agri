<script setup lang="ts">
/**
 * One scorecard, and the questions it asks.
 *
 * The old screen could add a question and never change or remove one, which is a one-way door: a
 * weight typed wrong stayed wrong, and the only way out was a new template. Everything here is
 * editable, and the running weight total is on screen while you work, because the rule it has to
 * satisfy is invisible until something is refused.
 */
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

interface Question {
  uuid: string; question: string; description: string | null
  weight: number; minimum: number | null; sort_order: number
}

const props = defineProps<{
  template: { uuid: string; name: string; status: string | null; industry_id: number | null; industry: string | null }
  questions: Question[]
  weight: number
  industries: { id: number; industry_type_name: string }[]
  usedBy: number
}>()

// ── The template itself ───────────────────────────────────────────────────────
const details = useForm({
  name: props.template.name,
  industry_id: props.template.industry_id,
  status: props.template.status ?? 'active',
})
function saveDetails() {
  details.patch(route('suppliers.appraisals.templates.update', props.template.uuid), { preserveScroll: true })
}

const confirmDelete = ref(false)
function destroy() {
  router.delete(route('suppliers.appraisals.templates.destroy', props.template.uuid))
}

// ── Questions ─────────────────────────────────────────────────────────────────
const adding = ref(false)
const addForm = useForm<{ question: string; description: string; weight_percentage: number | null; minimum_threshold: number | null }>({
  question: '', description: '', weight_percentage: null, minimum_threshold: null,
})
function add() {
  addForm.post(route('suppliers.appraisals.questions.store', props.template.uuid), {
    preserveScroll: true,
    onSuccess: () => { addForm.reset(); adding.value = false },
  })
}

const editingUuid = ref<string | null>(null)
const editForm = useForm<{ question: string; description: string; weight_percentage: number | null; minimum_threshold: number | null; sort_order: number }>({
  question: '', description: '', weight_percentage: null, minimum_threshold: null, sort_order: 0,
})
function startEdit(q: Question) {
  editingUuid.value = q.uuid
  editForm.question = q.question
  editForm.description = q.description ?? ''
  editForm.weight_percentage = q.weight
  editForm.minimum_threshold = q.minimum
  editForm.sort_order = q.sort_order
  editForm.clearErrors()
}
function saveEdit() {
  if (!editingUuid.value) return
  editForm.patch(route('suppliers.appraisals.questions.update', [props.template.uuid, editingUuid.value]), {
    preserveScroll: true,
    onSuccess: () => { editingUuid.value = null },
  })
}
function removeQuestion(q: Question) {
  router.delete(route('suppliers.appraisals.questions.destroy', [props.template.uuid, q.uuid]), { preserveScroll: true })
}

/*
 * Moving a question is a sort_order change, not a drag library. Two buttons do the whole job on a
 * list this short, and they work with a keyboard and a screen reader without any further thought.
 */
function move(q: Question, delta: number) {
  const ordered = [...props.questions]
  const i = ordered.findIndex(x => x.uuid === q.uuid)
  const j = i + delta
  if (i < 0 || j < 0 || j >= ordered.length) return
  router.patch(route('suppliers.appraisals.questions.update', [props.template.uuid, q.uuid]), {
    question: q.question,
    description: q.description,
    weight_percentage: q.weight,
    minimum_threshold: q.minimum,
    sort_order: ordered[j].sort_order,
  }, { preserveScroll: true })
}

const remaining = computed(() => Math.round((100 - props.weight) * 100) / 100)
const weightState = computed(() => {
  if (props.weight === 100) return { colour: 'success', message: t('supplier_management.appraisals.weight_ok') }
  if (props.weight > 100) return { colour: 'error', message: t('supplier_management.appraisals.weight_over_hint') }
  return { colour: 'warning', message: t('supplier_management.appraisals.weight_under_hint', { short: remaining.value }) }
})
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ template.name }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ template.industry ?? t('supplier_management.appraisals.any_industry') }}
          <template v-if="usedBy"> · {{ t('supplier_management.appraisals.used_by', { count: usedBy }) }}</template>
        </p>
      </div>
      <VBtn variant="tonal" :href="route('suppliers.appraisals.templates')">
        {{ t('supplier_management.appraisals.back_to_templates') }}
      </VBtn>
    </div>

    <!-- The rule that is invisible until something is refused. -->
    <VAlert :type="weightState.colour === 'success' ? 'success' : weightState.colour === 'error' ? 'error' : 'warning'"
            variant="tonal" density="comfortable" class="mb-4">
      <div class="d-flex align-center justify-space-between flex-wrap ga-2">
        <span class="text-body-2">{{ weightState.message }}</span>
        <span class="text-h6" style="font-variant-numeric: tabular-nums">{{ weight }}%</span>
      </div>
    </VAlert>

    <VRow>
      <!-- ── Details ──────────────────────────────────────────────────────── -->
      <VCol cols="12" md="4">
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-semibold">{{ t('supplier_management.appraisals.template_details') }}</VCardTitle>
          <VDivider />
          <VCardText>
            <VTextField v-model="details.name" :label="t('supplier_management.appraisals.template_name')"
                        density="comfortable" variant="outlined" :error-messages="details.errors.name" class="mb-3" />
            <VSelect v-model="details.industry_id" :items="industries" item-title="industry_type_name" item-value="id"
                     :label="t('supplier_management.appraisals.industry')" density="comfortable" variant="outlined"
                     clearable :error-messages="details.errors.industry_id" class="mb-3" />
            <VSelect v-model="details.status" :items="['active', 'retired']"
                     :label="t('supplier_management.appraisals.status')" density="comfortable" variant="outlined" />
          </VCardText>
          <VDivider />
          <VCardActions>
            <!-- A template an appraisal was scored against is retired, not removed: every past
                 appraisal reads its questions and weights through it. -->
            <VBtn v-if="!usedBy" size="small" variant="text" color="error" @click="confirmDelete = true">
              {{ t('supplier_management.appraisals.delete_template') }}
            </VBtn>
            <span v-else class="text-caption text-medium-emphasis ms-2">{{ t('supplier_management.appraisals.cannot_delete') }}</span>
            <VSpacer />
            <VBtn color="primary" variant="flat" size="small" :loading="details.processing" @click="saveDetails">
              {{ t('supplier_management.appraisals.save') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>

      <!-- ── Questions ────────────────────────────────────────────────────── -->
      <VCol cols="12" md="8">
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="d-flex align-center justify-space-between">
            <span class="text-body-1 font-weight-semibold">{{ t('supplier_management.appraisals.questions') }}</span>
            <VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-plus" @click="adding = !adding">
              {{ t('supplier_management.appraisals.add_question') }}
            </VBtn>
          </VCardTitle>
          <VDivider />

          <VCardText v-if="adding">
            <VTextField v-model="addForm.question" :label="t('supplier_management.appraisals.question')"
                        density="compact" variant="outlined" :error-messages="addForm.errors.question" class="mb-2" />
            <VTextarea v-model="addForm.description" :label="t('supplier_management.appraisals.description')"
                       rows="2" density="compact" variant="outlined" class="mb-2" />
            <VRow dense>
              <VCol cols="6">
                <VTextField v-model.number="addForm.weight_percentage" type="number" min="1" :max="100"
                            :label="t('supplier_management.appraisals.weight')"
                            :hint="t('supplier_management.appraisals.weight_remaining', { remaining })" persistent-hint
                            density="compact" variant="outlined" :error-messages="addForm.errors.weight_percentage" />
              </VCol>
              <VCol cols="6">
                <VTextField v-model.number="addForm.minimum_threshold" type="number" min="1" max="5"
                            :label="t('supplier_management.appraisals.minimum')"
                            :hint="t('supplier_management.appraisals.minimum_hint')" persistent-hint
                            density="compact" variant="outlined" :error-messages="addForm.errors.minimum_threshold" />
              </VCol>
            </VRow>
            <div class="d-flex justify-end ga-2 mt-2">
              <VBtn size="small" variant="text" @click="adding = false">{{ t('supplier_management.appraisals.cancel') }}</VBtn>
              <VBtn size="small" color="primary" :loading="addForm.processing" :disabled="!addForm.question" @click="add">
                {{ t('supplier_management.appraisals.add') }}
              </VBtn>
            </div>
          </VCardText>

          <VDivider v-if="adding" />

          <VCardText v-if="!questions.length" class="text-center py-8 text-medium-emphasis">
            {{ t('supplier_management.appraisals.no_questions') }}
          </VCardText>
          <VList v-else density="comfortable">
            <template v-for="(q, i) in questions" :key="q.uuid">
              <!-- Editing is its own row: a named slot must be a direct child of the component,
                   so #append cannot sit inside a conditional <template>. -->
              <VListItem v-if="editingUuid === q.uuid">
                <div class="w-100 py-2">
                    <VTextField v-model="editForm.question" :label="t('supplier_management.appraisals.question')"
                                density="compact" variant="outlined" :error-messages="editForm.errors.question" class="mb-2" />
                    <VTextarea v-model="editForm.description" :label="t('supplier_management.appraisals.description')"
                               rows="2" density="compact" variant="outlined" class="mb-2" />
                    <VRow dense>
                      <VCol cols="6">
                        <VTextField v-model.number="editForm.weight_percentage" type="number" min="1" max="100"
                                    :label="t('supplier_management.appraisals.weight')" density="compact" variant="outlined"
                                    :error-messages="editForm.errors.weight_percentage" />
                      </VCol>
                      <VCol cols="6">
                        <VTextField v-model.number="editForm.minimum_threshold" type="number" min="1" max="5"
                                    :label="t('supplier_management.appraisals.minimum')" density="compact" variant="outlined"
                                    :error-messages="editForm.errors.minimum_threshold" />
                      </VCol>
                    </VRow>
                    <div class="d-flex justify-end ga-2 mt-2">
                      <VBtn size="small" variant="text" @click="editingUuid = null">{{ t('supplier_management.appraisals.cancel') }}</VBtn>
                      <VBtn size="small" color="primary" :loading="editForm.processing" @click="saveEdit">
                        {{ t('supplier_management.appraisals.save') }}
                      </VBtn>
                    </div>
                </div>
              </VListItem>
              <VListItem v-else>
                <VListItemTitle class="text-body-2">{{ q.question }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">
                    {{ q.weight }}%<template v-if="q.minimum"> · {{ t('supplier_management.appraisals.minimum_of', { n: q.minimum }) }}</template>
                    <template v-if="q.description"> · {{ q.description }}</template>
                  </VListItemSubtitle>
                  <template #append>
                    <VBtn size="x-small" variant="text" icon="tabler-arrow-up" :disabled="i === 0"
                          :title="t('supplier_management.appraisals.move_up')" @click="move(q, -1)" />
                    <VBtn size="x-small" variant="text" icon="tabler-arrow-down" :disabled="i === questions.length - 1"
                          :title="t('supplier_management.appraisals.move_down')" @click="move(q, 1)" />
                    <VBtn size="x-small" variant="text" color="primary" @click="startEdit(q)">
                      {{ t('supplier_management.appraisals.edit') }}
                    </VBtn>
                    <VBtn size="x-small" variant="text" color="error" @click="removeQuestion(q)">
                      {{ t('supplier_management.appraisals.remove') }}
                    </VBtn>
                </template>
              </VListItem>
              <VDivider v-if="i < questions.length - 1" />
            </template>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="confirmDelete" max-width="420">
      <VCard rounded="lg">
        <VCardTitle class="text-body-1 font-weight-semibold">{{ t('supplier_management.appraisals.delete_template') }}</VCardTitle>
        <VCardText class="text-body-2">{{ t('supplier_management.appraisals.delete_confirm', { name: template.name }) }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="confirmDelete = false">{{ t('supplier_management.appraisals.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" @click="destroy">{{ t('supplier_management.appraisals.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
