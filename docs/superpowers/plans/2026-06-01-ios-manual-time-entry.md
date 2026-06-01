# iOS Manual Time Entry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the iOS app log a time entry by hand (project + description + duration + date + billable), backed by a new `POST /api/entries` endpoint.

**Architecture:** Cross-repo. **Part A** (backend `di09-ernte`): a new `Api\EntryController@store` reusing the existing `StoreEntryRequest`, wired under `auth:sanctum`. **Part B** (iOS `../ernte`): a "Log time" sheet with a native hours+minutes wheel that synthesizes `started_at`/`ended_at` from date + duration (mirroring the web) and POSTs them.

**Tech Stack:** Laravel + Sanctum + Pest (backend, via `ddev`); SwiftUI + URLSession (iOS, built with `xcodebuild`). **Two repos** — each task states which.

---

## Context the engineer needs

- **Backend repo:** `/Users/sam/Documents/_projects/di09-ernte`. **iOS repo:** `/Users/sam/Documents/_projects/ernte`.
- The backend `StoreEntryRequest` (`app/Http/Requests/StoreEntryRequest.php`) already validates `project_id`/`task_id`/`description`/`started_at`/`ended_at`/`billable`, converts incoming UTC instants to the app timezone, and `authorize()` returns `true` — so it works as-is for an API call under `auth:sanctum` (which supplies `$request->user()`). `time_entries.description` is **NOT NULL**, so blank descriptions must coalesce to `''` (the web `EntryController@store` does the same).
- `routes/api.php` has an `auth:sanctum` group; tests use `Laravel\Sanctum\Sanctum::actingAs($user)`.
- The iOS `APIClient` (actor) decodes ISO-8601 dates; POSTs send `[String: Any]` JSON bodies; 422 → `APIError.validation`, 401 → `APIError.unauthorized`. Projects are already client-side via `TimerViewModel.projects` (`ProjectSummary`). The Timer screen presents sheets with `.sheet(isPresented:)` (pattern: `StartTimerSheet`). The iOS project has scheme `ernte` and a target `ernte`; there is **no Swift test target**, so iOS verification is an `xcodebuild` build + manual run.
- **Part B depends on Part A** (the iOS form calls the new endpoint), so do Part A first.

## File structure

**Part A — `di09-ernte`:**
- Create: `app/Http/Controllers/Api/EntryController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Http/Api/EntryApiTest.php`

**Part B — `../ernte`:**
- Modify: `ernte/Models/DTOs.swift` (add `EntryResponse`)
- Modify: `ernte/Networking/APIClient.swift` (add `createEntry`)
- Modify: `ernte/Features/Timer/TimerViewModel.swift` (add `logTime`)
- Modify: `ernte/Features/Timer/TimerView.swift` (add `LogTimeSheet` + "Log time…" button)

---

## Task 1: Backend `POST /api/entries` (repo: `di09-ernte`)

**Files:**
- Create: `app/Http/Controllers/Api/EntryController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/Api/EntryApiTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Http/Api/EntryApiTest.php`:

```php
<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['billable' => true]);
});

test('POST /api/entries requires authentication', function () {
    $this->postJson('/api/entries', [])->assertUnauthorized();
});

test('POST /api/entries creates a finished entry for the authenticated user', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/entries', [
        'project_id' => $this->project->id,
        'description' => 'Logged from iOS',
        'started_at' => '2026-05-27T09:00:00Z',
        'ended_at'   => '2026-05-27T10:30:00Z',
        'billable'   => true,
    ])->assertCreated()->assertJsonStructure(['id']);

    $entry = TimeEntry::first();
    expect($entry->user_id)->toBe($this->user->id);
    expect($entry->project_id)->toBe($this->project->id);
    expect($entry->description)->toBe('Logged from iOS');
    expect($entry->duration_seconds)->toBe(5400);
});

test('POST /api/entries with a blank description stores an empty string', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/entries', [
        'project_id' => $this->project->id,
        'description' => null,
        'started_at' => '2026-05-27T09:00:00Z',
        'ended_at'   => '2026-05-27T10:00:00Z',
        'billable'   => true,
    ])->assertCreated();

    expect(TimeEntry::first()->description)->toBe('');
});

test('POST /api/entries rejects ended_at not after started_at', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/entries', [
        'project_id' => $this->project->id,
        'started_at' => '2026-05-27T10:00:00Z',
        'ended_at'   => '2026-05-27T09:00:00Z',
        'billable'   => true,
    ])->assertStatus(422)->assertJsonValidationErrors('ended_at');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `ddev exec php artisan test tests/Feature/Http/Api/EntryApiTest.php`
Expected: FAIL (route `POST /api/entries` doesn't exist → 404, so the assertions fail).

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/EntryController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEntryRequest;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;

class EntryController extends Controller
{
    public function store(StoreEntryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $entry = TimeEntry::create([
            ...$data,
            // description is NOT NULL; a blank field arrives as null via ConvertEmptyStringsToNull.
            'description' => $data['description'] ?? '',
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['id' => $entry->id], 201);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/api.php`, add the import alongside the other `use App\Http\Controllers\Api\...;` lines:

```php
use App\Http\Controllers\Api\EntryController;
```

and add this line **inside** the `Route::middleware('auth:sanctum')->group(function () { ... })` block (e.g. right after the `/timer/discard` route):

```php
    Route::post('/entries', [EntryController::class, 'store']);
```

- [ ] **Step 5: Run to verify it passes**

Run: `ddev exec php artisan test tests/Feature/Http/Api/EntryApiTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit** (in `di09-ernte`, branch `feat/ios-manual-time-entry`)

```bash
git add app/Http/Controllers/Api/EntryController.php routes/api.php tests/Feature/Http/Api/EntryApiTest.php
git commit -m "feat(api): POST /api/entries to create a time entry"
```

---

## Task 2: iOS API client method (repo: `../ernte`)

**Files:**
- Modify: `ernte/Models/DTOs.swift`
- Modify: `ernte/Networking/APIClient.swift`

> All paths below are relative to `/Users/sam/Documents/_projects/ernte`. First, create a branch there:
> `git -C /Users/sam/Documents/_projects/ernte checkout -b feat/manual-time-entry`

- [ ] **Step 1: Add the response DTO**

In `ernte/Models/DTOs.swift`, add (e.g. after `TokenResponse`):

```swift
struct EntryResponse: Codable {
    let id: Int
}
```

- [ ] **Step 2: Add the `createEntry` method**

In `ernte/Networking/APIClient.swift`, add this method right after `discardTimer()` (before the `// MARK: - Projects` line):

```swift
    func createEntry(projectId: Int, description: String,
                     startedAt: Date, endedAt: Date, billable: Bool) async throws -> EntryResponse {
        let iso = ISO8601DateFormatter()
        return try await request("POST", "/api/entries", body: [
            "project_id": projectId,
            "description": description,
            "started_at": iso.string(from: startedAt),
            "ended_at": iso.string(from: endedAt),
            "billable": billable,
        ])
    }
```

- [ ] **Step 3: Commit** (in `../ernte`)

```bash
git -C /Users/sam/Documents/_projects/ernte add ernte/Models/DTOs.swift ernte/Networking/APIClient.swift
git -C /Users/sam/Documents/_projects/ernte commit -m "feat(ios): APIClient.createEntry for POST /api/entries"
```

---

## Task 3: iOS "Log time" sheet (repo: `../ernte`)

**Files:**
- Modify: `ernte/Features/Timer/TimerViewModel.swift`
- Modify: `ernte/Features/Timer/TimerView.swift`

- [ ] **Step 1: Add `logTime` to the view model**

In `ernte/Features/Timer/TimerViewModel.swift`, add this method after `discard()` (before the `private func run` helper):

```swift
    /// Create a manual entry from a date + duration. Anchors the start on the
    /// chosen day at the current time-of-day (times are invisible; this keeps the
    /// entry on its date), end = start + minutes. Returns true on success.
    func logTime(projectId: Int, description: String, date: Date, minutes: Int, billable: Bool) async -> Bool {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }
        do {
            let cal = Calendar.current
            let timeOfDay = cal.dateComponents([.hour, .minute, .second], from: Date())
            var comps = cal.dateComponents([.year, .month, .day], from: date)
            comps.hour = timeOfDay.hour
            comps.minute = timeOfDay.minute
            comps.second = timeOfDay.second
            let start = cal.date(from: comps) ?? date
            let end = start.addingTimeInterval(Double(minutes) * 60)
            _ = try await api.createEntry(projectId: projectId, description: description,
                                          startedAt: start, endedAt: end, billable: billable)
            await load()
            return true
        } catch APIError.unauthorized {
            session.handleUnauthorized()
            return false
        } catch let error as APIError {
            errorMessage = error.userMessage
            return false
        } catch {
            errorMessage = "Something went wrong."
            return false
        }
    }
```

- [ ] **Step 2: Add the "Log time…" button + sheet state in `TimerView`**

In `ernte/Features/Timer/TimerView.swift`:

(a) Add a state var after `@State private var sheetIsSwitch = false`:

```swift
    @State private var showLogSheet = false
```

(b) Add an always-visible "Log time…" section. Immediately after the `if let running = model.running { … } else { … }` block closes (i.e. right before `if let totals = model.payload?.totals {`), insert:

```swift
                Section {
                    Button("Log time…") { showLogSheet = true }
                }
                .listRowBackground(Theme.paper)

```

(c) Add a second sheet. Immediately after the existing `.sheet(isPresented: $showStartSheet) { … }` modifier, add:

```swift
            .sheet(isPresented: $showLogSheet) {
                LogTimeSheet(model: model) { showLogSheet = false }
            }
```

- [ ] **Step 3: Add the `LogTimeSheet` view**

In `ernte/Features/Timer/TimerView.swift`, add this private struct at the end of the file (after `StartTimerSheet`):

