export interface ApiUser {
  id: number;
  type: 'user';
  attributes: {
    name: string;
    email?: string;
    role: 'customer' | 'owner' | 'admin';
    created_at?: string;
  };
}

export interface ApiCategory {
  id: number;
  type: 'category';
  attributes: {
    slug: string;
    name: string;
    description: string | null;
    is_active: boolean;
    /** Null for an industry; set for a speciality beneath one. */
    parent_id: number | null;
    parent_slug?: string | null;
    is_top_level: boolean;
    /** Present only when the request asked for `with_counts=1`. Subtree totals. */
    children_count?: number;
    businesses_count?: number;
    listings_count?: number;
  };
}

export interface ApiAttributeDefinition {
  id: number;
  type: 'category_attribute_definition';
  attributes: {
    key: string;
    label: string;
    type: 'string' | 'integer' | 'decimal' | 'boolean' | 'select';
    options: string[] | null;
    is_required: boolean;
    is_filterable: boolean;
    sort_order: number;
  };
}

export interface ApiLocation {
  id: number;
  type: 'location';
  attributes: {
    city: string;
    area: string | null;
    locality: string | null;
    state: string | null;
    country: string;
    postal_code: string | null;
    latitude: string | null;
    longitude: string | null;
  };
}

export interface ApiIndustry {
  id: number;
  type: 'industry';
  attributes: {
    slug: string;
    name: string;
  };
}

export interface ApiBusiness {
  id: number;
  type: 'business';
  attributes: {
    slug: string;
    name: string;
    business_type: string | null;
    years_in_business: number | null;
    trade_type: 'b2b' | 'b2c' | 'both' | null;
    logo_url: string | null;
    cover_url: string | null;
    about: string | null;
    phone: string | null;
    whatsapp: string | null;
    email: string | null;
    website: string | null;
    business_hours: Record<string, { open?: string; close?: string; closed?: boolean }> | null;
    is_available: boolean;
    is_verified: boolean;
    /** Null means "not rated yet" — never render it as a zero score. */
    rating: string | null;
    review_count: number;
    is_fully_verified: boolean;
    /** Phase 9 platform vetting state. Public; the documents behind it are not. */
    verification_status: VerificationStatus;
    verification_status_label: string;
    is_suspended: boolean;
    verification: {
    /**
     * Whether a GST number is on file — not the number, which is owner/admin only.
     * Lets the public checklist tell "not applicable" apart from "not yet confirmed".
     */
    gst_applicable?: boolean;
      email: boolean;
      phone: boolean;
      business: boolean;
      address: boolean;
      gst: boolean;
    };
    gst_number?: string | null;
    listings_count?: number;
    orders_count?: number;
    reviews_count?: number;
    documents_count?: number;
  };
  relationships?: {
    location?: ApiLocation | null;
    category?: ApiCategory | null;
    industries?: ApiIndustry[];
    service_areas?: ApiLocation[];
  };
}

export interface ApiProduct {
  id: number;
  type: 'product';
  attributes: {
    name: string;
    description: string | null;
    unit: string | null;
    min_order: number | null;
    max_order: number | null;
    capacity: string | null;
    customization_available: boolean;
    is_active: boolean;
    created_at: string | null;
  };
  relationships?: {
    category?: ApiCategory | null;
  };
}

export interface ApiService {
  id: number;
  type: 'service';
  attributes: {
    name: string;
    description: string | null;
    min_order: number | null;
    estimated_delivery_time: string | null;
    customization_available: boolean;
    is_active: boolean;
    created_at: string | null;
  };
  relationships?: {
    category?: ApiCategory | null;
  };
}

export interface ApiMedia {
  id: number;
  type: 'media';
  attributes: {
    url: string | null;
    filename: string;
    mime_type: string | null;
    is_primary: boolean;
    sort_order: number;
  };
}

export type SystemStatus = 'draft' | 'pending_review' | 'published' | 'rejected' | 'archived';
export type AvailabilityStatus = 'available' | 'sold' | 'rented' | 'expired' | 'inactive';

export type LeadStatus = 'new' | 'contacted' | 'converted' | 'lost';

export interface ApiLead {
  id: number;
  type: 'lead';
  attributes: {
    name: string;
    email: string | null;
    phone: string | null;
    message: string | null;
    status: LeadStatus;
    contacted_at: string | null;
    created_at: string | null;
  };
  relationships: {
    listing?: ApiListing;
  };
}

