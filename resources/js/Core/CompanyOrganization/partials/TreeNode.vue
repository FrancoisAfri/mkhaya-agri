<script setup lang="ts">
import { ref } from 'vue'
import type { UnitNode, UnitUser } from '../Store/store'
import UserAvatar from './UserAvatar.vue'

const props = defineProps<{
  node:     UnitNode
  depth:    number
  getColor: (type: string | null) => string
}>()

const emit = defineEmits<{
  (e: 'drag-start', id: number): void
  (e: 'drop-on', id: number | null, ev: DragEvent): void
  (e: 'drag-over', ev: DragEvent): void
  (e: 'add-child', id: number, name: string): void
  (e: 'edit', node: UnitNode): void
  (e: 'delete', node: UnitNode): void
}>()

const expanded   = ref(true)
const isDragOver = ref(false)
const color      = props.getColor(props.node.type)

const getInitial = (u: UnitUser) =>
  u.initial || `${u.first_name?.[0] ?? ''}${u.surname?.[0] ?? ''}`.toUpperCase() || '??'
</script>

<template>
  <div
    class="tree-node"
    :class="{ 'drag-over': isDragOver }"
    :draggable="true"
    @dragstart.stop="emit('drag-start', node.id)"
    @dragover.stop.prevent="isDragOver = true; emit('drag-over', $event)"
    @dragleave.stop="isDragOver = false"
    @drop.stop.prevent="isDragOver = false; emit('drop-on', node.id, $event)"
  >
    <!-- ── Row ── -->
    <div class="node-row" :style="{ paddingLeft: `${depth * 24 + 12}px` }">

      <!-- Expand toggle -->
      <button v-if="node.children.length" class="expand-btn" @click="expanded = !expanded">
        <VIcon :icon="expanded ? 'tabler-chevron-down' : 'tabler-chevron-right'" size="14" />
      </button>
      <span v-else class="expand-spacer" />

      <!-- Type dot -->
      <div class="node-dot" :style="{ background: color }" />

      <!-- Name + type badge -->
      <div class="node-info min-w-0" style="flex: 1;">
        <span class="node-name">{{ node.name }}</span>
        <span v-if="node.type" class="node-badge" :style="{ background: color + '18', color }">
          {{ node.type }}
        </span>
      </div>

      <!-- Manager avatar chip -->
      <VTooltip v-if="node.user" location="top" :open-delay="100" :close-delay="0">
        <template #activator="{ props: tp }">
          <div v-bind="tp" class="manager-chip" :style="{ '--chip-color': color }">
            <UserAvatar
              :src="node.user.profile_pic"
              :initial="getInitial(node.user)"
              :size="24"
              :color="color"
              rounded="full"
            />
          </div>
        </template>
        <!-- Tooltip card -->
        <div class="tooltip-card">
          <div class="tooltip-banner" :style="{ background: `linear-gradient(135deg, ${color}22, ${color}10)` }">
            <UserAvatar
              :src="node.user.profile_pic"
              :initial="getInitial(node.user)"
              :size="46"
              :color="color"
              rounded="lg"
            />
            <div class="tooltip-meta">
              <p class="tooltip-name">{{ node.user.first_name }} {{ node.user.surname }}</p>
              <p v-if="node.user.job_function" class="tooltip-role">
                <VIcon icon="tabler-briefcase" size="10" style="opacity:.6;margin-right:3px;" />
                {{ node.user.job_function }}
              </p>
            </div>
          </div>
          <div class="tooltip-footer">
            <VIcon icon="tabler-mail" size="13" style="color:#94a3b8;" />
            <span>{{ node.user.email }}</span>
          </div>
        </div>
      </VTooltip>

      <!-- Drag handle -->
      <VIcon icon="tabler-grip-vertical" class="drag-handle" size="16" />

      <!-- Action buttons -->
      <div class="node-actions">
        <button class="action-btn indigo" title="Add child" @click.stop="emit('add-child', node.id, node.name)">
          <VIcon icon="tabler-plus" size="12" />
        </button>
        <button class="action-btn amber" title="Edit" @click.stop="emit('edit', node)">
          <VIcon icon="tabler-pencil" size="12" />
        </button>
        <button class="action-btn red" title="Delete" @click.stop="emit('delete', node)">
          <VIcon icon="tabler-trash" size="12" />
        </button>
      </div>
    </div>

    <!-- Children -->
    <div v-if="expanded && node.children.length" class="children-list">
      <TreeNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :depth="depth + 1"
        :get-color="getColor"
        @drag-start="emit('drag-start', $event)"
        @drop-on="(id, ev) => emit('drop-on', id, ev)"
        @drag-over="emit('drag-over', $event)"
        @add-child="(id, name) => emit('add-child', id, name)"
        @edit="emit('edit', $event)"
        @delete="emit('delete', $event)"
      />
    </div>
  </div>
