# iOS Home Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Home tab to the ernte iOS app — a one-glance overview (running timer, hours & earnings, money owed) — fed by a single new `GET /api/dashboard` endpoint.

**Architecture:** A new Laravel API endpoint composes three already-built projections (`TimerToday`, `DashboardProjections::stats`, `InvoiceProjections::stats`) plus one new 7-day sparkline query into a single JSON payload. The iOS app adds DTOs, an `APIClient` method, a `HomeViewModel`, and a `HomeView` (three stacked cards in the paper/forest theme), then wires Home as the first tab.

**Tech Stack:** PHP 8.2 / Laravel 12 / Pest 4 / Sanctum (backend); Swift / SwiftUI / Observation (iOS). Two repos: backend `/Users/sam/Documents/_projects/di09-ernte`, iOS `/Users/sam/Documents/_projects/ernte`.

**Notes for the implementer:**
- The Xcode project uses **file-system-synchronized groups** — new `.swift` files placed under `ernte/Features/...` or `ernte/Models/` are picked up automatically. **Do not edit `project.pbxproj`.**
- The iOS app has **no networking unit-test target**, so iOS verification is "builds + manually correct in the simulator". Backend gets a real Pest test.
- Run backend tests with `ddev artisan test <path>`.

---

## Task 1: Backend — `GET /api/dashboard` endpoint

Repo: `/Users/sam/Documents/_projects/di09-ernte`

**Files:**
- Create: `app/Http/Controllers/Api/DashboardController.php`
- Modify: `app/Support/DashboardProjections.php` (add `summary()` + two private helpers)
- Modify: `routes/api.php` (add one route)
- Test: `tests/Feature/Http/Api/DashboardApiTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Http/Api/DashboardApiTest.php`:

```php
<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('GET /api/dashboard requires authentication', function () {
    $this->getJson('/api/dashboard')->assertUnauthorized();
});

test('GET /api/dashboard returns timer, hours and money blocks', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'started_at' => now()->setTime(9, 0),
        'ended_at' => now()->setTime(11, 0),
        'billable' => true,
    ]);
    Sanctum::actingAs($user);

    $res = $this->getJson('/api/dashboard')->assertOk();

    $res->assertJsonStructure([
        'timer' => ['running', 'today' => ['total_seconds', 'billable_seconds', 'earnings_amount']],
        'hours' => ['week_hours', 'sparkline'],
        'money' => ['outstanding', 'overdue', 'unbilled'],
    ]);
    expect($res->json('hours.sparkline'))->toHaveCount(7);
    expect($res->json('timer.running'))->toBeNull();
    expect($res->json('timer.today.total_seconds'))->toBe(7200);
});

test('GET /api/dashboard surfaces the running entry', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'started_at' => now()->subMinutes(30),
    ]);
    Sanctum::actingAs($user);

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('timer.running.project.code', $project->code);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev artisan test tests/Feature/Http/Api/DashboardApiTest.php`
Expected: FAIL — route `/api/dashboard` not defined (404 / `assertUnauthorized` may pass but the structure tests fail because the route returns a 404).

- [ ] **Step 3: Add the `summary()` method + helpers to `DashboardProjections`**

In `app/Support/DashboardProjections.php`, add these three methods inside the class (after the existing `stats()` method). The class already imports `App\Models\TimeEntry`, `App\Models\Project`, `App\Models\User`, and `Illuminate\Support\Carbon`, and already defines the `DURATION_SECONDS_SQL` constant.

