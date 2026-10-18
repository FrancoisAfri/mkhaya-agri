<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Rule = {
  uuid: string
  name: string
  flag_type: string
  is_active: boolean
  parameters: Record<string, unknown>
  category: { name: string } | null
}

type CreateRuleForm = {
  name: string
  flag_type: string
  parameters: Record<string, any>
  applies_to_category: number | null
}

const props = defineProps<{
  rules: Rule[]
}>()

const showCreate = ref(false)
const confirmDialog = ref()

const createForm = useForm<CreateRuleForm>({
  name: '',
  flag_type: '',
  parameters: {},
  applies_to_category: null,
})

const flagTypeOptions = computed(() => [
  { title: t('assets_misuse_flags_index.types.after_hours_usage'), value: 'after_hours_usage' },
  { title: t('assets_misuse_flags_index.types.unauthorized_location'), value: 'unauthorized_location' },
  { title: t('assets_misuse_flags_index.types.rapid_movement'), value: 'rapid_movement' },
  { title: t('assets_misuse_flags_index.types.repeated_incidents'), value: 'repeated_incidents' },
  { title: t('assets_misuse_flags_index.types.other'), value: 'other' },
])

function getFlagTypeLabel(value: string): string {
  return flagTypeOptions.value.find(opt => opt.value === value)?.title ?? value
}

function submitCreate() {
  createForm.post(route('assets.misuse-detection-rules.store'), {
    onSuccess: () => { showCreate.value = false; createForm.reset() },
  })
}

function toggleActive(rule: Rule) {
  router.patch(route('assets.misuse-detection-rules.update', rule.uuid), { is_active: !rule.is_active }, { preserveScroll: true })
}

function deleteRule(rule: Rule) {
  confirmDialog.value.open(
    { title: t('assets_misuse_detection_rules_index.confirm.delete_title'), message: t('assets_misuse_detection_rules_index.confirm.delete_message', { name: rule.name }), confirmText: t('assets_misuse_detection_rules_index.confirm.delete_text'), confirmColor: 'error', icon: 'tabler-trash' },
    () => router.delete(route('assets.misuse-detection-rules.destroy', rule.uuid), { preserveScroll: true }),
  )
}

function updateParameters(value: string) {
  try {
    createForm.parameters = JSON.parse(value)
  } catch {
    // ignore invalid JSON while typing
  }
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <VBreadcrumbs :items="[
          { title: t('assets_misuse_detection_rules_index.breadcrumbs.misuse_flags'), href: route('assets.misuse-flags.index') },
          { title: t('assets_misuse_detection_rules_index.page_title') },
        ]" class="pa-0 mb-1" />
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_misuse_detection_rules_index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_misuse_detection_rules_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="showCreate = true">{{ t('assets_misuse_detection_rules_index.add_rule') }}</VBtn>
    </div>

    <VCard elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('assets_misuse_detection_rules_index.table.name') }}</th>
            <th>{{ t('assets_misuse_detection_rules_index.table.type') }}</th>
            <th>{{ t('assets_misuse_detection_rules_index.table.category') }}</th>
            <th>{{ t('assets_misuse_detection_rules_index.table.parameters') }}</th>
            <th class="text-center">{{ t('assets_misuse_detection_rules_index.table.active') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rules" :key="r.uuid">
            <td class="font-weight-medium">{{ r.name }}</td>
            <td>{{ getFlagTypeLabel(r.flag_type) }}</td>
            <td>{{ r.category?.name ?? 'All' }}</td>
            <td class="text-caption text-medium-emphasis">{{ JSON.stringify(r.parameters) }}</td>
            <td class="text-center">
              <VSwitch :model-value="r.is_active" density="compact" hide-details @update:model-value="toggleActive(r)" />
            </td>
            <td>
              <VBtn size="small" icon="tabler-trash" variant="text" color="error" @click="deleteRule(r)" />
            </td>
          </tr>
          <tr v-if="!rules.length">
            <td colspan="6" class="text-center text-medium-emphasis py-8">{{ t('assets_misuse_detection_rules_index.empty') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="showCreate" max-width="540">
      <VCard>
        <VCardTitle class="pa-6 pb-0">{{ t('assets_misuse_detection_rules_index.dialog.title') }}</VCardTitle>
        <VCardText class="pa-6">
          <VForm @submit.prevent="submitCreate">
            <VRow>
              <VCol cols="12">
                <VTextField v-model="createForm.name" :label="t('assets_misuse_detection_rules_index.dialog.rule_name')" :error-messages="createForm.errors.name" />
              </VCol>
              <VCol cols="12">
                <VSelect v-model="createForm.flag_type" :items="flagTypeOptions" item-title="title" item-value="value" :label="t('assets_misuse_detection_rules_index.dialog.flag_type')" :error-messages="createForm.errors.flag_type" />
              </VCol>
              <VCol cols="12">
                <VTextarea
                  :model-value="JSON.stringify(createForm.parameters, null, 2)"
                  :label="t('assets_misuse_detection_rules_index.dialog.parameters')"
                  :error-messages="createForm.errors.parameters"
                  rows="4"
                  @update:model-value="updateParameters"
                />
              </VCol>
            </VRow>
            <div class="d-flex gap-3 mt-2">
              <VBtn type="submit" color="primary" :loading="createForm.processing">{{ t('assets_misuse_detection_rules_index.dialog.create') }}</VBtn>
              <VBtn variant="text" @click="showCreate = false">{{ t('assets_misuse_detection_rules_index.dialog.cancel') }}</VBtn>
            </div>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
