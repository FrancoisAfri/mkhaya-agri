<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Sequence = {
  id: string; document_type: string; factory: string | null; factory_id: string | null
  is_company_wide: boolean; prefix: string; suffix: string; padding: number; next_number: number
  reset_period: string; is_active: boolean; preview: string | null
}

defineProps<{
  sequences: Sequence[]
  document_types: string[]
  reset_periods: string[]
  factories: Array<{ id: string; code: string; name: string }>
}>()

const dialog = ref(false)
const editing = ref<Sequence | null>(null)
const form = useForm({
  document_type: 'PRODUCTION_ORDER', factory_uuid: null as string | null, prefix: '', suffix: '',
  padding: 5, next_number: 1, reset_period: 'YEAR', is_active: true,
})

function openCreate() {
  editing.value = null
  form.reset()
  form.clearErrors()
  dialog.value = true
}

function openEdit(s: Sequence) {
  editing.value = s
  form.document_type = s.document_type
  form.factory_uuid = s.factory_id
  form.prefix = s.prefix
  form.suffix = s.suffix
  form.padding = s.padding
  form.next_number = s.next_number
  form.reset_period = s.reset_period
  form.is_active = s.is_active
  form.clearErrors()
  dialog.value = true
}

function save() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (editing.value) {
    form.patch(route('manufacturing.setup.numbering.update', editing.value.id), done)
  } else {
    form.post(route('manufacturing.setup.numbering.store'), done)
  }
}

// What the next number would be called, so a change to the pattern is seen before it is used.
function sample() {
  const n = String(form.next_number).padStart(form.padding, '0')
  const year = form.reset_period === 'YEAR' ? `${new Date().getFullYear()}-`
    : form.reset_period === 'MONTH' ? `${new Date().toISOString().slice(0, 7)}-` : ''
  return `${form.prefix}${year}${n}${form.suffix}`
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.numbering.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.numbering.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('manufacturing.numbering.add') }}</VBtn>
    </div>

    <VCard border>
      <VTable v-if="sequences.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('manufacturing.numbering.document') }}</th>
            <th>{{ t('manufacturing.numbering.scope') }}</th>
            <th>{{ t('manufacturing.numbering.next') }}</th>
            <th>{{ t('manufacturing.numbering.reset') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in sequences" :key="s.id" :class="{ 'opacity-60': !s.is_active }">
            <td>{{ t(`manufacturing.document_type.${s.document_type}`) }}</td>
            <td>{{ s.is_company_wide ? t('manufacturing.numbering.company_wide') : s.factory }}</td>
            <td><code>{{ s.preview }}</code></td>
            <td>{{ t(`manufacturing.reset.${s.reset_period}`) }}</td>
            <td class="text-end">
              <VBtn icon="tabler-pencil" variant="text" size="small" :aria-label="t('manufacturing.common.edit')"
                    @click="openEdit(s)" />
            </td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-center text-medium-emphasis py-10">
        {{ t('manufacturing.numbering.none') }}
        <div class="text-caption mt-1">{{ t('manufacturing.numbering.none_hint') }}</div>
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="520">
      <VCard :title="editing ? t('manufacturing.numbering.edit') : t('manufacturing.numbering.add')">
        <VCardText>
          <VSelect v-model="form.document_type" :disabled="!!editing" class="mb-2"
                   :items="document_types.map(d => ({ title: t(`manufacturing.document_type.${d}`), value: d }))"
                   :error-messages="form.errors.document_type" :label="t('manufacturing.numbering.document')" />
          <VSelect v-model="form.factory_uuid" :disabled="!!editing" clearable :items="factories" item-value="id"
                   :item-title="(f: any) => `${f.code} — ${f.name}`" class="mb-2"
                   :hint="t('manufacturing.numbering.scope_hint')" persistent-hint
                   :label="t('manufacturing.numbering.factory')" />
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model="form.prefix" :label="t('manufacturing.numbering.prefix')" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="form.suffix" :label="t('manufacturing.numbering.suffix')" />
            </VCol>
            <VCol cols="4">
              <VTextField v-model.number="form.padding" type="number" min="1" max="12"
                          :label="t('manufacturing.numbering.padding')" />
            </VCol>
            <VCol cols="4">
              <!-- Setting this backwards would re-issue numbers already on documents; the server
                   refuses and says so, but the field warns first. -->
              <VTextField v-model.number="form.next_number" type="number" min="1"
                          :error-messages="form.errors.next_number" :label="t('manufacturing.numbering.next')" />
            </VCol>
            <VCol cols="4">
              <VSelect v-model="form.reset_period"
                       :items="reset_periods.map(r => ({ title: t(`manufacturing.reset.${r}`), value: r }))"
                       :label="t('manufacturing.numbering.reset')" />
            </VCol>
          </VRow>
          <VAlert type="info" variant="tonal" density="compact" class="mt-2">
            {{ t('manufacturing.numbering.sample') }}: <code>{{ sample() }}</code>
          </VAlert>
          <p v-if="form.errors.document_type" class="text-error text-body-2 mt-2">{{ form.errors.document_type }}</p>
          <VSwitch v-if="editing" v-model="form.is_active" color="primary" hide-details class="mt-2"
                   :label="t('manufacturing.product.active')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
