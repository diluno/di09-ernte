# iOS Companion — Timer Live Activity Implementation Plan

> **For agentic workers:** Executed collaboratively. The **user** creates the Widget Extension target, sets file target-memberships, and builds; **Claude** authors all Swift + the Info.plist key. App repo: `/Users/sam/Documents/_projects/ernte`, app target/module `ernte`, widget target `ernteWidgets`. Steps use checkbox (`- [ ]`).

**Goal:** A display-only Live Activity that shows the running timer's project and a live-ticking elapsed time on the Lock Screen and Dynamic Island.

**Architecture:** A Widget Extension hosts an `ActivityConfiguration`. A shared `TimerActivityAttributes` (in both targets) carries `{projectName, startedAt}`. The app starts/updates/ends the activity as the timer changes; the elapsed time self-ticks via `Text(_:style:.timer)` (no background updates/push). No backend changes.

**Tech Stack:** SwiftUI, WidgetKit, ActivityKit, iOS 17.

**Key fact:** `Text(date, style: .timer)` renders a count-up from `date` that the system updates on the Lock Screen even while the app is suspended — so we never push per-second updates.

---

## Task 1: Create the Widget Extension target + enable Live Activities (USER + Claude)

- [ ] **Step 1 (User): add the target.** Xcode ▸ File ▸ New ▸ Target ▸ **Widget Extension**. Product name **`ernteWidgets`**. **Check "Include Live Activity"**, leave "Include Configuration App Intent" **unchecked**. Activate the scheme if prompted. This creates an `ernteWidgets/` folder with template files (`ernteWidgetsBundle.swift`, a sample widget, a sample Live Activity + sample attributes).

- [ ] **Step 2 (User): report paths.** Tell Claude the absolute path of the `ernteWidgets/` folder and list the `.swift` files Xcode generated in it.

- [ ] **Step 3 (Claude): enable Live Activities** in the APP's `ernte/Info.plist` — add:
```xml
	<key>NSSupportsLiveActivities</key>
	<true/>
```

- [ ] **Step 4: Commit**
```bash
git add ernte/Info.plist ernteWidgets
git commit -m "chore(ios): add ernteWidgets extension target + NSSupportsLiveActivities"
```

