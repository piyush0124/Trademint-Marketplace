# Architecture (Approved)

## Decisions

- **Monorepo** — `apps/api`, `apps/web`
- **Auth** — Sanctum bearer tokens
- **Publish flow** — Listings: draft → pending_review → admin approve/reject. Requirements: draft → published (customer-driven). Quotes: draft → sent (business-driven)
- **Currency** — Per-record ISO 4217 code (listing, quote); default from `config('marketplace.default_currency')`, currently `INR`
- **Money** — Monetary maths runs in integer minor units on the backend (`App\Support\Money`). Client-supplied totals are never trusted or stored
- **Authorization** — Laravel policies, auto-discovered. Ownership is always derived from the authenticated user, never from request input

## Stack

- React + Bootstrap (frontend)
- Laravel REST API (backend)
- MySQL / SQLite (database)
- Laravel Sanctum (authentication)

## Domain

General-purpose business operating marketplace: **a customer states a need, a business offers to meet it.** The core transaction pipeline is industry-agnostic — nothing in it branches on category.

```
Requirement → Matching → Enquiry → Quote → Comparison → Negotiation → Order → Payment → Fulfilment → Review → Trust
```

The whole pipeline is implemented (Phases 1–10). Nothing in it branches on category, city or industry.

### Category model

Categories are database-driven and hierarchical (`categories.parent_id`, one level by convention), seeded across ~24 industries — construction, manufacturing, IT, logistics, professional services, home services and others. Real estate is **one category among many**, not the MVP category.

Category-specific detail lives in an optional module — `category_attribute_definitions` + `listing_attribute_values` — and never in the core transaction tables. Real-estate attributes (property_type, bedrooms, area_sqft, …) are defined there, attached to that single category. The `cars` category is seeded inactive.

### Location model

Database-driven and pan-India: `locations` rows carry city, area, locality, state, country and optional coordinates. Businesses declare service areas via `business_service_areas`. No city, state or region is referenced in code — matching compares normalised location text and coordinates.

### Core entities

| Entity | Role |
|---|---|
| `Requirement` | What a customer needs |
| `RequirementMatch` | Why a business is suitable, with a stored score and explanation |
| `Enquiry` | The customer ↔ business conversation about a requirement, before pricing |
| `Quote` / `QuoteItem` | A commercial offer and its priced lines (`item_type`: product, service, project, custom) |
| `QuoteRevision` | An immutable priced offer. The authoritative ledger; `quotes` mirrors the current revision's money so earlier endpoints keep working |
| `Negotiation` / `NegotiationMessage` | Turn-based counter-offers over a quote. Only the party who did *not* author the current revision may counter, accept or reject |
| `Order` / `OrderItem` / `OrderStatusHistory` | The agreement, its frozen lines, and an append-only lifecycle audit |
| `Payment` / `Refund` / `PaymentWebhookEvent` | Payment attempts, refunds, and deduplicated provider callbacks |
| `Review` | One per completed order, enforced by a unique index on `reviews.order_id` |
| `BusinessDocument` | Verification evidence on a private disk. Never publicly linked |
| `Dispute` | Raised by either party on an order, decided only by an admin |
| `AuditLog` | Append-only record of consequential decisions, with actor and IP |
| `Listing`, `Product`, `Service` | A business's catalogue |
| `Lead` | Separate, older flow: a public enquiry against a single listing. Not part of the requirement pipeline |

### Matching

Deterministic and rule-based (no AI). Six weighted dimensions — category, capability, location, budget, availability, trust — all tunable via `config('marketplace.matching')`.

### Payments (Phase 8)

Provider-agnostic by contract: `App\Contracts\PaymentGateway`, with two implementations registered in `config('marketplace.payments')`.

- **`ManualGateway`** (default) — offline settlement. The customer starts a payment, pays out of band, and the *business* confirms receipt. No third-party account needed to run the platform.
- **`HostedGateway`** — the redirect + HMAC-signed-webhook shape a hosted provider uses. Adding a real provider means writing one class, not touching the payment flow.

Invariants: amounts are always the server-computed balance (never sent by the client); the order's payment position is **recomputed** from its payments, never incremented; webhook replays are deduplicated by a unique `(provider, event_id)` on `payment_webhook_events`, and amount, currency and order are re-verified against our own record before anything is applied.

### Trust (Phase 9)

`TrustScoreCalculator` scores weighted factors from `config('marketplace.trust')`. Factors with no evidence are **excluded from the weighting** rather than scored zero, so a new business reads as *unproven*, not *bad*, and the passport says "not enough data yet". Every factor publishes its own evidence string, so the score is auditable by whoever reads it.

Reviews are only reachable through a completed order (`reviews.order_id` is unique), which makes "you must have traded with them" structural rather than a rule someone can forget. Business ratings are recomputed from visible reviews, so moderation corrects the average immediately.

### Extension points

- `PaymentGateway` — add a provider by implementing one interface and registering it in config
- 16 domain events (`EnquiryAccepted/Declined`, `Quote*`, `Negotiation*`, `Order*`, `Payment*`, `RefundRecorded`) have **no listeners** by design. They are the seam for a notification layer; no notification infrastructure exists yet
- `AuditLogger` is called explicitly at decision points rather than from model observers, so the log stays a record of decisions rather than a firehose of writes

## Routing gotcha

Literal path segments must be registered **before** wildcard routes sharing their prefix, because Laravel matches in registration order. `GET businesses/verification` is therefore declared above the public `GET businesses/{slug}`, in its own auth group. Left in the later authenticated block it 404s as "no business with the slug 'verification'".

## Known documentation debt

`docs/api/openapi.yaml` documents Phase 1 endpoints only (auth, categories, listings). It covers none of the ~130 routes added since. Treat `apps/api/routes/api.php` as the source of truth until it is regenerated.
