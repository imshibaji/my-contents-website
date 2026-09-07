# Project Architecture & Claude Agent Guidelines

## System Overview
- **Frontend / SSG Engine**: Astro 5+ with Content Collections (loader API) and Tailwind CSS.
- **Backend Services**: Native PHP (located in `/api/`), handles PayU SHA-512 payment signatures, candidate enquiry telemetry, and dual email dispatch.
- **Content Engine**: Markdown collections inside `contents/courses/` and `contents/articles/` strictly typed with Zod schemas.

---

## Development Workflow & Commands

Development requires both the Astro static dev server and the PHP runtime to handle telemetry/checkout APIs.

### 1. Astro Dev Server
```bash
# Start background dev server
astro dev --background

# Server management
astro dev status
astro dev logs
astro dev stop

```

### 2. PHP API Server

Run concurrently to serve `/api/enquiry.php` and `/api/payu/`:

```bash
php -S localhost:8000

```

> **Note:** For frontend-to-backend API routing in dev mode, ensure requests to `/api/*` reach the PHP server or are proxied appropriately.

### 3. Build & Cache Invalidation

Astro caches content collection queries aggressively. If updates in `contents/courses/*.md` frontmatter do not reflect on pages:

```bash
# Clear build and loader caches
rm -rf .astro dist node_modules/.vite

# Production build
npm run build

```

---

## Project Structure & Critical Files

```
├── contents/
│   ├── courses/                 # Course collection markdown files
│   └── articles/                # Technical deep-dive articles
├── src/
│   ├── content.config.ts        # Content Collections schema with Zod
│   ├── layouts/Layout.astro     # Core layout wrapper
│   └── pages/
│       └── courses/
│           ├── index.astro      # Catalog listing with client-side filter & pagination
│           ├── [...slug].astro  # Dynamic course details & plan selection
│           ├── enquiry.astro    # Step 1: Candidate intake form -> POST /api/enquiry.php
│           └── checkout.astro   # Step 2: Verified payment terminal -> POST /api/payu/init.php
└── api/
    ├── enquiry.php              # Lead capture & dual email dispatch (Admin + Student)
    └── payu/
        ├── config.php           # Merchant keys, COURSE_CATALOG pricing, and mailer helper
        ├── init.php             # Price determination, SHA-512 hash calculation, lead email
        └── response.php         # PayU return verification & enrollment completion

```

---

## Content Schema & Frontmatter Rules

When modifying or generating files under `contents/courses/`:

* **Data Types**:
* `price` and `originalPrice` **MUST be raw numbers** (e.g., `80000`, NOT `"80000"`).
* `installmentPrice` **MUST be a raw number** (e.g., `25000`).


* **Strict Enum Fields**:
* `level`: `"Beginner"` | `"Intermediate"` | `"Advanced"` | `"All Levels"`
* `mode`: `"Live Mentorship"` | `"Self-Paced"` | `"Hybrid"`


* **Accordion Structure**:
* The syllabus must use `<details>` and `<summary>` HTML tags.
* The first `<details>` block MUST have the `open` attribute. Subsequent blocks must omit it.
* Always include `.module-number`, `.module-title`, and `.module-meta` spans inside `<summary>`.



---

## Checkout & Enrollment Architecture

* **Two-Step Qualification Flow**:
1. **Enquiry (`/courses/enquiry.astro`)**: Captures Name, Email, Phone, and chosen `payment_plan` (`full` vs `installment`). Submits to `/api/enquiry.php`.
2. **Notification**: `/api/enquiry.php` sends dual notifications (Admin receives candidate telemetry, student receives acknowledgment), then redirects to `/courses/checkout.astro` with URL parameters.
3. **Payment Terminal (`/courses/checkout.astro`)**: Displays locked candidate information and the full tuition breakdown. Submits to `/api/payu/init.php` to generate SHA-512 params and auto-submits to PayU.


* **Pricing Catalog Synchronization**:
* Whenever a course's `price` or `installmentPrice` changes in `contents/courses/<slug>.md`, **you must update `$COURSE_CATALOG` in `api/payu/config.php**` to prevent payment amount tampering.



---

## Technical Documentation & References

* [Astro Routing & Dynamic Slugs](https://docs.astro.build/en/guides/routing/)
* [Astro Content Collections & Loaders](https://docs.astro.build/en/guides/content-collections/)
* [Astro Styling with Tailwind](https://docs.astro.build/en/guides/styling/)
* [PayU Custom Checkout & Hash Integration](https://devguide.payu.in/)