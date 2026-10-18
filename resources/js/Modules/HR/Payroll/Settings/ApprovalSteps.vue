<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()

interface Step { id: number; step_number: number; name: string; approver_type: string; role_name: string | null; employee_id: number | null; employee_name: string | null; is_active: boolean }
const props = defineProps<{ steps: Step[]; roles: string[]; employees: { id: number; name: string }[] }>()

const dialog = ref(false)
const editing = ref<Step | null>(null)
const form = useForm({ step_number: 1, name: '', approver_type: 'role', role_name: '' as string | null, employee_id: null as number | null, is_active: true })

function openNew() {
  editing.value = null
  form.reset(); form.clearErrors()
  form.step_number = (props.steps.reduce((m, s) => Math.max(m, s.step_number), 0) || 0) + 1
  dialog.value = true
}
function openEdit(s: Step) {
  editing.value = s
  form.clearErrors()
  form.step_number = s.step_number; form.name = s.name; form.approver_type = s.approver_type
  form.role_name = s.role_name; form.employee_id = s.employee_id; form.is_active = s.is_active
  dialog.value = true
}
function submit() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (editing.value) form.patch(ziggyRoute('payroll.settings.approval-steps.update', editing.value.id), opts)
  else form.post(ziggyRoute('payroll.settings.approval-steps.store'), opts)
}
function remove(s: Step) {
  router.delete(ziggyRoute('payroll.settings.approval-steps.destroy', s.id), { preserveScroll: true })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6"><VRow justify="center"><VCol cols="12" lg="9">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('payroll_approval_steps.eyebrow') }}</div>
        <h4 class="text-h4">{{ t('payroll_approval_steps.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0" style="max-width: 640px">{{ t('payroll_approval_steps.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('payroll_approval_steps.add') }}</VBtn>
    </div>

    <VAlert v-if="!steps.length" type="info" variant="tonal" class="mb-4">{{ t('payroll_approval_steps.empty') }}</VAlert>

    <VCard v-else elevation="0" border>
      <VList lines="two">
        <template v-for="(s, i) in steps" :key="s.id">
          <VListItem>
            <template #prepend>
              <VAvatar color="primary" variant="tonal" size="36"><span class="font-weight-bold">{{ s.step_number }}</span></VAvatar>
            </template>
            <VListItemTitle class="font-weight-medium">
              {{ s.name }}
              <VChip v-if="!s.is_active" size="x-small" color="grey" class="ms-2">{{ t('payroll_approval_steps.inactive') }}</VChip>
            </VListItemTitle>
            <VListItemSubtitle>
              <VIcon size="14" class="me-1">{{ s.approver_type === 'employee' ? 'tabler-user' : 'tabler-users-group' }}</VIcon>
              <span v-if="s.approver_type === 'employee'">{{ s.employee_name || ('#' + s.employee_id) }}</span>
              <span v-else>{{ t('payroll_approval_steps.any_with_role', { role: s.role_name }) }}</span>
            </VListItemSubtitle>
            <template #append>
              <VBtn
                icon="tabler-pencil" size="small" variant="text" class="row-action"
                :aria-label="`Edit approval step ${s.sequence ?? ''}`" @click="openEdit(s)"
              />
              <VBtn
                icon="tabler-trash" size="small" variant="text" color="error" class="row-action"
                :aria-label="`Remove approval step ${s.sequence ?? ''}`" @click="remove(s)"
              />
            </template>
          </VListItem>
          <VDivider v-if="i < steps.length - 1" />
        </template>
      </VList>
    </VCard>
  </VCol></VRow></VContainer>

  <VDialog v-model="dialog" max-width="520">
    <VCard>
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
        {{ editing ? t('payroll_approval_steps.edit') : t('payroll_approval_steps.add') }}
      </VCardTitle>
      <VDivider />
      <VCardText class="pa-4">
        <VRow>
          <VCol cols="4"><VTextField v-model.number="form.step_number" type="number" :label="t('payroll_approval_steps.form.step')" variant="outlined" density="comfortable" :error-messages="form.errors.step_number" /></VCol>
          <VCol cols="8"><VTextField v-model="form.name" :label="t('payroll_approval_steps.form.name')" :placeholder="t('payroll_approval_steps.form.name_placeholder')" variant="outlined" density="comfortable" :error-messages="form.errors.name" /></VCol>
          <VCol cols="12">
            <VSelect v-model="form.approver_type" :items="[{title: t('payroll_approval_steps.form.type_role'), value: 'role'}, {title: t('payroll_approval_steps.form.type_employee'), value: 'employee'}]" item-title="title" item-value="value" :label="t('payroll_approval_steps.form.approver_type')" variant="outlined" density="comfortable" />
          </VCol>
          <VCol v-if="form.approver_type === 'role'" cols="12">
            <VSelect v-model="form.role_name" :items="roles" :label="t('payroll_approval_steps.form.role')" variant="outlined" density="comfortable" :error-messages="form.errors.role_name" />
          </VCol>
          <VCol v-else cols="12">
            <VSelect v-model="form.employee_id" :items="employees" item-title="name" item-value="id" :label="t('payroll_approval_steps.form.employee')" variant="outlined" density="comfortable" :error-messages="form.errors.employee_id" />
          </VCol>
          <VCol cols="12"><VSwitch v-model="form.is_active" :label="t('payroll_approval_steps.form.active')" color="primary" density="compact" hide-details /></VCol>
        </VRow>
      </VCardText>
      <VDivider />
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="outlined" @click="dialog = false">{{ t('payroll_approval_steps.form.cancel') }}</VBtn>
        <VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="submit">{{ t('payroll_approval_steps.form.save') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
