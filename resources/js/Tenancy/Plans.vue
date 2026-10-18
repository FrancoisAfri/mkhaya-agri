<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute

interface Plan {
  id: number; code: string; name: string; max_users: number | null
  ai_token_quota: number | null; sms_quota: number | null; storage_gb: number | null
  regular_price_monthly: number | null; support: Record<string, any> | null
  modules: string[] | null; price_monthly: number; is_active: boolean; sort: number
}
interface ModuleDef { code: string; name: string }

const props = defineProps<{ plans: Plan[]; moduleCatalog: ModuleDef[] }>()

const confirmRef = ref()
const dialogOpen = ref(false)
const editingId = ref<string | null>(null)

function fmtMoney(n: any) { return 'R ' + Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 }) }
function moduleName(code: string) { return props.moduleCatalog.find(m => m.code === code)?.name ?? code }
function moduleCount(p: Plan) { return p.modules === null ? props.moduleCatalog.length : p.modules.length }
function isAllModules(p: Plan) { return p.modules === null }

function toggleAll(on: boolean) { form.modules = on ? props.moduleCatalog.map(m => m.code) : [] }

function quotaLabel(v: number | null) { return v === null ? 'default' : (v === 0 ? 'Unlimited' : Number(v).toLocaleString()) }

const form = useForm({
  name: '', max_users: null as number | null,
  ai_token_quota: null as number | null, sms_quota: null as number | null, storage_gb: null as number | null,
  modules: [] as string[], price_monthly: 0 as number | null, regular_price_monthly: null as number | null,
  is_active: true, sort: 0,
})

