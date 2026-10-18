<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

const props = defineProps<{
  serviceTypes: any[]
  partCategories: any[]
  catalogParts: { data: any[] }
  approvalSteps: any[]
  settings: any
  approvers: { id: number; email: string }[]
}>()

const tab = ref('service_types')

// Each tab keeps its own form; `editing` holds the row being changed, or null for a new one.
const serviceForm  = useForm({ id: null as number | null, name: '', code: '', description: '', standard_hours: 0, standard_cost: 0, is_active: true })
const categoryForm = useForm({ id: null as number | null, name: '', description: '', is_active: true })
const partForm     = useForm({ id: null as number | null, category_id: null as number | null, name: '', part_number: '', unit: '', unit_price: 0, is_active: true })
const stepForm     = useForm({ id: null as number | null, name: '', sequence: 1, approver_type: 'user', approver_user_id: null as number | null, approver_role: '', min_amount: null as number | null, is_active: true })

const settingsForm = useForm({
  card_number_prefix: props.settings?.card_number_prefix ?? 'JC',
  next_card_number: props.settings?.next_card_number ?? 1,
  require_approval: !!props.settings?.require_approval,
  require_mechanic_feedback: !!props.settings?.require_mechanic_feedback,
  allow_parts_without_stock: !!props.settings?.allow_parts_without_stock,
  notify_on_assignment: !!props.settings?.notify_on_assignment,
  notify_on_completion: !!props.settings?.notify_on_completion,
  default_labour_rate: props.settings?.default_labour_rate ?? 0,
})

function saveService() {
  const opts = { preserveScroll: true, onSuccess: () => serviceForm.reset() }
  serviceForm.id
    ? serviceForm.patch(route('fleet.job-cards.setup.service-types.update', serviceForm.id), opts)
    : serviceForm.post(route('fleet.job-cards.setup.service-types.store'), opts)
}

function saveCategory() {
  const opts = { preserveScroll: true, onSuccess: () => categoryForm.reset() }
  categoryForm.id
    ? categoryForm.patch(route('fleet.job-cards.setup.part-categories.update', categoryForm.id), opts)
    : categoryForm.post(route('fleet.job-cards.setup.part-categories.store'), opts)
}

function savePart() {
  const opts = { preserveScroll: true, onSuccess: () => partForm.reset() }
  partForm.id
    ? partForm.patch(route('fleet.job-cards.setup.parts.update', partForm.id), opts)
    : partForm.post(route('fleet.job-cards.setup.parts.store'), opts)
}

function saveStep() {
  const opts = { preserveScroll: true, onSuccess: () => stepForm.reset() }
  stepForm.id
    ? stepForm.patch(route('fleet.job-cards.setup.approval-steps.update', stepForm.id), opts)
    : stepForm.post(route('fleet.job-cards.setup.approval-steps.store'), opts)
}

const edit = (form: any, row: any) => Object.keys(form.data()).forEach(k => { form[k] = row[k] ?? form[k] })
const destroy = (name: string, id: number) => router.delete(route(`fleet.job-cards.setup.${name}.destroy`, id), { preserveScroll: true })
const toggle  = (name: string, id: number) => router.post(route(`fleet.job-cards.setup.${name}.toggle`, id), {}, { preserveScroll: true })
</script>