export interface LeadStatusCounts {
  new: number;
  contacted: number;
  converted: number;
  lost: number;
}

export interface ApiListing {
  id: string;
  type: 'listing';
  attributes: {
    /**
     * Keyword-bearing URL segment, e.g. `full-truckload-freight-a915e971`.
     *
     * Build links with `listingPath()` rather than reading this directly — it is
     * null for a titleless draft, and the helper handles the uuid fallback.
     */
    slug: string | null;
    title: string | null;
    description: string | null;
    price: string | null;
    price_max: string | null;
    unit: string | null;
    min_order_quantity: number | null;
    currency: string;
    system_status: SystemStatus;
    availability_status: AvailabilityStatus;
    rejection_reason?: string | null;
    submitted_at: string | null;
    published_at: string | null;
    rejected_at: string | null;
    archived_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    is_editable: boolean;
    leads_count?: number;
    new_leads_count?: number;
    /** Category attribute values keyed by definition key. Convenient for lookups. */
    attributes: Record<string, string | number | boolean | null>;
    /**
     * The same values with their database-defined label and sort order. Use this
     * for display — it is what lets any industry render without a frontend label map.
     */
    attribute_details?: {
      key: string;
      label: string;
      type: 'string' | 'integer' | 'decimal' | 'boolean' | 'select';
      value: string | number | boolean | null;
    }[];
  };
  relationships: {
    category?: ApiCategory;
    location?: ApiLocation | null;
    user?: ApiUser;
    business?: ApiBusiness | null;
    media?: ApiMedia[];
  };
}

/**
 * Paginated `meta`. `status_counts` is generic because each collection
 * returns its own shape (leads count by lead status, requirements by
 * requirement status): callers name the shape they expect, and the default
 * index signature keeps untyped reads like `status_counts?.new` working.
 */
export interface PaginatedMeta<TStatusCounts = Record<string, number>> {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
  status_counts?: TStatusCounts;
}

export interface ApiEnvelope<T> {
  success: boolean;
  message: string;
  data: T;
}

export interface PaginatedResponse<T, TStatusCounts = Record<string, number>> {
  success: boolean;
  message: string;
  data: T[];
  meta: PaginatedMeta<TStatusCounts>;
  links?: Record<string, string | null>;
}

export type RequirementStatus = 'draft' | 'published' | 'matching' | 'receiving_quotes' | 'closed' | 'cancelled';
export type RequirementUrgency = 'low' | 'normal' | 'high' | 'urgent';

export interface ApiRequirement {
  id: string;
  type: 'requirement';
  attributes: {
    title: string | null;
    description: string | null;
    quantity: string | null;
    unit: string | null;
    budget_min: string | null;
    budget_max: string | null;
    required_date: string | null;
    urgency: RequirementUrgency | null;
    status: RequirementStatus;
    published_at: string | null;
    closed_at: string | null;
    cancelled_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    is_editable: boolean;
    is_cancellable: boolean;
  };
  relationships: {
    category?: ApiCategory;
    location?: ApiLocation | null;
    customer?: ApiUser;
  };
}

export interface RequirementStatusCounts {
  draft: number;
  published: number;
  matching: number;
  receiving_quotes: number;
  closed: number;
  cancelled: number;
}

/* ---------------------------------------------------------------------- *
 | Matching engine (Phase 4)
 * ---------------------------------------------------------------------- */

export type MatchResponseStatus = 'pending' | 'accepted' | 'declined';

/** The six scoring dimensions, keyed exactly as the API returns them. */
export type MatchDimension = 'category' | 'capability' | 'location' | 'budget' | 'availability' | 'trust';

export interface MatchDimensionScore {
  /** Normalised 0..1 sub-score for this dimension. */
  score: number;
  /** Raw configured weight, plus its share of the total as a percentage. */
  weight: number;
  weight_percentage: number;
  /** Percentage points this dimension contributed to the overall match. */
  points: number;
  reason: string | null;
}

export interface MatchedCapability {
  type: 'product' | 'service';
  name: string;
}

export interface MatchResponseCounts {
  pending: number;
  accepted: number;
  declined: number;
}

