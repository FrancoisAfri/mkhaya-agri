<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  rules: any
  courses: any[]
  positions: any[]
  scopeTypes: Record<string, string>
  frequencies: Record<string, string>
  filters: Record<string, any>
}>()

const search      = ref(props.filters.search ?? '')
const ruleDialog  = ref(false)
const saving      = ref(false)
const editingRule = ref<any>(null)
const snackbar    = ref(false)
const snackbarText= ref('')
const assigning   = ref<Record<number, boolean>>({})

const defaultForm = () => ({
  course_id: null as number | null,
  name: '', description: '',
  scope_type: 'all', scope_id: null as number | null, scope_value: '',
  frequency: 'once', frequency_days: null as number | null,
  due_within_days: 30, grace_period_days: 7, escalate_after_days: 3,
  reminder_days_before: [30, 14, 7, 1] as number[],
  escalate_to_role: 'manager',
  restrict_actions: [] as string[],
  is_active: true,
})
const form = ref(defaultForm())

const reminderOptions = [60, 30, 21, 14, 7, 3, 1]

const scopeTypeItems  = computed(() => Object.entries(props.scopeTypes).map(([value, label]) => ({ value, label })))
const frequencyItems  = computed(() => Object.entries(props.frequencies).map(([value, label]) => ({ value, label })))

