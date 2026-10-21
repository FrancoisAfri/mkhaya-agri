<script setup lang="ts">
/**
 * A dialog form described by a list of fields, so every Mkhaya Agri screen captures data the same
 * way: the same labels, the same server-side error under each field, the same save button.
 */
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'

export type FieldItem = string | { title: string; value: any }
export type FormField = {
  key: string
  label: string
  type?: 'text' | 'number' | 'date' | 'datetime' | 'select' | 'multiselect' | 'autocomplete' | 'textarea' | 'switch' | 'file'
  items?: FieldItem[] | ((form: Record<string, any>) => FieldItem[])
  cols?: number
  hint?: string
  required?: boolean
  default?: any
  show?: (form: Record<string, any>) => boolean
  multiple?: boolean   // files: keep every one chosen
  accept?: string
}

const props = withDefaults(defineProps<{
  modelValue: boolean
  title: string
  fields: FormField[]
  values?: Record<string, any>
  url: string
  method?: 'post' | 'patch' | 'delete'
  submitLabel?: string
  intro?: string
  maxWidth?: number | string
}>(), { method: 'post', submitLabel: 'Save', maxWidth: 720 })

const emit = defineEmits<{ (e: 'update:modelValue', v: boolean): void; (e: 'saved'): void }>()

const human = (s: string) => s.replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase())
const itemsFor = (f: FormField) => {
  const raw = typeof f.items === 'function' ? f.items(form.value) : (f.items ?? [])
  return raw.map(i => (typeof i === 'string' ? { title: human(i), value: i } : i))
}
const blank = () => Object.fromEntries(props.fields.map(f => [f.key, f.default ?? (f.type === 'switch' ? false : f.type === 'multiselect' ? [] : f.type === 'file' ? null : (f.type === 'number' ? null : ''))]))

const form = ref(useForm<Record<string, any>>(blank()))
watch(() => props.modelValue, open => {
  if (open) form.value = useForm<Record<string, any>>({ ...blank(), ...(props.values ?? {}) })
}, { immediate: true })
const visible = computed(() => props.fields.filter(f => !f.show || f.show(form.value)))
const hasFile = computed(() => props.fields.some(f => f.type === 'file'))

function submit() {
  const opts = {
    preserveScroll: true,
    forceFormData: hasFile.value,
    onSuccess: () => { emit('update:modelValue', false); emit('saved') },
  }
  // Empty strings mean "not given"; the server treats null as absent.
  form.value.transform(d => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v])))
  if (props.method === 'patch') form.value.patch(props.url, opts)
  else if (props.method === 'delete') form.value.delete(props.url, opts)
  else form.value.post(props.url, opts)
}
</script>

<template>
  <VDialog :model-value="modelValue" :max-width="maxWidth" scrollable @update:model-value="v => emit('update:modelValue', v)">
    <VCard :title="title">
      <VCardText>
        <p v-if="intro" class="text-body-2 text-medium-emphasis mb-4">{{ intro }}</p>
        <VAlert v-if="Object.keys(form.errors).some(k => !fields.some(f => f.key === k))" type="error" variant="tonal" density="compact" class="mb-4">
          <div v-for="(msg, k) in form.errors" :key="k"><template v-if="!fields.some(f => f.key === k)">{{ msg }}</template></div>
        </VAlert>
        <VRow>
          <VCol v-for="f in visible" :key="f.key" cols="12" :sm="f.cols ?? 6">
            <VSelect v-if="f.type === 'select'" v-model="form[f.key]" :label="f.label + (f.required ? ' *' : '')" :items="itemsFor(f)"
              :error-messages="form.errors[f.key]" :hint="f.hint" :persistent-hint="!!f.hint" clearable />
            <VSelect v-else-if="f.type === 'multiselect'" v-model="form[f.key]" :label="f.label + (f.required ? ' *' : '')" :items="itemsFor(f)" multiple chips closable-chips
              :error-messages="form.errors[f.key]" :hint="f.hint" :persistent-hint="!!f.hint" />
            <VAutocomplete v-else-if="f.type === 'autocomplete'" v-model="form[f.key]" :label="f.label + (f.required ? ' *' : '')" :items="itemsFor(f)"
              :error-messages="form.errors[f.key]" :hint="f.hint" :persistent-hint="!!f.hint" clearable />
            <VTextarea v-else-if="f.type === 'textarea'" v-model="form[f.key]" :label="f.label + (f.required ? ' *' : '')" rows="2" auto-grow
              :error-messages="form.errors[f.key]" :hint="f.hint" :persistent-hint="!!f.hint" />
            <VSwitch v-else-if="f.type === 'switch'" v-model="form[f.key]" :label="f.label" color="primary" inset hide-details="auto"
              :error-messages="form.errors[f.key]" />
            <VFileInput v-else-if="f.type === 'file'" :label="f.label" prepend-icon="" prepend-inner-icon="tabler-paperclip" :multiple="!!f.multiple" :accept="f.accept"
              :error-messages="form.errors[f.key] ?? form.errors[f.key + '.0']" :hint="f.hint" :persistent-hint="!!f.hint"
              @update:model-value="v => (form[f.key] = f.multiple ? (Array.isArray(v) ? v : v ? [v] : []) : (Array.isArray(v) ? v[0] : v))" />
            <VTextField v-else v-model="form[f.key]" :type="f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : f.type === 'datetime' ? 'datetime-local' : 'text'"
              :label="f.label + (f.required ? ' *' : '')" :error-messages="form.errors[f.key]" :hint="f.hint" :persistent-hint="!!f.hint"
              @update:model-value="v => { if (f.type === 'number') form[f.key] = v === '' ? null : Number(v) }" />
          </VCol>
        </VRow>
        <slot :form="form" />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="emit('update:modelValue', false)">Cancel</VBtn>
        <VBtn color="primary" :loading="form.processing" @click="submit">{{ submitLabel }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
