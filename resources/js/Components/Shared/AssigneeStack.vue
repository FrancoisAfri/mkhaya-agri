<script setup lang="ts">
/**
 * Who a task belongs to, drawn the same way everywhere.
 *
 * Assignment was recorded and shown nowhere: a card carried a code, a name and a date, so an
 * unowned task and one with a foreman on it looked identical. That is the first question anybody
 * asks of a board, and the module could not answer it on any screen.
 *
 * Initials rather than photographs, because this platform stores no profile pictures — there is no
 * photo column on `users` or `hr_people` anywhere in the schema. Drawing a placeholder silhouette
 * for everybody would be a row of identical grey faces, which carries less than two letters do. The
 * colour is derived from the name, so the same person is the same colour on the board, in the work
 * list and in the drawer, and the stack becomes recognisable at a glance rather than merely legible.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Person = { id: string | null; name: string | null; is_lead?: boolean; role?: string | null }

const props = withDefaults(defineProps<{
  people: Person[]
  size?: number
  /** Show the first person's name beside the faces. Off where the row is already tight. */
  showName?: boolean
  /** Draw a muted placeholder when nobody is on the task, rather than nothing at all. */
  showEmpty?: boolean
  max?: number
}>(), { size: 24, showName: false, showEmpty: false, max: 3 })

/**
 * Two letters from a display name.
 *
 * A single-word name gives its first two characters rather than one, because a column of single
 * letters is much harder to tell apart than a column of pairs.
 */
function initials(name: string | null): string {
  const parts = (name ?? '').trim().split(/\s+/).filter(Boolean)

  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()

  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
}

/*
 * Vuetify's theme colours rather than arbitrary hexes, so the faces stay legible in both themes and
 * against whatever surface the host screen uses. Error is deliberately absent: a red face would
 * read as a person in trouble.
 */
const PALETTE = ['primary', 'info', 'success', 'warning', 'secondary']

function colour(person: Person): string {
  const seed = person.id ?? person.name ?? ''
  let hash = 0

  for (let i = 0; i < seed.length; i++) hash = (hash * 31 + seed.charCodeAt(i)) >>> 0

  return PALETTE[hash % PALETTE.length]
}

const shown = computed(() => props.people.slice(0, props.max))
const overflow = computed(() => Math.max(0, props.people.length - props.max))

/** The lead is first, so it is their name that stands for the task in a one-name space. */
const label = computed(() => {
  if (!props.people.length) return t('project_management.tasks.unassigned')

  return props.people.length === 1
    ? (props.people[0].name ?? t('project_management.tasks.someone'))
    : t('project_management.tasks.assignee_and_others', {
        name: props.people[0].name ?? t('project_management.tasks.someone'),
        count: props.people.length - 1,
      })
})
</script>

<template>
  <div v-if="people.length || showEmpty" class="d-inline-flex align-center" :title="label">
    <div v-if="people.length" class="assignee-stack d-inline-flex align-center">
      <VAvatar
        v-for="person in shown" :key="person.id ?? person.name ?? ''"
        :size="size" :color="colour(person)" variant="tonal"
        class="assignee-face"
      >
        <span class="assignee-initials">{{ initials(person.name) }}</span>

        <!-- The one person answerable for the task, marked rather than merely ordered first:
             position alone is not a claim anybody can read. -->
        <VIcon
          v-if="person.is_lead && people.length > 1"
          icon="tabler-star-filled" :size="Math.round(size / 3)"
          class="assignee-lead"
        />
      </VAvatar>

      <VAvatar v-if="overflow" :size="size" color="surface-variant" variant="tonal" class="assignee-face">
        <span class="assignee-initials">+{{ overflow }}</span>
      </VAvatar>
    </div>

    <!-- Nobody on it. Said out loud, because an empty space reads as a column that failed to load. -->
    <VAvatar v-else :size="size" color="surface-variant" variant="tonal" class="assignee-face">
      <VIcon icon="tabler-user-off" :size="Math.round(size * 0.55)" class="text-disabled" />
    </VAvatar>

    <span v-if="showName" class="text-caption ms-2 text-truncate">{{ label }}</span>
  </div>
</template>

<style scoped>
/* Overlapped so a crew of four costs the width of about two. */
.assignee-stack > .assignee-face + .assignee-face { margin-inline-start: -6px; }

.assignee-face {
  /* A ring in the surface colour is what keeps overlapping faces separable. */
  border: 2px solid rgb(var(--v-theme-surface));
  position: relative;
}

.assignee-initials {
  font-size: 0.62rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  line-height: 1;
}

.assignee-lead {
  position: absolute;
  inset-block-end: -2px;
  inset-inline-end: -2px;
  color: rgb(var(--v-theme-warning));
}
</style>
