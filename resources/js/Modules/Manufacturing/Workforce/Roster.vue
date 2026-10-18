<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  week: string
  days: string[]
  entries: Array<{ id: number; user_id: number; date: string; shift: string | null; work_centre: string | null; overtime_minutes: number }>
  people: Array<{ id: number; name: string }>
  shifts: Array<{ id: number; name: string }>
  work_centres: Array<{ id: number; name: string }>
  gaps: Array<{ work_centre: string; date: string; needed: number; rostered: number; gap: number }>
  can: { manage: boolean }
}>()

const go = (offset: number) => {
  const d = new Date(props.week); d.setDate(d.getDate() + offset * 7)
  router.get(route('manufacturing.workforce.roster'), { week: d.toISOString().slice(0, 10) }, { preserveState: true })
}
const rostered = computed(() => props.people.filter(p => props.entries.some(e => e.user_id === p.id)))
const cell = (userId: number, date: string) => props.entries.filter(e => e.user_id === userId && e.date === date)
const remove = (id: number) => router.delete(route('manufacturing.workforce.roster.destroy', id), { preserveScroll: true })

const dialog = ref(false)
const form = useForm({ user_id: null as number | null, shift_id: props.shifts[0]?.id ?? null, work_centre_id: null as number | null, dates: [] as string[], overtime_minutes: 0 })
const save = () => form.post(route('manufacturing.workforce.roster.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset() } })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.labour.roster_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.labour.roster_subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 align-center">
        <VBtn icon variant="text" @click="go(-1)"><VIcon icon="tabler-chevron-left" /></VBtn>
        <span class="text-body-1">{{ t('manufacturing.labour.week_of', { d: week }) }}</span>
        <VBtn icon variant="text" @click="go(1)"><VIcon icon="tabler-chevron-right" /></VBtn>
        <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('manufacturing.labour.add_to_roster') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="gaps.length" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-medium mb-1">{{ t('manufacturing.labour.gaps_n', { n: gaps.length }) }}</div>
      <div v-for="g in gaps" :key="g.work_centre + g.date" class="text-body-2">{{ g.date }} · {{ g.work_centre }}: {{ t('manufacturing.labour.gap_line', { need: g.needed, have: g.rostered, gap: g.gap }) }}</div>
    </VAlert>

    <VCard border>
      <div class="overflow-x-auto">
        <VTable density="compact">
          <thead><tr><th>{{ t('manufacturing.labour.person') }}</th><th v-for="d in days" :key="d" class="text-center">{{ d.slice(5) }}</th></tr></thead>
          <tbody>
            <tr v-for="p in rostered" :key="p.id">
              <td class="text-no-wrap">{{ p.name }}</td>
              <td v-for="d in days" :key="d" class="text-center">
                <VChip v-for="e in cell(p.id, d)" :key="e.id" size="x-small" label class="ma-1" :closable="can.manage" @click:close="remove(e.id)">
                  {{ e.shift }}<span v-if="e.work_centre">&nbsp;· {{ e.work_centre }}</span><span v-if="e.overtime_minutes">&nbsp;+{{ e.overtime_minutes }}</span>
                </VChip>
              </td>
            </tr>
            <tr v-if="!rostered.length"><td :colspan="days.length + 1" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.labour.nobody_rostered') }}</td></tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VDialog v-model="dialog" max-width="520">
      <VCard :title="t('manufacturing.labour.add_to_roster')">
        <VCardText>
          <VAutocomplete v-model="form.user_id" :items="people" item-value="id" item-title="name" :error-messages="form.errors.user_id" :label="t('manufacturing.labour.person')" class="mb-2" />
          <VSelect v-model="form.shift_id" :items="shifts" item-value="id" item-title="name" :label="t('manufacturing.labour.shift')" class="mb-2" />
          <VSelect v-model="form.work_centre_id" :items="work_centres" item-value="id" item-title="name" clearable :label="t('manufacturing.floor.work_centre')" class="mb-2" />
          <VSelect v-model="form.dates" :items="days" multiple chips :error-messages="form.errors.dates" :label="t('manufacturing.labour.days')" class="mb-2" />
          <VTextField v-model.number="form.overtime_minutes" type="number" min="0" :label="t('manufacturing.labour.overtime_minutes')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.user_id || !form.dates.length" @click="save">{{ t('manufacturing.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