```swift
private struct LogTimeSheet: View {
    let model: TimerViewModel
    let onDone: () -> Void

    @State private var projectId: Int?
    @State private var description = ""
    @State private var hours = 1
    @State private var minutes = 0
    @State private var date = Date()
    @State private var billable = true

    private var totalMinutes: Int { hours * 60 + minutes }

    var body: some View {
        NavigationStack {
            Form {
                Picker("Project", selection: $projectId) {
                    Text("Select…").tag(Int?.none)
                    ForEach(model.projects) { project in
                        Text(project.name).tag(Int?.some(project.id))
                    }
                }
                TextField("Description (optional)", text: $description)
                HStack {
                    Picker("Hours", selection: $hours) {
                        ForEach(0..<13, id: \.self) { Text("\($0) h").tag($0) }
                    }
                    .pickerStyle(.wheel)
                    Picker("Minutes", selection: $minutes) {
                        ForEach(Array(stride(from: 0, to: 60, by: 5)), id: \.self) { Text("\($0) m").tag($0) }
                    }
                    .pickerStyle(.wheel)
                }
                DatePicker("Date", selection: $date, displayedComponents: .date)
                Toggle("Billable", isOn: $billable)
            }
            .erntePaperList()
            .navigationTitle("Log Time")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel", action: onDone)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        guard let projectId else { return }
                        Task {
                            let ok = await model.logTime(projectId: projectId, description: description,
                                                         date: date, minutes: totalMinutes, billable: billable)
                            if ok { onDone() }
                        }
                    }
                    .disabled(projectId == nil || totalMinutes == 0)
                }
            }
        }
    }
}
```

- [ ] **Step 4: Build the iOS app**

Run (from `/Users/sam/Documents/_projects/ernte`):

```bash
cd /Users/sam/Documents/_projects/ernte && xcodebuild -project ernte.xcodeproj -scheme ernte -destination 'platform=iOS Simulator,name=iPhone 17 Pro' build CODE_SIGNING_ALLOWED=NO
```

Expected: `** BUILD SUCCEEDED **`. If a simulator name error occurs, run `xcrun simctl list devices available | grep iPhone` and substitute an available iPhone simulator name.

- [ ] **Step 5: Commit** (in `../ernte`)

```bash
git -C /Users/sam/Documents/_projects/ernte add ernte/Features/Timer/TimerViewModel.swift ernte/Features/Timer/TimerView.swift
git -C /Users/sam/Documents/_projects/ernte commit -m "feat(ios): Log time sheet (manual duration entry)"
```

---

## Task 4: Verification

**Files:** none (verification only)

- [ ] **Step 1: Backend suite (repo `di09-ernte`)**

Run: `ddev exec php artisan test tests/Feature/Http/Api/EntryApiTest.php tests/Feature/Http/Api/TimerApiTest.php`
Expected: all PASS.

- [ ] **Step 2: iOS build (repo `../ernte`)**

Run: `cd /Users/sam/Documents/_projects/ernte && xcodebuild -project ernte.xcodeproj -scheme ernte -destination 'platform=iOS Simulator,name=iPhone 17 Pro' build CODE_SIGNING_ALLOWED=NO`
Expected: `** BUILD SUCCEEDED **`.

- [ ] **Step 3: Manual check** (run the app against a logged-in account)

  - The Timer tab shows a "Log time…" button (whether or not a timer is running).
  - The sheet has Project, Description, an hours+minutes wheel (default 1h 0m), Date (today), Billable (on).
  - "Save" is disabled until a project is picked and is disabled at 0h 0m.
  - Saving `1h 30m` on a project creates the entry; the sheet dismisses and the Timer "Today" totals increase by 1h30 after the reload.
  - An invalid case (e.g. server rejects) keeps the sheet open and surfaces an error.

- [ ] **Step 4: No commit** (verification only). If issues are found, return to the relevant task.

---

## Self-review notes

- **Spec coverage:** `POST /api/entries` reusing `StoreEntryRequest` + auth + description coalescing (Task 1) ✓; `EntryResponse` + `APIClient.createEntry` posting ISO timestamps (Task 2) ✓; `logTime` synthesis (date @ current time-of-day, end = start + minutes) + reload (Task 3 Step 1) ✓; native hours+minutes wheel, project/description/date/billable, Save disabled at 0 duration (Task 3 Step 3) ✓; "Log time…" entry point + sheet (Task 3 Step 2) ✓; backend Pest tests + iOS build + manual (Tasks 1 & 4) ✓.
- **Naming/signature consistency:** `createEntry(projectId:description:startedAt:endedAt:billable:)` is defined in Task 2 and called identically in Task 3; `logTime(projectId:description:date:minutes:billable:)` defined and called identically; `EntryResponse { id }` matches the backend `{ id }` 201 body; `totalMinutes` gates Save and is passed as `minutes`.
- **Cross-repo:** Tasks 1 & 4-step-1 run in `di09-ernte` (branch `feat/ios-manual-time-entry`); Tasks 2, 3 & 4-step-2 run in `../ernte` (branch `feat/manual-time-entry`).