/**
 * One match record. The customer reads it for the `business` relationship,
 * a matched business reads it for the `requirement` relationship — the API
 * only ever includes the side the viewer is entitled to see.
 */
export interface ApiRequirementMatch {
  id: string;
  type: 'requirement_match';
  attributes: {
    match_score: number;
    match_percentage: number;
    /** Why this business was matched, most influential reason first. */
    reasons: string[];
    score_breakdown: Partial<Record<MatchDimension, MatchDimensionScore>>;
    capabilities: MatchedCapability[];
    invited_at: string | null;
    viewed_at: string | null;
    response_status: MatchResponseStatus;
    response_status_label: string;
    responded_at: string | null;
    response_note: string | null;
    question: string | null;
    question_asked_at: string | null;
    /** True only for the invited business, and only while it can still act. */
    can_respond: boolean;
  };
  relationships: {
    business?: ApiBusiness | null;
    requirement?: ApiRequirement | null;
  };
}

export interface MatchListMeta extends Partial<PaginatedMeta> {
  total: number;
  requirement_status?: RequirementStatus;
  response_counts?: MatchResponseCounts;
  weights?: Record<MatchDimension, number>;
  minimum_score?: number;
}

export interface MatchListResponse {
  success: boolean;
  message: string;
  data: ApiRequirementMatch[];
  meta: MatchListMeta;
}

/* ---------------------------------------------------------------------- *
 | Enquiry + quotation engine (Phase 5)
 * ---------------------------------------------------------------------- */

export type EnquiryStatus = 'pending' | 'accepted' | 'declined' | 'closed' | 'cancelled';

export interface EnquiryStatusCounts {
  pending: number;
  accepted: number;
  declined: number;
  closed: number;
  cancelled: number;
}

export interface ApiEnquiry {
  id: string;
  type: 'enquiry';
  attributes: {
    status: EnquiryStatus;
    status_label: string;
    initiated_by: string;
    customer_message: string | null;
    business_message: string | null;
    accepted_at: string | null;
    declined_at: string | null;
    closed_at: string | null;
    cancelled_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    quotes_count?: number;
    /** Affordance hints from the API; the backend re-checks every one. */
    can_respond: boolean;
    can_quote: boolean;
    can_cancel: boolean;
    is_viewer_customer: boolean;
    is_viewer_business: boolean;
  };
  relationships: {
    requirement?: ApiRequirement | null;
    business?: ApiBusiness | null;
    match?: ApiRequirementMatch | null;
    customer?: ApiUser | null;
    quotes?: ApiQuote[];
  };
}

export type QuoteStatus = 'draft' | 'sent' | 'viewed' | 'expired' | 'accepted' | 'rejected' | 'withdrawn';

export interface QuoteStatusCounts {
  draft: number;
  sent: number;
  viewed: number;
  expired: number;
  accepted: number;
  rejected: number;
  withdrawn: number;
}

/** Generic across every industry — never a property/vehicle/etc. type. */
export type QuoteItemType = 'product' | 'service' | 'project' | 'custom';

export interface ApiQuoteItem {
  id: number;
  type: 'quote_item';
  attributes: {
    sort_order: number;
    description: string;
    item_type: QuoteItemType;
    item_type_label: string;
    reference_type: string | null;
    reference_id: number | null;
    /**
     * Money and quantity arrive as fixed-point decimal *strings*, straight
     * from the backend's integer-based calculation. Parse them for display
     * only — never recompute a total from them and send it back.
     */
    quantity: string;
    unit: string | null;
    unit_price: string;
    tax_rate: string;
    tax_amount: string;
    discount_amount: string;
    subtotal: string;
    total: string;
  };
}