**CHECKPOINT 1:** User confirms the stock widget extension builds (the template's sample widget) before continuing.

---

## Task 2: Shared `TimerActivityAttributes` (Claude authors; USER sets memberships)

**Files (Claude creates):** `ernte/Shared/TimerActivityAttributes.swift`

- [ ] **Step 1: Create the file**
```swift
import ActivityKit
import Foundation

struct TimerActivityAttributes: ActivityAttributes {
    public struct ContentState: Codable, Hashable {
        var projectName: String
        var startedAt: Date
    }
}
```

- [ ] **Step 2 (User): add it to BOTH targets.** Select `TimerActivityAttributes.swift` in Xcode ▸ File Inspector ▸ **Target Membership** ▸ check **both** `ernte` and `ernteWidgets`.

- [ ] **Step 3: Commit**
```bash
git add ernte/Shared/TimerActivityAttributes.swift
git commit -m "feat(ios): shared TimerActivityAttributes for the Live Activity"
```

---

## Task 3: `LiveActivityController` (app target)

**Files (Claude creates):** `ernte/Support/LiveActivityController.swift`

- [ ] **Step 1: Create the controller**
```swift
import ActivityKit
import Foundation

@MainActor
enum LiveActivityController {
    /// Reconcile the Live Activity with the current running entry.
    /// - running project + start → start (or update) the activity
    /// - nil → end any running activity
    static func sync(projectName: String?, startedAt: Date?) {
        guard ActivityAuthorizationInfo().areActivitiesEnabled else { return }

        let current = Activity<TimerActivityAttributes>.activities.first

        if let projectName, let startedAt {
            let state = TimerActivityAttributes.ContentState(projectName: projectName, startedAt: startedAt)
            let content = ActivityContent(state: state, staleDate: nil)
            if let current {
                Task { await current.update(content) }
            } else {
                _ = try? Activity.request(
                    attributes: TimerActivityAttributes(),
                    content: content,
                    pushType: nil
                )
            }
        } else if let current {
            Task { await current.end(nil, dismissalPolicy: .immediate) }
        }
    }
}
```

- [ ] **Step 2 (User): add to the `ernte` target** (Add Files / verify membership = ernte only).

- [ ] **Step 3: Commit**
```bash
git add ernte/Support/LiveActivityController.swift
git commit -m "feat(ios): LiveActivityController — start/update/end the timer activity"
```

---

## Task 4: Wire the controller into `TimerViewModel`

**Files (Claude modifies):** `ernte/Features/Timer/TimerViewModel.swift`

The `run(_:)` helper already runs after every timer mutation/load and updates `payload`. Reconcile the activity there — one integration point covers start/switch/stop/discard/load.

- [ ] **Step 1: Add the sync call** at the end of the `do` block in `run(_:)`, right after `payload = try await operation()`:
```swift
            payload = try await operation()
            LiveActivityController.sync(
                projectName: running?.project.name,
                startedAt: running?.startedAt
            )
```
(`running` is the existing computed `payload?.running`.)

- [ ] **Step 2: Commit**
```bash
git add ernte/Features/Timer/TimerViewModel.swift
git commit -m "feat(ios): drive the Live Activity from the timer view model"
```

---

## Task 5: The Live Activity widget UI (`ernteWidgets`)

**Files:**
- Create: `ernteWidgets/TimerLiveActivity.swift`
- Modify: `ernteWidgets/ernteWidgetsBundle.swift` (point the bundle at our widget)
- Delete (or empty) the template's sample widget + sample live activity files.

- [ ] **Step 1: Create `ernteWidgets/TimerLiveActivity.swift`**
```swift
import ActivityKit
import WidgetKit
import SwiftUI

struct TimerLiveActivity: Widget {
    private let forest = Color(red: 0x2d/255, green: 0x4a/255, blue: 0x3a/255)
    private let paper  = Color(red: 0xf5/255, green: 0xf1/255, blue: 0xea/255)
    private let ink    = Color(red: 0x1a/255, green: 0x1a/255, blue: 0x1a/255)

    var body: some WidgetConfiguration {
        ActivityConfiguration(for: TimerActivityAttributes.self) { context in
            // Lock Screen / banner
            HStack(spacing: 12) {
                Image(systemName: "leaf.fill").foregroundStyle(forest)
                VStack(alignment: .leading, spacing: 2) {
                    Text(context.state.projectName)
                        .font(.system(.subheadline, design: .monospaced))
                        .foregroundStyle(ink)
                        .lineLimit(1)
                    Text(context.state.startedAt, style: .timer)
                        .font(.system(.title2, design: .monospaced))
                        .monospacedDigit()
                        .foregroundStyle(ink)
                }
                Spacer()
            }
            .padding()
            .activityBackgroundTint(paper)
            .activitySystemActionForegroundColor(forest)
        } dynamicIsland: { context in
            DynamicIsland {
                DynamicIslandExpandedRegion(.leading) {
                    Image(systemName: "leaf.fill").foregroundStyle(forest)
                }
                DynamicIslandExpandedRegion(.trailing) {
                    Text(context.state.startedAt, style: .timer)
                        .monospacedDigit().multilineTextAlignment(.trailing)
                        .frame(maxWidth: 64)
                }
                DynamicIslandExpandedRegion(.center) {
                    Text(context.state.projectName)
                        .font(.system(.caption, design: .monospaced))
                        .lineLimit(1)
                }
            } compactLeading: {
                Image(systemName: "leaf.fill").foregroundStyle(forest)
            } compactTrailing: {
                Text(context.state.startedAt, style: .timer)
                    .monospacedDigit().frame(maxWidth: 44)
            } minimal: {
                Image(systemName: "leaf.fill").foregroundStyle(forest)
            }
            .widgetURL(URL(string: "ernte://timer"))
        }
    }
}
```

- [ ] **Step 2: Point the bundle at our widget** — replace the body of `ernteWidgets/ernteWidgetsBundle.swift` so it vends only `TimerLiveActivity`:
```swift
import WidgetKit
import SwiftUI

@main
struct ernteWidgetsBundle: WidgetBundle {
    var body: some Widget {
        TimerLiveActivity()
    }
}
```

- [ ] **Step 3: Remove the template samples.** Empty the contents of the generated sample widget file and the sample Live Activity file (the ones Xcode created in Task 1 — names reported in Task 1 Step 2), replacing each with a single `import WidgetKit` line, OR delete them from the target. (We avoid leaving a second `@main` or duplicate `ActivityAttributes`.) **The sample file that defines a sample `…Attributes` MUST be removed** so it doesn't conflict with `TimerActivityAttributes`.

- [ ] **Step 4: Commit**
```bash
git add ernteWidgets
git commit -m "feat(ios): timer Live Activity — lock screen + Dynamic Island"
```

---

## Task 6: BUILD CHECKPOINT — verify, build, test

**Owner:** User (Xcode).

- [ ] **Step 1: Target memberships.** Confirm `TimerActivityAttributes.swift` is in **both** targets; `LiveActivityController.swift` + `TimerViewModel.swift` in `ernte`; `TimerLiveActivity.swift` + bundle in `ernteWidgets`.
- [ ] **Step 2: Build (⌘B)** the `ernte` scheme. Paste any errors (a common one is a leftover sample `…Attributes`/second `@main` in the widget target — Task 5 Step 3).
- [ ] **Step 3: Run (⌘R)** on the iPhone 17 Pro simulator. Then:
  1. Start a timer (pick a project) → a Live Activity appears (Lock Screen + Dynamic Island) showing the project and a ticking elapsed time.
  2. Lock the device (Cmd-L in the simulator) → the timer keeps ticking on the Lock Screen.
  3. Switch project → the activity's project name updates.
  4. Stop or Discard → the activity disappears.
  5. Tap the activity → the app opens.
- [ ] **Step 4: Report.** When it works, `fastlane beta` ships it.

---

## Self-Review Notes

- **Spec coverage:** new widget target (Task 1), shared attributes (Task 2), `LiveActivityController` start/update/end guarded by authorization (Task 3), view-model wiring incl. the `load()` reconcile via the shared `run()` path (Task 4), Lock-Screen + Dynamic Island UI with self-ticking timer + system mono + tap-to-open (Task 5), and manual verification incl. app-killed/lock-screen ticking (Task 6). Out-of-scope items (Stop button, push, JetBrains Mono in widget) are excluded.
- **Edge cases:** authorization disabled → `sync` no-ops; app reopened with a timer running → `load()`→`run()`→`sync` updates the existing activity (found via `Activity.activities.first`); nothing running → `sync` ends any stale activity.
- **Type consistency:** `TimerActivityAttributes.ContentState{projectName, startedAt}` is used identically in the controller (Task 3) and widget (Task 5); `sync(projectName:startedAt:)` is called from `TimerViewModel.run` (Task 4) with `running?.project.name` / `running?.startedAt` (matching the existing `RunningEntry` DTO).
