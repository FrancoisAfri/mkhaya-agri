<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
// AppDateTimePicker is registered globally by the @core auto-import plugin, like everywhere else
// in the application that uses it; importing it by path would resolve to nothing.
import FileDrop from '@/Modules/Sheq/Partials/FileDrop.vue'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Field = {
  key: string
  label: string
  type?: 'text' | 'textarea' | 'select' | 'multiselect' | 'date' | 'datetime' | 'number'
    | 'switch' | 'file' | 'files' | 'lookup'
  items?: { title: string; value: unknown }[]
  required?: boolean
  hint?: string
  cols?: number
  /**
   * What the field starts as each time the dialog opens.
   *
   * Today's date on a reading, the only site a tenant has, the unit a meter is already in — the
   * answer somebody would type anyway. Applied on every open, not only the first, so a cancelled
   * entry never leaves yesterday's date behind in the next one.
   */
  default?: unknown
  /**
   * `lookup` only: searchable, and each item may carry a `fill` of other field values.
   *
   * Picking an existing record then fills the rest of the form from it instead of asking somebody
   * to retype a name and a registration number that are already on file — which is how the same
   * company ends up in the system twice under two spellings.
   */
  lookupItems?: { title: string; subtitle?: string; value: unknown; fill?: Record<string, unknown> }[]
  /** Said under a lookup once something is picked, so the consequence is visible before saving. */
  fillNote?: string
  /**
   * Shown only when this says so, given what has been filled in so far.
   *
   * A PPE size is asked for when the item chosen needs one and not otherwise; a restriction is
   * asked for when the outcome is "fit with restriction". Asking for both every time trains
   * people to leave fields blank, and a blank field nobody reads is how a real one gets skipped.
   */
  showIf?: (values: Record<string, unknown>) => boolean
  /**
   * A hint that depends on what has been filled in so far.
   *
   * What a supplier at HIGH risk will be asked to produce, as soon as HIGH is chosen — so the
   * invitation says what is wanted rather than the supplier discovering it one rejection at a
   * time. Takes precedence over `hint`.
   */
  hintFor?: (values: Record<string, unknown>) => string
}

const props = defineProps<{
  title: string
  action: string
  fields: Field[]
  /**
   * Last chance to reshape what is posted.
   *
   * A picker that collects person ids where the endpoint wants `[{hr_person_id, name}]`: the
   * field stays a plain list the person can see and clear, and the shape the server wants is
   * derived here rather than kept in a second parallel piece of state that can fall out of step
   * with the one on screen.
   */
  transform?: (values: Record<string, unknown>) => Record<string, unknown>
  /** Shown under the heading — what this record means once it exists. */
  note?: string
  buttonLabel: string
}>()

const open = ref(false)

// A `files` field starts as a list, not null — the dropzone appends to what is there, and
// appending to null drops the first file silently.
const blankValue = (f: Field) => f.default !== undefined ? f.default
  : f.type === 'switch' ? false
  : f.type === 'files' || f.type === 'multiselect' ? []
  : null

const blank = () =>
  Object.fromEntries(props.fields.map(f => [f.key, blankValue(f)])) as Record<string, unknown>

const form = useForm(blank())

// Reset on open, so a cancelled entry never leaks into the next one.
watch(open, isOpen => { if (isOpen) form.defaults(blank()), form.reset(), form.clearErrors() })

/*
 * Which fields this form is actually asking for, given what has been filled in so far.
 *
 * The template renders these and `submit` blanks the rest from the same predicate, so a size
 * typed against an item that needs one cannot be posted after switching to an item that does
 * not — where it would be stored against a record it means nothing on.
 */
const visible = computed(() => props.fields.filter(f => ! f.showIf || f.showIf(form.data())))

const hintOf = (field: Field) => field.hintFor ? field.hintFor(form.data()) : field.hint

// Either kind of file field means multipart: a file cannot travel as JSON.
const hasFile = props.fields.some(f => f.type === 'file' || f.type === 'files')

/**
 * Pick an existing record and the rest of the form follows from it.
 *
 * Only empty fields are filled, so somebody who typed a different contact name before reaching
 * for the picker does not have their typing overwritten. Clearing the lookup leaves the filled
 * values alone: they are now this record's values, and silently emptying them would look like
 * the form losing work.
 */
function onLookup(field: Field, value: unknown) {
  form[field.key] = value

  const picked = field.lookupItems?.find(i => i.value === value)

  if (!picked?.fill) return

  for (const [key, filled] of Object.entries(picked.fill)) {
    if (form[key] === null || form[key] === '' || form[key] === undefined) {
      form[key] = filled
    }
  }
}

const pickedLookup = (field: Field) =>
  field.lookupItems?.find(i => i.value === form[field.key]) ?? null

