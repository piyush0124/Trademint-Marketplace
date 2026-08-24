/**
 * AI layer types, mirroring the Phase 11–14 API responses.
 *
 * Kept in their own file rather than appended to `api.ts` because the whole AI surface is
 * optional — it can be switched off server-side, and a reader should be able to see at a
 * glance which types belong to a feature that may not be present.
 */

export type AiConversationType =
  | 'customer_assistant'
  | 'business_assistant'
  | 'admin_copilot'
  | 'requirement_builder'
  | 'search_assistant';

export type AiMessageRole = 'system' | 'user' | 'assistant' | 'tool';

export type AiActionStatus =
  | 'pending_confirmation'
  | 'confirmed'
  | 'executed'
  | 'rejected'
  | 'failed'
  | 'expired';

/** A write the assistant has proposed. Nothing happens until it is confirmed. */
export interface AiPendingAction {
  uuid: string;
  tool: string;
  summary: string | null;
}

export interface AiAction {
  id: string;
  type: 'ai_action';
  attributes: {
    tool: string;
    /** Composed server-side from validated arguments — not the model's description. */
    summary: string | null;
    status: AiActionStatus;
    status_label: string;
    is_confirmable: boolean;
    arguments: Record<string, unknown>;
    result: Record<string, unknown> | null;
    failure_reason: string | null;
    expires_at: string | null;
    executed_at: string | null;
    created_at: string | null;
  };
}

export interface AiMessage {
  id: number;
  type: 'ai_message';
  attributes: {
    role: AiMessageRole;
    /** Null for tool turns — machinery, not conversation. */
    content: string | null;
    tool: string | null;
    tool_ok: boolean | null;
    /** Which tools produced the facts in this reply. Rendered as provenance. */
    tools_used: string[];
    pending_actions: AiPendingAction[];
    model: string | null;
    provider: string | null;
    created_at: string | null;
  };
}

export interface AiConversation {
  id: string;
  type: 'ai_conversation';
  attributes: {
    conversation_type: AiConversationType;
    conversation_type_label: string;
    title: string | null;
    context: Record<string, string> | null;
    message_count?: number;
    last_message_at: string | null;
    created_at: string | null;
  };
  relationships: {
    messages?: AiMessage[];
    actions?: AiAction[];
  };
}

/**
 * Everything the UI needs to decide whether to render AI at all.
 *
 * `enabled` and `available` are separate on purpose: "switched off by the operator" and
 * "provider is down" need different messages, and conflating them makes the second look
 * like a bug.
 */
export interface AiStatus {
  enabled: boolean;
  available: boolean;
  provider: string;
  model: string;
  features: Record<string, boolean>;
  daily_limit: number;
  used_today: number;
  /** Null means unlimited. */
  remaining_today: number | null;
  conversation_types: { value: AiConversationType; label: string; enabled: boolean }[];
}

/* ---------------------------------------------------------------------- *
 | Requirement builder
 * ---------------------------------------------------------------------- */

export interface AiRequirementDraft {
  title: string | null;
  description: string | null;
  category: string | null;
  category_name: string | null;
  city: string | null;
  state: string | null;
  quantity: number | null;
  unit: string | null;
  budget_min: number | null;
  budget_max: number | null;
  currency: string;
  required_date: string | null;
  timeline_text: string | null;
  urgency: string;
  specifications: string[];
}

export interface AiRequirementMeta {
  /** Per-field, so the UI highlights what to check rather than everything. */
  confidence: Record<string, number>;
  resolution: { category: string; location: string; location_known: boolean };
  /** Real category rows only — a "did you mean" picker cannot offer a fake one. */
  category_options: { slug: string; name: string }[];
  location_options: { city: string; state: string | null }[];
  missing: string[];
  questions: string[];
  ready_to_draft: boolean;
  ready_to_publish: boolean;
  note: string;
}

/* ---------------------------------------------------------------------- *
 | Natural language search
 * ---------------------------------------------------------------------- */

export interface AiSearchMeta {
  total: number;
  query: string;
  /** What the AI understood, so a buyer can correct it rather than guess. */
  interpretation: Record<string, string | number | boolean>;
  filters_applied: Record<string, string | number | boolean>;
  /** Mentioned in the query but not expressible as a filter. */
  not_applied: string[];
  used_ai: boolean;
  explanations: Record<string, string[]>;
  fallback_notice: string | null;
}

/* ---------------------------------------------------------------------- *
 | Intelligence
 * ---------------------------------------------------------------------- */

export interface AiMatchExplanation {
  requirement: string;
  count: number;
  matches: {
    match_id: string;
    business: string | null;
    business_slug: string | null;
    city: string | null;
    /** From the deterministic engine, never generated. */
    match_score: number;
    reasons: string[];
    breakdown: { dimension: string; score: number; weight_percentage: number; reason: string | null }[];
    matched_capabilities: { type: string; name: string }[];
    verified: boolean;
    rating: number | null;
    review_count: number;
    response_status: string;
  }[];
  summary: string;
  used_ai: boolean;
}

export interface AiQuoteComparison {
  requirement: string;
  count: number;
  currency?: string;
  quotes: Record<string, unknown>[];
  /** Server-computed comparisons. The model never does arithmetic on money. */
  facts: {
    lowest_total: string;
    highest_total: string;
    spread: string;
    spread_percentage: number | null;
    cheapest_quote_number: string | null;
    fastest_quote_number: string | null;
    expired_count: number;
    verified_supplier_count: number;
    quotes_without_dates: number;
    quotes_without_warranty: number;
    quotes_without_payment_terms: number;
  };
  summary: string;
  used_ai: boolean;
  note?: string;
}

export interface AiListingDraft {
  title: string | null;
  category: string | null;
  category_name: string | null;
  summary: string | null;
  description: string | null;
  features: string[];
  service_details: string[];
  faqs: { question: string; answer: string }[];
  seo_description: string | null;
  suggested_unit: string | null;
}

export interface AiQuoteStructure {
  line_items: { description: string; item_type: string }[];
  cost_components: string[];
  missing_information: string[];
  questions_for_customer: string[];
  terms_to_specify: string[];
  scope_risks: string[];
}

/* ---------------------------------------------------------------------- *
 | Admin usage
 * ---------------------------------------------------------------------- */

export interface AiUsageReport {
  window_days: number;
  totals: {
    requests: number;
    successful: number;
    failed: number;
    tokens: number;
    estimated_cost: string;
    average_latency_ms: number;
  };
  by_feature: { feature: string; requests: number; tokens: number; estimated_cost: string; average_latency_ms: number }[];
  by_role: { role: string; requests: number; tokens: number }[];
  by_status: { status: string; requests: number }[];
  top_users: { user_id: number; role: string | null; requests: number; tokens: number }[];
}
