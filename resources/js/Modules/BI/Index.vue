<script setup lang="ts">
// BI home: the template gallery (only templates built on modules the company subscribes to) and
// the dashboards saved in this company. Anyone with BI opens templates; designers also build.
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

const props = defineProps<{
  templates: { key: string; name: string; group: string; description: string; icon: string; widgets: number; modules: number }[]
  dashboards: { uuid: string; name: string; description: string | null; visibility: string; template_key: string | null; updated_at: string }[]
  canDesign: boolean
}>()

const search = ref('')
const group = ref<string | null>(null)
const groups = computed(() => [...new Set(props.templates.map(t => t.group))].sort())
const shown = computed(() => props.templates.filter(t => (!group.value || t.group === group.value)
  && (!search.value || `${t.name} ${t.description} ${t.group}`.toLowerCase().includes(search.value.toLowerCase()))))
const byGroup = computed(() => groups.value.map(g => ({ g, items: shown.value.filter(t => t.group === g) })).filter(x => x.items.length))
const newDialog = ref(false)
const newName = ref('')
function createBlank() { router.post('/bi', { name: newName.value }) }
const vis = (v: string) => ({ private: 'Only me', shared: 'Shared', organisation: 'Everyone' } as any)[v] ?? v
</script>

<template>
  <div class="mkbi-home">
    <header class="d-flex flex-wrap align-end justify-space-between gap-4 mb-6">
      <div>
        <p class="mkbi-eyebrow">Business Intelligence</p>
        <h1 class="mkbi-title">Dashboards</h1>
        <p class="mkbi-sub">{{ templates.length }} ready-made dashboards built on the modules you use — open one, filter it, click any chart to drill in.</p>
      </div>
      <VBtn v-if="canDesign" color="primary" prepend-icon="tabler-plus" @click="newDialog = true">New dashboard</VBtn>
    </header>

    <section v-if="dashboards.length" class="mb-8">
      <h2 class="mkbi-h2">Your dashboards</h2>
      <div class="mkbi-grid">
        <a v-for="d in dashboards" :key="d.uuid" :href="`/bi/${d.uuid}`" class="mkbi-tile">
          <div class="d-flex align-center justify-space-between"><VIcon icon="tabler-layout-dashboard" color="primary" size="22" /><VChip size="x-small" variant="tonal">{{ vis(d.visibility) }}</VChip></div>
          <strong>{{ d.name }}</strong><span>{{ d.description || 'Custom dashboard' }}</span>
        </a>
      </div>
    </section>

    <div class="d-flex flex-wrap align-center gap-3 mb-4">
      <h2 class="mkbi-h2 mb-0 me-2">Templates</h2>
      <VTextField v-model="search" placeholder="Search templates" prepend-inner-icon="tabler-search" density="compact" variant="outlined" hide-details style="max-width: 280px" />
      <div class="mkbi-seg">
        <button :class="{ on: !group }" @click="group = null">All</button>
        <button v-for="g in groups" :key="g" :class="{ on: group === g }" @click="group = g">{{ g }}</button>
      </div>
    </div>

    <section v-for="s in byGroup" :key="s.g" class="mb-6">
      <h3 class="mkbi-h3">{{ s.g }} <span>{{ s.items.length }}</span></h3>
      <div class="mkbi-grid">
        <a v-for="t in s.items" :key="t.key" :href="`/bi/t/${t.key}`" class="mkbi-tile">
          <div class="d-flex align-center justify-space-between"><span class="mkbi-ico"><VIcon :icon="t.icon" size="20" /></span><small>{{ t.widgets }} visuals<template v-if="t.modules > 1"> · {{ t.modules }} modules</template></small></div>
          <strong>{{ t.name }}</strong><span>{{ t.description }}</span>
        </a>
      </div>
    </section>
    <div v-if="!byGroup.length" class="text-center text-medium-emphasis py-12">No templates match your search.</div>

    <VDialog v-model="newDialog" max-width="460">
      <VCard title="New dashboard">
        <VCardText><p class="text-body-2 text-medium-emphasis mb-3">Start from a blank canvas — or open any template and choose “Copy &amp; customise”.</p>
          <VTextField v-model="newName" label="Name" density="compact" variant="outlined" autofocus /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="newDialog = false">Cancel</VBtn><VBtn color="primary" :disabled="!newName" @click="createBlank">Create</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.mkbi-home { padding: 8px 8px 48px; }
.mkbi-eyebrow { margin: 0 0 4px; font-size: 12px; color: #6A0DAD; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; }
.mkbi-title { margin: 0; font-size: 28px; font-weight: 700; letter-spacing: -.6px; color: #16161F; }
.mkbi-sub { margin: 4px 0 0; font-size: 13px; color: #8A8A99; max-width: 640px; }
.mkbi-h2 { font-size: 16px; font-weight: 700; color: #16161F; margin-bottom: 12px; }
.mkbi-h3 { font-size: 13px; font-weight: 700; color: #45455A; text-transform: uppercase; letter-spacing: .6px; margin: 0 0 10px; }
.mkbi-h3 span { color: #A0A0B0; font-weight: 600; margin-left: 4px; }
.mkbi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
.mkbi-tile { display: flex; flex-direction: column; gap: 6px; padding: 16px; background: #fff; border: 1px solid #EEEEF3; border-radius: 16px; text-decoration: none; color: inherit; transition: all .15s; min-height: 132px; }
.mkbi-tile:hover { border-color: #C9B3E6; box-shadow: 0 10px 26px rgba(106,13,173,.08); transform: translateY(-1px); }
.mkbi-tile strong { font-size: 14px; color: #16161F; } .mkbi-tile span { font-size: 12px; color: #77778A; line-height: 1.45; } .mkbi-tile small { font-size: 11px; color: #A0A0B0; }
.mkbi-ico { display: inline-flex; width: 34px; height: 34px; border-radius: 10px; background: #F3EEFA; color: #6A0DAD; align-items: center; justify-content: center; }
.mkbi-seg { display: inline-flex; flex-wrap: wrap; background: #F3F1F8; border-radius: 10px; padding: 3px; gap: 2px; }
.mkbi-seg button { border: 0; background: transparent; padding: 5px 12px; font-size: 12px; font-weight: 600; color: #5B5B6E; border-radius: 8px; cursor: pointer; }
.mkbi-seg button.on, .mkbi-seg button:hover { background: #fff; color: #6A0DAD; }
</style>
