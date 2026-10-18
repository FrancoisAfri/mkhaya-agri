<script setup lang="ts">
/**
 * A summary of the document, and a place to ask about it.
 *
 * Two things are shown that a panel like this usually hides.
 *
 * When the assistant could not answer from the document, it says so rather than presenting the
 * reply as an answer — a refusal sends somebody to read the file, and a confident wrong answer does
 * not.
 *
 * When it is unavailable, the reason is given. "Not available" invites somebody to keep clicking;
 * "this document is a scan with no readable text" tells them what to do instead.
 */
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

export interface Insight {
  uuid: string
  question: string | null
  content: string | null
  data: Record<string, any> | null
  grounded: boolean
  created_at: string | null
  asked_by: string | null
}

export interface FieldSuggestion {
  uuid: string
  data: Record<string, any>
  created_at: string | null
}

const props = defineProps<{
  documentUuid: string
  summary: Insight | null
  history: Insight[]
  fields: FieldSuggestion | null
  applicableFields: string[]
  available: boolean
  reason: string | null
}>()

const asking = ref(false)
const form = useForm({ question: '' })

// ── Fields read off the document ───────────────────────────────────────────
const accepted = ref<string[]>([])

/** The suggested values that map to a field on the document, and can therefore be accepted. */
const applicable = computed(() => {
  const data = props.fields?.data ?? {}

  return props.applicableFields
    .filter(field => data[field] !== undefined && data[field] !== null && data[field] !== '')
    .map(field => ({ field, value: String(data[field]) }))
})

/** Everything else the document stated. Shown to read, never applied — there is nowhere to put it. */
const alsoFound = computed(() => {
  const other = props.fields?.data?.other

  return other && typeof other === 'object'
    ? Object.entries(other as Record<string, unknown>).map(([label, value]) => ({ label, value: String(value) }))
    : []
})

function extractFields() {
  router.post(route('document-management.ai.fields', props.documentUuid), {}, { preserveScroll: true })
}

function applyFields() {
  if (!props.fields || accepted.value.length === 0) return

  router.post(route('document-management.ai.fields.apply', props.fields.uuid),
    { accept: accepted.value }, { preserveScroll: true, onSuccess: () => { accepted.value = [] } })
}

function dismissFields() {
  if (!props.fields) return

  router.post(route('document-management.ai.fields.dismiss', props.fields.uuid), {}, { preserveScroll: true })
}

/** The structured half of a summary, in the order somebody reads it. */
const sections = computed(() => {
  const data = props.summary?.data ?? {}

  return [
    { key: 'key_points', kind: 'list' as const, items: data.key_points },
    { key: 'dates', kind: 'pairs' as const, items: data.dates, valueKey: 'date' },
    { key: 'amounts', kind: 'pairs' as const, items: data.amounts, valueKey: 'value' },
    { key: 'obligations', kind: 'list' as const, items: data.obligations },
    { key: 'responsibilities', kind: 'list' as const, items: data.responsibilities },
    { key: 'risks', kind: 'list' as const, items: data.risks },
    { key: 'renewal', kind: 'text' as const, items: data.renewal },
    { key: 'termination', kind: 'text' as const, items: data.termination },
  ].filter(s => (Array.isArray(s.items) ? s.items.length > 0 : Boolean(s.items)))
})

function summarise(refresh = false) {
  router.post(route('document-management.ai.summarise', props.documentUuid), { refresh }, {
    preserveScroll: true,
  })
}

function ask() {
  form.post(route('document-management.ai.ask', props.documentUuid), {
    preserveScroll: true,
    onSuccess: () => { form.reset(); asking.value = false },
  })
}
</script>