const headers = [
  { title: t('lms_compliance_rules.table.rule_name'), key: 'name' },
  { title: t('lms_compliance_rules.table.course'),    key: 'course.title' },
  { title: t('lms_compliance_rules.table.scope'),     key: 'scope_type',   sortable: false },
  { title: t('lms_compliance_rules.table.frequency'), key: 'frequency' },
  { title: t('lms_compliance_rules.table.due_in'),    key: 'due_within_days', sortable: true },
  { title: t('lms_compliance_rules.table.status'),    key: 'is_active' },
  { title: t('lms_compliance_rules.table.stats'),     key: 'stats',        sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' },
]

function scopeColor(s: string) {
  const map: Record<string, string> = { all: 'grey', position: 'primary', department: 'teal', location: 'cyan', employee: 'purple' }
  return map[s] ?? 'grey'
}

function toggleReminder(d: number) {
  const idx = form.value.reminder_days_before.indexOf(d)
  if (idx >= 0) form.value.reminder_days_before.splice(idx, 1)
  else form.value.reminder_days_before.push(d)
  form.value.reminder_days_before.sort((a, b) => b - a)
}

function applyFilters() {
  router.get(route('lms.admin.compliance.rules'), { search: search.value }, { preserveState: true, replace: true })
}

function goPage(page: number) {
  router.get(route('lms.admin.compliance.rules'), { ...props.filters, page }, { preserveState: true })
}

function openCreate() { editingRule.value = null; form.value = defaultForm(); ruleDialog.value = true }

function openEdit(rule: any) {
  editingRule.value = rule
  form.value = {
    course_id: rule.course_id, name: rule.name, description: rule.description ?? '',
    scope_type: rule.scope_type, scope_id: rule.scope_id, scope_value: rule.scope_value ?? '',
    frequency: rule.frequency, frequency_days: rule.frequency_days,
    due_within_days: rule.due_within_days, grace_period_days: rule.grace_period_days,
    escalate_after_days: rule.escalate_after_days,
    reminder_days_before: rule.reminder_days_before ?? [30, 14, 7, 1],
    escalate_to_role: rule.escalate_to_role,
    restrict_actions: rule.restrict_actions ?? [],
    is_active: rule.is_active,
  }
  ruleDialog.value = true
}

function saveRule() {
  saving.value = true
  const url    = editingRule.value ? route('lms.admin.compliance.rules.update', editingRule.value.id) : route('lms.admin.compliance.rules.store')
  const method = editingRule.value ? 'patch' : 'post'
  router[method](url, form.value, {
    onSuccess: () => { snackbarText.value = t('lms_compliance_rules.toast.saved'); snackbar.value = true; ruleDialog.value = false },
    onFinish:  () => { saving.value = false },
    preserveScroll: true,
  })
}

function deleteRule(rule: any) {
  router.delete(route('lms.admin.compliance.rules.destroy', rule.id), {
    onSuccess: () => { snackbarText.value = t('lms_compliance_rules.toast.deleted'); snackbar.value = true },
    preserveScroll: true,
  })
}

function assignRule(rule: any) {
  assigning.value[rule.id] = true
  router.post(route('lms.admin.compliance.rules.assign', rule.id), {}, {
    onSuccess: () => { snackbarText.value = t('lms_compliance_rules.toast.assigned'); snackbar.value = true },
    onFinish:  () => { assigning.value[rule.id] = false },
    preserveScroll: true,
  })
}
</script>

<template>
  <div class="p-3">
    <div class="d-flex align-center gap-3 mb-4 flex-wrap">
      <div>
        <div class="text-h6">{{ t('lms_compliance_rules.title') }}</div>
        <div class="text-body-2 text-medium-emphasis">{{ t('lms_compliance_rules.subtitle') }}</div>
      </div>
      <VSpacer />
      <VBtn color="primary" prepend-icon="mdi-plus" @click="openCreate">{{ t('lms_compliance_rules.new_rule') }}</VBtn>
    </div>

    <div class="mb-4">
      <VTextField
        v-model="search" :label="t('lms_compliance_rules.search')" prepend-inner-icon="mdi-magnify"
        density="compact" hide-details clearable style="max-width:300px"
        @update:model-value="applyFilters"
      />
    </div>

    <VCard>
      <VDataTable :headers="headers" :items="rules.data" hide-default-footer>

        <template #item.scope_type="{ item }">
          <VChip :color="scopeColor(item.scope_type)" size="small" label>
            {{ scopeTypes[item.scope_type] ?? item.scope_type }}
            <span v-if="item.scope_value" class="ml-1">— {{ item.scope_value }}</span>
          </VChip>
        </template>

        <template #item.frequency="{ item }">
          <span class="text-body-2">{{ frequencies[item.frequency] ?? item.frequency }}</span>
        </template>

        <template #item.is_active="{ item }">
          <VChip :color="item.is_active ? 'success' : 'grey'" size="x-small">
            {{ item.is_active ? t('lms_compliance_rules.table.active') : t('lms_compliance_rules.table.inactive') }}
          </VChip>
        </template>

        <template #item.stats="{ item }">
          <div class="d-flex gap-1 flex-wrap">
            <VChip size="x-small" color="primary" variant="tonal">{{ t('lms_compliance_rules.table.assigned', { count: item.assignments_count }) }}</VChip>
            <VChip v-if="item.overdue_count" size="x-small" color="error" variant="tonal">{{ t('lms_compliance_rules.table.overdue', { count: item.overdue_count }) }}</VChip>
          </div>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn icon size="x-small" variant="text" color="success" :loading="assigning[item.id]" @click="assignRule(item)">
              <VIcon size="16">mdi-account-multiple-plus</VIcon>
              <VTooltip activator="parent">{{ t('lms_compliance_rules.table.assign_tooltip') }}</VTooltip>
            </VBtn>
            <VBtn icon size="x-small" variant="text" @click="openEdit(item)"><VIcon size="16">mdi-pencil</VIcon></VBtn>
            <VBtn icon size="x-small" variant="text" color="error" @click="deleteRule(item)"><VIcon size="16">mdi-delete</VIcon></VBtn>
          </div>
        </template>
      </VDataTable>

      <div v-if="rules.last_page > 1" class="d-flex justify-center pa-4">
        <VPagination :model-value="rules.current_page" :length="rules.last_page" density="compact" @update:model-value="goPage" />
      </div>
    </VCard>

    <!-- Create / Edit dialog -->
    <VDialog v-model="ruleDialog" max-width="680" scrollable>
      <VCard>
        <VCardTitle class="pa-4">{{ editingRule ? t('lms_compliance_rules.dialog.title_edit') : t('lms_compliance_rules.dialog.title_new') }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="12"><VTextField v-model="form.name" :label="t('lms_compliance_rules.dialog.rule_name')" density="compact" /></VCol>
            <VCol cols="12"><VSelect v-model="form.course_id" :items="courses" item-title="title" item-value="id" :label="t('lms_compliance_rules.dialog.course')" density="compact" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.description" :label="t('lms_compliance_rules.dialog.description')" density="compact" rows="2" /></VCol>
            <VCol cols="6"><VSelect v-model="form.scope_type" :items="scopeTypeItems" item-title="label" item-value="value" :label="t('lms_compliance_rules.dialog.scope')" density="compact" /></VCol>
            <VCol cols="6">
              <VSelect v-if="form.scope_type === 'position'" v-model="form.scope_id" :items="positions" item-title="name" item-value="id" :label="t('lms_compliance_rules.dialog.position')" density="compact" @update:model-value="(v) => form.scope_value = positions.find(p => p.id === v)?.name ?? ''" />
              <VTextField v-else-if="['department','location'].includes(form.scope_type)" v-model.number="form.scope_id" :label="t('lms_compliance_rules.dialog.division_level')" type="number" density="compact" :hint="t('lms_compliance_rules.dialog.division_hint')" persistent-hint />
            </VCol>
            <VCol cols="6"><VSelect v-model="form.frequency" :items="frequencyItems" item-title="label" item-value="value" :label="t('lms_compliance_rules.dialog.frequency')" density="compact" /></VCol>
            <VCol cols="6" v-if="form.frequency === 'custom'"><VTextField v-model.number="form.frequency_days" :label="t('lms_compliance_rules.dialog.every_n_days')" type="number" density="compact" /></VCol>
            <VCol cols="4"><VTextField v-model.number="form.due_within_days" :label="t('lms_compliance_rules.dialog.due_within_days')" type="number" density="compact" /></VCol>
            <VCol cols="4"><VTextField v-model.number="form.grace_period_days" :label="t('lms_compliance_rules.dialog.grace_period_days')" type="number" density="compact" /></VCol>
            <VCol cols="4"><VTextField v-model.number="form.escalate_after_days" :label="t('lms_compliance_rules.dialog.escalate_after_days')" type="number" density="compact" /></VCol>
            <VCol cols="12">
              <div class="text-body-2 mb-1">{{ t('lms_compliance_rules.dialog.reminder_days') }}</div>
              <div class="d-flex gap-2 flex-wrap">
                <VChip v-for="d in reminderOptions" :key="d"
                  :color="form.reminder_days_before.includes(d) ? 'primary' : 'default'"
                  :variant="form.reminder_days_before.includes(d) ? 'flat' : 'tonal'"
                  size="small" style="cursor:pointer" @click="toggleReminder(d)">{{ d }}d</VChip>
              </div>
            </VCol>
            <VCol cols="6"><VSelect v-model="form.escalate_to_role" :items="['manager','hr','admin']" :label="t('lms_compliance_rules.dialog.escalate_to')" density="compact" /></VCol>
            <VCol cols="6"><VSwitch v-model="form.is_active" :label="t('lms_compliance_rules.dialog.active')" color="primary" density="compact" /></VCol>
            <VCol cols="12">
              <VCombobox v-model="form.restrict_actions" :label="t('lms_compliance_rules.dialog.restrict_actions')" density="compact" multiple chips closable-chips :hint="t('lms_compliance_rules.dialog.restrict_hint')" persistent-hint />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="ruleDialog = false">{{ t('lms_compliance_rules.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="saving" @click="saveRule">{{ editingRule ? t('lms_compliance_rules.dialog.update') : t('lms_compliance_rules.dialog.create') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snackbar" :timeout="3000" color="success" location="bottom right">{{ snackbarText }}</VSnackbar>
  </div>
</template>
