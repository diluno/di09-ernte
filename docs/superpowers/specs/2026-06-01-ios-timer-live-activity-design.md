# iOS Companion — Timer Live Activity — Design

**Date:** 2026-06-01
**Status:** Approved (pending spec review)
**Repo:** `/Users/sam/Documents/_projects/ernte` (target/module `ernte`)

## Summary

A **display-only Live Activity** for the running timer: while a timer runs, the
Lock Screen and Dynamic Island show the project name and a live-ticking elapsed
time. Tapping it opens the app. No backend changes; purely client-side off the
running entry the app already has.

## Decisions (from brainstorming)

- **Display-only** — no Stop button on the activity (tap opens the app). The
  interactive-Stop path (App Group + shared Keychain + App Intent) is deferred.
- **Content:** project name + live-ticking elapsed (no task).
- **Widget font:** system monospaced for v1 (JetBrains Mono parity is optional later).
- **Ticking:** via SwiftUI `Text(timerInterval:)` / `.timer` style — the system
  renders the count from `startedAt`, so it ticks on the Lock Screen even when the
  app is suspended/closed. No periodic updates or push needed.

## Architecture

### New target
- A **Widget Extension** target `ernteWidgets` (user creates in Xcode: New ▸ Target ▸
  Widget Extension, "Include Live Activity" checked, no configuration intent).

### Shared model — `TimerActivityAttributes.swift` (member of BOTH targets)
```
struct TimerActivityAttributes: ActivityAttributes {
    struct ContentState: Codable, Hashable {
        var projectName: String
        var startedAt: Date
    }
}
```
(No static attributes needed beyond the type.)

### App side — `LiveActivityController` (app target)
- Wraps `Activity<TimerActivityAttributes>`; methods `start(project:startedAt:)`,
  `update(project:startedAt:)`, `end()`, guarded by
  `ActivityAuthorizationInfo().areActivitiesEnabled`.
- Holds a reference to the current `Activity` (or looks it up via
  `Activity<TimerActivityAttributes>.activities`).
- Wired into `TimerViewModel`:
  - after `start`/`switch` (timer now running): start the activity, or update it
    if one already exists, using `running.project.name` + `running.startedAt`.
  - after `stop`/`discard` (nothing running): `end()`.
  - in `load()`: reconcile — running but no activity → start; not running →
    end any stale activity.

### Widget side — `TimerLiveActivity` (`ernteWidgets`)
- `ActivityConfiguration(for: TimerActivityAttributes.self)`:
  - **Lock Screen / banner:** leaf glyph + project name + ticking elapsed
    (`Text(timerInterval: state.startedAt...Date.distantFuture, countsDown: false)`),
    paper background, forest accent, `.monospacedDigit()`.
  - **Dynamic Island:** compact leading = leaf; compact trailing = ticking time;
    expanded = project name + ticking time; minimal = ticking time.
- Colors: a small inline palette in the widget (paper `#f5f1ea`, ink `#1a1a1a`,
  forest `#2d4a3a`) — the app's `Theme` is in the app target, so the widget keeps
  its own tiny copy rather than sharing the whole file.

### App Info.plist
- `NSSupportsLiveActivities` = `YES`.

## Data flow

`TimerViewModel` already receives the running entry (`project.name`, `startedAt: Date`)
from `/api/timer` and the timer mutations. The controller maps that to the
`ContentState`. The widget renders `startedAt` as a self-ticking timer — the app
does not push per-second updates.

## Error handling / edge cases

- Activities disabled by the user → `start` is a no-op (guarded); app works normally.
- App killed while a timer runs → the activity persists and keeps ticking (system
  renders from `startedAt`) until the system dismissal limit or until the app is
  reopened and `load()` reconciles. Stopping always happens in-app.
- Switching projects updates the activity's `projectName` and `startedAt`.

## Out of scope

- Interactive Stop/Switch on the activity (App Intents) — deferred (needs App Group
  + shared Keychain).
- Push-updated activities (not needed; the timer self-ticks).
- JetBrains Mono in the widget (system mono for v1).

## Testing

Manual on the iPhone 17 Pro simulator (Dynamic Island + Live Activities supported):
start a timer → activity appears on Lock Screen and Dynamic Island and ticks;
switch project → updates; stop/discard → disappears; tap → opens the app.

## Collaboration / build model

User creates the widget extension target, adds `TimerActivityAttributes.swift` to
both targets, and adds the `NSSupportsLiveActivities` Info.plist key. Claude authors
all Swift (shared attributes, `LiveActivityController`, `TimerViewModel` wiring,
`TimerLiveActivity` widget).