export interface ApiQuote {
  id: string;
  type: 'quote';
  attributes: {
    quote_number: string;
    title: string;
    description: string | null;

    subtotal: string;
    tax_amount: string;
    delivery_fee: string;
    discount_amount: string;
    other_charges: string;
    total_amount: string;
    currency: string;

    validity_date: string | null;
    estimated_delivery_date: string | null;
    estimated_completion_date: string | null;

    payment_terms: string | null;
    warranty_terms: string | null;
    cancellation_terms: string | null;
    notes: string | null;

    status: QuoteStatus;
    status_label: string;
    sent_at: string | null;
    viewed_at: string | null;
    expires_at: string | null;
    accepted_at: string | null;
    rejected_at: string | null;
    withdrawn_at: string | null;
    rejection_reason: string | null;
    created_at: string | null;
    updated_at: string | null;

    is_expired: boolean;
    /** Phase 6: number of offers in this quote's immutable history. */
    revision_count: number;
    /** Present when a negotiation is open on this quote. */
    negotiation_id?: string | null;
    can_edit: boolean;
    can_send: boolean;
    can_withdraw: boolean;
    can_decide: boolean;
    is_viewer_customer: boolean;
    is_viewer_business: boolean;
  };
  relationships: {
    items?: ApiQuoteItem[];
    business?: ApiBusiness | null;
    requirement?: ApiRequirement | null;
    enquiry?: ApiEnquiry | null;
    /** The live offer, used to seed a counter form. */
    current_revision?: ApiQuoteRevision | null;
  };
}

/** Line payload sent to the API. Derived amounts are deliberately absent. */
export interface QuoteItemInput {
  description: string;
  item_type: QuoteItemType;
  reference_id?: number | null;
  quantity: string;
  unit?: string | null;
  unit_price: string;
  tax_rate?: string;
  discount_amount?: string;
  sort_order?: number;
}

export interface QuoteInput {
  title: string;
  description?: string | null;
  currency?: string;
  validity_date?: string | null;
  estimated_delivery_date?: string | null;
  estimated_completion_date?: string | null;
  payment_terms?: string | null;
  warranty_terms?: string | null;
  cancellation_terms?: string | null;
  notes?: string | null;
  delivery_fee?: string;
  other_charges?: string;
  discount_amount?: string;
  items: QuoteItemInput[];
}

export interface SimpleListResponse<T> {
  success: boolean;
  message: string;
  data: T[];
  meta: { total: number };
}

/* ---------------------------------------------------------------------- *
 | Negotiation + quote revisions (Phase 6)
 * ---------------------------------------------------------------------- */

export type NegotiationStatus = 'open' | 'countered' | 'accepted' | 'rejected' | 'expired' | 'withdrawn' | 'closed';
export type NegotiationParty = 'customer' | 'business';
export type TimelineEntryType = 'counter' | 'accept' | 'reject' | 'withdraw' | 'close' | 'message' | 'system';

export interface NegotiationStatusCounts {
  open: number;
  countered: number;
  accepted: number;
  rejected: number;
  expired: number;
  withdrawn: number;
  closed: number;
}

/** One immutable offer in the history. Money is decimal strings, as stored. */
export interface ApiQuoteRevision {
  id: string;
  type: 'quote_revision';
  attributes: {
    revision_number: number;
    revision_type: string;
    authored_by: NegotiationParty;
    authored_by_label: string;
    is_final_offer: boolean;
    subtotal: string;
    tax_amount: string;
    delivery_fee: string;
    discount_amount: string;
    other_charges: string;
    total_amount: string;
    currency: string;
    validity_date: string | null;
    estimated_delivery_date: string | null;
    estimated_completion_date: string | null;
    expires_at: string | null;
    is_expired: boolean;
    payment_terms: string | null;
    warranty_terms: string | null;
    cancellation_terms: string | null;
    notes: string | null;
    message: string | null;
    is_current: boolean;
    superseded_at: string | null;
    created_at: string | null;
  };
  relationships: {
    items?: ApiQuoteItem[];
  };
}

export interface ApiNegotiationMessage {
  id: number;
  type: 'negotiation_message';
  attributes: {
    entry_type: TimelineEntryType;
    /** Which side spoke. The author's identity is deliberately not exposed. */
    author_party: NegotiationParty | 'system';
    body: string | null;
    created_at: string | null;
  };
  relationships: {
    revision?: ApiQuoteRevision | null;
  };
}

export interface ApiNegotiation {
  id: string;
  type: 'negotiation';
  attributes: {
    status: NegotiationStatus;
    status_label: string;
    initiated_by: NegotiationParty;
    awaiting_party: NegotiationParty | null;
    round: number;
    is_active: boolean;
    is_expired: boolean;
    last_activity_at: string | null;
    accepted_at: string | null;
    rejected_at: string | null;
    withdrawn_at: string | null;
    closed_at: string | null;
    outcome_note: string | null;
    created_at: string | null;
    viewer_party: NegotiationParty | null;
    is_my_turn: boolean;
    /** Turn rules, resolved server-side. Render buttons from these, not from roles. */
    can_counter: boolean;
    can_accept: boolean;
    can_reject: boolean;
    can_withdraw: boolean;
    can_message: boolean;
  };
  relationships: {
    quote?: ApiQuote | null;
    requirement?: ApiRequirement | null;
    current_revision?: ApiQuoteRevision | null;
    revisions?: ApiQuoteRevision[];
    messages?: ApiNegotiationMessage[];
    business?: ApiBusiness | null;
  };
}

