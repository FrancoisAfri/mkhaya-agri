<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Seta = {
  id: number; name: string; acronym: string; description: string | null
  website: string | null; levy_code: string | null
  contact_email: string | null; contact_phone: string | null; is_active: boolean
}

const props = defineProps<{
  stats: Record<string, number>
  setas: Seta[]
  upcomingDeadlines: { label: string; date: string; type: string; urgency: string }[]
  primarySdf: any
  currentYear: number
}>()

const setaDialog       = ref(false)
const setaEdit         = ref<Seta | null>(null)
const setaDeleting     = ref(false)
const setaDeleteTarget = ref<Seta | null>(null)
const setaDeleteDialog = ref(false)

const setaForm = useForm({
  name: '', acronym: '', description: '', website: '',
  levy_code: '', contact_email: '', contact_phone: '', is_active: true as boolean,
})

function openSetaCreate() { setaEdit.value = null; setaForm.reset(); setaForm.clearErrors(); setaDialog.value = true }
function openSetaEdit(seta: Seta) {
  setaEdit.value = seta
  setaForm.name = seta.name; setaForm.acronym = seta.acronym; setaForm.description = seta.description ?? ''
  setaForm.website = seta.website ?? ''; setaForm.levy_code = seta.levy_code ?? ''
  setaForm.contact_email = seta.contact_email ?? ''; setaForm.contact_phone = seta.contact_phone ?? ''
  setaForm.is_active = seta.is_active; setaForm.clearErrors(); setaDialog.value = true
}
function submitSeta() {
  if (setaEdit.value) { setaForm.patch(route('lms.admin.qcto.setas.update', { seta: setaEdit.value.id }), { onSuccess: () => { setaDialog.value = false } }) }
  else { setaForm.post(route('lms.admin.qcto.setas.store'), { onSuccess: () => { setaDialog.value = false; setaForm.reset() } }) }
}
function confirmDeleteSeta(seta: Seta) { setaDeleteTarget.value = seta; setaDeleteDialog.value = true }
function executeDeleteSeta() { if (!setaDeleteTarget.value) return; setaDeleting.value = true; router.delete(route('lms.admin.qcto.setas.destroy', { seta: setaDeleteTarget.value.id }), { onFinish: () => { setaDeleting.value = false; setaDeleteDialog.value = false; setaDeleteTarget.value = null } }) }

const urgencyColor: Record<string, string> = { critical: 'error', high: 'warning', medium: 'info', low: 'success' }

const statCards = computed(() => [
  { label: t('lms_qcto_dashboard.stats.registered_courses'), value: props.stats.registered_courses, icon: 'tabler-certificate', color: 'primary' },
  { label: t('lms_qcto_dashboard.stats.active_learnerships'), value: props.stats.active_learnerships, icon: 'tabler-school', color: 'success' },
  { label: t('lms_qcto_dashboard.stats.active_enrolments'), value: props.stats.active_enrolments, icon: 'tabler-users', color: 'info' },
  { label: t('lms_qcto_dashboard.stats.registered_assessors'), value: props.stats.registered_assessors, icon: 'tabler-user-check', color: 'warning' },
  { label: t('lms_qcto_dashboard.stats.registered_moderators'), value: props.stats.registered_moderators, icon: 'tabler-eye-check', color: 'secondary' },
  { label: t('lms_qcto_dashboard.stats.active_sdfs'), value: props.stats.active_sdfs, icon: 'tabler-briefcase', color: 'primary' },
  { label: t('lms_qcto_dashboard.stats.active_aqps'), value: props.stats.active_aqps, icon: 'tabler-building', color: 'info' },
])

