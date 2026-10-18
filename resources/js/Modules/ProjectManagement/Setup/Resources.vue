<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// A dialog capped at 820px is a usable form on a laptop and a letterbox on a phone. The people
// this module is for are frequently holding one, so every dialog here goes full-screen below the
// `sm` breakpoint rather than shrinking a multi-column layout into a column of clipped fields.
const { smAndDown } = useDisplay()

type Resource = {
  id: string; kind: string; name: string; identifier: string | null
  capacity: number | null; rate_per_day: number | null; currency: string | null
  available_from: string | null; available_to: string | null
  is_active: boolean; notes: string | null
  expired: boolean; expiring_soon: boolean
}

const props = defineProps<{
  resources: Resource[]
  kinds: string[]
  currency: string
}>()

const filter = ref<string>('all')

const shown = computed(() => filter.value === 'all'
  ? props.resources
  : props.resources.filter(r => r.kind === filter.value))

// A licence about to lapse is the one thing on this page somebody needs to act on, so it is called
// out above the table rather than left to be noticed in a row.
const expiring = computed(() => props.resources.filter(r => r.expiring_soon || r.expired))

const dialog = ref(false)
const editing = ref<Resource | null>(null)

const form = useForm<{
  kind: string; name: string; identifier: string; capacity: number | null
  rate_per_day: number | null; currency: string
  available_from: string; available_to: string; is_active: boolean; notes: string
}>({
  kind: 'room', name: '', identifier: '', capacity: null,
  rate_per_day: null, currency: props.currency,
  available_from: '', available_to: '', is_active: true, notes: '',
})

function openCreate() {
  editing.value = null
  form.reset()
  form.clearErrors()
  dialog.value = true
}

function openEdit(r: Resource) {
  editing.value = r
  form.clearErrors()
  form.kind = r.kind
  form.name = r.name
  form.identifier = r.identifier ?? ''
  form.capacity = r.capacity
  form.rate_per_day = r.rate_per_day
  form.currency = r.currency ?? props.currency
  form.available_from = r.available_from ?? ''
  form.available_to = r.available_to ?? ''
  form.is_active = r.is_active
  form.notes = r.notes ?? ''
  dialog.value = true
}

function save() {
  const options = { preserveScroll: true, onSuccess: () => { dialog.value = false } }

  if (editing.value)
    form.put(route('project-management.setup.resources.update', editing.value.id), options)
  else
    form.post(route('project-management.setup.resources.store'), options)
}

const confirmer = ref()

function remove(r: Resource) {
  confirmer.value.open({
    title: t('project_management.resources.confirm_remove_title'),
    message: t('project_management.resources.confirm_remove_named', { name: r.name }),
    confirmText: t('project_management.actions.delete'),
    icon: 'tabler-trash',
  }, () => router.delete(
    route('project-management.setup.resources.destroy', r.id),
    { preserveScroll: true },
  ))
}

function kindIcon(kind: string) {
  return kind === 'room' ? 'tabler-door'
    : kind === 'licence' ? 'tabler-license'
      : kind === 'plant' ? 'tabler-backhoe' : 'tabler-box'
}

