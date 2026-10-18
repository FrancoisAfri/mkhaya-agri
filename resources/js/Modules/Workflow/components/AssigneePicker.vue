<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  types: Record<string, string>; roles: string[]; people: Array<{ id: number; name: string }>; departments: Array<{ id: number; name: string }>
  fields: Array<{ key: string; label: string; type?: string }>; label?: string; disabled?: boolean; allowRecord?: boolean
}>()
const model = defineModel<{ type?: string; value?: any } | null>()
const type = computed({ get: () => model.value?.type ?? null, set: (t: any) => { model.value = { type: t, value: null } } })
const value = computed({ get: () => model.value?.value ?? null, set: (v: any) => { model.value = { type: model.value?.type, value: v } } })
const typeItems = computed(() => Object.entries(props.types).filter(([k]) => props.allowRecord || !['record_owner', 'budget_holder'].includes(k)).map(([value, title]) => ({ value, title })))
</script>

<template>
  <div>
    <VSelect v-model="type" :items="typeItems" :label="label ?? 'Assign to'" density="compact" :disabled="disabled" class="mb-2" />
    <VAutocomplete v-if="type === 'user'" :model-value="Array.isArray(value) ? value : value ? [value] : []" @update:model-value="value = $event" :items="people" item-title="name" item-value="id"
                   multiple chips label="People" density="compact" :disabled="disabled" />
    <VAutocomplete v-else-if="type === 'role'" v-model="value" :items="roles" label="Role" density="compact" :disabled="disabled" />
    <VAutocomplete v-else-if="type === 'department_head' || type === 'department_members'" v-model="value" :items="departments" item-title="name" item-value="id"
                   label="Department (empty = the requester's)" clearable density="compact" :disabled="disabled" />
    <VSelect v-else-if="type === 'field'" v-model="value" :items="fields.filter(f => !f.type || f.type === 'user' || f.type === 'users')" item-title="label" item-value="key" label="Form field holding the person" density="compact" :disabled="disabled" />
  </div>
</template>
