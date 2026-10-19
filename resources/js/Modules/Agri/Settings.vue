<script setup lang="ts">
import { ref } from 'vue'
import { route } from 'ziggy-js'
import PageErrors from './Components/PageErrors.vue'
import FormDialog, { type FormField } from './Components/FormDialog.vue'
import { human, statusColor } from './Components/agri'

const props = defineProps<{
  crop_types: any[]; varieties: any[]; seasons: any[]; species: any[]; breeds: any[]
  options: { crop_categories: string[]; yield_units: string[]; tracking: string[] }
  can: { manage: boolean }
}>()
const tab = ref('crops')
const dialog = ref<null | { kind: string; row: any | null }>(null)
const open = (kind: string, row: any = null) => { dialog.value = { kind, row } }

const fields: Record<string, FormField[]> = {
  crop_types: [
    { key: 'code', label: 'Code', required: true, cols: 4 }, { key: 'name', label: 'Name', required: true, cols: 8 },
    { key: 'category', label: 'Category', type: 'select', required: true, items: props.options.crop_categories },
    { key: 'yield_unit', label: 'Yield unit', type: 'select', required: true, items: props.options.yield_units },
    { key: 'is_active', label: 'In use', type: 'switch', default: true },
  ],
  varieties: [
    { key: 'crop_type_id', label: 'Crop', type: 'select', required: true, items: props.crop_types.map(c => ({ title: c.name, value: c.id })) },
    { key: 'name', label: 'Variety / cultivar', required: true }, { key: 'days_to_maturity', label: 'Days to maturity', type: 'number' },
    { key: 'is_active', label: 'In use', type: 'switch', default: true },
  ],
  seasons: [
    { key: 'code', label: 'Code', required: true, cols: 4 }, { key: 'name', label: 'Name', required: true, cols: 8 },
    { key: 'starts_on', label: 'Starts', type: 'date', required: true }, { key: 'ends_on', label: 'Ends', type: 'date', required: true },
    { key: 'status', label: 'Status', type: 'select', required: true, items: ['open', 'closed'], default: 'open' },
  ],
  species: [
    { key: 'code', label: 'Code', required: true, cols: 4 }, { key: 'name', label: 'Name', required: true, cols: 8 },
    { key: 'tracking', label: 'Tracked', type: 'select', required: true, items: [{ title: 'Individually (tags)', value: 'individual' }, { title: 'As a flock (head count)', value: 'group' }] },
    { key: 'gestation_days', label: 'Gestation (days)', type: 'number' }, { key: 'is_active', label: 'In use', type: 'switch', default: true },
  ],
  breeds: [
    { key: 'species_id', label: 'Species', type: 'select', required: true, items: props.species.map(s => ({ title: s.name, value: s.id })) },
    { key: 'name', label: 'Breed', required: true }, { key: 'is_active', label: 'In use', type: 'switch', default: true },
  ],
}
const titles: Record<string, string> = { crop_types: 'crop', varieties: 'variety', seasons: 'season', species: 'species', breeds: 'breed' }
</script>

<template>
  <div>
    <PageErrors />
    <div class="mb-6">
      <h4 class="text-h4 mb-1">Farm Settings</h4>
      <p class="text-body-1 text-medium-emphasis mb-0">Your organisation's crops, cultivars, seasons, species and breeds — shared by every farm. A starting catalogue for Southern Africa is installed; change it as you farm.</p>
    </div>
    <VCard>
      <VTabs v-model="tab"><VTab value="crops">Crops &amp; varieties</VTab><VTab value="seasons">Seasons</VTab><VTab value="livestock">Species &amp; breeds</VTab></VTabs>
      <VDivider />
      <VWindow v-model="tab">
        <VWindowItem value="crops">
          <VRow class="pa-4">
            <VCol cols="12" md="6">
              <div class="d-flex justify-space-between mb-2"><h6 class="text-h6">Crops</h6><VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="open('crop_types')">Crop</VBtn></div>
              <VTable density="compact"><tbody>
                <tr v-for="c in crop_types" :key="c.id" style="cursor: pointer" @click="can.manage && open('crop_types', c)">
                  <td>{{ c.name }}<div class="text-caption">{{ c.code }}</div></td><td>{{ human(c.category) }}</td><td>{{ c.yield_unit }}</td><td>{{ c.varieties_count }} varieties</td>
                  <td><VChip v-if="!c.is_active" size="x-small">Not in use</VChip></td>
                </tr>
              </tbody></VTable>
            </VCol>
            <VCol cols="12" md="6">
              <div class="d-flex justify-space-between mb-2"><h6 class="text-h6">Varieties</h6><VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="open('varieties')">Variety</VBtn></div>
              <VTable density="compact"><tbody>
                <tr v-for="v in varieties" :key="v.id" style="cursor: pointer" @click="can.manage && open('varieties', v)"><td>{{ v.name }}</td><td>{{ v.crop_type?.name }}</td><td>{{ v.days_to_maturity ? v.days_to_maturity + ' days' : '' }}</td></tr>
              </tbody></VTable>
            </VCol>
          </VRow>
        </VWindowItem>
        <VWindowItem value="seasons">
          <div class="pa-4">
            <div class="d-flex justify-end mb-2"><VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="open('seasons')">Season</VBtn></div>
            <VTable density="comfortable"><thead><tr><th>Season</th><th>From</th><th>To</th><th>Status</th></tr></thead><tbody>
              <tr v-for="s in seasons" :key="s.id" style="cursor: pointer" @click="can.manage && open('seasons', s)"><td>{{ s.name }}<div class="text-caption">{{ s.code }}</div></td><td>{{ s.starts_on }}</td><td>{{ s.ends_on }}</td>
                <td><VChip :color="statusColor(s.status)" size="small" variant="tonal">{{ human(s.status) }}</VChip></td></tr>
            </tbody></VTable>
          </div>
        </VWindowItem>
        <VWindowItem value="livestock">
          <VRow class="pa-4">
            <VCol cols="12" md="6">
              <div class="d-flex justify-space-between mb-2"><h6 class="text-h6">Species</h6><VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="open('species')">Species</VBtn></div>
              <VTable density="compact"><tbody>
                <tr v-for="s in species" :key="s.id" style="cursor: pointer" @click="can.manage && open('species', s)"><td>{{ s.name }}</td><td>{{ s.tracking === 'group' ? 'Flock count' : 'Individual tags' }}</td><td>{{ s.breeds_count }} breeds</td></tr>
              </tbody></VTable>
            </VCol>
            <VCol cols="12" md="6">
              <div class="d-flex justify-space-between mb-2"><h6 class="text-h6">Breeds</h6><VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="open('breeds')">Breed</VBtn></div>
              <VTable density="compact"><tbody>
                <tr v-for="b in breeds" :key="b.id" style="cursor: pointer" @click="can.manage && open('breeds', b)"><td>{{ b.name }}</td><td>{{ b.species?.name }}</td></tr>
              </tbody></VTable>
            </VCol>
          </VRow>
        </VWindowItem>
      </VWindow>
    </VCard>

    <FormDialog v-if="dialog" :model-value="!!dialog" :title="(dialog.row ? 'Edit ' : 'Add ') + titles[dialog.kind]" :fields="fields[dialog.kind]" :values="dialog.row ?? {}"
      :url="dialog.row ? route('agri.settings.update', [dialog.kind, dialog.row.uuid]) : route('agri.settings.store', dialog.kind)" :method="dialog.row ? 'patch' : 'post'"
      @update:model-value="v => !v && (dialog = null)" />
  </div>
</template>
