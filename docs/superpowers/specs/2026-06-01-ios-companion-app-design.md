# iOS Companion App — Design

**Date:** 2026-06-01
**Status:** Approved (pending spec review)

## Summary

A native SwiftUI iOS app that serves as a **mobile companion** to ernte — not a
full reimplementation of the web app. It covers the things you do away from the
desk: tracking time, glancing at status, and taking quick billing actions. The
table-heavy work (creating invoices/estimates with line items) stays on the
desktop web app, which is exactly the part that's painful to make responsive.

This is a personal tool for a single user installed on the owner's own devices —
no App Store release, no multi-tenant concerns.

## Motivation

Making the existing Inertia/Vue web app responsive is hard because of the
data-dense tables. Rather than fight that, we build a focused native app for the
mobile-appropriate subset of features and leave creation flows on the desktop.

## Scope

**In scope (mobile):**
- Time tracking on the go (start/stop/switch/discard the timer, see what's running)
- Glanceable, read-only status (project status & totals; invoice/estimate states,
  amounts, who owes)
- Light billing actions (mark invoice paid/sent, send a reminder; accept/decline/send
  an estimate)

**Out of scope (stays on desktop web):**
- Creating or editing invoices/estimates with line items
- Project/client CRUD, settings, VAT administration, recurring-invoice management
- Offline sync (v1 shows last-loaded data and surfaces errors; no local write queue)

## Architecture

Two codebases:

- **`di09-ernte` (this repo)** gains a small, self-contained JSON API under `/api`.
  The existing Inertia web app is untouched — the API is purely additive.
- **`ernte-ios` (new, separate repo)** holds the SwiftUI Xcode project.

**Shared backend logic.** The codebase already extracts domain logic into services
and projection helpers (`App\Services\Timer\TimerService`,
`App\Services\Invoicing\InvoiceLifecycle`, `App\Support\TimerToday`,
`App\Support\InvoiceProjections`, and the estimate equivalents). The new API
controllers reuse these directly and return JSON Resources instead of Inertia
responses. No business logic is duplicated and no broad refactor is required.

## Shipping plan — three independent slices

Each slice is independently usable and becomes its own implementation plan.

| Slice | Backend | iOS | Outcome |
|---|---|---|---|
| **1 — Foundation + Timer** | API routing, Sanctum tokens, `HasApiTokens`, token login, `/api/me`, timer endpoints + Resource | App scaffold, login + Keychain, `APIClient`, Timer tab | Track time from the phone (proves the whole stack end-to-end) |
| **2 — Status (read-only)** | projects + invoices/estimates index/detail JSON Resources | Projects tab, Billing read views | Glance at status, amounts, who owes |
| **3 — Light actions** | mark paid/sent, send reminder; estimate accept/decline/send | action buttons + confirmations on detail screens | Act on billing from the phone |

Slice 1 is the spine: it forces auth, the API client, Keychain, and navigation
into existence, so slices 2 and 3 are mostly additive.

## Backend — JSON API

### Wiring
- Enable API routing in `bootstrap/app.php` (`api: __DIR__.'/../routes/api.php'`).
- All routes behind `auth:sanctum` except token login.
- Add `Laravel\Sanctum\HasApiTokens` to `User`; publish/run Sanctum's
  `personal_access_tokens` migration.

### Auth (single-user, token-based)
- `POST /api/auth/token` — `{ email, password, device_name }` → verify credentials,
  return a long-lived Sanctum token + basic user. Token stored in the iOS Keychain.
- `DELETE /api/auth/token` — revoke the current token (logout).
- `GET /api/me` — current user + business profile basics (name, currency) for display.
- Any `401` → the app clears its token and returns to the login screen.

### Feature endpoints
Each method is a thin controller that reuses the existing service/projection and
wraps output in an API Resource.

- **Timer** (Slice 1): `GET /api/timer`, `POST /api/timer/start`,
  `POST /api/timer/stop`, `POST /api/timer/switch`, `POST /api/timer/discard`
  — reuse `TimerService` + `TimerToday`.
- **Projects** (Slice 2): `GET /api/projects`, `GET /api/projects/{code}`.
- **Invoices** (Slice 2/3): `GET /api/invoices`, `GET /api/invoices/{number}`,
  `POST /api/invoices/{invoice}/mark-sent`, `POST /api/invoices/{invoice}/paid`,
  `POST /api/invoices/{invoice}/send` — reuse `InvoiceLifecycle`.
- **Estimates** (Slice 2/3): `GET /api/estimates`, `GET /api/estimates/{number}`,
  `POST /api/estimates/{estimate}/accept`, `POST /api/estimates/{estimate}/decline`,
  `POST /api/estimates/{estimate}/send` — reuse the estimate lifecycle service.

### JSON shape
New `App\Http\Resources\*` classes (e.g. `TimerResource`, `ProjectResource`,
`InvoiceResource`, `EstimateResource`) define a stable JSON contract so the iOS
`Codable` models don't break when internal projections change.

### Backend testing
Pest feature tests per endpoint: token auth is required, JSON shape is correct,
state transitions behave (e.g. start → stop), and existing web routes still pass.

## iOS app — `ernte-ios`

### Target & structure
- iOS 17+, SwiftUI, async/await. Light MVVM.
- Layers:
  - **`APIClient`** — a `URLSession`-based actor: builds requests, attaches the
    bearer token, decodes JSON, maps failures to a single `APIError`. Configurable
    base URL (deployed Forge host for real use; DDEV URL for local testing).
  - **`KeychainStore`** — store/read/clear the token.
  - **`Session`** (observable) — holds auth state; missing/invalid token → Login.
  - **Feature view models** (`TimerViewModel`, etc.) call `APIClient` and expose
    state to views.
- `Codable` DTOs mirror the API Resources.

### Navigation
`TabView` with **Timer · Projects · Billing** (Billing = invoices + estimates).
Login is presented over the app whenever there is no valid token.

### Screens by slice
- **Slice 1 — Login + Timer:** email/password login; Timer tab shows the running
  entry (project name, live-ticking elapsed) with Start (project picker), Stop,
  Switch, Discard.
- **Slice 2 — Projects + Billing read:** Projects list → detail (status, totals,
  recent entries); Billing list of invoices/estimates with state + amount + who
  owes, plus read-only detail.
- **Slice 3 — Light actions:** action buttons on detail screens (mark paid/sent,
  send reminder; accept/decline/send) with a confirmation step, optimistic UI +
  refresh.

### v1 guardrails
- No offline sync — show last-loaded data, surface a clear error on failure,
  pull-to-refresh.
- No invoice/estimate creation — stays on desktop web.

### iOS testing
Modest: unit tests for `APIClient` JSON decoding and `KeychainStore`; a couple of
view-model state tests.

## Connectivity

Because this is a real native app hitting the server over the internet, day-to-day
use points at the deployed Forge host over HTTPS. The configurable base URL also
allows pointing at the local DDEV URL during development.

## Distribution

Personal install on the owner's own devices via Xcode with a free or $99/yr Apple
Developer account. No App Store review, privacy disclosures, or public listing.
