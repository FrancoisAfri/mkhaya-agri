<script setup lang="ts">
// A condition as rows joined by "all" or "any" (§7). Values are compared, never executed.
import { computed } from 'vue'

const props = defineProps<{ fields: Array<{ key: string; label: string }>; operators: Record<string, string>; patterns: string[]; disabled?: boolean }>()
const model = defineModel<Record<string, any> | null>()

const join = computed({
  get: () => (model.value?.any ? 'any' : 'all'),
  set: (j: string) => { model.value = { [j]: rows.value } },
})
const rows = computed<any[]>(() => {
  const c = model.value
  if (!c || !Object.keys(c).length) return []
  if (c.all) return c.all
  if (c.any) return c.any
  return [c]
})
function update(list: any[]) { model.value = list.length ? { [join.value]: list } : null }
function add() { update([...rows.value, { field: props.fields[0]?.key ?? '', operator: 'gt', value: '' }]) }
function remove(i: number) { const l = [...rows.value]; l.splice(i, 1); update(l) }
function set(i: number, patch: Record<string, any>) { const l = [...rows.value]; l[i] = { ...l[i], ...patch }; update(l) }
const noValue = ['empty', 'not_empty', 'true', 'false']
const opItems = computed(() => Object.entries(props.operators).map(([value, title]) => ({ value, title })))
</script>

<template>
  <div>
    <div v-if="rows.length > 1" class="d-flex align-center gap-2 mb-2">
      <span class="text-body-2">Match</span>
      <VBtnToggle v-model="join" density="compact" variant="outlined" divided mandatory :disabled="disabled">
        <VBtn value="all" size="small">all</VBtn><VBtn value="any" size="small">any</VBtn>
      </VBtnToggle>
      <span class="text-body-2">of these</span>
    </div>
    <div v-for="(r, i) in rows" :key="i" class="d-flex flex-wrap gap-2 align-start mb-2">
      <VCombobox :model-value="r.field" :items="fields" item-title="label" item-value="key" :return-object="false" label="Field" density="compact" hide-details
                 style="min-inline-size: 150px; flex: 2" :disabled="disabled" @update:model-value="set(i, { field: $event })" />
      <VSelect :model-value="r.operator" :items="opItems" label="Is" density="compact" hide-details style="min-inline-size: 130px; flex: 1.4" :disabled="disabled" @update:model-value="set(i, { operator: $event })" />
      <template v-if="!noValue.includes(r.operator)">
        <VSelect v-if="r.operator === 'matches'" :model-value="r.value" :items="patterns" label="Pattern" density="compact" hide-details style="flex: 1.4" :disabled="disabled" @update:model-value="set(i, { value: $event })" />
        <div v-else-if="r.operator === 'between'" class="d-flex gap-1" style="flex: 1.6">
          <VTextField :model-value="(r.value ?? [])[0]" label="From" density="compact" hide-details :disabled="disabled" @update:model-value="set(i, { value: [$event, (r.value ?? [])[1]] })" />
          <VTextField :model-value="(r.value ?? [])[1]" label="To" density="compact" hide-details :disabled="disabled" @update:model-value="set(i, { value: [(r.value ?? [])[0], $event] })" />
        </div>
        <VCombobox v-else-if="['in', 'not_in'].includes(r.operator)" :model-value="Array.isArray(r.value) ? r.value : []" multiple chips label="Values" density="compact" hide-details
                   style="flex: 1.6" :disabled="disabled" @update:model-value="set(i, { value: $event })" />
        <VTextField v-else :model-value="r.value" label="Value" density="compact" hide-details style="flex: 1.4" :disabled="disabled"
                    @update:model-value="set(i, { value: $event !== '' && !Number.isNaN(Number($event)) && !['before', 'after'].includes(r.operator) ? Number($event) : $event })" />
      </template>
      <VBtn icon="tabler-x" size="small" variant="text" :disabled="disabled" @click="remove(i)" />
    </div>
    <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" :disabled="disabled" @click="add">Add condition</VBtn>
  </div>
</template>
