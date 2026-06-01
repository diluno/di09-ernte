# iOS manual time entry — design

**Date:** 2026-06-01
**Status:** Approved, ready for planning
**Repos:** backend `di09-ernte` (this repo) + iOS `../ernte`

## Problem

The web app can create a time entry by hand (project + description + **duration** + date +
billable). The iOS companion can only start/stop the live timer — it has no way to log time
after the fact. Add a "Log time" form to iOS, entering a duration, matching the web.

## Key findings (from exploration)

- iOS already displays entries as durations (no From/Until anywhere) and already has the
  project list client-side (in the `/api/timer` `TimerPayload.projects`, model `ProjectSummary`).
- iOS auth is a Sanctum bearer token (Keychain); `APIClient` posts JSON and already decodes
  ISO-8601 dates. The Timer screen presents sheets via `.sheet(isPresented:)` (pattern:
  `StartTimerSheet`).
- The backend `/api` is Sanctum-guarded (`routes/api.php`, `auth:sanctum` group). There is no
  `/api/entries` yet — iOS only calls `/api/timer/*`.
- The web `StoreEntryRequest` already validates `project_id` / `task_id` / `description` /
  `started_at` / `ended_at` / `billable` and converts incoming UTC instants to the app
  timezone. It is generic and reusable for an API call.

## Scope

Add `POST /api/entries` (backend) and a "Log time" sheet (iOS) that creates a finished entry
from a duration. Out of scope: editing/deleting entries from iOS, task selection (the web
manual form has none either), schema changes, the web app.

## Decisions (from brainstorming)

- **Mirror the web's synthesis:** the iOS form turns date + duration into `started_at`/`ended_at`
  and posts those, so the backend reuses `StoreEntryRequest` untouched.
- **Duration input on iOS:** a native hours + minutes wheel picker (no text parsing).
- **Spec/plan live in `di09-ernte/docs/superpowers`**, each plan task marks its repo.

## Architecture

### Backend — `di09-ernte`

**New `app/Http/Controllers/Api/EntryController.php`:**

```php
public function store(StoreEntryRequest $request): JsonResponse
{
    $data = $request->validated();
    $entry = TimeEntry::create([
        ...$data,
        'description' => $data['description'] ?? '', // NOT NULL column; blank arrives as null
        'user_id' => $request->user()->id,
    ]);
    return response()->json(['id' => $entry->id], 201);
}
```

- **Reuse `StoreEntryRequest`** as-is (its `authorize()` returns true; the `auth:sanctum`
  middleware supplies `$request->user()`). Same validation + UTC→app-tz conversion as the web.
- **Route** (inside the existing `auth:sanctum` group in `routes/api.php`):
  `Route::post('/entries', [\App\Http\Controllers\Api\EntryController::class, 'store']);`
- Mirrors the web `EntryController@store` logic (incl. the description coalescing —
  see the `time_entries.description` NOT NULL gotcha).

### iOS — `../ernte`

**`APIClient` method** (mirrors the timer POST pattern; encodes ISO-8601 via the same strategy):

```swift
func createEntry(projectId: Int, description: String,
                 startedAt: Date, endedAt: Date, billable: Bool) async throws -> EntryResponse
```

posting `{ project_id, description, started_at, ended_at, billable }` (ISO-8601 strings) to
`POST /api/entries`. New DTO `struct EntryResponse: Codable { let id: Int }`.

**`LogTimeSheet`** (new SwiftUI sheet, modeled on `StartTimerSheet`):
- **Project** `Picker` over `model.projects` (`ProjectSummary`).
- **Description** `TextField` (optional).
- **Duration** native wheel: two pickers, `hours` (0–12+) and `minutes` (0–55, step 5), bound
  to `@State` ints. Default 1h 0m.
- **Date** `DatePicker` (`.date` only), default today.
- **Billable** `Toggle`, default on.
- **Save** disabled when `hours == 0 && minutes == 0` (a zero-length entry would fail the
  backend `ended_at after:started_at` rule).
- On Save: synthesize `startedAt` = the picked date at the **current time-of-day**;
  `endedAt` = `startedAt + (hours*60+minutes) minutes`; call `createEntry(...)`; on success
  dismiss and reload the timer payload (`model.load()`) so totals refresh.

**Timer screen** (`TimerView`): add a **"+ Log time"** button alongside "Start timer…",
presenting `LogTimeSheet` via `.sheet(isPresented:)`. A `TimerViewModel.logTime(...)` method
wraps `api.createEntry(...)` then `await load()`.

## Data flow

```
iOS LogTimeSheet
  → APIClient.createEntry → POST /api/entries  { project_id, description, started_at, ended_at, billable }
  → Sanctum auth → StoreEntryRequest (UTC→app tz) → TimeEntry::create → 201 { id }
  → iOS reloads /api/timer → totals/entries refresh
422 validation → APIError.validation([String:[String]]) (existing handling)
```

## Error handling / edge cases

- **Zero duration:** Save disabled, so `ended_at` is always strictly after `started_at`.
- **Long/late durations** may roll `ended_at` past midnight — harmless (`duration_seconds` =
  ended − started is correct; matches the web's behavior).
- **Validation/401:** surfaced via the iOS app's existing `APIError` handling; the sheet stays
  open on error.
- Backend description coalescing avoids the `time_entries.description` NOT NULL violation.

## Testing

- **Backend (Pest), `di09-ernte`:** new `tests/Feature/Http/Api/EntryApiTest.php` —
  - an authenticated `POST /api/entries` with valid project + ISO timestamps creates the entry
    (201, correct `user_id`, `duration_seconds`);
  - `ended_at` not after `started_at` → 422;
  - unauthenticated request → 401.
- **iOS, `../ernte`:** no view test harness; verified by `xcodebuild` build + manual run
  (log `1h 30m` on a project → entry created, today totals update; confirm 0h 0m disables Save;
  confirm an invalid request surfaces an error without dismissing).
