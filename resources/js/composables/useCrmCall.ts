import axios from 'axios'
import { ref } from 'vue'
import { route as ziggyRoute } from 'ziggy-js'

/**
 * Provider-agnostic click-to-call. Posts to the CRM, which dials through
 * whichever VoIP provider is active (SIP/Zoiper, Twilio, Vonage, Telnyx), logs
 * the call as an activity, and returns either a sip:/tel: URI to open (so the
 * softphone launches) or confirmation that an API call was placed.
 */
export interface CallTarget {
  to: string
  name?: string
  related_type?: 'lead' | 'contact' | 'account' | 'deal'
  related_id?: number
}

export function useCrmCall() {
  const calling = ref(false)
  const lastMessage = ref('')

  function csrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
  }

  async function call(target: CallTarget): Promise<{ success: boolean; message: string }> {
    calling.value = true
    try {
      const { data } = await axios.post(ziggyRoute('crm.calls.initiate'), target, {
        headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
      })
      lastMessage.value = data.message
      // sip:/tel: links launch the OS-registered phone (e.g. Zoiper).
      if (data.uri) window.location.href = data.uri
      return { success: true, message: data.message }
    } catch (e: any) {
      const message = e?.response?.data?.message ?? 'Could not place the call.'
      lastMessage.value = message
      return { success: false, message }
    } finally {
      calling.value = false
    }
  }

  return { call, calling, lastMessage }
}
