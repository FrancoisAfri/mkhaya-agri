<script setup lang="ts">
/**
 * Alarm settings: the range each measurement must stay in (for a production type or one unit) and
 * how long it may be out before it counts, and for each severity who is told first and who next
 * when nobody acknowledges.
 */
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import FormDialog, { type FormField } from '@/Modules/Agri/Components/FormDialog.vue'
import { human, num, severityColor } from '../Agriculture/prd'

const props = defineProps<{
  thresholds: Array<{ id: string; metric: string; min: number | null; max: number | null; severity: string; sustain_minutes: number; scope: string | null; is_active: boolean; message: string | null; is_ccp: boolean; corrective_action: string | null }>
  policies: Array<{ severity: string; steps: Array<{ after_minutes: number | null; user_ids: number[]; channels: string[] }> | null; default_minutes: number | null }>
  types: Array<{ value: string; title: string }>
  units: Array<{ value: string; title: string }>
  users: Array<{ value: number; title: string }>
  severities: string[]
  channels: { sms: { configured: boolean; active: boolean; provider: string; username: string | null }
    whatsapp: { configured: boolean; active: boolean; phone_number_id: string | null; business_account_id: string | null; alert_template: string | null; template_language: string; last_test_at: string | null; last_test_status: string | null } }
  myMobile: string | null
}>()
const sms = ref({ channel: 'sms', is_active: props.channels.sms.active, provider: props.channels.sms.provider || 'bulksms', username: props.channels.sms.username ?? '', password: '' })
const wa = ref({ channel: 'whatsapp', is_active: props.channels.whatsapp.active, phone_number_id: props.channels.whatsapp.phone_number_id ?? '', business_account_id: props.channels.whatsapp.business_account_id ?? '',
  token: '', alert_template: props.channels.whatsapp.alert_template ?? '', template_language: props.channels.whatsapp.template_language || 'en' })
const saveChannel = (body: Record<string, any>) => router.post('/manufacturing/command/settings/channels', body, { preserveScroll: true })
const testChannel = (channel: string) => router.post('/manufacturing/command/settings/channels/test', { channel }, { preserveScroll: true })

const adding = ref(false)
const fields: FormField[] = [
  { key: 'metric', label: 'Measurement', type: 'select', items: ['temperature', 'humidity', 'ammonia', 'co2', 'water_flow', 'soil_moisture', 'dissolved_oxygen', 'ph', 'ec', 'water_temperature', 'core_temperature', 'cold_room_temperature', 'brix'], required: true, cols: 6 },
  { key: 'severity', label: 'Severity', type: 'select', items: props.severities, default: 'warning', cols: 6 },
  { key: 'min_value', label: 'Minimum', type: 'number', cols: 4 }, { key: 'max_value', label: 'Maximum', type: 'number', cols: 4 },
  { key: 'sustain_minutes', label: 'Out of range for (minutes)', type: 'number', default: 10, cols: 4 },
  { key: 'production_type_id', label: 'For every unit of type', type: 'select', items: props.types, cols: 6 },
  { key: 'unit_id', label: 'Or for one unit', type: 'select', items: props.units, cols: 6 },
  { key: 'message', label: 'What to tell people', cols: 12 },
  { key: 'is_ccp', label: 'Critical control point (HACCP): a breach holds the running batch', type: 'switch', cols: 12 },
  { key: 'corrective_action', label: 'Corrective action (from the HACCP plan)', type: 'textarea', cols: 12, show: f => !!f.is_ccp },
]
const editing = ref<{ severity: string; steps: Array<{ after_minutes: number | null; user_ids: number[]; channels: string[] }> } | null>(null)
const edit = (p: any) => { editing.value = { severity: p.severity, steps: p.steps ? JSON.parse(JSON.stringify(p.steps)) : [{ after_minutes: p.default_minutes, user_ids: [], channels: ['in_app'] }, { after_minutes: p.default_minutes, user_ids: [], channels: ['in_app'] }] } }
const save = () => router.post('/manufacturing/command/settings/escalation', editing.value!, { preserveScroll: true, onSuccess: () => { editing.value = null } })
const name = (id: number) => props.users.find(u => u.value === id)?.title ?? `#${id}`
</script>

