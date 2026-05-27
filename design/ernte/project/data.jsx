/* Mock data for ernte */

const CLIENTS = [
  { id: "atlas", name: "Atlas Robotics", short: "AR", contact: "Marit Hesse", email: "marit@atlas.dev", rate: 145, projects: 3, outstanding: 4280, hours_ytd: 312 },
  { id: "korbel", name: "Körbel & Söhne GmbH", short: "KS", contact: "Stefan Körbel", email: "s.korbel@korbel.de", rate: 120, projects: 2, outstanding: 0, hours_ytd: 188 },
  { id: "northlit", name: "Northlit Press", short: "NP", contact: "Annie Park", email: "annie@northlit.co", rate: 130, projects: 1, outstanding: 1850, hours_ytd: 92 },
  { id: "halden", name: "Halden Studio", short: "HS", contact: "Ola Halden", email: "ola@halden.no", rate: 110, projects: 1, outstanding: 0, hours_ytd: 64 },
  { id: "kelp", name: "Kelp Forest Co.", short: "KF", contact: "Mira Okafor", email: "mira@kelp.co", rate: 150, projects: 1, outstanding: 7200, hours_ytd: 41 },
  { id: "private", name: "Private / Internal", short: "··", contact: "—", email: "—", rate: 0, projects: 2, outstanding: 0, hours_ytd: 28 },
];

const PROJECTS = [
  {
    id: "atlas-fleet", name: "Fleet Console v2", client: "atlas", code: "ATLS-FLT",
    status: "active", glyph: "alt-0",
    budget_hours: 220, spent_hours: 142.5, budget_amount: 31900, spent_amount: 20662.5,
    rate: 145, billable: true,
    last_activity: "12m ago",
    description: "Operator UI for the next-gen fleet control system. Replaces the legacy Java tool.",
    deadline: "Jul 18",
    started: "Mar 02"
  },
  {
    id: "korbel-erp", name: "ERP Migration", client: "korbel", code: "KS-ERP",
    status: "active", glyph: "alt-1",
    budget_hours: 160, spent_hours: 154.0, budget_amount: 19200, spent_amount: 18480,
    rate: 120, billable: true,
    last_activity: "2h ago",
    description: "SAP → Odoo migration. Data mapping, reports, training docs.",
    deadline: "Jun 30",
    started: "Apr 11"
  },
  {
    id: "atlas-docs", name: "Developer Docs Portal", client: "atlas", code: "ATLS-DOC",
    status: "active", glyph: "alt-2",
    budget_hours: 80, spent_hours: 84.5, budget_amount: 11600, spent_amount: 12252.5,
    rate: 145, billable: true,
    last_activity: "yesterday",
    description: "Static site for API docs. MDX + Astro. Owns search, versioning, code samples.",
    deadline: "Jun 14",
    started: "Feb 20"
  },
  {
    id: "northlit-site", name: "Marketing Site Refresh", client: "northlit", code: "NL-WEB",
    status: "active", glyph: "alt-3",
    budget_hours: 120, spent_hours: 28.0, budget_amount: 15600, spent_amount: 3640,
    rate: 130, billable: true,
    last_activity: "3h ago",
    description: "Type-driven editorial refresh of the marketing site. Astro + custom CMS.",
    deadline: "Aug 22",
    started: "May 13"
  },
  {
    id: "kelp-brand", name: "Brand System", client: "kelp", code: "KF-BRD",
    status: "active", glyph: "alt-4",
    budget_hours: 60, spent_hours: 41.0, budget_amount: 9000, spent_amount: 6150,
    rate: 150, billable: true,
    last_activity: "4d ago",
    description: "Identity rework + design tokens + Figma library.",
    deadline: "Jul 04",
    started: "Apr 28"
  },
  {
    id: "halden-app", name: "iOS App MVP", client: "halden", code: "HS-IOS",
    status: "active", glyph: "alt-0",
    budget_hours: 100, spent_hours: 64.0, budget_amount: 11000, spent_amount: 7040,
    rate: 110, billable: true,
    last_activity: "yesterday",
    description: "Reading-tracker SwiftUI app. Just shipped TestFlight build 0.4.",
    deadline: "Aug 01",
    started: "Mar 25"
  },
  {
    id: "korbel-support", name: "Retainer / Support", client: "korbel", code: "KS-SUP",
    status: "active", glyph: "alt-1",
    budget_hours: 16, spent_hours: 6.5, budget_amount: 1920, spent_amount: 780,
    rate: 120, billable: true, retainer: true,
    last_activity: "5h ago",
    description: "Monthly retainer. 16h / month. Resets on the 1st.",
    deadline: "Jun 30 (monthly)",
    started: "Jan 01"
  },
  {
    id: "ernte-dev", name: "ernte (self)", client: "private", code: "ERNTE",
    status: "active", glyph: "alt-2",
    budget_hours: 0, spent_hours: 22.0, budget_amount: 0, spent_amount: 0,
    rate: 0, billable: false,
    last_activity: "1d ago",
    description: "Internal time tracking on the time tracker. Yes, this is recursive.",
    deadline: "—",
    started: "Jan 14"
  },
];