```php
    /** Single aggregate payload for the iOS Home tab. Composes existing projections. */
    public static function summary(User $user): array
    {
        $timer = TimerToday::payload($user);
        $stats = self::stats($user);
        $invoice = \App\Support\InvoiceProjections::stats();

        return [
            'timer' => [
                'running' => self::runningEntry($user),
                'today' => $timer['totals'],
            ],
            'hours' => [
                'week_hours' => $stats['week_hours'],
                'sparkline' => self::dailyHoursSparkline($user, 7),
            ],
            'money' => [
                'outstanding' => $invoice['outstanding'],
                'overdue' => $invoice['overdue'],
                'unbilled' => $stats['unbilled_amount'],
            ],
        ];
    }

    /** The user's currently running entry in the API's running-entry shape, or null. */
    private static function runningEntry(User $user): ?array
    {
        $running = TimeEntry::running()
            ->where('user_id', $user->id)
            ->with(['project:id,name,code', 'task:id,name'])
            ->first();

        return $running ? [
            'id' => $running->id,
            'description' => $running->description,
            'task_name' => $running->task?->name,
            'started_at' => $running->started_at->toIso8601String(),
            'duration_seconds' => $running->duration_seconds,
            'billable' => (bool) $running->billable,
            'project' => [
                'id' => $running->project->id,
                'name' => $running->project->name,
                'code' => $running->project->code,
            ],
        ] : null;
    }

    /** Hours tracked per day for the past $days days (oldest→newest), for the sparkline. */
    private static function dailyHoursSparkline(User $user, int $days): array
    {
        $start = Carbon::today()->subDays($days - 1);
        $now = Carbon::now()->toDateTimeString();

        $byDay = TimeEntry::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $start)
            ->selectRaw('DATE(started_at) AS day, SUM(' . self::DURATION_SECONDS_SQL . ') AS secs', [$now])
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $key = $start->copy()->addDays($i)->toDateString();
            $out[] = round((($byDay->get($key)->secs ?? 0)) / 3600, 1);
        }

        return $out;
    }
```

Add this import at the top of the file alongside the existing `use` statements:

```php
use App\Support\TimerToday;
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/Api/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DashboardProjections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(DashboardProjections::summary($request->user()));
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/api.php`, add the import near the other `Api\...` controller imports:

```php
use App\Http\Controllers\Api\DashboardController;
```

Then add this line inside the existing `Route::middleware('auth:sanctum')->group(function () { ... })` block (e.g. right after the `/me` route):

```php
    Route::get('/dashboard', [DashboardController::class, 'show']);
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `ddev artisan test tests/Feature/Http/Api/DashboardApiTest.php`
Expected: PASS (4 passed).

- [ ] **Step 7: Run Pint and the full suite**

Run: `ddev exec ./vendor/bin/pint app/Http/Controllers/Api/DashboardController.php app/Support/DashboardProjections.php`
Run: `ddev artisan test`
Expected: green.

- [ ] **Step 8: Commit**

```bash
cd /Users/sam/Documents/_projects/di09-ernte
git add app/Http/Controllers/Api/DashboardController.php app/Support/DashboardProjections.php routes/api.php tests/Feature/Http/Api/DashboardApiTest.php
git commit -m "feat(api): dashboard summary endpoint for iOS Home tab

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: iOS — DTOs + APIClient method

Repo: `/Users/sam/Documents/_projects/ernte`

**Files:**
- Modify: `ernte/Models/StatusDTOs.swift` (append new structs)
- Modify: `ernte/Networking/APIClient.swift` (add one method)

- [ ] **Step 1: Add the DTOs**

Append to `ernte/Models/StatusDTOs.swift` (the file already defines `Paginated`, invoice/estimate stats, etc.). `RunningEntry` and `TimerTotals` already exist in `ernte/Models/DTOs.swift` and are reused here:

```swift
// MARK: - Dashboard (Home tab)

struct DashboardResponse: Codable {
    let timer: DashboardTimer
    let hours: DashboardHours
    let money: DashboardMoney
}

struct DashboardTimer: Codable {
    let running: RunningEntry?
    let today: TimerTotals
}

struct DashboardHours: Codable {
    let weekHours: Double
    let sparkline: [Double]
    enum CodingKeys: String, CodingKey {
        case sparkline
        case weekHours = "week_hours"
    }
}

struct DashboardMoney: Codable {
    let outstanding: Double
    let overdue: Double
    let unbilled: Double
}
```

- [ ] **Step 2: Add the APIClient method**

In `ernte/Networking/APIClient.swift`, add this method in the `// MARK: - Endpoints` area (e.g. right after the `me()` method):

```swift
    func dashboard() async throws -> DashboardResponse {
        try await request("GET", "/api/dashboard")
    }
```

