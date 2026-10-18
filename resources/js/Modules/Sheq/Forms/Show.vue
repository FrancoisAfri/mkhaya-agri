<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Field = {
  key: string; label: string; value: string | null; generated: string | null
  source: string | null; required: boolean; multiline: boolean
  is_gap: boolean; overridden: boolean
}

type FormDoc = {
  uuid: string; form_code: string; title: string; status: string
  content: { title: string; instrument: string | null; guidance: string | null
             sections: { title: string; fields: Field[] }[] }
  gap_count: number; gaps: string[]; override_count: number; pack: string
  generated_at: string; finalised_at: string | null
  overrides: { field_key: string; generated_value: string | null
               override_value: string | null; reason: string | null; overridden_at: string }[]
}

const props = defineProps<{ form: FormDoc; canManage?: boolean }>()

const isFinal = computed(() => props.form.status === 'FINAL')
const showDocument = ref(false)

const allFields = computed(() =>
  props.form.content.sections.flatMap(s => s.fields))

/**
 * Submission progress, which the pattern calls for and which a legal form needs more than a
 * landing page does: the question "how much of this is left" has a real answer here.
 */
const requiredFields = computed(() => allFields.value.filter(f => f.required))
const completed = computed(() => requiredFields.value.filter(f => !f.is_gap).length)
const percent = computed(() =>
  requiredFields.value.length === 0 ? 100
    : Math.round((completed.value / requiredFields.value.length) * 100))

/**
 * Only the outstanding fields are put in front of the user.
 *
 * The pattern's "≤ 3 fields" conversion rule cannot govern a gazetted form — the Commissioner
 * decides how many boxes a W.Cl.2 has, not us. What carries over is its intent: ask for the few
 * things still missing rather than presenting all twenty-one and hoping somebody spots the blanks.
 */
const outstanding = computed(() => allFields.value.filter(f => f.is_gap))

const editing = ref<Field | null>(null)
const edit = useForm({ field_key: '', value: '' as string | null, reason: '' })
const finalise = useForm({})

const openEditor = (field: Field) => {
  if (isFinal.value || !props.canManage) return
  editing.value = field
  edit.field_key = field.key
  edit.value = field.value
  edit.reason = ''
  edit.clearErrors()
}

const saveField = () => {
  edit.post(`/sheq/forms/${props.form.uuid}/override`, {
    preserveScroll: true,
    onSuccess: () => { editing.value = null },
  })
}

const submitFinal = () => finalise.post(`/sheq/forms/${props.form.uuid}/finalise`, { preserveScroll: true })
</script>

