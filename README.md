# Shibaji Debnath — Engineering Platform & Technical Academies

A high-performance personal engineering platform and course delivery system built with **Astro 5+**, **Tailwind CSS**, and a lightweight **PHP Backend** for transaction verification and candidate qualification.

---

## ⚡ Architecture Overview

- **Frontend & SSG**: Astro 5 (Static Site Generation with Content Collections Loader API).
- **Backend API Runtime**: Native PHP (`/api/`) powering candidate lead telemetry, dual transactional notifications, and PayU SHA-512 payment signatures.
- **Content Store**: Schema-validated Markdown files under `contents/courses/` and `contents/articles/`.
- **Payment Gateway**: PayU custom integration supporting Full 1-Year access and 4-Quarter milestone split installments.

---

## 📁 Repository Structure

```text
.
├── api/                             # PHP API Endpoints
│   ├── enquiry.php                  # Step 1 lead telemetry & dual email dispatch
│   └── payu/
│       ├── config.php               # Merchant credentials, price catalog & mailer
│       ├── init.php                 # Price validation, SHA-512 hash & gateway payload
│       └── response.php             # Gateway return handler & payment verification
├── contents/                        # Markdown Content Repositories
│   ├── courses/                     # Cohort syllabi and pricing frontmatter
│   └── articles/                    # Deep-dive architecture write-ups
├── public/                          # Static assets, branding, and icons
├── src/
│   ├── content.config.ts            # Astro content collections schema (Zod)
│   ├── layouts/
│   │   └── Layout.astro             # Global master layout & SEO meta tags
│   └── pages/
│       └── courses/
│           ├── index.astro          # Searchable catalog & topic filter grid
│           ├── [...slug].astro      # Course overview, accordion syllabus & pricing selector
│           ├── enquiry.astro        # Step 1: Candidate intake form
│           └── checkout.astro       # Step 2: Locked candidate payment terminal
└── package.json

```

---

## 🛠️ Local Development Setup

The platform uses a decoupled frontend and API architecture. Both servers must run concurrently during local testing.

### 1. Install Dependencies

```bash
npm install

```

### 2. Run Astro Frontend

```bash
# Standard dev server (localhost:4321)
npm run dev

# Or run as background service
astro dev --background

```

### 3. Run PHP Backend Server

Run a local PHP development server from the project root to serve `/api/*`:

```bash
php -S localhost:8000

```

> **Configuration Tip:** Set your local reverse proxy or configure development requests to route `/api/*` directly to `localhost:8000`.

---

## 🔄 Candidate Qualification & Enrollment Flow

```text
Course Details Page ([...slug].astro)
   │  (Selects Full vs. Quarterly Milestone)
   ▼
Step 1: Application Form (enquiry.astro)
   │  (Submits profile payload)
   ▼
API Telemetry (api/enquiry.php)
   ├── Dispatches instant email alerts to Admin & Student
   └── Redirects with verified parameters
   ▼
Step 2: Payment Terminal (checkout.astro)
   │  (Displays locked candidate invoice)
   ▼
PayU Gateway Initiation (api/payu/init.php)
   ├── Validates amount against server-side $COURSE_CATALOG
   └── Computes SHA-512 checksum and redirects to PayU

```

---

## 📦 Core CLI Commands

| Command | Action |
| --- | --- |
| `npm run dev` | Starts local Astro dev server at `http://localhost:4321` |
| `npm run build` | Compiles production static build to `./dist/` |
| `npm run preview` | Previews the production build locally |
| `rm -rf .astro dist` | Clears content collection cache if Markdown updates lag |

---

## 🛡️ Production Deployment Notes

1. **Environment & Merchant Keys**: Update `PAYU_MODE` to `'PROD'` and supply production `PAYU_MERCHANT_KEY` and `PAYU_MERCHANT_SALT` inside `api/payu/config.php`.
2. **Server-Side Price Catalog**: When changing course prices in `contents/courses/*.md`, always update `$COURSE_CATALOG` in `api/payu/config.php` to prevent client-side price tampering.
3. **Web Server Rules**: Ensure your production Nginx or Apache configuration passes PHP requests under `/api/` to `php-fpm` while serving the root static assets directly from `/dist/`.