<template>
  <VCard elevation="0" border>
    <VCardText class="d-flex align-center ga-2 pb-2">
      <VIcon size="20" icon="mdi-text-box-search-outline" />
      <span class="text-body-2 font-weight-medium">{{ d('ai_title') }}</span>
      <VSpacer />
      <VBtn
        v-if="available && summary" size="small" variant="text" icon="mdi-refresh"
        :aria-label="d('ai_regenerate')" @click="summarise(true)"
      />
    </VCardText>

    <VDivider />

    <!-- The reason, not just the absence. -->
    <VCardText v-if="!available" class="text-body-2 text-medium-emphasis">
      {{ reason ? d(reason) : d('ai_unavailable') }}
    </VCardText>

    <template v-else>
      <VCardText v-if="!summary">
        <p class="text-body-2 text-medium-emphasis mb-3">{{ d('ai_summary_none') }}</p>
        <VBtn size="small" color="primary" variant="tonal" prepend-icon="mdi-auto-fix" @click="summarise()">
          {{ d('ai_summarise') }}
        </VBtn>
      </VCardText>

      <VCardText v-else>
        <p class="text-body-2 mb-3" style="white-space:pre-line">{{ summary.content }}</p>

        <!-- The structured half. Sections the document did not support are absent rather than
             empty, so a two-page memo does not render six headings with nothing under them. -->
        <div v-for="section in sections" :key="section.key" class="mb-3">
          <div class="text-caption font-weight-medium text-medium-emphasis mb-1">
            {{ d('ai_section_' + section.key) }}
          </div>

          <ul v-if="section.kind === 'list'" class="ps-4 text-body-2">
            <li v-for="(item, i) in section.items" :key="i">{{ item }}</li>
          </ul>

          <VTable v-else-if="section.kind === 'pairs'" density="compact">
            <tbody>
              <tr v-for="(row, i) in section.items" :key="i">
                <td class="text-body-2">{{ row.label }}</td>
                <td class="text-body-2 font-weight-medium">{{ row[section.valueKey!] }}</td>
              </tr>
            </tbody>
          </VTable>

          <p v-else class="text-body-2 mb-0">{{ section.items }}</p>
        </div>

        <!-- Said every time, not once in a help page. A summary is a reading aid, not the document. -->
        <div class="text-caption text-medium-emphasis">{{ d('ai_summary_caveat') }}</div>
      </VCardText>

      <VDivider />

      <!-- ── What the document says its fields are ───────────────────────── -->
      <VCardText v-if="fields && applicable.length" class="pb-2">
        <div class="text-body-2 font-weight-medium mb-1">{{ d('ai_fields_found') }}</div>
        <!-- Said before the checkboxes: this is a reading, and accepting it is the person's act. -->
        <div class="text-caption text-medium-emphasis mb-2">{{ d('ai_fields_hint') }}</div>

        <VCheckbox
          v-for="row in applicable" :key="row.field"
          v-model="accepted" :value="row.field" density="compact" hide-details
        >
          <template #label>
            <span class="text-body-2">
              {{ d('field_' + row.field) }}: <strong>{{ row.value }}</strong>
            </span>
          </template>
        </VCheckbox>

        <div v-if="alsoFound.length" class="mt-2">
          <div class="text-caption text-medium-emphasis">{{ d('ai_fields_other') }}</div>
          <div v-for="row in alsoFound" :key="row.label" class="text-caption">
            {{ row.label }}: {{ row.value }}
          </div>
        </div>

        <div class="d-flex ga-2 mt-2">
          <VBtn size="small" variant="text" @click="dismissFields">{{ d('ai_fields_dismiss') }}</VBtn>
          <VBtn
            size="small" color="primary" variant="tonal"
            :disabled="accepted.length === 0" @click="applyFields"
          >{{ d('ai_fields_apply') }}</VBtn>
        </div>
      </VCardText>

      <VCardText v-else-if="!fields" class="pb-2">
        <VBtn size="small" variant="text" prepend-icon="mdi-form-select" @click="extractFields">
          {{ d('ai_extract_fields') }}
        </VBtn>
      </VCardText>

      <VDivider />

      <VCardText class="pb-2">
        <VBtn
          v-if="!asking" size="small" variant="text" prepend-icon="mdi-comment-question-outline"
          @click="asking = true"
        >{{ d('ai_ask') }}</VBtn>

        <form v-else @submit.prevent="ask">
          <VTextField
            v-model="form.question" :label="d('ai_question')" density="comfortable" autofocus
            :error-messages="form.errors.question" :hint="d('ai_question_hint')" persistent-hint
          />
          <div class="d-flex ga-2 mt-2">
            <VBtn size="small" variant="text" @click="asking = false">{{ d('cancel') }}</VBtn>
            <VBtn
              size="small" color="primary" type="submit" :loading="form.processing"
              :disabled="!form.question.trim()"
            >{{ d('ai_ask') }}</VBtn>
          </div>
        </form>
      </VCardText>

      <VList v-if="history.length" density="compact">
        <VListItem v-for="item in history" :key="item.uuid">
          <VListItemTitle class="text-body-2 font-weight-medium">{{ item.question }}</VListItemTitle>
          <VListItemSubtitle class="text-caption" style="white-space:normal">
            <!-- A refusal is labelled as one rather than dressed as an answer. -->
            <VChip v-if="!item.grounded" size="x-small" variant="tonal" color="warning" class="me-1">
              {{ d('ai_not_in_document') }}
            </VChip>
            {{ item.content }}
            <div v-if="item.asked_by" class="text-medium-emphasis mt-1">
              {{ item.asked_by }}<span v-if="item.created_at"> · {{ item.created_at }}</span>
            </div>
          </VListItemSubtitle>
        </VListItem>
      </VList>
    </template>
  </VCard>
</template>