<template>
  <div class="pa-4 form-page">
    <!-- ── 1. Hero: what this is, and the authority it answers to ──────────────────────────
         Trust & Authority, applied literally: the instrument, the pack version that produced the
         layout and the audit guarantee are stated as credentials, because the person signing this
         is taking responsibility for it. -->
    <VCard elevation="0" rounded="lg" class="hero mb-4 no-print">
      <div class="pa-6">
        <div class="d-flex align-start justify-space-between flex-wrap gap-4">
          <div style="min-inline-size: 0">
            <div class="d-flex align-center gap-2 mb-1 flex-wrap">
              <VChip size="small" variant="flat" class="code-chip">{{ form.form_code }}</VChip>
              <VChip :color="isFinal ? 'success' : 'warning'" size="small" variant="flat">
                {{ isFinal ? t('sheq.forms.final') : t('sheq.forms.draft') }}
              </VChip>
            </div>

            <h1 class="hero-title">{{ form.title }}</h1>
            <p v-if="form.content.instrument" class="hero-instrument">
              {{ form.content.instrument }}
            </p>

            <!-- Credential badges. Each states a fact somebody may be asked to defend. -->
            <div class="d-flex flex-wrap gap-2 mt-4">
              <span class="credential">
                <VIcon icon="mdi-shield-check-outline" size="15" />
                {{ t('sheq.forms.assessed_against', { pack: form.pack }) }}
              </span>
              <span class="credential">
                <VIcon icon="mdi-history" size="15" />
                {{ t('sheq.forms.audit_logged') }}
              </span>
              <span v-if="form.override_count" class="credential">
                <VIcon icon="mdi-pencil-outline" size="15" />
                {{ t('sheq.forms.amendments', { count: form.override_count }) }}
              </span>
            </div>
          </div>

          <div class="d-flex align-center gap-2 flex-wrap">
            <VBtn variant="tonal" prepend-icon="mdi-file-pdf-box" :href="`/sheq/forms/${form.uuid}/pdf`">
              {{ t('sheq.forms.download') }}
            </VBtn>
          </div>
        </div>
      </div>
    </VCard>

    <VRow class="no-print">
      <!-- ── 2. Progress + 3. The few things still outstanding ─────────────────────────── -->
      <VCol cols="12" md="7">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-body-2 font-weight-medium">{{ t('sheq.forms.progress') }}</span>
              <span class="text-body-2 text-medium-emphasis">
                {{ t('sheq.forms.progress_count', { done: completed, total: requiredFields.length }) }}
              </span>
            </div>

            <VProgressLinear
              :model-value="percent" height="10" rounded
              :color="percent === 100 ? 'success' : 'warning'"
              :aria-label="t('sheq.forms.progress')"
              aria-live="polite"
            />

            <p v-if="percent === 100" class="text-body-2 mt-3 mb-0 text-success">
              <VIcon icon="mdi-check-circle-outline" size="16" class="me-1" />
              {{ t('sheq.forms.complete_ready') }}
            </p>
          </VCardText>
        </VCard>

        <!-- The action zone. Nothing else competes with it. -->
        <VCard v-if="outstanding.length && !isFinal" elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.forms.still_needed') }}
          </VCardTitle>
          <VCardText class="pt-0">
            <p class="text-body-2 text-medium-emphasis mb-4">{{ t('sheq.forms.gaps_hint') }}</p>

            <div
              v-for="field in outstanding" :key="field.key"
              class="outstanding-row d-flex align-center justify-space-between gap-3 py-3"
            >
              <div style="min-inline-size: 0">
                <div class="text-body-2 font-weight-medium">{{ field.label }}</div>
                <!-- Icon and words, never colour alone. -->
                <div class="text-caption text-warning d-flex align-center gap-1 mt-1">
                  <VIcon icon="mdi-alert-circle-outline" size="14" />
                  {{ t('sheq.forms.not_supplied') }}
                </div>
              </div>
              <VBtn
                v-if="canManage" size="small" color="primary" variant="tonal"
                prepend-icon="mdi-pencil-outline" @click="openEditor(field)"
              >{{ t('sheq.forms.fill_in') }}</VBtn>
            </div>
          </VCardText>
        </VCard>

        <VAlert v-if="isFinal" type="info" variant="tonal" class="mb-4">
          {{ t('sheq.forms.final_frozen', { at: form.finalised_at }) }}
        </VAlert>
      </VCol>

      <!-- ── 4. Single CTA ────────────────────────────────────────────────────────────── -->
      <VCol cols="12" md="5">
        <VCard elevation="0" border rounded="lg" class="submit-card">
          <VCardText class="text-center pa-6">
            <VIcon
              :icon="percent === 100 ? 'mdi-check-decagram-outline' : 'mdi-file-clock-outline'"
              size="40" :color="percent === 100 ? 'success' : 'warning'" class="mb-3"
            />
            <div class="text-body-1 font-weight-semibold mb-1">
              {{ isFinal ? t('sheq.forms.submitted_title') : t('sheq.forms.submit_title') }}
            </div>
            <p class="text-body-2 text-medium-emphasis mb-4">
              {{ isFinal ? t('sheq.forms.submitted_body') : t('sheq.forms.submit_body') }}
            </p>

            <VBtn
              v-if="!isFinal && canManage"
              color="primary" variant="flat" size="large" block
              prepend-icon="mdi-check-decagram"
              :loading="finalise.processing"
              :disabled="finalise.processing || form.gap_count > 0"
              @click="submitFinal"
            >{{ t('sheq.forms.finalise') }}</VBtn>

            <p v-if="!isFinal && form.gap_count > 0" class="text-caption text-medium-emphasis mt-3 mb-0">
              {{ t('sheq.forms.finalise_blocked', { count: form.gap_count }) }}
            </p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── 5. The document itself, behind a toggle ─────────────────────────────────────── -->
    <div class="d-flex align-center gap-2 mb-3 no-print">
      <VBtn
        variant="text" size="small"
        :prepend-icon="showDocument ? 'mdi-chevron-up' : 'mdi-chevron-down'"
        :aria-expanded="showDocument"
        @click="showDocument = !showDocument"
      >{{ showDocument ? t('sheq.forms.hide_document') : t('sheq.forms.preview_document') }}</VBtn>
      <span class="text-caption text-medium-emphasis">
        {{ t('sheq.forms.field_count', { count: allFields.length }) }}
      </span>
    </div>

    <VCard v-show="showDocument" elevation="0" border rounded="lg" class="statutory-form">
      <div class="pa-6">
        <div class="form-masthead">
          <h2 class="form-title">{{ form.content.title }}</h2>
          <p v-if="form.content.instrument" class="form-instrument">{{ form.content.instrument }}</p>
        </div>

        <p v-if="form.content.guidance" class="form-guidance">{{ form.content.guidance }}</p>

        <section v-for="section in form.content.sections" :key="section.title" class="mt-6">
          <h3 class="form-section">{{ section.title }}</h3>

          <dl class="form-grid">
            <template v-for="field in section.fields" :key="field.key">
              <dt class="form-label">
                {{ field.label }}
                <abbr v-if="field.required" :title="t('sheq.forms.required')" class="req">*</abbr>
              </dt>
              <dd class="form-value">
                <span v-if="field.value" :class="field.overridden ? 'amended' : ''">{{ field.value }}</span>
                <span v-else class="gap">
                  <VIcon icon="mdi-alert-circle-outline" size="15" class="me-1" />
                  {{ t('sheq.forms.not_supplied') }}
                </span>

                <VBtn
                  v-if="!isFinal && canManage"
                  variant="text" size="x-small" icon class="ms-1 no-print"
                  :aria-label="t('sheq.forms.edit_field', { field: field.label })"
                  @click="openEditor(field)"
                >
                  <VIcon icon="mdi-pencil-outline" size="15" />
                </VBtn>

                <div v-if="field.overridden" class="amended-note no-print">
                  {{ t('sheq.forms.was_generated', { value: field.generated || t('sheq.forms.blank') }) }}
                </div>
              </dd>
            </template>
          </dl>
        </section>
      </div>
    </VCard>

    <!-- ── Audit trail ─────────────────────────────────────────────────────────────────── -->
    <VCard v-if="form.overrides.length" elevation="0" border rounded="lg" class="mt-4 no-print">
      <VCardTitle class="text-body-1 font-weight-semibold">
        {{ t('sheq.forms.amendments', { count: form.override_count }) }}
      </VCardTitle>
      <VTable density="comfortable">
        <thead>
          <tr>
            <th scope="col">{{ t('sheq.forms.field') }}</th>
            <th scope="col">{{ t('sheq.forms.system_proposed') }}</th>
            <th scope="col">{{ t('sheq.forms.changed_to') }}</th>
            <th scope="col">{{ t('sheq.forms.reason') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(o, i) in form.overrides" :key="i">
            <td class="text-body-2">{{ o.field_key }}</td>
            <td class="text-body-2 text-medium-emphasis">{{ o.generated_value ?? '—' }}</td>
            <td class="text-body-2 font-weight-medium">{{ o.override_value ?? '—' }}</td>
            <td class="text-body-2 text-medium-emphasis">{{ o.reason ?? '—' }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- ── Field editor ────────────────────────────────────────────────────────────────── -->
    <VDialog :model-value="!!editing" max-width="720" @update:model-value="editing = null">
      <VCard v-if="editing">
        <VCardTitle class="text-body-1 font-weight-semibold">{{ editing.label }}</VCardTitle>
        <VCardText>
          <div class="text-caption text-medium-emphasis mb-1">{{ t('sheq.forms.system_proposed') }}</div>
          <VSheet border rounded class="pa-3 text-body-2 mb-4">
            {{ editing.generated ?? t('sheq.forms.blank') }}
            <span v-if="editing.source" class="text-caption text-medium-emphasis d-block mt-1">
              {{ t('sheq.forms.from_source', { source: editing.source }) }}
            </span>
          </VSheet>

          <VTextarea
            v-if="editing.multiline" v-model="edit.value" :label="editing.label"
            rows="4" variant="outlined" density="comfortable"
            :error-messages="edit.errors.value" autofocus
          />
          <VTextField
            v-else v-model="edit.value" :label="editing.label"
            variant="outlined" density="comfortable"
            :error-messages="edit.errors.value" autofocus
          />

          <VTextField
            v-model="edit.reason" :label="t('sheq.forms.reason_optional')"
            variant="outlined" density="comfortable" class="mt-3"
            :error-messages="edit.errors.reason"
            :hint="t('sheq.forms.reason_hint')" persistent-hint
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="editing = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="edit.processing"
                :disabled="edit.processing" @click="saveField">
            {{ t('sheq.registers.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
/* Trust & Authority, scoped to this page rather than imposed on the app: navy carries the
   institutional weight, and the gold rule is the certification accent the style calls for. */
.hero {
  background: linear-gradient(135deg, #1E3A8A 0%, #1E40AF 100%);
  color: #fff;
  border-block-end: 3px solid #B45309;
}

.hero-title {
  font-family: Georgia, "Times New Roman", serif;
  font-size: 1.5rem;
  font-weight: 700;
  line-height: 1.3;
  margin: 0;
}

.hero-instrument {
  font-size: .9rem;
  font-style: italic;
  opacity: .85;
  margin: 4px 0 0;
}

.code-chip { background: rgba(255, 255, 255, .18) !important; color: #fff !important; }

/* Credentials read as stamps rather than as chips — 4.5:1 against the navy. */
.credential {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: .78rem;
  padding: 4px 10px;
  border: 1px solid rgba(255, 255, 255, .35);
  border-radius: 999px;
  background: rgba(255, 255, 255, .1);
  color: #fff;
}

.outstanding-row + .outstanding-row {
  border-block-start: 1px solid rgba(var(--v-theme-on-surface), .1);
}

.submit-card { position: sticky; inset-block-start: 88px; }

/* The document reads as the legal instrument it is. */
.statutory-form { font-family: Georgia, "Times New Roman", serif; }

.form-masthead { border-block-end: 2px solid currentColor; padding-block-end: 8px; }
.form-title { font-size: 1.15rem; font-weight: 700; margin: 0; }
.form-instrument { font-size: .85rem; font-style: italic; margin: 2px 0 0; opacity: .75; }

.form-guidance {
  font-size: .85rem;
  border-inline-start: 3px solid rgba(var(--v-theme-on-surface), .3);
  padding: 6px 10px;
  margin-block-start: 12px;
  opacity: .85;
}

.form-section {
  font-size: .8rem;
  text-transform: uppercase;
  letter-spacing: .07em;
  border-block-end: 1px solid rgba(var(--v-theme-on-surface), .5);
  padding-block-end: 3px;
  margin-block-end: 8px;
}

.form-grid { display: grid; grid-template-columns: minmax(160px, 34%) 1fr; margin: 0; }

.form-label,
.form-value {
  padding: 7px 8px;
  border-block-end: 1px solid rgba(var(--v-theme-on-surface), .14);
  font-size: .9rem;
  line-height: 1.5;
}

.form-label { opacity: .8; }
.form-value { font-weight: 600; margin: 0; }
.req { text-decoration: none; opacity: .7; }

.gap { font-weight: 400; font-style: italic; color: rgb(var(--v-theme-warning)); }
.amended { border-block-end: 1px dashed rgb(var(--v-theme-primary)); }

.amended-note {
  font-size: .72rem;
  font-family: system-ui, sans-serif;
  opacity: .65;
  margin-block-start: 2px;
}

@media (max-width: 600px) {
  .form-grid { grid-template-columns: 1fr; }
  .form-label { border-block-end: none; padding-block-end: 0; opacity: .65; }
  .submit-card { position: static; }
}

@media (prefers-reduced-motion: reduce) {
  .hero { transition: none; }
}

@media print {
  .no-print { display: none !important; }
  .statutory-form { border: none !important; }
}
</style>
