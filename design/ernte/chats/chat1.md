# Ernte Time Tracking

_Started 2026-05-27 13:16 UTC_

---

## User

i'm creating a self hosted alternative to harvest (time tracking/invoicing app). it's called "ernte"

## Assistant

_[tool: questions_v2]_

## User

Questions answered:
- scope: Projects & tasks, Clients, Invoices, Timer / time entry
- primary_screen: Project dashboard with budgets
- audience: Solo freelancer self-hosting
- aesthetic: Developer-tool flavor — dense, mono, terminal vibes
- color_palette: 1
- density: Decide for me
- device: Decide for me
- self_hosted_signals: Decide for me
- variations: One polished direction, multiple screens
- tweaks: Decide for me

## Assistant

_[tool: update_todos]_

Got it — I'll commit to this direction up front:

**System I'm using**
- **Aesthetic:** developer-tool terminal vibes, but warmed up with cream paper + forest/rust accents (palette #1). Dense but not cramped.
- **Type:** JetBrains Mono throughout. Sizes 11/13/15/24/36. Tight tracking.
- **Palette:** `#f5f1ea` paper, `#1a1a1a` ink, `#2d4a3a` forest (primary), `#c97b3c` rust (accent/warn), `#b54834` red (over budget).
- **Self-hosted signal:** subtle — a status footer showing `localhost:7878 · v0.4.2 · DB OK · uptime`. No heavy admin chrome.
- **Tweaks:** theme (paper/dark), density (comfortable/compact), accent color.
- **Screens:** Project dashboard (hero) → Project detail → Timer/today → Clients → Invoices, all reachable via sidebar.

Let me start.

_[tool: copy_starter_component]_

_[tool: write_file]_

_[tool: write_file]_

_[tool: write_file]_

_[tool: write_file]_

_[tool: write_file]_

_[tool: grep]_

_[tool: str_replace_edit]_

_[tool: str_replace_edit]_

_[tool: str_replace_edit]_

_[tool: done]_

_[tool: fork_verifier_agent]_

Built **ernte** — a self-hosted time-tracking prototype with a developer-tool / warm-paper aesthetic.

**What's there:**
- **Projects dashboard** (hero) — 8 projects with hours + fees budget bars, 14-day sparklines, status badges (over / at risk / retainer), stats strip
- **Project detail** — burn-down chart, task list with budgets, recent entries, activity heatmap
- **Timer / Today** — large running display, today's 6 entries, by-project breakdown, keyboard shortcuts
- **Clients** — table with rates, outstanding balances, YTD hours
- **Invoices** — list + a full invoice document view with line items, VAT, payment details
- **Self-hosted footer** — `localhost:7878 · v0.4.2 · db postgres 16.2 · backup 2h ago · uptime 14d`
- **Sidebar** — pinned projects, this-week mini-bar chart
- **Tweaks** — paper/dark theme, comfortable/compact density, accent color

Toggle Tweaks from the toolbar to try dark mode + compact density.

