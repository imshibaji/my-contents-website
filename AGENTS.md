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
# Docroot MUST be public/ - the endpoints live at public/api/**, so serving the
# repo root makes every /api/*.php request 404.
php -S localhost:8000 -t public

# Preferred (matches package.json "dev:php")
npm run dev
```

> **Note**: `php -S localhost:8000` without `-t public` serves the repo root and
> returns **404 for `/api/enquiry.php` and `/api/payu/*`**, which looks exactly
> like a broken payment gateway.

### 3. Build & Cache Invalidation

Astro caches content collection queries aggressively. If updates in `contents/courses/*.md` frontmatter do not reflect on pages:

```bash
# Clear build and loader caches
rm -rf .astro dist node_modules/.vite

# Production build (also runs verify:deploy — see below)
npm run build

```

### 4. Deploy Verification

`npm run build` runs `verify:deploy` automatically. **Do not upload `dist/` unless it
passes.** A partial upload is what broke production before: `init.php` and
`response.php` reached the server while `config.php` did not, so every payment
returned a blank HTTP 500 that the frontend could only report as
"Network communication failure".

```bash
npm run verify:deploy            # check ./dist
node scripts/verify-deploy.mjs ./public
```

It asserts that every required PHP endpoint is present, non-empty, syntactically
PHP, free of a stray closing `?>`, that `config.php` does not contain the
unparenthesised `?? ... ===` precedence bug, that the catalog JSON parses, and
that the redirect targets exist.

### 5. Uploading `dist/`

* Upload **the entire `dist/` tree**, including `dist/.htaccess` and
  `dist/api/payu/config.php`. Missing files surface as a redirect to `/`
  (HTTP 302) because of the `.htaccess` rewrite rule, not as a 404.
* `.env` is gitignored and must be configured on the host as real environment
  variables (hPanel → PHP → Configuration, or a `.env` above the web root).
  Never place a `.env` inside the document root.

### 6. Required Environment Variables

| Variable | Purpose |
|---|---|
| `PAYU_MERCHANT_KEY` | Merchant key. Required — the API refuses to run without it. |
| `PAYU_MERCHANT_SALT` | Salt for the SHA-512 hash. Required. |
| `PAYU_MODE` | `TEST` or `PROD`. `TEST` sends customers to test.payu.in and moves no money. |
| `SITE_URL` | Origin for PayU `surl`/`furl`. Production: `https://shibajidebnath.com`. A trailing slash is stripped automatically. |
| `ADMIN_EMAIL` | Recipient of payment notifications. |

`config.php` resolves `.env` by searching `__DIR__`-relative paths,
`DOCUMENT_ROOT`, its parent, and `getcwd()`. If credentials are still missing it
returns HTTP 500 with `"code":"payu_config_incomplete"` and logs the paths it
tried, rather than silently hashing with an empty salt and letting PayU reject
the payment.

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
└── public/                      # PHP dev server docroot (-t public)
    └── api/
        ├── enquiry.php          # Lead capture & dual email dispatch (Admin + Student)
        ├── course_catalog.json  # Server-side price source of truth (price_full / price_installment)
        └── payu/
            ├── config.php       # .env loading, PayU credentials, catalog resolver, mailer helper
            ├── init.php         # Price determination, SHA-512 hash calculation, lead email
            └── response.php     # PayU return verification & enrollment completion

> `dist/` is the deployable build output and contains a **copy** of `public/api/**`.
> After changing any PHP endpoint, either run `npm run build` or copy the file into
> `dist/api/` — otherwise production keeps serving the stale broken copy.

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
* Server-side prices come from `public/api/course_catalog.json` (`price_full` / `price_installment`), **not** from a hardcoded PHP array. Whenever a course's `price` or `installmentPrice` changes in `contents/courses/<slug>.md`, **you must update the matching entry in `public/api/course_catalog.json`** to prevent payment amount tampering.
* Any slug present in the markdown but missing from the catalog silently falls through to the `custom-payment` branch in `init.php`, which trusts a client-supplied `amount`.



---

## Technical Documentation & References

* [Astro Routing & Dynamic Slugs](https://docs.astro.build/en/guides/routing/)
* [Astro Content Collections & Loaders](https://docs.astro.build/en/guides/content-collections/)
* [Astro Styling with Tailwind](https://docs.astro.build/en/guides/styling/)
* [PayU Custom Checkout & Hash Integration](https://devguide.payu.in/)