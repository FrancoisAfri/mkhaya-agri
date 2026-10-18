<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Category { id: number; name: string; code: string|null; description: string|null; severity_level: string; active: boolean }
interface Status    { id: number; name: string; color: string; is_closed: boolean; sort_order: number; active: boolean }
interface Outcome   { id: number; name: string; description: string|null; requires_sanction: boolean; active: boolean }
interface Sanction  { id: number; name: string; description: string|null; severity_level: string; duration_days: number|null; active: boolean }
interface Setting   { id: number; key: string; value: string|null; description: string|null }

const props = defineProps<{
  categories: Category[]
  statuses:   Status[]
  outcomes:   Outcome[]
  sanctions:  Sanction[]
  settings:   Setting[]
}>()

const tab = ref('categories')

// ── Shared delete dialog ───────────────────────────────────────────────────────
const deleteDialog    = ref(false)
const deleteItemLabel = ref('')
const deleteAction    = ref<() => void>(() => {})

function confirmDel(label: string, action: () => void) {
  deleteItemLabel.value = label
  deleteAction.value    = action
  deleteDialog.value    = true
}

function executeDel() {
  deleteAction.value()
  deleteDialog.value = false
}

const severityOptions = [
  { title: 'Minor',   value: 'minor' },
  { title: 'Serious', value: 'serious' },
  { title: 'Gross',   value: 'gross' },
]
const colorOptions    = ['primary', 'secondary', 'success', 'warning', 'error', 'info', 'teal', 'deep-purple', 'grey']

// ── Category form ──────────────────────────────────────────────────────────────
const catDrawer   = ref(false)
const catEdit     = ref<Category | null>(null)
const catForm     = useForm({ name: '', code: '', description: '', severity_level: 'minor', active: true })

function openCatDrawer(cat?: Category) {
  catEdit.value = cat ?? null
  catForm.reset()
  if (cat) {
    catForm.name = cat.name; catForm.code = cat.code ?? ''; catForm.description = cat.description ?? ''
    catForm.severity_level = cat.severity_level; catForm.active = cat.active
  }
  catDrawer.value = true
}

function saveCategory() {
  const url = catEdit.value
    ? route('disciplinary.setup.categories.update', catEdit.value.id)
    : route('disciplinary.setup.categories.store')
  const method = catEdit.value ? 'put' : 'post'
  catForm[method](url, { onSuccess: () => { catDrawer.value = false } })
}

function deleteCategory(id: number, name: string) {
  confirmDel(name, () => useForm({}).delete(route('disciplinary.setup.categories.destroy', id)))
}

// ── Status form ────────────────────────────────────────────────────────────────
const statusDrawer = ref(false)
const statusEdit   = ref<Status | null>(null)
const statusForm   = useForm({ name: '', color: 'primary', is_closed: false, sort_order: 0, active: true })

function openStatusDrawer(s?: Status) {
  statusEdit.value = s ?? null
  statusForm.reset()
  if (s) {
    statusForm.name = s.name; statusForm.color = s.color; statusForm.is_closed = s.is_closed
    statusForm.sort_order = s.sort_order; statusForm.active = s.active
  }
  statusDrawer.value = true
}

function saveStatus() {
  const url = statusEdit.value
    ? route('disciplinary.setup.statuses.update', statusEdit.value.id)
    : route('disciplinary.setup.statuses.store')
  const method = statusEdit.value ? 'put' : 'post'
  statusForm[method](url, { onSuccess: () => { statusDrawer.value = false } })
}

function deleteStatus(id: number, name: string) {
  confirmDel(name, () => useForm({}).delete(route('disciplinary.setup.statuses.destroy', id)))
}

// ── Outcome form ───────────────────────────────────────────────────────────────
const outcomeDrawer = ref(false)
const outcomeEdit   = ref<Outcome | null>(null)
const outcomeForm   = useForm({ name: '', description: '', requires_sanction: false, active: true })

function openOutcomeDrawer(o?: Outcome) {
  outcomeEdit.value = o ?? null
  outcomeForm.reset()
  if (o) {
    outcomeForm.name = o.name; outcomeForm.description = o.description ?? ''
    outcomeForm.requires_sanction = o.requires_sanction; outcomeForm.active = o.active
  }
  outcomeDrawer.value = true
}

function saveOutcome() {
  const url = outcomeEdit.value
    ? route('disciplinary.setup.outcomes.update', outcomeEdit.value.id)
    : route('disciplinary.setup.outcomes.store')
  const method = outcomeEdit.value ? 'put' : 'post'
  outcomeForm[method](url, { onSuccess: () => { outcomeDrawer.value = false } })
}

function deleteOutcome(id: number, name: string) {
  confirmDel(name, () => useForm({}).delete(route('disciplinary.setup.outcomes.destroy', id)))
}