function money(value: number | null, currency: string | null) {
  return value === null ? '—' : `${currency || props.currency} ${value.toFixed(2)}`
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.resources.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('project_management.resources.subtitle') }}
        </p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
        {{ t('project_management.resources.add') }}
      </VBtn>
    </div>

    <VAlert
      v-if="expiring.length" type="warning" variant="tonal" class="mb-4"
      :title="t('project_management.resources.expiring_title')"
    >
      <ul class="mb-0 ps-4">
        <li v-for="r in expiring" :key="r.id">
          {{ r.name }} —
          <template v-if="r.expired">{{ t('project_management.resources.expired_on', { date: r.available_to }) }}</template>
          <template v-else>{{ t('project_management.resources.expires_on', { date: r.available_to }) }}</template>
        </li>
      </ul>
    </VAlert>

    <VCard border>
      <VCardText class="pb-0">
        <VChipGroup v-model="filter" mandatory selected-class="text-primary">
          <VChip value="all" size="small" label>{{ t('project_management.resources.all') }}</VChip>
          <VChip v-for="k in kinds" :key="k" :value="k" size="small" label>
            {{ t(`project_management.resources.kind_${k}`) }}
          </VChip>
        </VChipGroup>
      </VCardText>
      <VDivider class="mt-3" />

      <VTable v-if="shown.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('project_management.resources.name') }}</th>
            <th>{{ t('project_management.resources.identifier') }}</th>
            <th class="text-end">{{ t('project_management.resources.capacity') }}</th>
            <th class="text-end">{{ t('project_management.resources.rate') }}</th>
            <th>{{ t('project_management.resources.available') }}</th>
            <th class="text-end">{{ t('project_management.actions.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in shown" :key="r.id">
            <td>
              <div class="d-flex align-center gap-2">
                <VIcon :icon="kindIcon(r.kind)" size="18" class="text-medium-emphasis" />
                <span>{{ r.name }}</span>
                <VChip v-if="!r.is_active" size="x-small" label>{{ t('project_management.resources.inactive') }}</VChip>
                <VChip v-if="r.expired" size="x-small" color="error" label>{{ t('project_management.resources.expired') }}</VChip>
              </div>
              <div v-if="r.notes" class="text-caption text-medium-emphasis">{{ r.notes }}</div>
            </td>
            <td>{{ r.identifier || '—' }}</td>
            <td class="text-end">{{ r.capacity ?? '—' }}</td>
            <td class="text-end">{{ money(r.rate_per_day, r.currency) }}</td>
            <td class="text-caption">
              {{ r.available_from || '—' }} → {{ r.available_to || t('project_management.resources.open_ended') }}
            </td>
            <td class="text-end">
              <VBtn
                icon="tabler-pencil" variant="text" size="small"
                :aria-label="t('project_management.resources.edit_named', { name: r.name })"
                @click="openEdit(r)"
              />
              <VBtn
                icon="tabler-trash" variant="text" size="small"
                :aria-label="t('project_management.resources.remove_named', { name: r.name })"
                @click="remove(r)"
              />
            </td>
          </tr>
        </tbody>
      </VTable>

      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.resources.none') }}
      </VCardText>
    </VCard>

    <VDialog :fullscreen="smAndDown" v-model="dialog" max-width="620">
      <VCard>
        <VCardItem>
          <VCardTitle>
            {{ editing ? t('project_management.resources.edit') : t('project_management.resources.add') }}
          </VCardTitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.kind"
                :items="kinds.map(k => ({ title: t(`project_management.resources.kind_${k}`), value: k }))"
                :label="t('project_management.resources.kind')"
                :error-messages="form.errors.kind"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.name" :label="t('project_management.resources.name')"
                :error-messages="form.errors.name" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.identifier" :label="t('project_management.resources.identifier')"
                :hint="t('project_management.resources.identifier_hint')" persistent-hint
                :error-messages="form.errors.identifier" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="form.capacity" type="number"
                :label="t('project_management.resources.capacity')"
                :hint="t('project_management.resources.capacity_hint')" persistent-hint
                :error-messages="form.errors.capacity" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="form.rate_per_day" type="number" step="0.01"
                :label="t('project_management.resources.rate')"
                :error-messages="form.errors.rate_per_day" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.currency" :label="t('project_management.resources.currency')"
                :error-messages="form.errors.currency" variant="outlined" density="compact" maxlength="3"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.available_from" type="date"
                :label="t('project_management.resources.available_from')"
                :error-messages="form.errors.available_from" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.available_to" type="date"
                :label="t('project_management.resources.available_to')"
                :hint="t('project_management.resources.available_to_hint')" persistent-hint
                :error-messages="form.errors.available_to" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.notes" :label="t('project_management.resources.notes')"
                :error-messages="form.errors.notes" variant="outlined" density="compact" rows="2"
              />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="form.is_active" :label="t('project_management.resources.is_active')" color="primary" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="save">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