/** One row of the server-computed comparison table. */
export interface QuoteComparisonRow {
  quote_id: string;
  quote_number: string;
  business_name: string | null;
  business_rating: string | null;
  business_is_verified: boolean;
  total_amount: string;
  currency: string;
  status: QuoteStatus;
  estimated_delivery_date: string | null;
  estimated_completion_date: string | null;
  validity_date: string | null;
  is_expired: boolean;
  warranty_terms: string | null;
  payment_terms: string | null;
  revision_count: number;
  is_negotiating: boolean;
  within_budget: boolean | null;
}

/* ---------------------------------------------------------------------- *
 | Orders (Phase 7)
 * ---------------------------------------------------------------------- */

export type OrderStatus =
  | 'pending' | 'confirmed' | 'processing' | 'in_progress' | 'ready'
  | 'dispatched' | 'delivered' | 'completed' | 'cancelled' | 'disputed';

export type OrderPaymentStatus =
  | 'unpaid' | 'pending' | 'partially_paid' | 'paid' | 'partially_refunded' | 'refunded' | 'failed';

export type FulfilmentStatus =
  | 'not_started' | 'in_progress' | 'ready' | 'dispatched' | 'delivered' | 'completed' | 'cancelled';

export type OrderStatusCounts = Record<OrderStatus, number>;

export interface ApiOrderItem {
  id: number;
  type: 'order_item';
  attributes: {
    sort_order: number;
    description: string;
    item_type: QuoteItemType;
    item_type_label: string;
    reference_type: string | null;
    reference_id: number | null;
    quantity: string;
    unit: string | null;
    unit_price: string;
    tax_rate: string;
    tax_amount: string;
    discount_amount: string;
    subtotal: string;
    total: string;
  };
}

export interface ApiOrderStatusHistory {
  id: number;
  type: 'order_status_history';
  attributes: {
    from_status: OrderStatus | null;
    from_status_label: string | null;
    to_status: OrderStatus;
    to_status_label: string;
    /** Which side acted — the individual's identity is deliberately not exposed. */
    changed_by_party: 'customer' | 'business' | 'admin' | 'system';
    note: string | null;
    created_at: string | null;
  };
}

export interface ApiOrder {
  id: string;
  type: 'order';
  attributes: {
    order_number: string;
    status: OrderStatus;
    status_label: string;
    payment_status: OrderPaymentStatus;
    payment_status_label: string;
    fulfilment_status: FulfilmentStatus;
    fulfilment_status_label: string;

    subtotal: string;
    tax_amount: string;
    delivery_fee: string;
    discount_amount: string;
    other_charges: string;
    total_amount: string;
    amount_paid: string;
    amount_refunded: string;
    amount_due: string;
    currency: string;

    expected_delivery_date: string | null;
    expected_completion_date: string | null;
    customer_notes: string | null;
    business_notes: string | null;
    cancellation_reason: string | null;

    placed_at: string | null;
    confirmed_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    created_at: string | null;

    viewer_party: 'customer' | 'business' | 'admin' | null;
    is_payable: boolean;
    /** Exactly the statuses this viewer may set right now, from the backend. */
    available_transitions: { value: OrderStatus; label: string }[];
    next_action: string | null;

    /* Phase 9/10 affordances, computed by the same services that enforce them. */
    can_review: boolean;
    /** Why the review form is hidden. Only ever populated for the customer. */
    review_blocked_reason: string | null;
    can_dispute: boolean;
    /** Allowed dispute reasons, from the backend — never a frontend list. */
    dispute_reasons: string[];
  };
  relationships: {
    items?: ApiOrderItem[];
    business?: ApiBusiness | null;
    requirement?: ApiRequirement | null;
    quote?: ApiQuote | null;
    status_history?: ApiOrderStatusHistory[];
    review?: ApiReview | null;
    active_dispute?: {
      id: string;
      reference: string;
      status: DisputeStatus;
      status_label: string;
      raised_by_party: 'customer' | 'business';
    } | null;
  };
}

