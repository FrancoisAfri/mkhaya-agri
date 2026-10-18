<script setup lang="ts">
/**
 * The metadata a document carries, as a form section.
 *
 * Shared between the upload panel and the detail screen so the two cannot drift — the same fields,
 * the same order, the same hints, wherever somebody meets them.
 *
 * Everything here is optional. An upload blocked because nobody knew the expiry date is an upload
 * that happens by email instead, so the fields are offered rather than demanded, and the ones most
 * often known come first.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

export interface Category { uuid: string; name: string; colour: string | null }

const model = defineModel<Record<string, any>>({ required: true })

const props = defineProps<{
  categories: Category[]
  levels: string[]
  errors?: Record<string, string>
  /** The detail screen has room for everything; the upload panel keeps the rare fields folded. */
  expanded?: boolean
}>()

const levelOptions = computed(() =>
  props.levels.map(value => ({ value, title: d('confidentiality_' + value) })),
)

const categoryOptions = computed(() => [
  { value: null, title: d('no_category') },
  ...props.categories.map(c => ({ value: c.uuid, title: c.name })),
])

/**
 * Whether the dates are in an order that makes sense.
 *
 * Checked here so it is said while somebody is typing rather than after a round trip. The server
 * validates it again — this is courtesy, not enforcement.
 */
const dateWarning = computed(() => {
  const { effective_date: from, expiry_date: to } = model.value
  return from && to && to < from ? d('expiry_before_effective') : null
})
</script>

<template>
  <div class="d-flex flex-column ga-3">
    <div class="field-grid">
      <VSelect
        v-model="model.category" :items="categoryOptions" density="comfortable"
        :label="d('field_category')" :error-messages="errors?.category"
      />
      <VTextField
        v-model="model.document_type" density="comfortable"
        :label="d('field_document_type')" :hint="d('document_type_hint')" persistent-hint
        :error-messages="errors?.document_type"
      />
    </div>

    <div class="field-grid">
      <VTextField
        v-model="model.document_number" density="comfortable"
        :label="d('field_document_number')" :hint="d('document_number_hint')" persistent-hint
        :error-messages="errors?.document_number"
      />
      <VSelect
        v-model="model.confidentiality" :items="levelOptions" density="comfortable"
        :label="d('field_confidentiality')" :error-messages="errors?.confidentiality"
      />
    </div>

    <template v-if="expanded">
      <!-- Three dates that get confused constantly, so each says what it means. -->
      <div class="field-grid field-grid--three">
        <VTextField
          v-model="model.document_date" type="date" density="comfortable"
          :label="d('field_document_date')" :hint="d('document_date_hint')" persistent-hint
          :error-messages="errors?.document_date"
        />
        <VTextField
          v-model="model.effective_date" type="date" density="comfortable"
          :label="d('field_effective_date')" :hint="d('effective_date_hint')" persistent-hint
          :error-messages="errors?.effective_date"
        />
        <VTextField
          v-model="model.expiry_date" type="date" density="comfortable"
          :label="d('field_expiry_date')" :hint="d('expiry_date_hint')" persistent-hint
          :error-messages="errors?.expiry_date"
        />
      </div>

      <VAlert v-if="dateWarning" type="warning" variant="tonal" density="compact">
        {{ dateWarning }}
      </VAlert>

      <VCombobox
        v-model="model.tags" :label="d('field_tags')" multiple chips closable-chips
        density="comfortable" :hint="d('tags_hint')" persistent-hint
        :error-messages="errors?.tags"
      />
    </template>
  </div>
</template>

<style scoped>
.field-grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: 1fr;
}

@media (min-width: 700px) {
  .field-grid { grid-template-columns: 1fr 1fr; }
  .field-grid--three { grid-template-columns: repeat(3, 1fr); }
}
</style>
