<script setup lang="ts">
/**
 * The farm API's documentation, read from the same OpenAPI document the API serves: every endpoint
 * by area, the ability it needs, its parameters, the body it takes and an example answer, plus the
 * records' fields and the webhook events. No extra library: the spec is plain JSON.
 */
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ spec: Record<string, any>; spec_url: string }>()
const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.api_docs.${key}`, params ?? {})

const find = ref('')
const open = ref<string | null>(null)
const METHOD: Record<string, string> = { get: 'info', post: 'success', patch: 'warning', delete: 'error' }
const server = computed(() => props.spec.servers?.[0]?.url ?? '')

/** A $ref into components, resolved one level (parameters, schemas, responses). */
const deref = (o: any) => {
  if (!o?.$ref) return o
  return o.$ref.replace('#/components/', '').split('/').reduce((acc: any, part: string) => acc?.[part], props.spec.components)
}
const operations = computed(() => {
  const out: any[] = []
  for (const [path, ops] of Object.entries<any>(props.spec.paths ?? {})) {
    for (const [method, op] of Object.entries<any>(ops)) {
      out.push({ key: `${method} ${path}`, method, path, ...op, tag: op.tags?.[0] ?? 'Other', params: (op.parameters ?? []).map(deref) })
    }
  }
  const q = find.value.trim().toLowerCase()
  return q ? out.filter(o => `${o.path} ${o.summary} ${o.tag} ${o['x-ability'] ?? ''}`.toLowerCase().includes(q)) : out
})
const byTag = computed(() => {
  const g: Record<string, any[]> = {}
  for (const o of operations.value) (g[o.tag] ??= []).push(o)
  return g
})
const example = (op: any) => {
  const ok = op.responses?.['200'] ?? op.responses?.['201']
  return ok?.content?.['application/json']?.example ?? null
}
const curl = (op: any) => {
  const url = `${server.value}${op.path}`
  const body = op.requestBody?.content?.['application/json']?.example
  return op.security?.length === 0
    ? `curl ${url}`
    : `curl -X ${op.method.toUpperCase()} ${url} \\\n  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"` +
      (body ? ` \\\n  -H "Content-Type: application/json" -H "Idempotency-Key: $(uuidgen)" \\\n  -d '${JSON.stringify(body)}'` : '')
}
const schemas = computed(() => Object.entries<any>(props.spec.components?.schemas ?? {}).filter(([n]) => !['Problem', 'PageMeta', 'WebhookDelivery'].includes(n)))
</script>

