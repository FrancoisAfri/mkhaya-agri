<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router, Link } from '@inertiajs/vue3'

interface Target { id: number; type: string; name: string; url: string; anchors: string[]; active: boolean; sort: number }
interface LinkRow { id: number; anchor: string; url: string; site: string; article: string | null; placed_at: string | null }
const props = defineProps<{ targets: Target[]; stats: { total_links: number; sites_reached: number; diversity: number | null; authority: number }; links: LinkRow[] }>()

const dialog = ref(false)
const editing = ref<Target | null>(null)
const form = useForm({ type: 'service', name: '', url: '', anchors: [] as string[], active: true, sort: 0 })

function openCreate() { editing.value = null; form.reset(); form.clearErrors(); dialog.value = true }
function openEdit(t: Target) {
  editing.value = t
  form.type = t.type; form.name = t.name; form.url = t.url; form.anchors = [...(t.anchors || [])]; form.active = t.active; form.sort = t.sort
  form.clearErrors(); dialog.value = true
}
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  editing.value ? form.put(`/platform/mkhaya-promo/${editing.value.id}`, opts) : form.post('/platform/mkhaya-promo', opts)
}
function toggle(t: Target) { router.patch(`/platform/mkhaya-promo/${t.id}/toggle`, {}, { preserveScroll: true }) }
function remove(t: Target) { if (confirm(`Remove "${t.name}"?`)) router.delete(`/platform/mkhaya-promo/${t.id}`, { preserveScroll: true }) }

const scoreColor = (s: number | null) => s == null ? 'grey' : s >= 66 ? 'success' : s >= 33 ? 'warning' : 'error'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">Mkhaya Promotion</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">Manage the products &amp; services advertised across the Authority Network, and verify placed links.</p>
      </div>
      <Link href="/platform/overview"><VBtn variant="text" prepend-icon="mdi-arrow-left">Platform</VBtn></Link>
    </div>

    <!-- Stats -->
    <VRow class="mb-1">
      <VCol cols="12" md="3">
        <VCard elevation="0" border rounded="lg" class="text-center">
          <VCardText>
            <VProgressCircular :model-value="stats.authority" :size="88" :width="9" :color="scoreColor(stats.authority)">
              <span class="text-h6 font-weight-bold">{{ stats.authority }}</span>
            </VProgressCircular>
            <div class="text-body-2 font-weight-semibold mt-2">Mkhaya Authority</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="9">
        <VRow>
          <VCol cols="6" md="4"><VCard elevation="0" border rounded="lg"><VCardText><div class="text-h4 font-weight-bold">{{ stats.total_links }}</div><div class="text-caption text-medium-emphasis">Links published</div></VCardText></VCard></VCol>
          <VCol cols="6" md="4"><VCard elevation="0" border rounded="lg"><VCardText><div class="text-h4 font-weight-bold">{{ stats.sites_reached }}</div><div class="text-caption text-medium-emphasis">Sites reached</div></VCardText></VCard></VCol>
          <VCol cols="6" md="4"><VCard elevation="0" border rounded="lg"><VCardText><div class="text-h4 font-weight-bold">{{ stats.diversity != null ? stats.diversity + '%' : '—' }}</div><div class="text-caption text-medium-emphasis">Anchor diversity</div></VCardText></VCard></VCol>
        </VRow>
      </VCol>
    </VRow>

    <!-- Targets -->
    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardTitle class="d-flex align-center justify-space-between">
        <span class="text-body-1 font-weight-semibold">Advertised products &amp; services</span>
        <VBtn size="small" color="primary" prepend-icon="mdi-plus" @click="openCreate">Add</VBtn>
      </VCardTitle>
      <VDivider />
      <VTable density="comfortable">
        <thead><tr><th>Name</th><th>Type</th><th>Landing URL</th><th>Anchors</th><th>Active</th><th></th></tr></thead>
        <tbody>
          <tr v-for="t in targets" :key="t.id">
            <td class="font-weight-medium">{{ t.name }}</td>
            <td><VChip size="x-small" variant="tonal" class="text-capitalize">{{ t.type }}</VChip></td>
            <td><a :href="t.url" target="_blank" rel="noopener" class="text-primary text-decoration-none">{{ t.url }}</a></td>
            <td><VChip v-for="(a, i) in t.anchors" :key="i" size="x-small" variant="tonal" class="me-1 mb-1">{{ a }}</VChip></td>
            <td><VSwitch :model-value="t.active" density="compact" hide-details color="success" @update:model-value="toggle(t)" /></td>
            <td class="text-end">
              <VBtn icon="mdi-pencil" size="x-small" variant="text" color="grey" @click="openEdit(t)" />
              <VBtn icon="mdi-delete" size="x-small" variant="text" color="grey" @click="remove(t)" />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Published links -->
    <VCard elevation="0" border rounded="lg">
      <VCardTitle class="text-body-1 font-weight-semibold">Published Mkhaya links — visit to verify</VCardTitle>
      <VDivider />
      <VTable v-if="links.length" density="comfortable">
        <thead><tr><th>Site</th><th>Article</th><th>Anchor</th><th>Landing</th><th>Placed</th></tr></thead>
        <tbody>
          <tr v-for="l in links" :key="l.id">
            <td>{{ l.site }}</td>
            <td class="text-truncate" style="max-width:260px">{{ l.article || '—' }}</td>
            <td>{{ l.anchor }}</td>
            <td><a :href="l.url" target="_blank" rel="noopener" class="text-primary"><VIcon size="16">mdi-open-in-new</VIcon></a></td>
            <td class="text-caption text-medium-emphasis">{{ l.placed_at }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-center py-8 text-medium-emphasis">No Mkhaya links published yet.</VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="520">
      <VCard rounded="lg">
        <VCardTitle class="text-body-1 font-weight-semibold">{{ editing ? 'Edit' : 'Add' }} promotion target</VCardTitle>
        <VDivider />
        <VCardText>
          <VSelect v-model="form.type" :items="[{title:'Product',value:'product'},{title:'Service',value:'service'}]" label="Type" density="comfortable" />
          <VTextField v-model="form.name" label="Name" density="comfortable" :error-messages="form.errors.name" />
          <VTextField v-model="form.url" label="Landing URL" placeholder="https://mkhayamk.co.za/…" density="comfortable" :error-messages="form.errors.url" />
          <VCombobox v-model="form.anchors" label="Anchors (rotated automatically)" multiple chips closable-chips density="comfortable" />
          <div class="d-flex align-center ga-4">
            <VSwitch v-model="form.active" label="Active" color="success" density="compact" hide-details />
            <VTextField v-model.number="form.sort" type="number" label="Sort" density="comfortable" hide-details style="max-width:120px" />
          </div>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">Save</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
