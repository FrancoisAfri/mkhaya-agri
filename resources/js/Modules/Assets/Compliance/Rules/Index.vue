<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Rule = {
  uuid: string
  name: string
  description: string | null
  rule_type: { value: string; label: string }
  applies_to: string
  interval_days: number
  grace_period_days: number
  is_active: boolean
  category: { name: string } | null
}

const props = defineProps<{
  rules: Rule[]
  categories: { id: number; name: string }[]
}>()

const showCreate = ref(false)
const confirmDialog = ref()

const createForm = useForm({
  name:               '',
  description:        '',
  rule_type:          '',
  applies_to:         'all',
  asset_category_id:  null as number | null,
  interval_days:      30,
  grace_period_days:  7,
})

const ruleTypeOptions = [
  { title: 'Maintenance Interval', value: 'maintenance_interval' },
  { title: 'Software Check', value: 'software_check' },
  { title: 'Inspection', value: 'inspection' },
  { title: 'Certification', value: 'certification' },
  { title: 'Other', value: 'other' },
]

function submitCreate() {
  createForm.post(route('assets.compliance-rules.store'), {
    onSuccess: () => { showCreate.value = false; createForm.reset() },
  })
}

function toggleActive(rule: Rule) {
  router.patch(route('assets.compliance-rules.update', rule.uuid), { is_active: !rule.is_active }, { preserveScroll: true })
}

function deleteRule(rule: Rule) {
  confirmDialog.value.open(
    { title: 'Delete rule?', message: `Delete rule "${rule.name}"?`, confirmText: 'Delete', confirmColor: 'error', icon: 'tabler-trash' },
    () => router.delete(route('assets.compliance-rules.destroy', rule.uuid), { preserveScroll: true }),
  )
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <VBreadcrumbs :items="[
          { title: 'Compliance', href: route('assets.compliance-dashboard.index') },
          { title: 'Rules' },
        ]" class="pa-0 mb-1" />
        <h4 class="text-h4 font-weight-bold mb-1">Compliance Rules</h4>
        <p class="text-medium-emphasis mb-0">Define compliance requirements for assets</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="showCreate = true">Add Rule</VBtn>
    </div>

    <VCard elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>Name</th>
            <th>Type</th>
            <th>Scope</th>
            <th class="text-right">Interval</th>
            <th class="text-right">Grace</th>
            <th class="text-center">Active</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rules" :key="r.uuid">
            <td>
              <p class="mb-0 font-weight-medium">{{ r.name }}</p>
              <p v-if="r.description" class="text-caption text-medium-emphasis mb-0">{{ r.description }}</p>
            </td>
            <td>{{ r.rule_type.label }}</td>
            <td>{{ r.applies_to === 'all' ? 'All Assets' : r.category?.name ?? 'Category' }}</td>
            <td class="text-right">{{ r.interval_days }}d</td>
            <td class="text-right">{{ r.grace_period_days }}d</td>
            <td class="text-center">
              <VSwitch :model-value="r.is_active" density="compact" hide-details @update:model-value="toggleActive(r)" />
            </td>
            <td>
              <VBtn size="small" icon="tabler-trash" variant="text" color="error" @click="deleteRule(r)" />
            </td>
          </tr>
          <tr v-if="!rules.length">
            <td colspan="7" class="text-center text-medium-emphasis py-8">No compliance rules defined</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Create Dialog -->
    <VDialog v-model="showCreate" max-width="560">
      <VCard>
        <VCardTitle class="pa-6 pb-0">New Compliance Rule</VCardTitle>
        <VCardText class="pa-6">
          <VForm @submit.prevent="submitCreate">
            <VRow>
              <VCol cols="12">
                <VTextField v-model="createForm.name" label="Rule Name *" :error-messages="createForm.errors.name" />
              </VCol>
              <VCol cols="12">
                <VSelect v-model="createForm.rule_type" :items="ruleTypeOptions" item-title="title" item-value="value" label="Rule Type *" :error-messages="createForm.errors.rule_type" />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField v-model.number="createForm.interval_days" label="Interval (days) *" type="number" min="1" :error-messages="createForm.errors.interval_days" />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField v-model.number="createForm.grace_period_days" label="Grace Period (days)" type="number" min="0" />
              </VCol>
              <VCol cols="12">
                <VSelect v-model="createForm.applies_to" :items="[{title:'All Assets',value:'all'},{title:'By Category',value:'category'}]" item-title="title" item-value="value" label="Applies To" />
              </VCol>
              <VCol v-if="createForm.applies_to === 'category'" cols="12">
                <VAutocomplete v-model="createForm.asset_category_id" :items="categories" item-title="name" item-value="id" label="Category *" :error-messages="createForm.errors.asset_category_id" />
              </VCol>
              <VCol cols="12">
                <VTextarea v-model="createForm.description" label="Description" rows="2" />
              </VCol>
            </VRow>
            <div class="d-flex gap-3 mt-2">
              <VBtn type="submit" color="primary" :loading="createForm.processing">Create</VBtn>
              <VBtn variant="text" @click="showCreate = false">Cancel</VBtn>
            </div>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
