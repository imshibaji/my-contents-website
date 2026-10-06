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

| Variable | Consumer | Purpose |
|---|---|---|
| `PAYU_MERCHANT_KEY` | `payu/config.php` | Merchant key. Required — the API refuses to run without it. |
| `PAYU_MERCHANT_SALT` | `payu/config.php` | Salt for the SHA-512 hash. Required. |
| `PAYU_MODE` | `payu/config.php` | `TEST` or `PROD`. If unset it is **inferred from `SITE_URL`**: a local host becomes `TEST`, anything else `PROD`. Set it explicitly on a staging domain, otherwise staging charges real cards. |
| `SITE_URL` | `payu/config.php`, `payu/response.php` | Origin for PayU `surl`/`furl` and the failure redirect. Production: `https://shibajidebnath.com`. A trailing slash is stripped automatically. |
| `ADMIN_EMAIL` | `payu/config.php` | Recipient of payment notifications. |
| `SMTP_GMAIL_USER` | `send-mail.php` | SMTP account used for enquiry/payment email. |
| `SMTP_GMAIL_PASS` | `send-mail.php` | SMTP app password. Without it, mail silently fails. |
| `PUBLIC_SUPABASE_URL` | `tool-lead.php`, `tool-sequence-supabase.php` | Supabase project URL. |
| `SUPABASE_SERVICE_KEY` | `tool-lead.php`, `tool-sequence-supabase.php` | Supabase `service_role` key. Without it, tool leads are dropped. |
| `DB_HOST` | `enquiry.php` | Postgres host. Previously read from `$_ENV`, which this server never populates — always fell back to `127.0.0.1`. |
| `DB_PORT` | `enquiry.php` | Postgres port, default `5432`. |
| `DB_NAME` | `enquiry.php` | Postgres database name. |
| `DB_USER` | `enquiry.php` | Postgres user. |
| `DB_PASSWORD` | `enquiry.php` | Postgres password. |

**How these are read.** `public/api/env-loader.php` is the only parser. Every endpoint
that touches configuration does `require_once __DIR__ . '/env-loader.php'` and reads
values through `env()` / `envInt()` / `envBool()`. Never `getenv()` and never
`$_ENV[...]` directly — `verify:deploy` fails the build on either.

Two behaviours matter on this host:

* `variables_order=GPCS`, so PHP never populates `$_ENV` from host variables.
  `env-loader.php` writes **both** `putenv()` and `$_ENV[]` so `variables_order`
  cannot silently break a reader.
* A host-level value always beats `.env`. A stale `.env` on the server cannot
  override a value you corrected in hPanel.

Every PayU session logs the resolved mode and gateway:

```
[PayU] mode=PROD (inferred from SITE_URL) gateway=https://secure.payu.in/_payment
```

If revenue stops arriving, that log line is the first thing to check.

`config.php` resolves `.env` by searching `__DIR__`-relative paths,
`DOCUMENT_ROOT`, its parent, and `getcwd()`. If credentials are still missing it
returns HTTP 500 with `"code":"payu_config_incomplete"` and names the missing
variables, rather than silently hashing with an empty salt and letting PayU
reject the payment.

### 7. Files That Must Not Be Committed

`.env` is gitignored. `.env.fixed` is **tracked** and must not be — it is a
placeholder template that looks like real config. Prefer a committed
`.env.example` containing placeholders only, and untrack the `.fixed` variant.

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