// ── Sanction form ──────────────────────────────────────────────────────────────
const sanctionDrawer = ref(false)
const sanctionEdit   = ref<Sanction | null>(null)
const sanctionForm   = useForm({ name: '', description: '', severity_level: 'minor', duration_days: null as number|null, active: true })

function openSanctionDrawer(s?: Sanction) {
  sanctionEdit.value = s ?? null
  sanctionForm.reset()
  if (s) {
    sanctionForm.name = s.name; sanctionForm.description = s.description ?? ''
    sanctionForm.severity_level = s.severity_level; sanctionForm.duration_days = s.duration_days
    sanctionForm.active = s.active
  }
  sanctionDrawer.value = true
}

function saveSanction() {
  const url = sanctionEdit.value
    ? route('disciplinary.setup.sanctions.update', sanctionEdit.value.id)
    : route('disciplinary.setup.sanctions.store')
  const method = sanctionEdit.value ? 'put' : 'post'
  sanctionForm[method](url, { onSuccess: () => { sanctionDrawer.value = false } })
}

function deleteSanction(id: number, name: string) {
  confirmDel(name, () => useForm({}).delete(route('disciplinary.setup.sanctions.destroy', id)))
}

// ── Settings form ──────────────────────────────────────────────────────────────
const settingsForm = useForm({
  settings: props.settings.map(s => ({ key: s.key, value: s.value ?? '' })),
})

function saveSettings() {
  settingsForm.post(route('disciplinary.setup.settings.save'))
}

