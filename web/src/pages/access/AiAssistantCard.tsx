/**
 * The AI card, wired to the assistant this product already has.
 *
 * "Ask Purchases" is a real screen backed by a real endpoint
 * (`v1/insights/ask`), so this button goes there rather than pretending to
 * answer here. A chat bubble on this page with a scripted reply inside it would
 * be a lie about a capability, on the one screen where trust is the product.
 *
 * TODO(ai-prefill): Ask Purchases keeps its question in component state, so the
 * suggested question below cannot be carried across yet. When it reads one from
 * the URL, pass it here instead of dropping the user on an empty box.
 */

import { useNavigate } from 'react-router-dom'
import { ArrowRight, Bot, Sparkles } from 'lucide-react'

export function AiAssistantCard() {
  const navigate = useNavigate()

  return (
    <section className="access-panel access-ai" aria-labelledby="access-ai-heading">
      <Sparkles size={16} aria-hidden className="access-ai__sparkle" />
      <h2 id="access-ai-heading">Need help setting access?</h2>
      <p>Our AI assistant can suggest the right permissions for your team.</p>

      <button
        type="button"
        className="access-btn access-btn--ai"
        onClick={() => navigate('/dashboard/ai-insights')}
      >
        Chat with AI <ArrowRight size={14} aria-hidden />
      </button>

      <Bot size={58} aria-hidden className="access-ai__visual" />
    </section>
  )
}
