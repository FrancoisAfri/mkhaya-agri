<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Clause = {
  id: number
  name: string
  category: string
  standard_text: string
  fallback_text: string | null
  is_required: boolean
  risk_guidance: string | null
  status: boolean
}

const props = defineProps<{
  clauses: { data: Clause[]; links: any[] }
  categories: string[]
  filters: { search?: string; category?: string }
}>()

const search = ref(props.filters.search ?? '')
const categoryFilter = ref(props.filters.category ?? '')
const confirmDialog = ref()

let timeoutId: ReturnType<typeof setTimeout>

function applyFilters() {
  clearTimeout(timeoutId)
  timeoutId = setTimeout(() => {
    router.get(route('contracts.clauses.index'),
      { search: search.value || undefined, category: categoryFilter.value || undefined },
      { preserveState: true, replace: true })
  }, 300)
}

const categoryColor: Record<string, string> = {
  termination: 'error',
  liability: 'warning',
  payment: 'success',
  confidentiality: 'info',
  indemnity: 'warning',
  ip: 'primary',
  data_protection: 'info',
  other: 'secondary',
}

function label(c: string) {
  return c.replace(/_/g, ' ')
}

// ── Add / edit dialog ────────────────────────────────────────────────────────
const dialog = ref(false)
const editing = ref<Clause | null>(null)

const form = useForm({
  name: '',
  category: 'other',
  standard_text: '',
  fallback_text: '',
  is_required: false,
  risk_guidance: '',
  status: true,
})

function openCreate() {
  editing.value = null
  form.reset()
  form.clearErrors()
  dialog.value = true
}

function openEdit(clause: Clause) {
  editing.value = clause
  form.clearErrors()
  form.name = clause.name
  form.category = clause.category
  form.standard_text = clause.standard_text
  form.fallback_text = clause.fallback_text ?? ''
  form.is_required = clause.is_required
  form.risk_guidance = clause.risk_guidance ?? ''
  form.status = clause.status
  dialog.value = true
}

function submit() {
  if (editing.value) {
    form.patch(route('contracts.clauses.update', editing.value.id), {
      preserveScroll: true,
      onSuccess: () => { dialog.value = false },
    })
  } else {
    form.post(route('contracts.clauses.store'), {
      preserveScroll: true,
      onSuccess: () => { dialog.value = false },
    })
  }
}

function destroy(clause: Clause) {
  confirmDialog.value.open(
    {
      title: t('clause_library.confirm.title'),
      message: t('clause_library.confirm.message', { name: clause.name }),
      confirmText: t('clause_library.confirm.confirm_text'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    () => { router.delete(route('contracts.clauses.destroy', clause.id), { preserveScroll: true }) },
  )
}

const categoryItems = [
  { title: '', value: '' },
  ...props.categories.map(cat => ({ title: label(cat), value: cat })),
]
</script>

<template>
  <div class="pa-4">
    <ConfirmDialog ref="confirmDialog" />

    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-book" size="26" />
        </VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('clause_library.page_title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('clause_library.description') }}</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
        {{ t('clause_library.new_clause') }}
      </VBtn>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VTextField
          v-model="search"
          density="compact"
          variant="outlined"
          hide-details
          :placeholder="t('clause_library.filters.search_placeholder')"
          prepend-inner-icon="tabler-search"
          style="max-width:260px"
          @keyup.enter="applyFilters"
          clearable
          @click:clear="applyFilters"
        />
        <VSelect
          v-model="categoryFilter"
          :items="categoryItems"
          density="compact"
          variant="outlined"
          hide-details
          :label="t('clause_library.filters.category_label')"
          style="max-width:200px"
          @update:model-value="applyFilters"
        />
        <VBtn variant="tonal" @click="applyFilters">{{ t('clause_library.filters.filter') }}</VBtn>
      </VCardText>
    </VCard>

    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('clause_library.table.name') }}</th>
            <th>{{ t('clause_library.table.category') }}</th>
            <th>{{ t('clause_library.table.required') }}</th>
            <th>{{ t('clause_library.table.status') }}</th>
            <th class="text-right">{{ t('clause_library.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!clauses.data.length">
            <td colspan="5" class="text-center text-medium-emphasis py-8">{{ t('clause_library.no_clauses') }}</td>
          </tr>
          <tr v-for="clause in clauses.data" :key="clause.id">
            <td class="font-weight-medium">{{ clause.name }}</td>
            <td>
              <VChip size="small" :color="categoryColor[clause.category] ?? 'secondary'" variant="tonal" class="text-capitalize">
                {{ label(clause.category) }}
              </VChip>
            </td>
            <td>
              <VChip size="small" :color="clause.is_required ? 'error' : 'default'" variant="tonal">
                {{ clause.is_required ? t('clause_library.required') : t('clause_library.optional') }}
              </VChip>
            </td>
            <td>
              <VChip size="small" :color="clause.status ? 'success' : 'secondary'" variant="tonal">
                {{ clause.status ? t('clause_library.active') : t('clause_library.inactive') }}
              </VChip>
            </td>
            <td class="text-right">
              <VBtn icon variant="text" size="small" @click="openEdit(clause)">
                <VIcon icon="tabler-edit" size="18" />
              </VBtn>
              <VBtn icon variant="text" size="small" color="error" @click="destroy(clause)">
                <VIcon icon="tabler-trash" size="18" />
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Add / edit dialog -->
    <VDialog v-model="dialog" max-width="640" persistent scrollable>
      <VCard :loading="form.processing" :disabled="form.processing">
        <VCardTitle class="d-flex align-center justify-space-between">
          <span>{{ editing ? t('clause_library.dialog.edit_title') : t('clause_library.dialog.new_title') }}</span>
          <VBtn icon variant="text" size="small" :disabled="form.processing" @click="dialog = false">
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow>
            <VCol cols="12" md="7">
              <VTextField
                v-model="form.name"
                :label="t('clause_library.form.name')"
                variant="outlined"
                density="compact"
                :error-messages="form.errors.name"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="5">
              <VSelect
                v-model="form.category"
                :items="categories"
                :label="t('clause_library.form.category')"
                variant="outlined"
                density="compact"
                class="text-capitalize"
                :error-messages="form.errors.category"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.standard_text"
                :label="t('clause_library.form.standard_text')"
                variant="outlined"
                rows="4"
                auto-grow
                :error-messages="form.errors.standard_text"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.fallback_text"
                :label="t('clause_library.form.fallback_text')"
                variant="outlined"
                rows="3"
                auto-grow
                :error-messages="form.errors.fallback_text"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.risk_guidance"
                :label="t('clause_library.form.risk_guidance')"
                variant="outlined"
                rows="2"
                auto-grow
                :error-messages="form.errors.risk_guidance"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSwitch
                v-model="form.is_required"
                :label="t('clause_library.form.required_clause')"
                color="error"
                hide-details
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSwitch
                v-model="form.status"
                :label="t('clause_library.form.active')"
                color="success"
                hide-details
                :disabled="form.processing"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="justify-end">
          <VBtn variant="tonal" :disabled="form.processing" @click="dialog = false">
            {{ t('clause_library.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="form.processing"
            :disabled="form.processing"
            @click="submit"
          >
            {{ editing ? t('clause_library.save') : t('clause_library.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
