import { FormEvent, useEffect, useRef, useState } from 'react';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { AiConversation, AiMessage, AiPendingAction } from '../../../types/ai';
import { useAuth } from '../../auth/AuthContext';
import { useAiStatus } from '../useAiStatus';
import AiConfirmationCard from './AiConfirmationCard';

/**
 * The assistant drawer.
 *
 * Three things it does that a generic chat widget would not:
 *
 *  - **Renders nothing at all when AI is unavailable.** No greyed-out button, no "AI is
 *    down" banner. The marketplace simply looks like itself, which is the point of the
 *    whole fallback design.
 *  - **Shows provenance.** Each reply lists the tools that produced it, so a user can see
 *    the answer came from their own data rather than from the model's imagination.
 *  - **Surfaces confirmations as a distinct card**, never as text the assistant claims to
 *    have acted on.
 *
 * The thread type is chosen from the signed-in role, so a customer cannot open an admin
 * copilot from the UI — and the API would refuse anyway.
 */
export default function AiAssistantPanel() {
  const { isOwner, isAdmin, isCustomer } = useAuth();
  const { status, isFeatureReady, refresh } = useAiStatus();

  const [open, setOpen] = useState(false);
  const [conversation, setConversation] = useState<AiConversation | null>(null);
  const [messages, setMessages] = useState<AiMessage[]>([]);
  const [pending, setPending] = useState<AiPendingAction[]>([]);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const scrollRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);

  const conversationType = isAdmin ? 'admin_copilot' : isOwner ? 'business_assistant' : 'customer_assistant';
  const feature = isAdmin ? 'admin_copilot' : 'assistant';
  const ready = isFeatureReady(feature);

  // Newest turn in view after every exchange.
  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, pending]);

  useEffect(() => {
    if (open) inputRef.current?.focus();
  }, [open, conversation]);

  useEffect(() => {
    if (!open) return;

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false);
    };

    document.addEventListener('keydown', onKeyDown);

    return () => document.removeEventListener('keydown', onKeyDown);
  }, [open]);

  // Nothing renders unless AI is on, reachable and enabled for this role.
  if (!ready) return null;

  const startConversation = async (): Promise<AiConversation | null> => {
    try {
      const res = await api.post<{ data: AiConversation }>('/ai/conversations', { type: conversationType });
      setConversation(res.data.data);

      return res.data.data;
    } catch (err) {
      setError(getErrorMessage(err));

      return null;
    }
  };

  const send = async (event: FormEvent) => {
    event.preventDefault();

    const text = input.trim();
    if (text === '' || busy) return;

    setError('');
    setBusy(true);

    const thread = conversation ?? (await startConversation());

    if (thread === null) {
      setBusy(false);

      return;
    }

    // Optimistic user turn: the assistant can take seconds on a local model, and a
    // message that vanishes until the reply arrives feels broken.
    const optimistic: AiMessage = {
      id: -Date.now(),
      type: 'ai_message',
      attributes: {
        role: 'user',
        content: text,
        tool: null,
        tool_ok: null,
        tools_used: [],
        pending_actions: [],
        model: null,
        provider: null,
        created_at: new Date().toISOString(),
      },
    };

    setMessages((current) => [...current, optimistic]);
    setInput('');

    try {
      const res = await api.post<{ data: AiMessage; meta: { pending_actions: AiPendingAction[] } }>(
        `/ai/conversations/${thread.id}/messages`,
        { message: text }
      );

      setMessages((current) => [...current, res.data.data]);
      setPending(res.data.meta.pending_actions ?? []);
      // Keeps the remaining-quota figure honest.
      refresh();
    } catch (err) {
      setError(getErrorMessage(err));
      // The optimistic turn is rolled back so the transcript matches the server's.
      setMessages((current) => current.filter((message) => message.id !== optimistic.id));
      setInput(text);
    } finally {
      setBusy(false);
    }
  };

  const suggestions = isCustomer
    ? ['What requirements have I posted?', 'Which quotes are pending?', 'How does negotiation work?']
    : isOwner
      ? ['What opportunities match my business?', 'Which enquiries need a reply?', 'Summarise my orders']
      : ['How many verifications are pending?', 'Show unresolved disputes', 'Which categories have the most demand?'];

  return (
    <>
      {!open && (
        <button type="button" className="mp-ai-launcher" onClick={() => setOpen(true)} aria-expanded={false} aria-controls="ai-assistant">
          <span aria-hidden="true">✦</span>
          <span className="d-none d-sm-inline">Ask the assistant</span>
        </button>
      )}

      {open && (
        <div className="mp-ai-panel" id="ai-assistant" role="dialog" aria-modal="false" aria-label="AI assistant">
          <header className="mp-ai-panel__header">
            <div className="min-width-0">
              <div className="mp-ai-panel__title">
                <span aria-hidden="true">✦</span> Assistant
              </div>
              <div className="mp-ai-panel__subtitle">
                {status?.remaining_today !== null && status?.remaining_today !== undefined
                  ? `${status.remaining_today} requests left today`
                  : 'Answers come from your own marketplace data'}
              </div>
            </div>
            <button type="button" className="mp-modal-close" onClick={() => setOpen(false)} aria-label="Close assistant">✕</button>
          </header>

          <div className="mp-ai-panel__body" ref={scrollRef}>
            {messages.length === 0 && (
              <div className="mp-ai-empty">
                <p className="small mb-3">
                  Ask about your own requirements, quotes and orders, or how the marketplace works.
                  Answers are read from your data — the assistant never invents a business or a price.
                </p>
                <div className="d-flex flex-column gap-2">
                  {suggestions.map((suggestion) => (
                    <button
                      key={suggestion}
                      type="button"
                      className="mp-ai-suggestion"
                      onClick={() => setInput(suggestion)}
                    >
                      {suggestion}
                    </button>
                  ))}
                </div>
              </div>
            )}

            {messages.map((message) => {
              const a = message.attributes;

              // Tool turns are machinery. They are shown as a provenance note rather
              // than a chat bubble, so the transcript stays readable.
              if (a.role === 'tool') {
                return (
                  <div key={message.id} className="mp-ai-tool-note">
                    {a.tool_ok === false ? 'Could not' : 'Checked'} {(a.tool ?? '').replace(/_/g, ' ')}
                  </div>
                );
              }

              return (
                <div key={message.id} className={`mp-ai-bubble mp-ai-bubble--${a.role}`}>
                  <div style={{ whiteSpace: 'pre-wrap' }}>{a.content}</div>
                  {a.tools_used.length > 0 && (
                    <div className="mp-ai-bubble__provenance">
                      From your {a.tools_used.map((tool) => tool.replace(/_/g, ' ').replace(/^get my /, '')).join(', ')}
                    </div>
                  )}
                </div>
              );
            })}

            {pending.map((action) => (
              <AiConfirmationCard
                key={action.uuid}
                action={action}
                onResolved={() => setPending((current) => current.filter((item) => item.uuid !== action.uuid))}
              />
            ))}

            {busy && <div className="mp-ai-typing" role="status" aria-live="polite">Thinking…</div>}

            {error && <div className="alert alert-danger py-2 small mb-0" role="alert">{error}</div>}
          </div>

          <form className="mp-ai-panel__footer" onSubmit={send}>
            <label className="visually-hidden" htmlFor="ai-input">Message the assistant</label>
            <textarea
              id="ai-input"
              ref={inputRef}
              className="form-control"
              rows={2}
              placeholder="Ask about your requirements, quotes or orders…"
              value={input}
              maxLength={4000}
              onChange={(event) => setInput(event.target.value)}
              onKeyDown={(event) => {
                // Enter sends, Shift+Enter breaks the line — the convention people expect.
                if (event.key === 'Enter' && !event.shiftKey) {
                  event.preventDefault();
                  send(event as unknown as FormEvent);
                }
              }}
            />
            <button type="submit" className="btn btn-mp-primary btn-sm" disabled={busy || input.trim() === ''}>
              Send
            </button>
          </form>
        </div>
      )}
    </>
  );
}