const TODAY_ENTRIES = [
  { id: 1, project: "atlas-fleet", task: "Map mode: cluster rendering",        start: "09:12", end: "10:48", dur: 5760, billable: true, running: false },
  { id: 2, project: "korbel-support", task: "Sync bug — partner export",        start: "10:52", end: "11:24", dur: 1920, billable: true, running: false },
  { id: 3, project: "atlas-fleet", task: "Code review: PR #318",                start: "13:30", end: "14:05", dur: 2100, billable: true, running: false },
  { id: 4, project: "atlas-docs",  task: "Versioned routing prototype",         start: "14:18", end: "15:42", dur: 5040, billable: true, running: false },
  { id: 5, project: "ernte-dev",   task: "Invoice PDF template",                start: "15:55", end: "16:22", dur: 1620, billable: false, running: false },
  { id: 6, project: "atlas-fleet", task: "Telemetry side-panel",                start: "16:30", end: null,    dur: 2543, billable: true, running: true  },
];

const INVOICES = [
  { id: "2026-014", client: "atlas",    issued: "May 20", due: "Jun 03", status: "sent",    total: 4280.00, hours: 29.5 },
  { id: "2026-013", client: "kelp",     issued: "May 12", due: "May 26", status: "overdue", total: 7200.00, hours: 48.0 },
  { id: "2026-012", client: "northlit", issued: "May 04", due: "May 18", status: "paid",    total: 5070.00, hours: 39.0 },
  { id: "2026-011", client: "atlas",    issued: "Apr 28", due: "May 12", status: "paid",    total: 12180.00, hours: 84.0 },
  { id: "2026-010", client: "korbel",   issued: "Apr 21", due: "May 05", status: "paid",    total: 6240.00, hours: 52.0 },
  { id: "2026-009", client: "halden",   issued: "Apr 14", due: "Apr 28", status: "paid",    total: 4400.00, hours: 40.0 },
  { id: "2026-008", client: "atlas",    issued: "Apr 07", due: "Apr 21", status: "paid",    total: 9425.00, hours: 65.0 },
  { id: "draft-1", client: "northlit",  issued: "—",      due: "—",      status: "draft",   total: 1850.00, hours: 14.0 },
];

const TASKS_BY_PROJECT = {
  "atlas-fleet": [
    { id: 1, name: "Map mode: cluster rendering", done: false, budget: 16, spent: 11.5 },
    { id: 2, name: "Telemetry side-panel",         done: false, budget: 20, spent: 14.2 },
    { id: 3, name: "Operator role permissions",    done: false, budget: 12, spent: 6.0 },
    { id: 4, name: "PR review queue",              done: false, budget: 30, spent: 22.8 },
    { id: 5, name: "Discovery / spec",             done: true,  budget: 24, spent: 24.0 },
    { id: 6, name: "Auth refactor",                done: true,  budget: 18, spent: 16.5 },
    { id: 7, name: "Storybook scaffold",           done: true,  budget: 8,  spent: 7.5 },
  ],
};

const WEEK_HOURS = [6.2, 7.8, 8.4, 7.1, 8.9, 0.5, 0]; // Mon..Sun

// Sparkline data — last 14 days hours/day
const SPARKS = {
  "atlas-fleet":   [3.2, 4.1, 0, 6.8, 7.2, 1.5, 0, 5.4, 6.1, 7.8, 8.4, 4.2, 6.5, 6.8],
  "korbel-erp":    [5.5, 6.2, 7.1, 8.0, 4.5, 0, 0, 6.2, 8.4, 7.5, 6.8, 5.0, 4.2, 2.0],
  "atlas-docs":    [1.0, 2.5, 3.1, 0, 0, 0, 0, 4.2, 5.5, 6.1, 3.2, 2.8, 0, 0],
  "northlit-site": [0, 0, 0, 0, 0, 0, 0, 0, 2.1, 3.4, 4.2, 5.1, 6.8, 6.4],
  "kelp-brand":    [4.2, 5.1, 6.4, 7.2, 3.1, 0, 0, 2.8, 3.5, 2.1, 0, 0, 0, 0],
  "halden-app":    [3.5, 4.2, 5.1, 6.2, 4.8, 0, 0, 5.5, 6.4, 7.2, 5.5, 4.1, 3.8, 0],
  "korbel-support":[0.5, 0, 1.2, 0, 0.8, 0, 0, 0, 1.5, 2.2, 0, 0, 0.8, 1.3],
  "ernte-dev":     [0, 0, 1.5, 2.1, 0, 0, 0, 0, 3.2, 4.1, 0, 0, 2.5, 1.8],
};

window.ERNTE_DATA = { CLIENTS, PROJECTS, TODAY_ENTRIES, INVOICES, TASKS_BY_PROJECT, WEEK_HOURS, SPARKS };
