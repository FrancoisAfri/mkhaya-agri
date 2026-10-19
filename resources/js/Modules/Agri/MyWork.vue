<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from './Components/PageErrors.vue'
import FormDialog from './Components/FormDialog.vue'
import { human, statusColor, today } from './Components/agri'

/** Built for a phone in the field: big targets, one column, the day's work first. */
const props = defineProps<{
  tasks: any[]; activities: any[]
  farms: Array<{ id: string; name: string; fields: Array<{ id: string; code: string; name: string }> }>
  can: { observe: boolean }
}>()
const dialog = ref<null | string>(null)
const target = ref<any>(null)
const open = (k: string, t: any = null) => { target.value = t; dialog.value = k }
const close = (v: boolean) => { if (!v) dialog.value = null }
const keep = { preserveScroll: true }
const startTask = (t: any) => router.post(route('agri.tasks.transition', t.id), { action: 'start' }, keep)
const startActivity = (a: any) => router.post(route('agri.crops.activities.status', a.id), { action: 'start' }, keep)
</script>

<template>
  <div class="mx-auto" style="max-width: 640px">
    <PageErrors />
    <div class="d-flex align-center justify-space-between mb-4">
      <div>
        <h4 class="text-h4 mb-0">My Work</h4>
        <div class="text-body-2 text-medium-emphasis">{{ new Date().toLocaleDateString('en-ZA', { weekday: 'long', day: 'numeric', month: 'long' }) }}</div>
      </div>
      <VBtn v-if="can.observe" color="warning" size="large" prepend-icon="tabler-bug" @click="open('observe')">Report</VBtn>
    </div>

    <h6 class="text-h6 mb-2">Tasks <VChip size="x-small">{{ tasks.length }}</VChip></h6>
    <VCard v-for="t in tasks" :key="t.id" class="mb-3" :color="t.overdue ? 'error' : undefined" :variant="t.overdue ? 'tonal' : 'elevated'">
      <VCardItem>
        <VCardTitle class="text-wrap">{{ t.title }}</VCardTitle>
        <VCardSubtitle>{{ t.farm }}<template v-if="t.field"> · {{ t.field }}</template><template v-if="t.due_on"> · due {{ t.due_on }}</template></VCardSubtitle>
        <template #append><VChip :color="statusColor(t.status)" size="small" variant="tonal">{{ human(t.status) }}</VChip></template>
      </VCardItem>
      <VCardText v-if="t.description || t.review_note">
        <div v-if="t.description">{{ t.description }}</div>
        <VAlert v-if="t.status === 'rejected'" type="error" variant="tonal" density="compact" class="mt-2">Redo: {{ t.review_note }}</VAlert>
      </VCardText>
      <VCardActions v-if="t.status !== 'submitted'">
        <VBtn v-if="['open', 'rejected'].includes(t.status)" size="large" variant="tonal" prepend-icon="tabler-player-play" @click="startTask(t)">Start</VBtn>
        <VSpacer />
        <VBtn size="large" color="success" variant="flat" prepend-icon="tabler-check" @click="open('submit', t)">Done</VBtn>
      </VCardActions>
      <VCardText v-else class="text-caption">Submitted — waiting for sign-off.</VCardText>
    </VCard>
    <VCard v-if="!tasks.length" class="mb-3"><VCardText class="text-center text-medium-emphasis">No tasks assigned to you.</VCardText></VCard>

    <h6 class="text-h6 mt-6 mb-2">Field activities <VChip size="x-small">{{ activities.length }}</VChip></h6>
    <VCard v-for="a in activities" :key="a.id" class="mb-3">
      <VCardItem>
        <VCardTitle class="text-wrap">{{ a.title }}</VCardTitle>
        <VCardSubtitle>{{ human(a.type) }} · {{ a.farm }}<template v-if="a.field"> · {{ a.field }}</template> · {{ a.planned_on }}</VCardSubtitle>
      </VCardItem>
      <VCardText v-if="a.inputs.length"><div v-for="(i, k) in a.inputs" :key="k" class="text-body-2"><VIcon icon="tabler-package" size="14" /> {{ i }}</div></VCardText>
      <VCardActions>
        <VBtn v-if="a.status === 'planned'" size="large" variant="tonal" prepend-icon="tabler-player-play" @click="startActivity(a)">Start</VBtn>
        <VSpacer />
        <VBtn size="large" color="success" variant="flat" prepend-icon="tabler-check" @click="open('complete', a)">Complete</VBtn>
      </VCardActions>
    </VCard>
    <VCard v-if="!activities.length"><VCardText class="text-center text-medium-emphasis">No field activities assigned to you.</VCardText></VCard>

    <template v-if="target">
      <FormDialog :model-value="dialog === 'submit'" :title="`Done: ${target.title}`" :url="route('agri.tasks.transition', target.id)" :values="{ action: 'submit' }" submit-label="Submit"
        :fields="[{ key: 'labour_hours', label: 'Hours spent', type: 'number', cols: 12 }, { key: 'completion_notes', label: 'What was done', type: 'textarea', cols: 12 }]" @update:model-value="close" />
      <FormDialog :model-value="dialog === 'complete'" :title="`Complete: ${target.title}`" :url="route('agri.crops.activities.status', target.id)" :values="{ action: 'complete', completed_at: today() }" submit-label="Complete"
        :fields="[{ key: 'area_covered', label: 'Area covered (ha)', type: 'number', cols: 6 }, { key: 'labour_hours', label: 'Hours', type: 'number', cols: 6 }, { key: 'machine_hours', label: 'Machine hours', type: 'number', cols: 6 }, { key: 'weather', label: 'Weather', cols: 6 }, { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 }]"
        @update:model-value="close" />
    </template>
    <FormDialog :model-value="dialog === 'observe'" title="Report what you saw" :url="route('agri.crops.observations.store')" :values="{ farm_id: farms[0]?.id, observed_on: today(), severity: 'medium' }"
      :fields="[
        { key: 'farm_id', label: 'Farm', type: 'select', required: true, items: farms.map(f => ({ title: f.name, value: f.id })), cols: 12 },
        { key: 'field_id', label: 'Field / camp', type: 'select', items: (f: any) => (farms.find(x => x.id === f.farm_id)?.fields ?? []).map(x => ({ title: x.code + ' · ' + x.name, value: x.id })), cols: 12 },
        { key: 'kind', label: 'What', type: 'select', required: true, items: ['pest', 'disease', 'weed', 'growth_stage', 'nutrient', 'water_stress', 'weather_damage', 'other'] },
        { key: 'severity', label: 'How bad', type: 'select', required: true, items: ['low', 'medium', 'high', 'critical'] },
        { key: 'description', label: 'Describe it', type: 'textarea', required: true, cols: 12 },
        { key: 'observed_on', label: 'Date', type: 'date', required: true, cols: 12 },
      ]" @update:model-value="close" />
  </div>
</template>