<template>
  <VContainer fluid class="pa-6">
    <h1 class="text-h5 font-weight-bold mb-1">{{ t('fleet_job_cards.setup_title') }}</h1>
    <p class="text-body-2 text-medium-emphasis mb-6">{{ t('fleet_job_cards.setup_subtitle') }}</p>

    <VCard>
      <VTabs v-model="tab" color="primary">
        <VTab value="service_types">{{ t('fleet_job_cards.service_types') }}</VTab>
        <VTab value="categories">{{ t('fleet_job_cards.part_categories') }}</VTab>
        <VTab value="parts">{{ t('fleet_job_cards.parts_catalogue') }}</VTab>
        <VTab value="steps">{{ t('fleet_job_cards.approval_flow') }}</VTab>
        <VTab value="settings">{{ t('fleet_job_cards.settings') }}</VTab>
      </VTabs>
      <VDivider />

      <VTabsWindow v-model="tab">
        <!-- Service types -->
        <VTabsWindowItem value="service_types">
          <VCardText>
            <VForm class="mb-4" @submit.prevent="saveService">
              <VRow dense align="center">
                <VCol cols="12" md="3"><VTextField v-model="serviceForm.name" :label="t('fleet_job_cards.name')" density="compact" :error-messages="serviceForm.errors.name" /></VCol>
                <VCol cols="6" md="2"><VTextField v-model="serviceForm.code" :label="t('fleet_job_cards.code')" density="compact" /></VCol>
                <VCol cols="6" md="2"><VTextField v-model.number="serviceForm.standard_hours" type="number" step="0.25" :label="t('fleet_job_cards.std_hours')" density="compact" /></VCol>
                <VCol cols="6" md="2"><VTextField v-model.number="serviceForm.standard_cost" type="number" step="0.01" :label="t('fleet_job_cards.std_cost')" density="compact" /></VCol>
                <VCol cols="6" md="3"><VBtn type="submit" color="primary" :loading="serviceForm.processing" block>{{ serviceForm.id ? t('fleet_job_cards.update') : t('fleet_job_cards.add') }}</VBtn></VCol>
              </VRow>
            </VForm>
            <VTable density="compact">
              <thead><tr><th>{{ t('fleet_job_cards.name') }}</th><th>{{ t('fleet_job_cards.code') }}</th><th class="text-end">{{ t('fleet_job_cards.std_hours') }}</th><th class="text-end">{{ t('fleet_job_cards.std_cost') }}</th><th>{{ t('fleet_job_cards.active') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="s in props.serviceTypes" :key="s.id">
                  <td>{{ s.name }}</td><td>{{ s.code ?? '—' }}</td>
                  <td class="text-end">{{ s.standard_hours ?? '—' }}</td><td class="text-end">{{ s.standard_cost ?? '—' }}</td>
                  <td><VSwitch :model-value="s.is_active" density="compact" hide-details @update:model-value="toggle('service-types', s.id)" /></td>
                  <td class="text-end">
                    <VBtn size="x-small" variant="text" icon="tabler-pencil" @click="edit(serviceForm, s)" />
                    <VBtn size="x-small" variant="text" color="error" icon="tabler-trash" @click="destroy('service-types', s.id)" />
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VTabsWindowItem>

        <!-- Part categories -->
        <VTabsWindowItem value="categories">
          <VCardText>
            <VForm class="mb-4" @submit.prevent="saveCategory">
              <VRow dense align="center">
                <VCol cols="12" md="4"><VTextField v-model="categoryForm.name" :label="t('fleet_job_cards.name')" density="compact" :error-messages="categoryForm.errors.name" /></VCol>
                <VCol cols="12" md="5"><VTextField v-model="categoryForm.description" :label="t('fleet_job_cards.description')" density="compact" /></VCol>
                <VCol cols="12" md="3"><VBtn type="submit" color="primary" :loading="categoryForm.processing" block>{{ categoryForm.id ? t('fleet_job_cards.update') : t('fleet_job_cards.add') }}</VBtn></VCol>
              </VRow>
            </VForm>
            <VTable density="compact">
              <thead><tr><th>{{ t('fleet_job_cards.name') }}</th><th>{{ t('fleet_job_cards.description') }}</th><th class="text-end">{{ t('fleet_job_cards.parts') }}</th><th>{{ t('fleet_job_cards.active') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="c in props.partCategories" :key="c.id">
                  <td>{{ c.name }}</td><td>{{ c.description ?? '—' }}</td><td class="text-end">{{ c.parts_count ?? 0 }}</td>
                  <td><VSwitch :model-value="c.is_active" density="compact" hide-details @update:model-value="toggle('part-categories', c.id)" /></td>
                  <td class="text-end">
                    <VBtn size="x-small" variant="text" icon="tabler-pencil" @click="edit(categoryForm, c)" />
                    <VBtn size="x-small" variant="text" color="error" icon="tabler-trash" @click="destroy('part-categories', c.id)" />
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VTabsWindowItem>

        <!-- Catalogue parts -->
        <VTabsWindowItem value="parts">
          <VCardText>
            <VForm class="mb-4" @submit.prevent="savePart">
              <VRow dense align="center">
                <VCol cols="12" md="3"><VSelect v-model="partForm.category_id" :items="props.partCategories" item-title="name" item-value="id" :label="t('fleet_job_cards.category')" density="compact" clearable /></VCol>
                <VCol cols="12" md="3"><VTextField v-model="partForm.name" :label="t('fleet_job_cards.name')" density="compact" :error-messages="partForm.errors.name" /></VCol>
                <VCol cols="6" md="2"><VTextField v-model="partForm.part_number" :label="t('fleet_job_cards.part_number')" density="compact" /></VCol>
                <VCol cols="6" md="2"><VTextField v-model.number="partForm.unit_price" type="number" step="0.01" :label="t('fleet_job_cards.unit_price')" density="compact" /></VCol>
                <VCol cols="12" md="2"><VBtn type="submit" color="primary" :loading="partForm.processing" block>{{ partForm.id ? t('fleet_job_cards.update') : t('fleet_job_cards.add') }}</VBtn></VCol>
              </VRow>
            </VForm>
            <VTable density="compact">
              <thead><tr><th>{{ t('fleet_job_cards.name') }}</th><th>{{ t('fleet_job_cards.category') }}</th><th>{{ t('fleet_job_cards.part_number') }}</th><th class="text-end">{{ t('fleet_job_cards.unit_price') }}</th><th>{{ t('fleet_job_cards.active') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="p in props.catalogParts.data" :key="p.id">
                  <td>{{ p.name }}</td><td>{{ p.category?.name ?? '—' }}</td><td>{{ p.part_number ?? '—' }}</td>
                  <td class="text-end">{{ p.unit_price }}</td>
                  <td><VSwitch :model-value="p.is_active" density="compact" hide-details @update:model-value="toggle('parts', p.id)" /></td>
                  <td class="text-end">
                    <VBtn size="x-small" variant="text" icon="tabler-pencil" @click="edit(partForm, p)" />
                    <VBtn size="x-small" variant="text" color="error" icon="tabler-trash" @click="destroy('parts', p.id)" />
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VTabsWindowItem>

        <!-- Approval flow -->
        <VTabsWindowItem value="steps">
          <VCardText>
            <VAlert type="info" variant="tonal" density="compact" class="mb-4">
              {{ t('fleet_job_cards.min_amount_hint') }}
            </VAlert>
            <VForm class="mb-4" @submit.prevent="saveStep">
              <VRow dense align="center">
                <VCol cols="12" md="3"><VTextField v-model="stepForm.name" :label="t('fleet_job_cards.step_name')" density="compact" :error-messages="stepForm.errors.name" /></VCol>
                <VCol cols="6" md="1"><VTextField v-model.number="stepForm.sequence" type="number" min="1" :label="t('fleet_job_cards.order')" density="compact" /></VCol>
                <VCol cols="6" md="2">
                  <VSelect
                    v-model="stepForm.approver_type"
                    :items="[
                      { title: t('fleet_job_cards.approver_user'), value: 'user' },
                      { title: t('fleet_job_cards.approver_role'), value: 'role' },
                      { title: t('fleet_job_cards.approver_line_manager'), value: 'line_manager' },
                    ]"
                    :label="t('fleet_job_cards.approver_type')"
                    density="compact"
                  />
                </VCol>
                <VCol cols="12" md="3">
                  <VSelect v-if="stepForm.approver_type === 'user'" v-model="stepForm.approver_user_id" :items="props.approvers" item-title="email" item-value="id" :label="t('fleet_job_cards.approver')" density="compact" />
                  <VTextField v-else-if="stepForm.approver_type === 'role'" v-model="stepForm.approver_role" :label="t('fleet_job_cards.role_name')" density="compact" />
                </VCol>
                <VCol cols="6" md="2"><VTextField v-model.number="stepForm.min_amount" type="number" step="0.01" :label="t('fleet_job_cards.min_amount')" density="compact" clearable /></VCol>
                <VCol cols="6" md="1"><VBtn type="submit" color="primary" :loading="stepForm.processing" block>{{ stepForm.id ? t('fleet_job_cards.update') : t('fleet_job_cards.add') }}</VBtn></VCol>
              </VRow>
            </VForm>
            <VTable density="compact">
              <thead><tr><th class="text-end">{{ t('fleet_job_cards.order') }}</th><th>{{ t('fleet_job_cards.step_name') }}</th><th>{{ t('fleet_job_cards.approver') }}</th><th class="text-end">{{ t('fleet_job_cards.min_amount') }}</th><th>{{ t('fleet_job_cards.active') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="s in props.approvalSteps" :key="s.id">
                  <td class="text-end">{{ s.sequence }}</td><td>{{ s.name }}</td>
                  <td>{{ s.approver?.email ?? s.approver_role ?? t('fleet_job_cards.approver_line_manager') }}</td>
                  <td class="text-end">{{ s.min_amount ?? '—' }}</td>
                  <td><VSwitch :model-value="s.is_active" density="compact" hide-details @update:model-value="toggle('approval-steps', s.id)" /></td>
                  <td class="text-end">
                    <VBtn size="x-small" variant="text" icon="tabler-pencil" @click="edit(stepForm, s)" />
                    <VBtn size="x-small" variant="text" color="error" icon="tabler-trash" @click="destroy('approval-steps', s.id)" />
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VTabsWindowItem>

        <!-- Settings -->
        <VTabsWindowItem value="settings">
          <VCardText>
            <VForm @submit.prevent="settingsForm.post(route('fleet.job-cards.setup.settings'), { preserveScroll: true })">
              <VRow>
                <VCol cols="6" md="3"><VTextField v-model="settingsForm.card_number_prefix" :label="t('fleet_job_cards.number_prefix')" density="compact" /></VCol>
                <VCol cols="6" md="3"><VTextField v-model.number="settingsForm.next_card_number" type="number" min="1" :label="t('fleet_job_cards.next_number')" density="compact" /></VCol>
                <VCol cols="6" md="3"><VTextField v-model.number="settingsForm.default_labour_rate" type="number" step="0.01" :label="t('fleet_job_cards.default_labour_rate')" density="compact" /></VCol>
              </VRow>
              <VSwitch v-model="settingsForm.require_approval" :label="t('fleet_job_cards.require_approval')" density="compact" hide-details />
              <VSwitch v-model="settingsForm.require_mechanic_feedback" :label="t('fleet_job_cards.require_feedback')" density="compact" hide-details />
              <VSwitch v-model="settingsForm.allow_parts_without_stock" :label="t('fleet_job_cards.allow_parts_no_stock')" density="compact" hide-details />
              <VSwitch v-model="settingsForm.notify_on_assignment" :label="t('fleet_job_cards.notify_assignment')" density="compact" hide-details />
              <VSwitch v-model="settingsForm.notify_on_completion" :label="t('fleet_job_cards.notify_completion')" density="compact" hide-details />
              <VBtn type="submit" color="primary" class="mt-4" :loading="settingsForm.processing">{{ t('fleet_job_cards.save_settings') }}</VBtn>
            </VForm>
          </VCardText>
        </VTabsWindowItem>
      </VTabsWindow>
    </VCard>
  </VContainer>
</template>