export interface QuoteComparisonResponse {
  success: boolean;
  message: string;
  data: ApiQuote[];
  meta: {
    total: number;
    requirement: {
      id: string;
      title: string | null;
      quantity: string | null;
      unit: string | null;
      budget_min: string | null;
      budget_max: string | null;
      required_date: string | null;
    };
    comparison: {
      lowest_total_quote_id: string | null;
      highest_total_quote_id: string | null;
      fastest_quote_id: string | null;
      best_rated_quote_id: string | null;
      currencies: string[];
      comparable_on_price?: boolean;
      totals: QuoteComparisonRow[];
    };
  };
}

export interface AuthResponse {
  success: boolean;
  message: string;
  data: {
    token: string;
    user: ApiUser;
  };
}

export interface ValidationError {
  success?: boolean;
  message?: string;
  errors?: Record<string, string[]>;
}

/* ---------------------------------------------------------------------- *
 | Payments (Phase 8)
 * ---------------------------------------------------------------------- */

export type PaymentStatus =
  | 'pending' | 'initiated' | 'processing' | 'paid' | 'failed'
  | 'cancelled' | 'partially_refunded' | 'refunded';

export type RefundStatus = 'pending' | 'processing' | 'refunded' | 'failed' | 'cancelled';

export type PaymentStatusCounts = Record<PaymentStatus, number>;

/** A payment method on offer. Contains no secrets — labels and keys only. */
export interface PaymentMethod {
  provider: string;
  label: string;
  supports_webhooks: boolean;
  is_default: boolean;
}

export interface ApiRefund {
  id: string;
  type: 'refund';
  attributes: {
    refund_reference: string;
    amount: string;
    currency: string;
    status: RefundStatus;
    status_label: string;
    reason: string | null;
    failure_reason: string | null;
    processed_at: string | null;
    created_at: string | null;
  };
}

export interface ApiPayment {
  id: string;
  type: 'payment';
  attributes: {
    payment_reference: string;
    provider: string;
    provider_payment_id?: string | null;
    amount: string;
    amount_refunded: string;
    currency: string;
    status: PaymentStatus;
    status_label: string;
    payment_method: string | null;
    /** Human-readable. Never a stack trace or raw provider error. */
    failure_reason: string | null;
    initiated_at: string | null;
    paid_at: string | null;
    failed_at: string | null;
    cancelled_at: string | null;
    expires_at: string | null;
    created_at: string | null;
    /** Whitelisted checkout details: instructions, redirect_url, client_token. */
    checkout: Record<string, string | boolean>;
    is_refundable: boolean;
    refundable_amount: string;
    can_confirm: boolean;
    can_refund: boolean;
    is_viewer_customer: boolean;
    is_viewer_business: boolean;
  };
  relationships: {
    order?: ApiOrder | null;
    refunds?: ApiRefund[];
    business?: ApiBusiness | null;
  };
}

export interface OrderPaymentsResponse {
  success: boolean;
  message: string;
  data: ApiPayment[];
  meta: {
    total: number;
    amount_due: string;
    currency: string;
    is_payable: boolean;
    methods: PaymentMethod[];
  };
}

/* ---------------------------------------------------------------------- *
 | Trust layer (Phase 9)
 * ---------------------------------------------------------------------- */

export type VerificationStatus = 'unverified' | 'pending' | 'verified' | 'rejected' | 'suspended';
export type ReviewStatus = 'published' | 'hidden' | 'flagged';

export interface ApiReview {
  id: string;
  type: 'review';
  attributes: {
    rating: number;
    comment: string | null;
    status: ReviewStatus;
    status_label: string;
    /** First name only — the customer's full identity is never published. */
    reviewer_name: string;
    business_response: string | null;
    business_responded_at: string | null;
    created_at: string | null;
    moderation_note?: string | null;
    can_respond: boolean;
    is_viewer_author: boolean;
  };
  relationships: {
    business?: ApiBusiness | null;
    order?: ApiOrder | null;
  };
}

/** One transparent trust factor. `available: false` means "not enough data yet". */
export interface TrustFactor {
  key: string;
  label: string;
  weight: number;
  score: number | null;
  contribution: number | null;
  available: boolean;
  evidence: string;
}

