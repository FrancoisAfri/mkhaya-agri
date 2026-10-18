<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  banks: any[]
  questionTypes: Record<string, string>
}>()

const selectedBankId       = ref<number | null>(null)
const selectedBank         = ref<any>(null)
const bankQuestions        = ref<any[]>([])
const createBankDialog     = ref(false)
const addQuestionDialog    = ref(false)
const deleteBankDialog     = ref(false)
const editingBankId        = ref<number | null>(null)
const deletingBank         = ref<any>(null)
const savingBank           = ref(false)
const savingQuestion       = ref(false)
const deletingBankLoading  = ref(false)

const bankForm = ref({ title: '', category: '', description: '' })
const blankQ = () => ({
  type: 'mcq', question_text: '', context_text: '', explanation: '', points: 1,
  options: [{ option_text: '', is_correct: false }, { option_text: '', is_correct: false }],
})
const qForm = ref(blankQ())

const optionTypes = ['mcq', 'multi_select', 'true_false', 'yes_no', 'rating_scale']
const typeHasOptions = computed(() => optionTypes.includes(qForm.value.type))
const questionTypeOptions = computed(() => Object.entries(props.questionTypes).map(([value, label]) => ({ value, label })))

function selectBank(bank: any) { selectedBankId.value = bank.id; selectedBank.value = bank; router.reload({ only: [] }); bankQuestions.value = (bank as any).questions ?? [] }
function openEditBank(bank: any) { editingBankId.value = bank.id; bankForm.value = { title: bank.title, category: bank.category ?? '', description: bank.description ?? '' }; createBankDialog.value = true }
function closeBankDialog() { createBankDialog.value = false; editingBankId.value = null; bankForm.value = { title: '', category: '', description: '' } }
function saveBank() { savingBank.value = true; const url = editingBankId.value ? route('lms.admin.question-banks.update', editingBankId.value) : route('lms.admin.question-banks.store'); const method = editingBankId.value ? 'patch' : 'post'; router[method](url, bankForm.value, { onSuccess: closeBankDialog, onFinish: () => { savingBank.value = false } }) }
function confirmDeleteBank(bank: any) { deletingBank.value = bank; deleteBankDialog.value = true }
function doDeleteBank() { if (!deletingBank.value) return; deletingBankLoading.value = true; router.delete(route('lms.admin.question-banks.destroy', deletingBank.value.id), { onSuccess: () => { deleteBankDialog.value = false; if (selectedBankId.value === deletingBank.value.id) { selectedBank.value = null; selectedBankId.value = null } }, onFinish: () => { deletingBankLoading.value = false } }) }
function addOption() { qForm.value.options.push({ option_text: '', is_correct: false }) }
function removeOption(i: number) { qForm.value.options.splice(i, 1) }
function saveBankQuestion() { if (!selectedBankId.value) return; savingQuestion.value = true; const payload: any = { ...qForm.value }; if (!typeHasOptions.value) delete payload.options; router.post(route('lms.admin.question-banks.questions.store', selectedBankId.value), payload, { onSuccess: () => { addQuestionDialog.value = false; qForm.value = blankQ() }, onFinish: () => { savingQuestion.value = false } }) }
function deleteBankQuestion(q: any) { if (!selectedBankId.value) return; router.delete(route('lms.admin.question-banks.questions.destroy', { bank: selectedBankId.value, question: q.id })) }
</script>