const severityColor = (s: string) => ({ minor: 'success', serious: 'warning', gross: 'error' })[s] ?? 'grey'
const cap = (s: string | null | undefined) => s ? s.charAt(0).toUpperCase() + s.slice(1) : '—'
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary.setup') }}</h1>
      </div>
      <VBtn variant="outlined" prepend-icon="tabler-layout-dashboard" :href="route('disciplinary.dashboard')">{{ t('disciplinary.dashboard') }}</VBtn>
    </div>

    <!-- Tabs -->
    <VCard>
      <VTabs v-model="tab" color="primary">
        <VTab value="categories">{{ t('disciplinary.categories') }}</VTab>
        <VTab value="statuses">{{ t('disciplinary.statuses') }}</VTab>
        <VTab value="outcomes">{{ t('disciplinary.outcomes') }}</VTab>
        <VTab value="sanctions">{{ t('disciplinary.sanctions') }}</VTab>
        <VTab value="settings">{{ t('disciplinary.settings') }}</VTab>
      </VTabs>
      <VDivider />

      <VTabsWindow v-model="tab">

        <!-- ── Categories ──────────────────────────────────────────────────── -->
        <VTabsWindowItem value="categories">
          <VCardText>
            <div class="d-flex justify-end mb-3">
              <VBtn color="primary" prepend-icon="tabler-plus" @click="openCatDrawer()">{{ t('disciplinary.add_category') }}</VBtn>
            </div>
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('disciplinary.name') }}</th>
                  <th>{{ t('disciplinary.code') }}</th>
                  <th>{{ t('disciplinary.severity') }}</th>
                  <th>{{ t('disciplinary.active') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="cat in categories" :key="cat.id">
                  <td>{{ cat.name }}</td>
                  <td>{{ cat.code ?? '—' }}</td>
                  <td><VChip :color="severityColor(cat.severity_level)" size="x-small">{{ cap(cat.severity_level) }}</VChip></td>
                  <td><VChip :color="cat.active ? 'success' : 'error'" size="x-small">{{ cat.active ? t('disciplinary.yes') : t('disciplinary.no') }}</VChip></td>
                  <td class="text-right">
                    <VBtn icon="tabler-pencil" size="x-small" variant="text" @click="openCatDrawer(cat)" />
                    <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteCategory(cat.id, cat.name)" />
                  </td>
                </tr>
                <tr v-if="!categories.length">
                  <td colspan="5" class="text-center text-medium-emphasis py-4">{{ t('disciplinary.no_data') }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── Statuses ────────────────────────────────────────────────────── -->
        <VTabsWindowItem value="statuses">
          <VCardText>
            <div class="d-flex justify-end mb-3">
              <VBtn color="primary" prepend-icon="tabler-plus" @click="openStatusDrawer()">{{ t('disciplinary.add_status') }}</VBtn>
            </div>
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('disciplinary.name') }}</th>
                  <th>{{ t('disciplinary.color') }}</th>
                  <th>{{ t('disciplinary.is_closed') }}</th>
                  <th>{{ t('disciplinary.sort_order') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in statuses" :key="s.id">
                  <td>{{ s.name }}</td>
                  <td><VChip :color="s.color" size="x-small">{{ cap(s.color) }}</VChip></td>
                  <td><VChip :color="s.is_closed ? 'grey' : 'success'" size="x-small">{{ s.is_closed ? t('disciplinary.yes') : t('disciplinary.no') }}</VChip></td>
                  <td>{{ s.sort_order }}</td>
                  <td class="text-right">
                    <VBtn icon="tabler-pencil" size="x-small" variant="text" @click="openStatusDrawer(s)" />
                    <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteStatus(s.id, s.name)" />
                  </td>
                </tr>
                <tr v-if="!statuses.length">
                  <td colspan="5" class="text-center text-medium-emphasis py-4">{{ t('disciplinary.no_data') }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── Outcomes ────────────────────────────────────────────────────── -->
        <VTabsWindowItem value="outcomes">
          <VCardText>
            <div class="d-flex justify-end mb-3">
              <VBtn color="primary" prepend-icon="tabler-plus" @click="openOutcomeDrawer()">{{ t('disciplinary.add_outcome') }}</VBtn>
            </div>
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('disciplinary.name') }}</th>
                  <th>{{ t('disciplinary.requires_sanction') }}</th>
                  <th>{{ t('disciplinary.active') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="o in outcomes" :key="o.id">
                  <td>{{ o.name }}</td>
                  <td><VChip :color="o.requires_sanction ? 'warning' : 'grey'" size="x-small">{{ o.requires_sanction ? t('disciplinary.yes') : t('disciplinary.no') }}</VChip></td>
                  <td><VChip :color="o.active ? 'success' : 'error'" size="x-small">{{ o.active ? t('disciplinary.yes') : t('disciplinary.no') }}</VChip></td>
                  <td class="text-right">
                    <VBtn icon="tabler-pencil" size="x-small" variant="text" @click="openOutcomeDrawer(o)" />
                    <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteOutcome(o.id, o.name)" />
                  </td>
                </tr>
                <tr v-if="!outcomes.length">
                  <td colspan="4" class="text-center text-medium-emphasis py-4">{{ t('disciplinary.no_data') }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── Sanctions ───────────────────────────────────────────────────── -->
        <VTabsWindowItem value="sanctions">
          <VCardText>
            <div class="d-flex justify-end mb-3">
              <VBtn color="primary" prepend-icon="tabler-plus" @click="openSanctionDrawer()">{{ t('disciplinary.add_sanction') }}</VBtn>
            </div>
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('disciplinary.name') }}</th>
                  <th>{{ t('disciplinary.severity') }}</th>
                  <th>{{ t('disciplinary.duration_days') }}</th>
                  <th>{{ t('disciplinary.active') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in sanctions" :key="s.id">
                  <td>{{ s.name }}</td>
                  <td><VChip :color="severityColor(s.severity_level)" size="x-small">{{ cap(s.severity_level) }}</VChip></td>
                  <td>{{ s.duration_days ?? '—' }}</td>
                  <td><VChip :color="s.active ? 'success' : 'error'" size="x-small">{{ s.active ? t('disciplinary.yes') : t('disciplinary.no') }}</VChip></td>
                  <td class="text-right">
                    <VBtn icon="tabler-pencil" size="x-small" variant="text" @click="openSanctionDrawer(s)" />
                    <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteSanction(s.id, s.name)" />
                  </td>
                </tr>
                <tr v-if="!sanctions.length">
                  <td colspan="5" class="text-center text-medium-emphasis py-4">{{ t('disciplinary.no_data') }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── Settings ────────────────────────────────────────────────────── -->
        <VTabsWindowItem value="settings">
          <VCardText>
            <VRow>
              <VCol v-for="(item, i) in settingsForm.settings" :key="item.key" cols="12" md="6">
                <VTextField v-model="settingsForm.settings[i].value" :label="item.key" variant="outlined" density="compact" />
              </VCol>
              <VCol v-if="!settingsForm.settings.length" cols="12" class="text-medium-emphasis text-body-2">
                {{ t('disciplinary.no_settings') }}
              </VCol>
            </VRow>
            <VBtn v-if="settingsForm.settings.length" color="primary" :loading="settingsForm.processing" @click="saveSettings">
              {{ t('disciplinary.save_settings') }}
            </VBtn>
          </VCardText>
        </VTabsWindowItem>

      </VTabsWindow>
    </VCard>

    <!-- ── Category Drawer ──────────────────────────────────────────────────── -->
    <VNavigationDrawer v-model="catDrawer" location="right" width="420" temporary>
      <VToolbar density="compact" color="primary" :title="catEdit ? t('disciplinary.edit_category') : t('disciplinary.add_category')" />
      <div class="pa-4 d-flex flex-column ga-3">
        <VTextField v-model="catForm.name" :label="t('disciplinary.name')" variant="outlined" density="compact" :error-messages="catForm.errors.name" />
        <VTextField v-model="catForm.code" :label="t('disciplinary.code')" variant="outlined" density="compact" :error-messages="catForm.errors.code" />
        <VTextarea v-model="catForm.description" :label="t('disciplinary.description')" variant="outlined" density="compact" rows="3" />
        <VSelect v-model="catForm.severity_level" :items="severityOptions" item-title="title" item-value="value" :label="t('disciplinary.severity')" variant="outlined" density="compact" />
        <VSwitch v-model="catForm.active" :label="t('disciplinary.active')" color="primary" inset />
        <div class="d-flex ga-2 justify-end mt-2">
          <VBtn variant="outlined" @click="catDrawer = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="catForm.processing" @click="saveCategory">{{ t('disciplinary.save') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>

    <!-- ── Status Drawer ────────────────────────────────────────────────────── -->
    <VNavigationDrawer v-model="statusDrawer" location="right" width="420" temporary>
      <VToolbar density="compact" color="primary" :title="statusEdit ? t('disciplinary.edit_status') : t('disciplinary.add_status')" />
      <div class="pa-4 d-flex flex-column ga-3">
        <VTextField v-model="statusForm.name" :label="t('disciplinary.name')" variant="outlined" density="compact" :error-messages="statusForm.errors.name" />
        <VSelect v-model="statusForm.color" :items="colorOptions" :label="t('disciplinary.color')" variant="outlined" density="compact" />
        <VTextField v-model.number="statusForm.sort_order" :label="t('disciplinary.sort_order')" variant="outlined" density="compact" type="number" />
        <VSwitch v-model="statusForm.is_closed" :label="t('disciplinary.is_closed')" color="grey" inset />
        <VSwitch v-model="statusForm.active" :label="t('disciplinary.active')" color="primary" inset />
        <div class="d-flex ga-2 justify-end mt-2">
          <VBtn variant="outlined" @click="statusDrawer = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="statusForm.processing" @click="saveStatus">{{ t('disciplinary.save') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>

    <!-- ── Outcome Drawer ───────────────────────────────────────────────────── -->
    <VNavigationDrawer v-model="outcomeDrawer" location="right" width="420" temporary>
      <VToolbar density="compact" color="primary" :title="outcomeEdit ? t('disciplinary.edit_outcome') : t('disciplinary.add_outcome')" />
      <div class="pa-4 d-flex flex-column ga-3">
        <VTextField v-model="outcomeForm.name" :label="t('disciplinary.name')" variant="outlined" density="compact" :error-messages="outcomeForm.errors.name" />
        <VTextarea v-model="outcomeForm.description" :label="t('disciplinary.description')" variant="outlined" density="compact" rows="3" />
        <VSwitch v-model="outcomeForm.requires_sanction" :label="t('disciplinary.requires_sanction')" color="warning" inset />
        <VSwitch v-model="outcomeForm.active" :label="t('disciplinary.active')" color="primary" inset />
        <div class="d-flex ga-2 justify-end mt-2">
          <VBtn variant="outlined" @click="outcomeDrawer = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="outcomeForm.processing" @click="saveOutcome">{{ t('disciplinary.save') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>

    <!-- ── Delete Confirmation Dialog ─────────────────────────────────────── -->
    <VDialog v-model="deleteDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4">{{ t('disciplinary.delete_entry') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          {{ t('disciplinary.delete_confirm_prefix') }} <strong>{{ deleteItemLabel }}</strong>? {{ t('disciplinary.delete_confirm_suffix') }}
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="deleteDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" @click="executeDel">{{ t('disciplinary.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Sanction Drawer ──────────────────────────────────────────────────── -->
    <VNavigationDrawer v-model="sanctionDrawer" location="right" width="420" temporary>
      <VToolbar density="compact" color="primary" :title="sanctionEdit ? t('disciplinary.edit_sanction') : t('disciplinary.add_sanction')" />
      <div class="pa-4 d-flex flex-column ga-3">
        <VTextField v-model="sanctionForm.name" :label="t('disciplinary.name')" variant="outlined" density="compact" :error-messages="sanctionForm.errors.name" />
        <VTextarea v-model="sanctionForm.description" :label="t('disciplinary.description')" variant="outlined" density="compact" rows="3" />
        <VSelect v-model="sanctionForm.severity_level" :items="severityOptions" item-title="title" item-value="value" :label="t('disciplinary.severity')" variant="outlined" density="compact" />
        <VTextField v-model.number="sanctionForm.duration_days" :label="t('disciplinary.duration_days')" variant="outlined" density="compact" type="number" />
        <VSwitch v-model="sanctionForm.active" :label="t('disciplinary.active')" color="primary" inset />
        <div class="d-flex ga-2 justify-end mt-2">
          <VBtn variant="outlined" @click="sanctionDrawer = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="sanctionForm.processing" @click="saveSanction">{{ t('disciplinary.save') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>
  </div>
</template>