function openCreate() { editingId.value = null; form.reset(); form.clearErrors(); form.modules = []; dialogOpen.value = true }
function openEdit(p: Plan) {
  editingId.value = p.id.toString()
  form.clearErrors()
  form.name = p.name; form.max_users = p.max_users
  form.ai_token_quota = p.ai_token_quota; form.sms_quota = p.sms_quota; form.storage_gb = p.storage_gb
  // null modules = all — pre-tick everything so the editor is explicit.
  form.modules = p.modules === null ? props.moduleCatalog.map(m => m.code) : [...p.modules]
  form.price_monthly = Number(p.price_monthly)
  form.regular_price_monthly = p.regular_price_monthly !== null ? Number(p.regular_price_monthly) : null
  form.is_active = p.is_active; form.sort = p.sort
  dialogOpen.value = true
}
const allSelected = computed(() => form.modules.length === props.moduleCatalog.length)
function submit() {
  const opts = { preserveScroll: true, onSuccess: () => { dialogOpen.value = false; form.reset() } }
  if (editingId.value) form.put(route('tenancy.plans.update', editingId.value), opts)
  else form.post(route('tenancy.plans.store'), opts)
}
function remove(p: Plan) {
  confirmRef.value.open(
    { title: 'Delete plan', message: `Delete plan "${p.name}"?`, confirmText: 'Delete', confirmColor: 'error' },
    () => router.delete(route('tenancy.plans.destroy', p.id), { preserveScroll: true }),
  )
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex flex-wrap align-center justify-space-between mb-6 gap-3">
      <div>
        <h3 class="text-h5 font-weight-bold mb-1">Subscription Plans</h3>
        <p class="text-body-2 text-medium-emphasis mb-0">Bundle modules with a user limit. Assign plans to companies on the Companies page.</p>
      </div>
      <div class="d-flex gap-2">
        <Link :href="route('tenancy.overview')"><VBtn variant="tonal" prepend-icon="tabler-arrow-left">Overview</VBtn></Link>
        <Link :href="route('tenancy.companies.index')"><VBtn variant="tonal" prepend-icon="tabler-building">Companies</VBtn></Link>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">New Plan</VBtn>
      </div>
    </div>

    <VRow>
      <VCol v-for="p in props.plans" :key="p.id" cols="12" md="4">
        <VCard elevation="0" border rounded="lg" class="h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-1">
              <div class="text-h6 font-weight-bold">{{ p.name }}</div>
              <VChip size="x-small" :color="p.is_active ? 'success' : 'secondary'" variant="tonal" label>{{ p.is_active ? 'Active' : 'Inactive' }}</VChip>
            </div>
            <div class="d-flex align-baseline gap-2 mb-2">
              <div class="text-h5 font-weight-bold">{{ fmtMoney(p.price_monthly) }}<span class="text-caption text-medium-emphasis">/mo</span></div>
              <div v-if="p.regular_price_monthly && Number(p.regular_price_monthly) > Number(p.price_monthly)" class="text-caption text-medium-emphasis">
                <span class="text-decoration-line-through">{{ fmtMoney(p.regular_price_monthly) }}</span> after launch
              </div>
            </div>
            <div class="text-body-2 mb-1">
              <VIcon icon="tabler-users" size="16" class="me-1" />{{ p.max_users ?? 'Unlimited' }} users
            </div>
            <div class="text-caption text-medium-emphasis mb-3">
              <VIcon icon="tabler-robot" size="14" class="me-1" />{{ quotaLabel(p.ai_token_quota) }} AI tokens/mo ·
              <VIcon icon="tabler-message" size="14" class="me-1" />{{ quotaLabel(p.sms_quota) }} SMS/mo ·
              <VIcon icon="tabler-database" size="14" class="me-1" />{{ p.storage_gb ?? '—' }} GB
            </div>
            <div class="text-caption text-medium-emphasis mb-1">{{ isAllModules(p) ? 'All modules' : moduleCount(p) + ' modules' }}</div>
            <div v-if="!isAllModules(p)" class="d-flex flex-wrap gap-1 mb-3">
              <VChip v-for="m in (p.modules || [])" :key="m" size="x-small" variant="tonal" label>{{ moduleName(m) }}</VChip>
            </div>
            <VChip v-else color="primary" variant="tonal" size="small" label class="mb-3"><VIcon start icon="tabler-star-filled" size="14" />Everything included</VChip>
            <div class="d-flex gap-2">
              <VBtn size="small" variant="tonal" prepend-icon="tabler-pencil" @click="openEdit(p)">Edit</VBtn>
              <VBtn size="small" variant="text" color="error" icon @click="remove(p)"><VIcon icon="tabler-trash" size="18" /></VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialogOpen" max-width="720" persistent scrollable>
      <VCard rounded="lg">
        <VCardTitle class="d-flex align-center justify-space-between pa-5">
          <span class="text-h6 font-weight-bold">{{ editingId ? 'Edit Plan' : 'New Plan' }}</span>
          <VBtn icon variant="text" size="small" @click="dialogOpen = false"><VIcon icon="tabler-x" /></VBtn>
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-5">
          <VRow dense>
            <VCol cols="12" md="4"><VTextField v-model="form.name" label="Plan name" :error-messages="form.errors.name" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.price_monthly" label="Launch price / month (R)" type="number" hint="Promotional price" persistent-hint /></VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.regular_price_monthly" label="Regular price / month (R)" type="number" clearable hint="After launch promotion" persistent-hint /></VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.max_users" label="User limit (blank = unlimited)" type="number" clearable /></VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.storage_gb" label="Storage (GB)" type="number" clearable /></VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.sort" label="Sort order" type="number" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.ai_token_quota" label="AI tokens / month (0 = unlimited)" type="number" clearable hint="Monthly AI allowance" persistent-hint /></VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.sms_quota" label="SMS / month (0 = unlimited)" type="number" clearable hint="Monthly SMS allowance" persistent-hint /></VCol>
            <VCol cols="12" md="4" class="d-flex align-center"><VSwitch v-model="form.is_active" label="Active" color="primary" hide-details /></VCol>
          </VRow>

          <div class="d-flex align-center justify-space-between mt-2 mb-1">
            <span class="text-subtitle-2 font-weight-bold">Modules <span class="text-caption text-medium-emphasis">(all ticked = everything included)</span></span>
            <span>
              <VBtn size="x-small" variant="text" @click="toggleAll(true)">All</VBtn>
              <VBtn size="x-small" variant="text" @click="toggleAll(false)">None</VBtn>
            </span>
          </div>
          <VRow dense>
            <VCol v-for="m in props.moduleCatalog" :key="m.code" cols="12" sm="6" md="4">
              <VCheckbox v-model="form.modules" :value="m.code" :label="m.name" density="compact" hide-details />
            </VCol>
          </VRow>
          <div v-if="allSelected" class="text-caption text-primary mt-2"><VIcon icon="tabler-info-circle" size="14" class="me-1" />All modules selected — this plan includes everything.</div>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3"><VSpacer /><VBtn variant="tonal" :disabled="form.processing" @click="dialogOpen = false">Cancel</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="submit">{{ editingId ? 'Save' : 'Create' }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmRef" />
  </VContainer>
</template>