<template>
  <VRow>
    <VCol cols="12" md="4">
      <VCard>
        <VCardTitle class="d-flex align-center pa-4">
          <span>{{ t('lms_question_banks.title') }}</span>
          <VSpacer />
          <VBtn size="small" color="primary" icon @click="createBankDialog = true"><VIcon>mdi-plus</VIcon></VBtn>
        </VCardTitle>
        <VList density="compact" nav>
          <VListItem v-for="bank in banks" :key="bank.id" :active="selectedBankId === bank.id" rounded="lg" class="mb-1" @click="selectBank(bank)">
            <VListItemTitle>{{ bank.title }}</VListItemTitle>
            <VListItemSubtitle>{{ bank.category ?? t('lms_question_banks.uncategorized') }} · {{ bank.questions_count === 1 ? t('lms_question_banks.questions_count', { count: 1 }) : t('lms_question_banks.questions_count', { count: bank.questions_count }) }}</VListItemSubtitle>
            <template #append>
              <VBtn icon size="x-small" variant="text" @click.stop="openEditBank(bank)"><VIcon size="16">mdi-pencil</VIcon></VBtn>
              <VBtn icon size="x-small" variant="text" color="error" @click.stop="confirmDeleteBank(bank)"><VIcon size="16">mdi-delete</VIcon></VBtn>
            </template>
          </VListItem>
        </VList>
      </VCard>
    </VCol>

    <VCol cols="12" md="8">
      <VCard v-if="selectedBank">
        <VCardTitle class="d-flex align-center pa-4">
          <span>{{ selectedBank.title }}</span>
          <VSpacer />
          <VBtn color="primary" prepend-icon="mdi-plus" size="small" @click="addQuestionDialog = true">{{ t('lms_question_banks.add_question') }}</VBtn>
        </VCardTitle>
        <VList v-if="selectedBank.questions_count > 0" density="compact">
          <VListItem v-for="(q, idx) in bankQuestions" :key="q.id" rounded="lg" class="ma-2">
            <template #prepend><VAvatar color="primary" variant="tonal" size="32">{{ idx + 1 }}</VAvatar></template>
            <VListItemTitle class="text-wrap">{{ q.question_text }}</VListItemTitle>
            <VListItemSubtitle><VChip size="x-small" class="mr-1">{{ questionTypes[q.type] ?? q.type }}</VChip>{{ q.points === 1 ? t('lms_question_banks.points', { count: 1 }) : t('lms_question_banks.points', { count: q.points }) }}</VListItemSubtitle>
            <template #append><VBtn icon size="x-small" variant="text" color="error" @click="deleteBankQuestion(q)"><VIcon size="16">mdi-delete</VIcon></VBtn></template>
          </VListItem>
        </VList>
        <VCardText v-else class="text-center text-medium-emphasis py-8">{{ t('lms_question_banks.no_questions') }}</VCardText>
      </VCard>
      <VCard v-else class="d-flex align-center justify-center" min-height="200">
        <VCardText class="text-center text-medium-emphasis">{{ t('lms_question_banks.select_bank') }}</VCardText>
      </VCard>
    </VCol>

    <VDialog v-model="createBankDialog" max-width="480" persistent>
      <VCard>
        <VCardTitle class="pa-4">{{ editingBankId ? t('lms_question_banks.bank_dialog.title_edit') : t('lms_question_banks.bank_dialog.title_new') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VTextField v-model="bankForm.title" :label="t('lms_question_banks.bank_dialog.title_label')" class="mb-3" />
          <VTextField v-model="bankForm.category" :label="t('lms_question_banks.bank_dialog.category_label')" :hint="t('lms_question_banks.bank_dialog.category_hint')" persistent-hint class="mb-3" />
          <VTextarea v-model="bankForm.description" :label="t('lms_question_banks.bank_dialog.description_label')" rows="2" auto-grow />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="closeBankDialog">{{ t('lms_question_banks.bank_dialog.cancel') }}</VBtn><VBtn color="primary" :loading="savingBank" @click="saveBank">{{ editingBankId ? t('lms_question_banks.bank_dialog.save') : t('lms_question_banks.bank_dialog.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="addQuestionDialog" max-width="680" persistent scrollable>
      <VCard>
        <VCardTitle class="pa-4">{{ t('lms_question_banks.question_dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6"><VSelect v-model="qForm.type" :items="questionTypeOptions" item-title="label" item-value="value" :label="t('lms_question_banks.question_dialog.type_label')" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model.number="qForm.points" :label="t('lms_question_banks.question_dialog.points_label')" type="number" min="0" /></VCol>
            <VCol cols="12"><VTextarea v-model="qForm.question_text" :label="t('lms_question_banks.question_dialog.question_text_label')" rows="3" auto-grow /></VCol>
            <VCol cols="12"><VTextarea v-model="qForm.context_text" :label="t('lms_question_banks.question_dialog.context_label')" rows="2" auto-grow /></VCol>
            <VCol cols="12"><VTextarea v-model="qForm.explanation" :label="t('lms_question_banks.question_dialog.explanation_label')" rows="2" auto-grow /></VCol>
            <template v-if="typeHasOptions">
              <VCol cols="12">
                <div class="d-flex align-center mb-2"><span class="text-subtitle-2">{{ t('lms_question_banks.question_dialog.options_label') }}</span><VSpacer /><VBtn size="x-small" variant="tonal" @click="addOption">{{ t('lms_question_banks.question_dialog.add_option') }}</VBtn></div>
                <div v-for="(opt, i) in qForm.options" :key="i" class="d-flex align-center gap-2 mb-2">
                  <VCheckbox v-model="opt.is_correct" density="compact" hide-details color="success" title="Mark as correct" />
                  <VTextField v-model="opt.option_text" :label="`Option ${i + 1}`" density="compact" hide-details class="flex-grow-1" />
                  <VBtn icon size="x-small" variant="text" color="error" @click="removeOption(i)"><VIcon>mdi-close</VIcon></VBtn>
                </div>
              </VCol>
            </template>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="addQuestionDialog = false">{{ t('lms_question_banks.question_dialog.cancel') }}</VBtn><VBtn color="primary" :loading="savingQuestion" @click="saveBankQuestion">{{ t('lms_question_banks.question_dialog.add_question') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteBankDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4">{{ t('lms_question_banks.delete_bank_dialog.title') }}</VCardTitle>
        <VCardText>{{ t('lms_question_banks.delete_bank_dialog.body', { title: deletingBank?.title }) }}</VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="deleteBankDialog = false">{{ t('lms_question_banks.delete_bank_dialog.cancel') }}</VBtn><VBtn color="error" :loading="deletingBankLoading" @click="doDeleteBank">{{ t('lms_question_banks.delete_bank_dialog.delete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </VRow>
</template>
