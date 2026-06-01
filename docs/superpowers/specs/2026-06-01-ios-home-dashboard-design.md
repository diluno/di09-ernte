# iOS Companion — Home Dashboard — Design

**Date:** 2026-06-01
**Status:** Approved (pending spec review)
**Repos:**
- iOS: `/Users/sam/Documents/_projects/ernte` (target/module `ernte`)
- Backend: `/Users/sam/Documents/_projects/di09-ernte` (Laravel API)

## Summary

A new **Home** tab for the iOS companion — a single-glance "morning" overview
for the one operator: *am I tracking, how much have I logged, what money is owed
me.* It is the **first/default tab**, fed by one new aggregate endpoint
`GET /api/dashboard`, and renders three stacked full-width cards (Layout A from
brainstorming):

1. **Timer** — forest-green hero with the running entry + live-ticking elapsed,
   or an idle "start a timer" state.
2. **Hours & earnings** — hours today (and billable), earnings today, hours this
   week, and a 7-day sparkline.
3. **Money owed** — outstanding, overdue, and unbilled-time totals.

## Decisions (from brainstorming)

- **Target:** iOS app only. The web Reports page stays a placeholder.
- **Placement:** Home becomes the **first tab** in `MainTabView`, so the app
  opens on the glance view (Timer/Projects/Billing/Account shift right).
- **Cards in scope:** Timer status/resume, Hours & earnings, Money owed.
  **"Needs attention"** (projects near budget, pending estimates) is **out of
  scope** (YAGNI).
- **Layout A** (stacked full-width cards), not the denser stat-grid (B).
- **Sparkline = last 7 days** of the current user's tracked hours.
- **No MTD earnings** — only *today's* earnings + *this week's* hours, matching
  the chosen mockup.
- **One request → one screen** — the screen makes a single `GET /api/dashboard`
  call, mirroring `BillingView`/`ProjectsView`.

## Backend

All data sources already exist in `app/Support/`; this endpoint **composes**
them — no new business logic except a 7-day per-user sparkline query.

### Route
`routes/api.php`, inside the `auth:sanctum` group:
```php
Route::get('/dashboard', [DashboardController::class, 'show']);
```

### Controller
`app/Http/Controllers/Api/DashboardController@show` — thin; returns
`response()->json(DashboardProjections::summary($request->user()))`.

### Projection
Extend the existing `App\Support\DashboardProjections` with:

```php
public static function summary(User $user): array
```

composed from existing helpers:

- **Timer block** — from `TimerToday::payload($user)`:
  - `running` — the running entry sub-array (project, description, started_at,
    duration_seconds, billable, task_name) or `null`. Reuse the exact shape the
    Timer tab already decodes (`RunningEntry`).
  - `today` — `{ total_seconds, billable_seconds, earnings_amount }` from that
    payload's `totals`.
- **Hours block:**
  - `week_hours` — from `DashboardProjections::stats($user)`.
  - `sparkline` — **new**: 7 numbers, hours per day for the past 7 days
    (oldest→newest), one grouped query for the current user using the existing
    `DURATION_SECONDS_SQL` / `DATE(started_at)` pattern already in this class
    (the 14-day project sparkline is the template).
- **Money block:**
  - `outstanding` and `overdue` — from `InvoiceProjections::stats()`.
  - `unbilled` — `unbilled_amount` from `DashboardProjections::stats($user)`.

### Response shape (snake_case on the wire)
```json
{
  "timer": {
    "running": {
      "id": 12,
      "description": "Website redesign",
      "task_name": null,
      "started_at": "2026-06-01T08:36:00+02:00",
      "duration_seconds": 5047,
      "billable": true,
      "project": { "id": 3, "name": "DILUNO", "code": "DIL" }
    },
    "today": { "total_seconds": 22320, "billable_seconds": 18360, "earnings_amount": 1120.0 }
  },
  "hours": {
    "week_hours": 28.4,
    "sparkline": [4.0, 7.0, 3.0, 9.0, 6.0, 8.0, 5.0]
  },
  "money": { "outstanding": 8450.0, "overdue": 2100.0, "unbilled": 3380.0 }
}
```
`running` is `null` when no timer is active. Amounts are decimal francs (CHF),
matching the existing invoice/timer API conventions.