const actionCards = computed(() => [
  { label: t('lms_qcto_dashboard.actions.pending_wsp'), value: props.stats.pending_wsp, color: props.stats.pending_wsp ? 'warning' : 'success', route: 'lms.admin.qcto.wsp-atr' },
  { label: t('lms_qcto_dashboard.actions.pending_atr'), value: props.stats.pending_atr, color: props.stats.pending_atr ? 'warning' : 'success', route: 'lms.admin.qcto.wsp-atr' },
  { label: t('lms_qcto_dashboard.actions.pending_eisas'), value: props.stats.pending_eisas, color: props.stats.pending_eisas ? 'warning' : 'success', route: 'lms.admin.qcto.aqp' },
  { label: t('lms_qcto_dashboard.actions.pending_moderation'), value: props.stats.pending_moderation, color: props.stats.pending_moderation ? 'error' : 'success', route: 'lms.admin.qcto.moderation' },
  { label: t('lms_qcto_dashboard.actions.pending_nlrd'), value: props.stats.pending_nlrd, color: props.stats.pending_nlrd ? 'warning' : 'success', route: 'lms.admin.qcto.nlrd' },
  { label: t('lms_qcto_dashboard.actions.expiring_assessors'), value: props.stats.expiring_assessors, color: props.stats.expiring_assessors ? 'error' : 'success', route: 'lms.admin.qcto.persons' },
  { label: t('lms_qcto_dashboard.actions.expiring_sdfs'), value: props.stats.expiring_sdfs, color: props.stats.expiring_sdfs ? 'error' : 'success', route: 'lms.admin.qcto.sdfs' },
])

const navLinks = computed(() => [
  { label: t('lms_qcto_dashboard.nav.assessors_moderators'), icon: 'tabler-user-check', route: 'lms.admin.qcto.persons' },
  { label: t('lms_qcto_dashboard.nav.sdfs'), icon: 'tabler-briefcase', route: 'lms.admin.qcto.sdfs' },
  { label: t('lms_qcto_dashboard.nav.learnerships'), icon: 'tabler-school', route: 'lms.admin.qcto.learnerships' },
  { label: t('lms_qcto_dashboard.nav.aqps_eisa'), icon: 'tabler-building', route: 'lms.admin.qcto.aqp' },
  { label: t('lms_qcto_dashboard.nav.wsp_atr'), icon: 'tabler-file-report', route: 'lms.admin.qcto.wsp-atr' },
  { label: t('lms_qcto_dashboard.nav.bbee_dashboard'), icon: 'tabler-chart-pie', route: 'lms.admin.qcto.bbee' },
  { label: t('lms_qcto_dashboard.nav.nlrd_submissions'), icon: 'tabler-cloud-upload', route: 'lms.admin.qcto.nlrd' },
  { label: t('lms_qcto_dashboard.nav.moderation_reports'), icon: 'tabler-clipboard-check', route: 'lms.admin.qcto.moderation' },
  { label: t('lms_qcto_dashboard.nav.poe_admin'), icon: 'tabler-folders', route: 'lms.admin.qcto.poe.admin' },
])
</script>