</template>

<style scoped>
.tree-node { border-radius: 10px; transition: background .1s; }
.drag-over { background: rgba(var(--v-theme-primary), 0.06); outline: 2px dashed rgb(var(--v-theme-primary)); outline-offset: -2px; border-radius: 10px; }

.node-row {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 12px; border-radius: 10px;
  cursor: grab; transition: background .12s; min-height: 46px;
}
.node-row:hover { background: rgba(var(--v-theme-on-surface), 0.04); }

.expand-btn {
  width: 20px; height: 20px; border-radius: 5px; border: none;
  background: transparent; cursor: pointer;
  color: rgba(var(--v-theme-on-surface), 0.4);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  transition: background .1s;
}
.expand-btn:hover { background: rgba(var(--v-theme-on-surface), 0.08); }
.expand-spacer { width: 20px; flex-shrink: 0; }

.node-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

.node-info { display: flex; align-items: center; gap: 8px; overflow: hidden; }
.node-name {
  font-size: 14px; font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.node-badge {
  font-size: 10px; font-weight: 700; padding: 2px 8px;
  border-radius: 20px; text-transform: capitalize;
  white-space: nowrap; flex-shrink: 0; letter-spacing: .3px;
}

.manager-chip {
  width: 28px; height: 28px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; cursor: pointer; overflow: hidden;
  border: 2px solid color-mix(in srgb, var(--chip-color) 30%, transparent);
  transition: transform .15s, box-shadow .15s;
}
.manager-chip:hover { transform: scale(1.12); box-shadow: 0 3px 10px rgba(0,0,0,.18); }

.tooltip-card {
  background: rgb(var(--v-theme-surface));
  border-radius: 14px; overflow: hidden; min-width: 220px;
  box-shadow: 0 8px 30px rgba(0,0,0,.14);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.tooltip-banner {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 14px 12px;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.tooltip-meta { min-width: 0; }
.tooltip-name {
  font-size: 13px; font-weight: 700;
  color: rgb(var(--v-theme-on-surface));
  margin: 0 0 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.tooltip-role {
  font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.5); margin: 0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.tooltip-footer {
  display: flex; align-items: center; gap: 7px;
  padding: 9px 14px; font-size: 11px;
  color: rgba(var(--v-theme-on-surface), 0.4);
}

.drag-handle { color: rgba(var(--v-theme-on-surface), 0.25); flex-shrink: 0; cursor: grab; }
.drag-handle:hover { color: rgba(var(--v-theme-on-surface), 0.5); }

.node-actions { display: flex; gap: 3px; opacity: 0; transition: opacity .15s; flex-shrink: 0; }
.node-row:hover .node-actions { opacity: 1; }

.action-btn {
  width: 26px; height: 26px; border-radius: 7px; border: none;
  background: transparent; cursor: pointer;
  display: flex; align-items: center; justify-content: center; transition: background .12s;
}
.action-btn.indigo { color: rgb(var(--v-theme-primary)); }
.action-btn.indigo:hover { background: rgba(var(--v-theme-primary), 0.1); }
.action-btn.amber  { color: rgb(var(--v-theme-warning)); }
.action-btn.amber:hover  { background: rgba(var(--v-theme-warning), 0.1); }
.action-btn.red    { color: rgb(var(--v-theme-error)); }
.action-btn.red:hover    { background: rgba(var(--v-theme-error), 0.1); }

.children-list {
  margin-left: 12px;
  border-left: 2px solid rgba(var(--v-border-color), var(--v-border-opacity));
  padding-left: 4px;
}
</style>
