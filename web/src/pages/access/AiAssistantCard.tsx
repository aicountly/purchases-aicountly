/**
 * The AI card, wired to AI Pulse.
 *
 * "Ask AI Pulse" opens the AI Pulse thread Connect pins first, in this company,
 * with the question below in its composer: the administrator reads it, says
 * who is on their team, and sends it themselves. Nothing is answered here — a
 * chat bubble on this page with a scripted reply inside it would be a lie about
 * a capability, on the one screen where trust is the product.
 *
 * Without Connect (down, blocked, not deployed) the button opens AI Pulse's own
 * app in a new tab instead. The question does not travel there, so the title
 * promises only AI Pulse. Purchase intelligence (`v1/insights/ask`) is not the
 * way there: it answers approved spend and payables questions, not who should
 * have which access.
 */

import { ArrowRight, Bot, Sparkles } from 'lucide-react'
import { useConnectReady } from '../../components/ConnectEmbed'
import { askAiPulse } from '../../services/connect'

const ACCESS_QUESTION =
  'Suggest access profiles for my team in AICOUNTLY Purchases — for example Buyer, Approver and Accounts payable — and the permissions each one should hold, giving only the access the job needs.'

export function AiAssistantCard() {
  const inConnect = useConnectReady()

  return (
    <section className="access-panel access-ai" aria-labelledby="access-ai-heading">
      <Sparkles size={16} aria-hidden className="access-ai__sparkle" />
      <h2 id="access-ai-heading">Need help setting access?</h2>
      <p>Our AI assistant can suggest the right permissions for your team.</p>

      <button
        type="button"
        className="access-btn access-btn--ai"
        title={inConnect ? 'Opens AI Pulse in Connect, with a question about access for your team ready to send' : 'Opens AI Pulse in a new tab'}
        onClick={() => askAiPulse(ACCESS_QUESTION)}
      >
        Ask AI Pulse <ArrowRight size={14} aria-hidden />
      </button>

      <Bot size={58} aria-hidden className="access-ai__visual" />
    </section>
  )
}
