<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

type Item = { key: string; name: string; category: string; description: string; source: 'library' | 'template'; fields: Array<{ label: string; type: string; required: boolean }>; installed: boolean }

const route = ziggyRoute
const props = defineProps<{ forms: Item[] }>()

const category = ref<string | null>(null)
const search = ref('')
const withTemplates = ref(false)
const pool = computed(() => props.forms.filter(f => withTemplates.value || f.source === 'library'))
const categories = computed(() => [...new Set(pool.value.map(f => f.category))].sort())
const shown = computed(() => {
  const q = (search.value ?? '').trim().toLowerCase()
  return pool.value.filter(f => (!category.value || f.category === category.value)
    && (!q || `${f.name} ${f.description} ${f.fields.map(x => x.label).join(' ')}`.toLowerCase().includes(q)))
})
const preview = ref<Item | null>(null)
const busy = ref<string | null>(null)
const install = (f: Item) => {
  busy.value = f.key
  router.post(route('workflow.forms.library.install', f.key), {}, { onFinish: () => { busy.value = null } })
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-2" @click="router.get(route('workflow.forms.index'))">Forms</VBtn>
    <h4 class="text-h4">Form library</h4>
    <div class="text-body-2 text-medium-emphasis mb-4">Ready-made forms. Adding one gives your company its own copy to change as you need; use it as the start form or a task form of any workflow.</div>
    <VRow class="mb-1">
      <VCol cols="12" md="4"><VTextField v-model="search" label="Search forms and fields" prepend-inner-icon="tabler-search" clearable density="compact" /></VCol>
      <VCol cols="12" md="5" class="d-flex align-center"><VSwitch v-model="withTemplates" color="primary" hide-details label="Include the forms inside workflow templates" /></VCol>
    </VRow>
    <VChipGroup v-model="category" selected-class="text-primary" column class="mb-3">
      <VChip :value="null" filter variant="outlined">All ({{ pool.length }})</VChip>
      <VChip v-for="c in categories" :key="c" :value="c" filter variant="outlined">{{ c }} ({{ pool.filter(f => f.category === c).length }})</VChip>
    </VChipGroup>
    <VRow>
      <VCol v-for="f in shown" :key="f.key" cols="12" md="6" lg="4">
        <VCard border class="h-100 d-flex flex-column">
          <VCardItem>
            <VCardTitle class="text-body-1 text-wrap">{{ f.name }}</VCardTitle>
            <VCardSubtitle>{{ f.category }}<span v-if="f.source === 'template'"> · from a workflow template</span></VCardSubtitle>
          </VCardItem>
          <VCardText class="text-body-2 flex-grow-1">{{ f.description }}</VCardText>
          <VCardActions>
            <VBtn variant="text" size="small" @click="preview = f">{{ f.fields.length }} fields</VBtn>
            <VSpacer />
            <VChip v-if="f.installed" size="small" label class="me-2">Added</VChip>
            <VBtn color="primary" variant="tonal" :loading="busy === f.key" @click="install(f)">{{ f.installed ? 'Add again' : 'Add' }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
      <VCol v-if="!shown.length" cols="12"><div class="text-body-2 text-medium-emphasis">No forms match.</div></VCol>
    </VRow>
    <VDialog :model-value="!!preview" max-width="560" @update:model-value="preview = null">
      <VCard v-if="preview" :title="preview.name" :subtitle="preview.category">
        <VCardText>
          <div class="text-body-2 mb-3">{{ preview.description }}</div>
          <VTable density="compact">
            <thead><tr><th>Field</th><th>Type</th><th>Required</th></tr></thead>
            <tbody><tr v-for="(x, i) in preview.fields" :key="i"><td>{{ x.label }}</td><td class="text-body-2">{{ x.type }}</td><td>{{ x.required ? 'Yes' : '' }}</td></tr></tbody>
          </VTable>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="preview = null">Close</VBtn><VBtn color="primary" :loading="busy === preview.key" @click="install(preview)">Add</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