<template>
  <div class="pa-4">
    <PageErrors />
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">Alarm settings</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Limits, how long before they count, and who is told — then who next.</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="adding = true">Add threshold</VBtn>
    </div>
    <VCard border class="mb-4">
      <VCardTitle>Thresholds</VCardTitle>
      <VTable density="comfortable">
        <thead><tr><th>Measurement</th><th>For</th><th class="text-end">Range</th><th class="text-end">Sustained</th><th>Severity</th></tr></thead>
        <tbody>
          <tr v-for="t in thresholds" :key="t.id">
            <td class="font-weight-medium">{{ human(t.metric) }} <VChip v-if="t.is_ccp" size="x-small" color="error" label>CCP</VChip><div v-if="t.message" class="text-caption">{{ t.message }}</div>
              <div v-if="t.corrective_action" class="text-caption text-medium-emphasis">Corrective action: {{ t.corrective_action }}</div></td>
            <td>{{ t.scope ?? 'Everything' }}</td>
            <td class="text-end">{{ t.min !== null ? num(t.min) : '–' }} to {{ t.max !== null ? num(t.max) : '–' }}</td>
            <td class="text-end">{{ t.sustain_minutes ? `${num(t.sustain_minutes, 1)} min` : 'at once' }}</td>
            <td><VChip size="small" label :color="severityColor(t.severity)">{{ t.severity }}</VChip></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
    <VCard border>
      <VCardTitle>Escalation</VCardTitle>
      <VCardText class="text-body-2 text-medium-emphasis">Without a policy, step 1 is everyone who responds to incidents and step 2 everyone who manages agricultural production — in the app, and for critical and emergency alarms also by SMS and WhatsApp when those are switched on below.</VCardText>
      <VTable density="comfortable">
        <thead><tr><th>Severity</th><th>Steps</th><th /></tr></thead>
        <tbody>
          <tr v-for="p in policies" :key="p.severity">
            <td><VChip size="small" label :color="severityColor(p.severity)">{{ p.severity }}</VChip></td>
            <td>
              <template v-if="p.steps">
                <div v-for="(s, k) in p.steps" :key="k" class="text-body-2">Step {{ k + 1 }}: {{ s.user_ids.map(name).join(', ') }} by {{ s.channels.join(', ') }}<template v-if="s.after_minutes"> — next step after {{ s.after_minutes }} min</template></div>
              </template>
              <span v-else class="text-medium-emphasis">Default<template v-if="p.default_minutes"> — next step after {{ p.default_minutes }} min</template></span>
            </td>
            <td class="text-end"><VBtn size="small" variant="tonal" @click="edit(p)">Edit</VBtn></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
    <VCard border class="mt-4">
      <VCardTitle>Alert channels</VCardTitle>
      <VCardText class="text-body-2 text-medium-emphasis">
        Your organisation's own SMS account and WhatsApp Business number. Critical and emergency alarms go to people's phones on whichever is switched on, as well as in the app.
        Messages go to the cell number on each person's HR record<template v-if="myMobile"> — yours is {{ myMobile }}</template><template v-else> — you have none, so add one to your HR record before testing</template>.
      </VCardText>
      <VCardText>
        <VRow>
          <VCol cols="12" md="6">
            <div class="d-flex align-center gap-2 mb-2"><VIcon icon="tabler-message" /> <strong>SMS</strong>
              <VChip size="x-small" label :color="channels.sms.configured && channels.sms.active ? 'success' : 'secondary'">{{ channels.sms.configured ? (channels.sms.active ? 'on' : 'off') : 'not set up' }}</VChip></div>
            <VSelect v-model="sms.provider" :items="[{ title: 'BulkSMS', value: 'bulksms' }]" label="Provider" density="compact" />
            <VTextField v-model="sms.username" label="Account username" density="compact" />
            <VTextField v-model="sms.password" type="password" :label="channels.sms.configured ? 'Password (leave empty to keep it)' : 'Password'" density="compact" autocomplete="new-password" />
            <VSwitch v-model="sms.is_active" label="Send alarms by SMS" color="success" density="compact" hide-details />
            <div class="text-caption text-medium-emphasis mb-2">Urgent alarms are sent even when the month's SMS allowance is used up.</div>
            <div class="d-flex gap-2"><VBtn color="primary" size="small" @click="saveChannel(sms)">Save</VBtn>
              <VBtn size="small" variant="tonal" :disabled="!channels.sms.configured || !myMobile" @click="testChannel('sms')">Send me a test</VBtn></div>
          </VCol>
          <VCol cols="12" md="6">
            <div class="d-flex align-center gap-2 mb-2"><VIcon icon="tabler-brand-whatsapp" /> <strong>WhatsApp</strong>
              <VChip size="x-small" label :color="channels.whatsapp.configured && channels.whatsapp.active ? 'success' : 'secondary'">{{ channels.whatsapp.configured ? (channels.whatsapp.active ? 'on' : 'off') : 'not set up' }}</VChip></div>
            <VTextField v-model="wa.phone_number_id" label="Phone number id (Meta WhatsApp Cloud API)" density="compact" />
            <VTextField v-model="wa.business_account_id" label="Business account id" density="compact" />
            <VTextField v-model="wa.token" type="password" :label="channels.whatsapp.configured ? 'Access token (leave empty to keep it)' : 'Access token'" density="compact" autocomplete="new-password" />
            <VRow dense><VCol cols="8"><VTextField v-model="wa.alert_template" label="Approved alert template name" hint="Body with {{1}} severity, {{2}} what happened, {{3}} link" persistent-hint density="compact" /></VCol>
              <VCol cols="4"><VTextField v-model="wa.template_language" label="Language" density="compact" /></VCol></VRow>
            <VSwitch v-model="wa.is_active" label="Send alarms by WhatsApp" color="success" density="compact" hide-details />
            <div v-if="channels.whatsapp.last_test_at" class="text-caption text-medium-emphasis mb-2">Last test {{ new Date(channels.whatsapp.last_test_at).toLocaleString() }}: {{ channels.whatsapp.last_test_status }}</div>
            <div class="d-flex gap-2 mt-2"><VBtn color="primary" size="small" @click="saveChannel(wa)">Save</VBtn>
              <VBtn size="small" variant="tonal" :disabled="!channels.whatsapp.configured || !myMobile" @click="testChannel('whatsapp')">Send me a test</VBtn></div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <FormDialog v-model="adding" title="Add a threshold" :fields="fields" url="/manufacturing/command/settings/thresholds" />
    <VDialog :model-value="!!editing" max-width="720" @update:model-value="v => !v && (editing = null)">
      <VCard v-if="editing" :title="`${human(editing.severity)} escalation`">
        <VCardText>
          <VRow v-for="(s, k) in editing.steps" :key="k" dense>
            <VCol cols="12" class="text-subtitle-2">Step {{ k + 1 }}</VCol>
            <VCol cols="12" md="6"><VSelect v-model="s.user_ids" :items="users" multiple chips label="Tell" /></VCol>
            <VCol cols="6" md="3"><VSelect v-model="s.channels" :items="['in_app', 'email', 'sms', 'whatsapp']" multiple label="By" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="s.after_minutes" type="number" label="Next step after (min)" /></VCol>
          </VRow>
          <VBtn size="small" variant="tonal" :disabled="editing.steps.length >= 5" @click="editing.steps.push({ after_minutes: 15, user_ids: [], channels: ['in_app'] })">Add a step</VBtn>
        </VCardText>
        <VCardActions><VSpacer /><VBtn @click="editing = null">Cancel</VBtn><VBtn color="primary" @click="save">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
