<script setup lang="ts">
import { computed } from 'vue'

/**
 * A field that names a person.
 *
 * Typing a name by hand is how these fields get four spellings of the same foreman, and how a
 * register ends up impossible to filter by who did the work. So the list is offered.
 *
 * But it is a combobox rather than a select, and that is the whole design. Site records are signed
 * by people who are frequently not in this system at all — the engineer who issued the instruction,
 * the client's representative who witnessed the test, the lab technician. A closed dropdown would
 * mean a site agent standing in front of a real signed instruction with no way to record who signed
 * it, and the field would simply be left blank. Offering the list while still accepting a typed
 * name gets consistency where consistency is possible and a record where it is not.
 *
 * Model value stays a plain string for exactly that reason: the stored value is a name, not a
 * foreign key, so a person who leaves the project does not erase who signed a certificate last year.
 */
const props = defineProps<{
  modelValue: string | null
  people?: Array<{ id: string; name: string; role?: string | null }>
  label: string
  hint?: string
  errorMessages?: string | string[]
  density?: 'default' | 'comfortable' | 'compact'
  autofocus?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [string | null] }>()

const value = computed({
  get: () => props.modelValue ?? null,
  // Vuetify hands back the whole item when one is picked from the list and a raw string when the
  // user types their own; both have to end up as the name that gets stored.
  set: (v: unknown) => emit('update:modelValue', typeof v === 'string' ? v : (v as { name?: string })?.name ?? null),
})

const items = computed(() => (props.people ?? []).map(p => ({
  title: p.name,
  subtitle: p.role ?? undefined,
  value: p.name,
})))
</script>

<template>
  <VCombobox
    v-model="value"
    :items="items"
    :label="label"
    :hint="hint"
    :persistent-hint="!!hint"
    :error-messages="errorMessages"
    :density="density ?? 'compact'"
    :autofocus="autofocus"
    variant="outlined"
    item-title="title"
    item-value="value"
    :return-object="false"
    clearable
    auto-select-first
  >
    <!-- The role is what tells two people with similar names apart on a busy project. -->
    <template #item="{ props: itemProps, item }">
      <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
    </template>
  </VCombobox>
</template>