<template>
  <div>
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.api.index')">{{ k('breadcrumb') }}</Link></div>
        <h4 class="text-h4 mb-1">{{ k('title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ k('subtitle') }}</p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-download" :href="spec_url" target="_blank">{{ k('download') }}</VBtn>
    </div>

    <VCard class="mb-4"><VCardText>
      <div class="text-subtitle-1 mb-1">{{ spec.info?.title }} · v{{ spec.info?.version }}</div>
      <div class="text-body-2 mb-2"><code>{{ server }}</code></div>
      <div class="text-body-2" style="white-space: pre-line">{{ spec.info?.description }}</div>
    </VCardText></VCard>

    <VTextField v-model="find" :label="k('filter')" prepend-inner-icon="tabler-search" density="compact" clearable hide-details class="mb-4" style="max-width: 420px" />

    <template v-for="(ops, tag) in byTag" :key="tag">
      <div class="text-h6 mt-4 mb-2">{{ tag }}</div>
      <VCard class="mb-2">
        <VList density="compact" class="py-0">
          <template v-for="op in ops" :key="op.key">
            <VListItem @click="open = open === op.key ? null : op.key">
              <div class="d-flex flex-wrap align-center gap-2">
                <VChip size="small" label :color="METHOD[op.method]" class="text-uppercase font-weight-bold" style="min-width: 64px">{{ op.method }}</VChip>
                <code>{{ op.path }}</code>
                <span class="text-body-2 text-medium-emphasis">{{ op.summary }}</span>
                <VSpacer />
                <VChip v-if="op['x-ability']" size="x-small" variant="tonal">{{ op['x-ability'] }}</VChip>
                <VChip v-else-if="op.security?.length === 0" size="x-small" variant="tonal" color="success">{{ k('public') }}</VChip>
              </div>
            </VListItem>
            <div v-if="open === op.key" class="px-4 pb-4">
              <div class="text-body-2 mb-2">{{ op.description }}</div>
              <template v-if="op.params.length">
                <div class="text-subtitle-2 mt-2">{{ k('parameters') }}</div>
                <VTable density="compact" class="mb-2"><tbody>
                  <tr v-for="p in op.params" :key="`${p.in}:${p.name}`"><td><code>{{ p.name }}</code></td><td class="text-caption">{{ p.in }}<template v-if="p.required"> · {{ k('required') }}</template></td>
                    <td class="text-caption">{{ p.schema?.format ?? p.schema?.type }}<template v-if="p.schema?.enum"> ({{ p.schema.enum.join(', ') }})</template></td><td class="text-caption">{{ p.description }}</td></tr>
                </tbody></VTable>
              </template>
              <template v-if="op.requestBody">
                <div class="text-subtitle-2 mt-2">{{ k('body') }}</div>
                <VTable density="compact" class="mb-2"><tbody>
                  <tr v-for="(s, name) in op.requestBody.content['application/json'].schema.properties" :key="name">
                    <td><code>{{ name }}</code></td><td class="text-caption">{{ s.format ?? s.type }}<template v-if="op.requestBody.content['application/json'].schema.required?.includes(name)"> · {{ k('required') }}</template></td>
                    <td class="text-caption">{{ s.enum?.join(', ') ?? s.items?.enum?.join(', ') }}</td></tr>
                </tbody></VTable>
              </template>
              <div class="text-subtitle-2 mt-2">{{ k('curl') }}</div>
              <pre class="text-caption pa-3 rounded bg-grey-lighten-4" style="white-space: pre-wrap">{{ curl(op) }}</pre>
              <template v-if="example(op)">
                <div class="text-subtitle-2 mt-2">{{ k('response') }}</div>
                <pre class="text-caption pa-3 rounded bg-grey-lighten-4" style="white-space: pre-wrap; max-height: 320px; overflow: auto">{{ JSON.stringify(example(op), null, 2) }}</pre>
              </template>
            </div>
            <VDivider />
          </template>
        </VList>
      </VCard>
    </template>

    <div class="text-h6 mt-6 mb-2">{{ k('webhooks') }}</div>
    <VCard class="mb-4"><VTable density="compact"><tbody>
      <tr v-for="(w, name) in spec.webhooks" :key="name"><td><code>{{ name }}</code></td><td class="text-body-2">{{ w.post.summary }}</td></tr>
    </tbody></VTable>
      <VCardText class="text-caption">{{ spec.components?.schemas?.WebhookDelivery?.description }}</VCardText></VCard>

    <div class="text-h6 mt-6 mb-2">{{ k('schemas') }}</div>
    <VExpansionPanels variant="accordion">
      <VExpansionPanel v-for="[name, s] in schemas" :key="name" :title="name">
        <VExpansionPanelText>
          <div class="text-body-2 mb-2">{{ s.description }}</div>
          <VTable density="compact"><tbody>
            <tr v-for="(f, fname) in s.properties" :key="fname"><td><code>{{ fname }}</code></td>
              <td class="text-caption">{{ Array.isArray(f.type) ? f.type.join(' | ') : f.type }}<template v-if="f.format"> ({{ f.format }})</template></td><td class="text-caption">{{ f.description }}</td></tr>
          </tbody></VTable>
        </VExpansionPanelText>
      </VExpansionPanel>
    </VExpansionPanels>
  </div>
</template>
