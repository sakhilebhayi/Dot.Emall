# The operator desk (R-43 W7)

Everything lives under `/operator`, behind sign-in, a verified e-mail and the `operator` middleware (registered in `bootstrap/app.php`; the routes are in the `// --- w7 ---` block of `routes/web.php`). Decisions D-110 to D-120 are in `R43_DECISIONS.md`.

| Page | Route name | What it does |
|---|---|---|
| Needs a person | `operator.home` | Every queue that is waiting, with count and oldest age, longest wait first; unknown sources (a ledger check that never ran) are "not known yet", not fine. |
| Listings | `operator.products.index`, `.show` | Queue with status, name and category filters; full listing with the seller's trust ladder (claimed numbers labelled claimed); approve, reject or take down with a reason the seller reads. |
| Flagged | `operator.flags.index` | Review aid: three rules, evidence shown, nothing automatic. |
| Stores | `operator.stores.index`, `.show` | Review queue and every store; approve, send back, suspend, reinstate with reasons. |
| Reviews and Q&A | `operator.moderation.index` | Held reviews and questions: release, or remove with a reason the author reads. |
| Reports | `operator.reports`, `.show` | Reported listings, stores, reviews, questions and answers grouped by item with reasons and reporter counts; take down or dismiss with an outcome note; repeat-offender view. |
| Returns | `operator.returns.index`, `.show` | Escalated returns; approve, approve and refund now, or uphold the decline, with a note both sides read. |
| Payments | `operator.payments.index`, `.events` | Desk (review, retry, mark refunded by hand, paid-twice indicator) and the payment events log. |
| Ledger | `operator.ledger.index` | Stored reconciliation runs, mismatches highlighted, "run it now". |
| Metrics | `operator.metrics`, `.export` | Seventeen defined metrics, period selector, two small charts with a table view, CSV. |
| Merchandising | `operator.merch.index` and children | Collections (manual or rule, live preview, dates), synonyms with a test box, searches that found nothing, "often needed with", categories and attributes, featured stores. |
| People | `operator.people.*`, `operator.team` | Find by id or e-mail, read-only 360 views (each opening is access-logged), operators. |
| Platform health | `operator.health` | Scheduled tasks, failed jobs, queue depth, unmatched payment events, stock-hold expiry, search index freshness. |
| Audit trail | `operator.audit` | Decisions and personal-data openings, read-only. |

Services are in `app/Services/Operator` (`OperatorDecisions` is the one door for every decision about another party). Tests are in `tests/Feature/Operator`; the signed-in smoke opens every page with the route, config and event caches on.

Dot.Analytics keys (published per company by `MetricsCollector`, not by this desk): `orders_7d`, `revenue_7d_cents` with `currency`, `low_stock_products`, and with `ANALYTICS_COMMERCE_METRICS=true` also `product_views_7d`, `add_to_cart_7d`, `wishlist_adds_7d`.
