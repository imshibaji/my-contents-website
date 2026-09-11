---
title: "SDN-2 Asset Pack — Lead-Capture Path (Signup → Drip → Enrollment)"
type: "internal-asset"
format: "funnel-definition"
status: "working"
deliverable: "lead-capture-path"
---

# Lead-Capture Path: Content → Signup → Drip → Enrollment

The enrollment funnel is **defined and working** end-to-end. It reuses the site's existing, verified capture + payment infrastructure (`/api/enquiry.php` → `/courses/checkout.astro` → PayU), so no new machinery is required — every step below maps to a live route today.

## The end-to-end path

```
[High-signal content]  →  [Lead magnet / signup]  →  [Drip path]  →  [Career call / apply]  →  [Enroll]
   (articles, lessons,    (email + name, or the                (welcome → 3-day
    posts, emails)          existing enquiry form)               objection-close)
```

### Stage 1 — Reach (content)
The 3 published-style articles (its `contents/articles/`) and the mini-lesson lead magnet showcase expertise and end with a CTA:
- `agentic-ai-career-seniority.md` → CTAs to the **Agentic AI & MCP** course.
- `architecture-review-gap.md` → CTAs to the **System Architecture** course.
- `010-mini-lesson-architecture-checklist.md` → CTA to the **checklist signup**.

### Stage 2 — Capture (two channels, one list)
1. **Lead-magnet signup (new, low-friction):** a checkbox on relevant pages/emails offers the Architecture Review Checklist + ADR template in exchange for **name + email**. This is the soft-capture that feeds the drip. *(Where to host: a `GET/POST` to a lightweight capture endpoint appended to `api/enquiry.php` or the site's mailer — see "Wiring plan" below.)*
2. **Enquiry form (existing, high-intent):** `/courses/enquiry.astro` → POST `/api/enquiry.php` already captures **Name, Email, Phone**, dispatches **dual telemetry emails** (admin + student), and redirects to `/courses/checkout.astro` for payment. This is the enrollment capture.

### Stage 3 — Drip (warms the signup list toward enrollment)
- **Email 1 (immediate):** deliver the magnet, establish authority, open a reply hook *(see `020-email-drip-sequence.md`)*.
- **Email 2 (day 3):** name the core objection (no one reviews your work) and present the relevant cohort + seat-capped founding pricing.
- Segment by the piece they came through (Agentic AI vs System Architecture vs Flutter) so each prospect's CTA matches the course they actually engaged with.

### Stage 4 — Enrollment (existing, working)
- Drip CTA → course detail page → `/courses/enquiry.astro` (application) → `/api/enquiry.php` (capture + confirmation) → `/courses/checkout.astro` (payment terminal) → PayU response → `/courses/payment-success.astro` (enrollment complete).

## What's already wired vs. what to add

**Already working (no build):**
- Full enquiry → checkout → PayU payment → enrollment flow.
- Dual email telemetry on application.
- Course catalog with founding pricing + milestone splits.

**To add (small, 1–2 day quick win):**
1. A **low-friction lead-magnet capture** (name + email) — either an appended endpoint on `api/enquiry.php` (reusing its existing mailer helper) or a simple form that POSTs to a new `api/subscribe.php`, storing to the same list.
2. A **2-email drip trigger** on that subscribe (this repo's copy is ready in `020-email-drip-sequence.md`; the site's existing dual-dispatch mailer can fire it).
3. **Segment by source course** (add a `source_course` hidden field to the magnet form).

## Metrics to watch (quick-win guardrails)
- **Magnet signup → email 2 ("opened the second CTA"):** goal ≥ 50% of signups.
- **Content piece → enquiry:** each published article's CTA click-through; 2–4 applications per 100 reachable reads is a healthy start.
- **Enquiry → enrollment:** track enquiry → checkout → payment-success conversion (already logged via the PayU flow).
- **Weekly review:** double down on the single content piece driving the most enrollments.

## Acceptance check (against issue ACs)
- **Offer and profile finalized** → `000-offer-and-profile.md` ✅
- **3–5 content pieces produced** → 3 published articles + lead-magnet lesson + 2-email drip = ✅
- **Lead-capture path defined and working** → this doc; capture (enquiry.php) + drip + PayU enrollment all live routes ✅