- [ ] **Step 3: Build to verify it compiles**

Run: `cd /Users/sam/Documents/_projects/ernte && xcodebuild -project ernte.xcodeproj -scheme ernte -destination 'platform=iOS Simulator,name=iPhone 16' build -quiet`
Expected: `** BUILD SUCCEEDED **`. (If the named simulator is missing, list with `xcrun simctl list devices available` and substitute any available iPhone, or build in Xcode with ⌘B.)

- [ ] **Step 4: Commit**

```bash
cd /Users/sam/Documents/_projects/ernte
git add ernte/Models/StatusDTOs.swift ernte/Networking/APIClient.swift
git commit -m "feat(ios): dashboard DTOs + APIClient.dashboard()

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: iOS — HomeViewModel

Repo: `/Users/sam/Documents/_projects/ernte`

**Files:**
- Create: `ernte/Features/Home/HomeViewModel.swift`

- [ ] **Step 1: Create the view model**

Mirrors `TimerViewModel`'s `run { }` error routing (401 → sign out; `APIError` → message). Stopping the timer from Home also clears the Live Activity, matching `TimerViewModel.run`.

Create `ernte/Features/Home/HomeViewModel.swift`:

```swift
import Foundation
import Observation

@Observable
@MainActor
final class HomeViewModel {
    var dashboard: DashboardResponse?
    var isLoading = false
    var errorMessage: String?

    private let session: Session
    private var api: APIClient { session.api }

    init(session: Session) { self.session = session }

    var running: RunningEntry? { dashboard?.timer.running }

    func load() async {
        await run { self.dashboard = try await self.api.dashboard() }
    }

    /// Stop the running timer, clear its Live Activity, then refresh the dashboard.
    func stopTimer() async {
        await run {
            _ = try await self.api.stopTimer()
            LiveActivityController.sync(projectName: nil, startedAt: nil)
            self.dashboard = try await self.api.dashboard()
        }
    }

    private func run(_ operation: @escaping () async throws -> Void) async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }
        do {
            try await operation()
        } catch APIError.unauthorized {
            session.handleUnauthorized()
        } catch let error as APIError {
            errorMessage = error.userMessage
        } catch {
            errorMessage = "Something went wrong."
        }
    }
}
```

- [ ] **Step 2: Build to verify it compiles**

Run: `cd /Users/sam/Documents/_projects/ernte && xcodebuild -project ernte.xcodeproj -scheme ernte -destination 'platform=iOS Simulator,name=iPhone 16' build -quiet`
Expected: `** BUILD SUCCEEDED **`.

- [ ] **Step 3: Commit**

```bash
cd /Users/sam/Documents/_projects/ernte
git add ernte/Features/Home/HomeViewModel.swift
git commit -m "feat(ios): HomeViewModel for the dashboard

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: iOS — Sparkline view + HomeView

Repo: `/Users/sam/Documents/_projects/ernte`

**Files:**
- Create: `ernte/Features/Home/SparklineView.swift`
- Create: `ernte/Features/Home/HomeView.swift`

- [ ] **Step 1: Create the sparkline subview**

A small bar chart in forest, scaled to its own max. Create `ernte/Features/Home/SparklineView.swift`:

```swift
import SwiftUI

/// A compact bar sparkline (hours per day) drawn in the forest accent.
struct SparklineView: View {
    let values: [Double]

    var body: some View {
        let maxValue = max(values.max() ?? 0, 0.01)
        HStack(alignment: .bottom, spacing: 3) {
            ForEach(Array(values.enumerated()), id: \.offset) { _, value in
                Capsule()
                    .fill(Theme.forest.opacity(0.85))
                    .frame(height: max(2, CGFloat(value / maxValue) * 26))
            }
        }
        .frame(height: 26)
    }
}
```

- [ ] **Step 2: Create the HomeView**

Three stacked cards in the paper/forest theme (Layout A). Live-ticking elapsed reuses the `Timer.publish(every: 1, ...)` + `now` pattern from `TimerView`. Idle timer shows a control that selects the Timer tab via the shared `selection` binding.