export interface ApiBusinessPassport {
  id: string;
  type: 'business_passport';
  attributes: {
    verification_status: VerificationStatus;
    verification_status_label: string;
    verified_at: string | null;
    is_fully_verified: boolean;
    is_suspended: boolean;
    /** Null when there is not enough evidence to score at all. */
    trust_score: number | null;
    trust_factors: TrustFactor[];
    rating: string | null;
    review_count: number;
    completed_orders: number;
    /** Null rather than a flattering default when there is no history. */
    response_rate: number | null;
    response_time_hours: number | null;
    years_in_business: number | null;
    established_on: string | null;
    member_since: string | null;
    active_listings: number;
    products_count: number;
    services_count: number;
    /** Aggregate counts only. The documents themselves are never exposed. */
    document_status: { total: number; approved: number; pending: number; rejected: number };
  };
  relationships: {
    business?: ApiBusiness | null;
    recent_reviews?: ApiReview[];
  };
}

export interface VerificationStateResponse {
  success: boolean;
  message: string;
  data: {
    status: VerificationStatus;
    status_label: string;
    notes: string | null;
    requested_at: string | null;
    verified_at: string | null;
    suspension_reason: string | null;
    can_request: boolean;
    /** Allowed document types come from the backend, never a frontend list. */
    document_types: string[];
    accepted_mime_types: string[];
    max_file_size_kb: number;
    documents: {
      id: string;
      type: string;
      title: string | null;
      status: string;
      review_notes: string | null;
      uploaded_at: string | null;
      reviewed_at: string | null;
    }[];
  };
}

/* ---------------------------------------------------------------------- *
 | Disputes + audit (Phase 10)
 * ---------------------------------------------------------------------- */

export type DisputeStatus = 'open' | 'under_review' | 'resolved' | 'rejected' | 'closed';

export interface ApiDispute {
  id: string;
  type: 'dispute';
  attributes: {
    reference: string;
    reason: string;
    description: string;
    evidence: string | null;
    status: DisputeStatus;
    status_label: string;
    raised_by_party: 'customer' | 'business';
    resolution: string | null;
    /** Only ever populated for admins. */
    admin_notes: string | null;
    resolved_at: string | null;
    created_at: string | null;
    is_active: boolean;
  };
  relationships: {
    order?: { id: string; order_number: string; total_amount: string; currency: string } | null;
    business?: { id: string; name: string } | null;
  };
}

export interface AdminDashboardResponse {
  success: boolean;
  message: string;
  data: {
    users: { total: number; customers: number; owners: number };
    businesses: { total: number; verified: number; pending: number; suspended: number };
    marketplace: { requirements: number; quotes: number; orders: number; completed_orders: number; reviews: number };
    money: {
      settled_payments: number;
      paid_total: string;
      refunded_total: string;
      /** Reporting currency, from server config — never assumed by the client. */
      currency: string;
      /** More than one entry means the totals above are a naive cross-currency sum. */
      currencies_in_use: string[];
    };
    attention: { pending_verifications: number; open_disputes: number; flagged_reviews: number; failed_payments: number };
  };
}

export interface AuditLogEntry {
  id: number;
  action: string;
  actor: string | null;
  actor_role: string | null;
  entity_type: string | null;
  entity_reference: string | null;
  ip_address: string | null;
  metadata: Record<string, string | number | boolean | null> | null;
  created_at: string | null;
}

/**
 * The admin dispute queue returns a flattened row rather than a full dispute — the
 * queue needs to be scannable, and the detail is one request away on
 * `GET /disputes/{id}`.
 */
export interface AdminDisputeRow {
  id: string;
  reference: string;
  status: DisputeStatus;
  reason: string;
  raised_by_party: 'customer' | 'business';
  order_number: string | null;
  business_name: string | null;
  created_at: string | null;
}

/** One document in the admin review queue. File contents are never included. */
export interface AdminBusinessDocument {
  id: string;
  type: string;
  title: string | null;
  status: string;
  file_mime: string | null;
  file_size: number | null;
  review_notes: string | null;
  uploaded_at: string | null;
}

export type VerificationCounts = Record<VerificationStatus, number>;
export type DisputeStatusCounts = Record<DisputeStatus, number>;