## iOS client

### DTOs — `Models/StatusDTOs.swift` (new structs)
```swift
struct DashboardResponse: Codable {
    let timer: DashboardTimer
    let hours: DashboardHours
    let money: DashboardMoney
}
struct DashboardTimer: Codable {
    let running: RunningEntry?          // reuse existing DTO from DTOs.swift
    let today: TimerTotals              // reuse existing { total_seconds, billable_seconds, earnings_amount }
}
struct DashboardHours: Codable {
    let weekHours: Double
    let sparkline: [Double]
    enum CodingKeys: String, CodingKey { case sparkline; case weekHours = "week_hours" }
}
struct DashboardMoney: Codable {
    let outstanding: Double
    let overdue: Double
    let unbilled: Double
}
```
`RunningEntry` already maps `started_at`/`duration_seconds`/`task_name`, and the
decoder uses `.iso8601` — no extra config needed.

### APIClient — `Networking/APIClient.swift`
```swift
func dashboard() async throws -> DashboardResponse {
    try await request("GET", "/api/dashboard")
}
```

### ViewModel — `Features/Home/HomeViewModel.swift`
`@Observable @MainActor final class HomeViewModel` with `dashboard:
DashboardResponse?`, `isLoading`, `errorMessage`, and a `load()` that uses the
same `run { }` wrapper as `BillingViewModel` (401 → `session.handleUnauthorized()`,
`APIError` → `errorMessage`).

### View — `Features/Home/HomeView.swift`
`NavigationStack` + `List` styled with `.erntePaperList()`, `.navigationTitle("Home")`,
`.refreshable { await model.load() }`, `.task { await model.load() }`. Three
sections:

1. **Timer hero** — forest card (`Theme.forest` background, paper text).
   - Running: project name + live-ticking elapsed using the 1s
     `Timer.publish(every: 1, ...).autoconnect()` + `now` pattern already in
     `TimerView`, plus a **Stop** button (`session.api.stopTimer()` → reload).
   - Idle: "No timer running" + a control that switches to the Timer tab.
2. **Hours & earnings** — today hours (with billable subtitle), earnings today
   (`Theme.forest`), week hours, and a small **sparkline** drawn as an `HStack`
   of capsule bars (or a `Canvas`) from `hours.sparkline`.
3. **Money owed** — three rows: Outstanding, Overdue (`Theme.red` when > 0),
   Unbilled time (`Theme.rust`). Uses the existing CHF formatting in
   `Support/Format.swift`.

### Navigation — `ContentView.swift`
Insert `HomeView(session:)` as the **first** `TabView` child with
`Label("Home", systemImage: "house")`, before Timer.

## Error / empty states

- **Network/API error** — surface `errorMessage` (same banner pattern as other
  tabs); pull-to-refresh retries.
- **Idle timer** — hero shows "No timer running" + start affordance.
- **Zero figures** — render `CHF 0` / `0h` rather than hiding rows, so the card
  layout stays stable.

## Testing

- **Backend (Pest feature test):** `GET /api/dashboard` with a Sanctum token —
  asserts the `timer`/`hours`/`money` blocks are present, `hours.sparkline` has
  length 7, a running-timer case populates `timer.running`, and a no-timer case
  yields `null`. Follows the existing API feature-test pattern under `tests/`.
- **iOS:** verify `DashboardResponse` decodes from a JSON fixture matching the
  shape above (if a unit-test target exists); otherwise manual verification in
  the simulator against the local DDEV API.

## Out of scope

- "Needs attention" card (projects near budget, pending estimates).
- MTD/quarterly earnings, charts beyond the 7-day sparkline.
- Web Reports dashboard.
- Any timer mutation beyond Stop from the hero (start/switch stay on the Timer tab).