<template>
  <div class="pa-5">
    <div class="d-flex align-center gap-3 mb-6">
      <VAvatar color="primary" variant="tonal" size="52" rounded><VIcon icon="tabler-certificate-2" size="28" /></VAvatar>
      <div>
        <h4 class="text-h4 mb-0">{{ t('lms_qcto_dashboard.title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_qcto_dashboard.subtitle', { year: currentYear }) }}</p>
      </div>
    </div>

    <VRow class="mb-4">
      <VCol v-for="card in statCards" :key="card.label" cols="12" sm="6" md="4" lg="2">
        <VCard rounded="lg" elevation="0" border><VCardText class="pa-4 text-center"><VAvatar :color="card.color" variant="tonal" size="44" class="mb-2"><VIcon :icon="card.icon" size="22" /></VAvatar><p class="text-h5 font-weight-bold mb-0">{{ card.value }}</p><p class="text-caption text-medium-emphasis mb-0">{{ card.label }}</p></VCardText></VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="4">
        <VCard rounded="lg" elevation="0" border class="mb-4">
          <VCardText class="pa-4">
            <p class="text-body-1 font-weight-semibold mb-3">{{ t('lms_qcto_dashboard.action_items') }}</p>
            <div class="d-flex flex-column gap-2">
              <div v-for="card in actionCards" :key="card.label" class="d-flex align-center justify-space-between pa-2 rounded cursor-pointer" style="border:1px solid rgba(var(--v-border-color),var(--v-border-opacity))" @click="router.visit(route(card.route))">
                <span class="text-body-2">{{ card.label }}</span><VChip :color="card.color" size="x-small" variant="tonal">{{ card.value }}</VChip>
              </div>
            </div>
          </VCardText>
        </VCard>
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-body-2 font-weight-semibold mb-2">{{ t('lms_qcto_dashboard.primary_sdf') }}</p>
            <template v-if="primarySdf">
              <div class="d-flex align-center gap-2">
                <VAvatar color="primary" size="36" variant="tonal"><VIcon icon="tabler-briefcase" size="18" /></VAvatar>
                <div>
                  <p class="text-body-2 font-weight-medium mb-0">{{ primarySdf.user?.person?.first_name }} {{ primarySdf.user?.person?.surname }}</p>
                  <p class="text-caption text-medium-emphasis mb-0">{{ primarySdf.seta?.acronym }} · {{ primarySdf.registration_number ?? t('lms_qcto_dashboard.table.no_reg_number') }}</p>
                  <VChip :color="primarySdf.status === 'active' ? 'success' : 'error'" size="x-small" variant="tonal" class="mt-1">{{ primarySdf.status }}</VChip>
                </div>
              </div>
            </template>
            <VAlert v-else type="warning" variant="tonal" density="compact" class="mt-1">{{ t('lms_qcto_dashboard.no_sdf') }}</VAlert>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-body-1 font-weight-semibold mb-3"><VIcon icon="tabler-calendar-event" size="18" class="me-1" />{{ t('lms_qcto_dashboard.upcoming_deadlines') }}</p>
            <div v-if="upcomingDeadlines.length" class="d-flex flex-column gap-2">
              <div v-for="d in upcomingDeadlines" :key="d.label + d.date" class="d-flex align-start gap-2 pa-2 rounded" style="border:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
                <VChip :color="urgencyColor[d.urgency]" size="x-small" variant="tonal" class="mt-0.5 flex-shrink-0">{{ d.urgency }}</VChip>
                <div class="flex-1-1"><p class="text-body-2 mb-0">{{ d.label }}</p><p class="text-caption text-medium-emphasis mb-0">{{ d.date }}</p></div>
              </div>
            </div>
            <VAlert v-else type="success" variant="tonal" density="compact">{{ t('lms_qcto_dashboard.no_deadlines') }}</VAlert>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-body-1 font-weight-semibold mb-3">{{ t('lms_qcto_dashboard.qcto_modules') }}</p>
            <div class="d-flex flex-column gap-2">
              <VBtn v-for="link in navLinks" :key="link.label" variant="tonal" :prepend-icon="link.icon" class="justify-start" @click="router.visit(route(link.route))">{{ link.label }}</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard rounded="lg" elevation="0" border class="mt-4">
      <VCardText class="pa-4">
        <div class="d-flex align-center justify-space-between mb-3">
          <p class="text-body-1 font-weight-semibold mb-0"><VIcon icon="tabler-building-bank" size="18" class="me-1" />{{ t('lms_qcto_dashboard.registered_setas') }}</p>
          <VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openSetaCreate">{{ t('lms_qcto_dashboard.add_seta') }}</VBtn>
        </div>
        <VTable v-if="setas.length" density="compact">
          <thead><tr><th>{{ t('lms_qcto_dashboard.table.acronym') }}</th><th>{{ t('lms_qcto_dashboard.table.name') }}</th><th>{{ t('lms_qcto_dashboard.table.levy_code') }}</th><th>{{ t('lms_qcto_dashboard.table.contact_email') }}</th><th>{{ t('lms_qcto_dashboard.table.status') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="seta in setas" :key="seta.id">
              <td class="font-weight-semibold">{{ seta.acronym }}</td><td>{{ seta.name }}</td><td>{{ seta.levy_code ?? '—' }}</td><td>{{ seta.contact_email ?? '—' }}</td>
              <td><VChip :color="seta.is_active ? 'success' : 'error'" size="x-small" variant="tonal">{{ seta.is_active ? t('lms_qcto_dashboard.table.active') : t('lms_qcto_dashboard.table.inactive') }}</VChip></td>
              <td class="text-right"><VBtn icon size="x-small" variant="text" @click="openSetaEdit(seta)"><VIcon icon="tabler-pencil" size="16" /></VBtn><VBtn icon size="x-small" variant="text" color="error" @click="confirmDeleteSeta(seta)"><VIcon icon="tabler-trash" size="16" /></VBtn></td>
            </tr>
          </tbody>
        </VTable>
        <VAlert v-else type="info" variant="tonal" density="compact">{{ t('lms_qcto_dashboard.no_setas') }}</VAlert>
      </VCardText>
    </VCard>

    <VDialog v-model="setaDialog" max-width="560" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ setaEdit ? t('lms_qcto_dashboard.seta_dialog.title_edit') : t('lms_qcto_dashboard.seta_dialog.title_new') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="8"><VTextField v-model="setaForm.name" :label="t('lms_qcto_dashboard.seta_dialog.full_name')" variant="outlined" density="compact" :error-messages="setaForm.errors.name" /></VCol>
            <VCol cols="4"><VTextField v-model="setaForm.acronym" :label="t('lms_qcto_dashboard.seta_dialog.acronym')" variant="outlined" density="compact" :error-messages="setaForm.errors.acronym" /></VCol>
            <VCol cols="6"><VTextField v-model="setaForm.levy_code" :label="t('lms_qcto_dashboard.seta_dialog.levy_code')" variant="outlined" density="compact" :error-messages="setaForm.errors.levy_code" /></VCol>
            <VCol cols="6"><VTextField v-model="setaForm.website" :label="t('lms_qcto_dashboard.seta_dialog.website')" variant="outlined" density="compact" :error-messages="setaForm.errors.website" /></VCol>
            <VCol cols="6"><VTextField v-model="setaForm.contact_email" :label="t('lms_qcto_dashboard.seta_dialog.contact_email')" variant="outlined" density="compact" :error-messages="setaForm.errors.contact_email" /></VCol>
            <VCol cols="6"><VTextField v-model="setaForm.contact_phone" :label="t('lms_qcto_dashboard.seta_dialog.contact_phone')" variant="outlined" density="compact" :error-messages="setaForm.errors.contact_phone" /></VCol>
            <VCol cols="12"><VTextarea v-model="setaForm.description" :label="t('lms_qcto_dashboard.seta_dialog.description')" variant="outlined" density="compact" rows="2" :error-messages="setaForm.errors.description" /></VCol>
            <VCol cols="12"><VSwitch v-model="setaForm.is_active" :label="t('lms_qcto_dashboard.seta_dialog.active')" color="success" density="compact" hide-details /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="setaDialog = false">{{ t('lms_qcto_dashboard.seta_dialog.cancel') }}</VBtn><VBtn color="primary" :loading="setaForm.processing" @click="submitSeta">{{ setaEdit ? t('lms_qcto_dashboard.seta_dialog.save') : t('lms_qcto_dashboard.seta_dialog.add') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="setaDeleteDialog" max-width="420" persistent>
      <VCard rounded="lg">
        <VCardText class="pa-6 text-center"><VAvatar color="error" variant="tonal" size="56" class="mb-4"><VIcon icon="tabler-trash" size="28" /></VAvatar><h6 class="text-h6 font-weight-semibold mb-2">{{ t('lms_qcto_dashboard.delete_dialog.title') }}</h6><p class="text-body-2 text-medium-emphasis mb-0">{{ t('lms_qcto_dashboard.delete_dialog.body', { acronym: setaDeleteTarget?.acronym }) }}</p></VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2 justify-end"><VBtn variant="tonal" color="secondary" @click="setaDeleteDialog = false">{{ t('lms_qcto_dashboard.delete_dialog.cancel') }}</VBtn><VBtn color="error" variant="tonal" :loading="setaDeleting" @click="executeDeleteSeta">{{ t('lms_qcto_dashboard.delete_dialog.remove') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