Create `ernte/Features/Home/HomeView.swift`:

```swift
import SwiftUI
import Combine

struct HomeView: View {
    @State private var model: HomeViewModel
    /// Lets the idle-timer card jump the user to the Timer tab.
    @Binding var selectedTab: Int

    @State private var now = Date()
    private let tick = Timer.publish(every: 1, on: .main, in: .common).autoconnect()

    init(session: Session, selectedTab: Binding<Int>) {
        _model = State(initialValue: HomeViewModel(session: session))
        _selectedTab = selectedTab
    }

    var body: some View {
        NavigationStack {
            List {
                timerSection
                if let hours = model.dashboard?.hours, let today = model.dashboard?.timer.today {
                    hoursSection(hours: hours, today: today)
                }
                if let money = model.dashboard?.money {
                    moneySection(money)
                }
                if let error = model.errorMessage {
                    Section { Text(error).foregroundStyle(Theme.red) }
                        .listRowBackground(Theme.paper)
                }
            }
            .erntePaperList()
            .navigationTitle("Home")
            .overlay { if model.isLoading && model.dashboard == nil { ProgressView() } }
            .refreshable { await model.load() }
            .task { await model.load() }
            .onReceive(tick) { now = $0 }
        }
    }

    // MARK: - Timer hero

    @ViewBuilder
    private var timerSection: some View {
        Section {
            if let running = model.running {
                VStack(alignment: .leading, spacing: 8) {
                    Text(running.project.name)
                        .font(.ernteMedium)
                        .foregroundStyle(Theme.paper)
                    Text(elapsed(since: running.startedAt))
                        .font(.ernteLargeNum)
                        .monospacedDigit()
                        .foregroundStyle(Theme.paper)
                    Button("Stop") { Task { await model.stopTimer() } }
                        .font(.ernteMedium)
                        .foregroundStyle(Theme.paper)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(4)
                .listRowBackground(Theme.forest)
            } else {
                Button("Start a timer…") { selectedTab = 1 }
                    .font(.ernteMedium)
                    .foregroundStyle(Theme.paper)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(4)
                    .listRowBackground(Theme.forest)
            }
        } header: {
            Text("Timer").ernteSectionHeader()
        }
    }

    // MARK: - Hours & earnings

    @ViewBuilder
    private func hoursSection(hours: DashboardHours, today: TimerTotals) -> some View {
        Section {
            LabeledContent("Today", value: Format.hours(Double(today.totalSeconds) / 3600))
            LabeledContent("Billable", value: Format.hours(Double(today.billableSeconds) / 3600))
            LabeledContent("Earned today", value: Format.money(today.earningsAmount))
            LabeledContent("This week", value: Format.hours(hours.weekHours))
            SparklineView(values: hours.sparkline)
                .listRowBackground(Theme.paper)
        } header: {
            Text("Hours & earnings").ernteSectionHeader()
        }
        .listRowBackground(Theme.paper)
    }

    // MARK: - Money owed

    @ViewBuilder
    private func moneySection(_ money: DashboardMoney) -> some View {
        Section {
            LabeledContent("Outstanding", value: Format.money(money.outstanding))
            LabeledContent("Overdue", value: Format.money(money.overdue))
                .foregroundStyle(money.overdue > 0 ? Theme.red : Theme.ink)
            LabeledContent("Unbilled time", value: Format.money(money.unbilled))
                .foregroundStyle(money.unbilled > 0 ? Theme.rust : Theme.ink)
        } header: {
            Text("Money owed").ernteSectionHeader()
        }
        .listRowBackground(Theme.paper)
    }

    private func elapsed(since start: Date) -> String {
        let s = max(0, Int(now.timeIntervalSince(start)))
        return String(format: "%d:%02d:%02d", s / 3600, (s % 3600) / 60, s % 60)
    }
}
```

- [ ] **Step 3: Build to verify it compiles**

Run: `cd /Users/sam/Documents/_projects/ernte && xcodebuild -project ernte.xcodeproj -scheme ernte -destination 'platform=iOS Simulator,name=iPhone 16' build -quiet`
Expected: `** BUILD SUCCEEDED **`.

