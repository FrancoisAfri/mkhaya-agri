import { useI18n } from 'vue-i18n'

/**
 * A compliance reason code, in words (COM-2).
 *
 * The code is the fact and the thing every screen counts, charts and groups on. It is not the
 * thing to show a person: `MEDICAL_EXPIRED` on a screen a site manager reads is a demand that
 * they learn an internal vocabulary.
 *
 * Shared rather than repeated in each page, because four copies of the same lookup is four
 * chances for one of them to render the key path instead. `te()` is checked first: a key nothing
 * defines has no failure mode in vue-i18n — it renders the key itself, which looks like a label
 * until somebody reads it — so an unknown code falls back to a readable form of the code rather
 * than to `sheq.compliance.reason.whatever`.
 */
export function useReasonLabel() {
  const { t, te } = useI18n()

  return (code: string | null | undefined): string => {
    if (!code) return ''

    const key = `sheq.compliance.reason.${code.toLowerCase()}`

    if (te(key)) return t(key)

    // A code added to the engine and not yet labelled. Readable, and visibly not a label.
    return code.toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())
  }
}
