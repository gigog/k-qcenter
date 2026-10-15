# K-QCENTER Project Rules

- Read `docs/K-QCENTER-SYSTEM-SPEC.md` before implementing product behavior.
- Use the Sarabun typeface across all user-facing interfaces and documents.
- Display user-facing dates in the Thai Buddhist calendar and times in Asia/Bangkok.
- Store and exchange timestamps using UTC/ISO 8601; convert only at presentation boundaries.
- K-QCENTER is the source of truth for slots, capacity, bookings, and queue states.
- K-QOnline and administrative clients must use the API and must never connect directly to the database.
- Never expose databases, Redis, remote administration, or admin pages through Tailscale Funnel.
- Preserve authorization boundaries by organization, branch, department, and role.
- Booking creation must be transactional, capacity-safe, and idempotent.
- Prefer accessible Thai copy and responsive layouts.