const submit = () => {
  const shown = new Set(visible.value.map(f => f.key))

  for (const field of props.fields) {
    if (! shown.has(field.key)) form[field.key] = blankValue(field)
  }

  if (props.transform) form.transform(values => props.transform!(values))

  form.post(props.action, {
    // A file field forces multipart; Inertia needs telling.
    forceFormData: hasFile,
    preserveScroll: true,
    onSuccess: () => { open.value = false },
  })
}
</script>

<template>
  <VBtn color="primary" prepend-icon="mdi-plus" @click="open = true">{{ buttonLabel }}</VBtn>

  <VDialog v-model="open" max-width="860" scrollable>
    <VCard>
      <VCardTitle class="text-body-1 font-weight-semibold">{{ title }}</VCardTitle>

      <VCardText>
        <p v-if="note" class="text-body-2 text-medium-emphasis mb-4">{{ note }}</p>

        <VRow>
          <VCol v-for="field in visible" :key="field.key" cols="12" :md="field.cols ?? 6">
            <VTextarea
              v-if="field.type === 'textarea'"
              v-model="form[field.key] as string" :label="field.label" rows="3"
              variant="outlined" density="comfortable"
              :error-messages="form.errors[field.key]" :hint="hintOf(field)" persistent-hint
            />
            <!-- Several at once, as chips that can be taken off one by one. -->
            <VSelect
              v-else-if="field.type === 'multiselect'"
              v-model="form[field.key] as unknown[]" :items="field.items" :label="field.label"
              multiple chips closable-chips
              variant="outlined" density="comfortable"
              :error-messages="form.errors[field.key]" :hint="hintOf(field)" persistent-hint
            />
            <VSelect
              v-else-if="field.type === 'select'"
              v-model="form[field.key]" :items="field.items" :label="field.label"
              variant="outlined" density="comfortable" clearable
              :error-messages="form.errors[field.key]" :hint="hintOf(field)" persistent-hint
            />
            <!--
              A searchable picker over records that already exist elsewhere in the system, so a
              contractor who is already a supplier is selected rather than typed in again.
            -->
            <template v-else-if="field.type === 'lookup'">
              <VAutocomplete
                :model-value="form[field.key]"
                :items="field.lookupItems ?? []"
                item-title="title" item-value="value"
                :label="field.label"
                variant="outlined" density="comfortable" clearable
                :error-messages="form.errors[field.key]" :hint="hintOf(field)" persistent-hint
                @update:model-value="(v: unknown) => onLookup(field, v)"
              >
                <template #item="{ props: itemProps, item }">
                  <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
                </template>
              </VAutocomplete>
              <p v-if="field.fillNote && pickedLookup(field)" class="text-caption text-success mt-1 mb-0">
                {{ field.fillNote }}
              </p>
            </template>
            <!-- A time as well as a date, for the fields where the hour is the point: a visitor
                 expected out at 16:00 is overstaying at 17:00, and a date alone cannot say so. -->
            <AppDateTimePicker
              v-else-if="field.type === 'datetime'"
              v-model="form[field.key] as string" :label="field.label"
              :config="{ enableTime: true, dateFormat: 'Y-m-d H:i', altInput: true,
                         altFormat: 'd M Y, H:i' }"
              :error-messages="form.errors[field.key]"
            />
            <VSwitch
              v-else-if="field.type === 'switch'"
              v-model="form[field.key] as boolean" :label="field.label" color="primary"
              :error-messages="form.errors[field.key]" hide-details
            />
            <!--
              The shared dropzone rather than a bare field, so every register in the module takes
              a dragged file the same way. `files` means several, and a zip is unpacked server-side
              into one record per file inside it.
            -->
            <FileDrop
              v-else-if="field.type === 'file' || field.type === 'files'"
              :model-value="(form[field.key] as File[]) ?? []"
              :multiple="field.type === 'files'"
              :hint="hintOf(field)"
              :error-messages="form.errors[field.key]"
              @update:model-value="(f: File[]) => form[field.key] = field.type === 'files' ? f : (f[0] ?? null)"
            />
            <VTextField
              v-else
              v-model="form[field.key] as string" :label="field.label"
              :type="field.type === 'date' ? 'date' : field.type === 'number' ? 'number' : 'text'"
              variant="outlined" density="comfortable"
              :error-messages="form.errors[field.key]" :hint="hintOf(field)" persistent-hint
            />
          </VCol>
        </VRow>
      </VCardText>

      <VCardActions class="px-4 pb-4">
        <VSpacer />
        <VBtn variant="tonal" @click="open = false">{{ t('sheq.action.cancel') }}</VBtn>
        <!-- Disabled while in flight, so a slow connection cannot create the record twice. -->
        <VBtn color="primary" variant="flat" :loading="form.processing"
              :disabled="form.processing" @click="submit">
          {{ t('sheq.registers.save') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