- [ ] **Step 4: Commit**

```bash
cd /Users/sam/Documents/_projects/ernte
git add ernte/Features/Home/SparklineView.swift ernte/Features/Home/HomeView.swift
git commit -m "feat(ios): HomeView dashboard cards + sparkline

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: iOS — Wire Home as the first tab

Repo: `/Users/sam/Documents/_projects/ernte`

**Files:**
- Modify: `ernte/ContentView.swift` (`MainTabView`)

- [ ] **Step 1: Add a selection-backed Home tab**

The existing `MainTabView` uses a plain `TabView` with no selection. Add a `selection` state so the idle-timer card can switch to the Timer tab, and insert Home first. Replace the `MainTabView` struct in `ernte/ContentView.swift` with:

```swift
struct MainTabView: View {
    let session: Session
    @State private var selectedTab = 0

    var body: some View {
        TabView(selection: $selectedTab) {
            HomeView(session: session, selectedTab: $selectedTab)
                .tabItem { Label("Home", systemImage: "house") }
                .tag(0)

            TimerView(session: session)
                .tabItem { Label("Timer", systemImage: "timer") }
                .tag(1)

            ProjectsView(session: session)
                .tabItem { Label("Projects", systemImage: "folder") }
                .tag(2)

            BillingView(session: session)
                .tabItem { Label("Billing", systemImage: "doc.text") }
                .tag(3)

            AccountView(session: session)
                .tabItem { Label("Account", systemImage: "person.crop.circle") }
                .tag(4)
        }
    }
}
```

(The `HomeView(session:selectedTab:)` initializer and the `selectedTab == 1` Timer mapping from Task 4 match these tags.)

- [ ] **Step 2: Build to verify it compiles**

Run: `cd /Users/sam/Documents/_projects/ernte && xcodebuild -project ernte.xcodeproj -scheme ernte -destination 'platform=iOS Simulator,name=iPhone 16' build -quiet`
Expected: `** BUILD SUCCEEDED **`.

- [ ] **Step 3: Manual verification in the simulator**

With the backend running (`ddev start` in the backend repo) and a signed-in session:
1. Launch the app in the simulator (Xcode ⌘R, or `xcodebuild ... test`-free run).
2. Confirm the app opens on **Home**.
3. With a timer running: hero shows project + live-ticking elapsed; **Stop** stops it and the card flips to "Start a timer…".
4. With no timer: tapping "Start a timer…" switches to the Timer tab.
5. Hours card shows today/billable/earned/this-week and a 7-bar sparkline; Money card shows outstanding/overdue/unbilled (overdue red when > 0).
6. Pull-to-refresh reloads.

- [ ] **Step 4: Commit**

```bash
cd /Users/sam/Documents/_projects/ernte
git add ernte/ContentView.swift
git commit -m "feat(ios): Home as the first tab (dashboard overview)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-review notes (verified against the spec)

- **Spec coverage:** Endpoint (Task 1) ✓; DTOs/APIClient (Task 2) ✓; ViewModel with shared error routing (Task 3) ✓; three Layout-A cards + sparkline + idle/Stop states (Task 4) ✓; Home as first tab (Task 5) ✓. Error/empty states: error banner + idle hero + `CHF 0`/`0h` via `Format` (always-rendered rows) ✓. Sparkline length 7 asserted in the backend test ✓. Running-timer null + populated cases tested ✓.
- **Type consistency:** `DashboardResponse`/`DashboardTimer`/`DashboardHours`/`DashboardMoney` defined in Task 2 are the exact types consumed in Tasks 3–4; `RunningEntry`/`TimerTotals` reused from `DTOs.swift`; JSON keys (`week_hours`, `total_seconds`, `billable_seconds`, `earnings_amount`, `outstanding`, `overdue`, `unbilled`) match the backend payload in Task 1. `HomeView(session:selectedTab:)` and tab tags 0/1 are consistent across Tasks 4–5.
- **Out of scope (unchanged):** no "Needs attention" card, no MTD earnings, no web Reports, no timer start/switch from Home (only Stop).
