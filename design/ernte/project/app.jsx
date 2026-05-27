const { useState, useEffect, useRef, useMemo } = React;
const { CLIENTS, PROJECTS, TODAY_ENTRIES, INVOICES, TASKS_BY_PROJECT, WEEK_HOURS, SPARKS } = window.ERNTE_DATA;

const clientById = (id) => CLIENTS.find(c => c.id === id) || { name: "—", short: "··" };
const projectById = (id) => PROJECTS.find(p => p.id === id) || { name: "—" };

// ─────── helpers ───────
const fmtHM = (sec) => {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = Math.floor(sec % 60);
  return `${String(h).padStart(2,"0")}:${String(m).padStart(2,"0")}:${String(s).padStart(2,"0")}`;
};
const fmtHours = (h) => h.toFixed(1) + "h";
const fmtMoney = (v) => "€" + v.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtMoneyShort = (v) => "€" + Math.round(v).toLocaleString("en-US");

function pctOf(spent, budget) {
  if (!budget) return 0;
  return Math.round((spent / budget) * 100);
}
function budgetBand(pct) {
  if (pct > 100) return "over";
  if (pct >= 85) return "warn";
  return "ok";
}

// ─────── Sparkline ───────
function Spark({ data, w = 90, h = 22, color }) {
  const max = Math.max(...data, 1);
  const stepX = w / (data.length - 1);
  const pts = data.map((v, i) => [i * stepX, h - (v / max) * (h - 2) - 1]);
  const path = pts.map((p, i) => `${i === 0 ? "M" : "L"}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(" ");
  const area = `M0,${h} ` + pts.map(p => `L${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(" ") + ` L${w},${h} Z`;
  return (
    <svg className="spark" viewBox={`0 0 ${w} ${h}`} width={w} height={h} preserveAspectRatio="none">
      <path className="area" d={area} style={color ? { fill: `color-mix(in oklch, ${color} 14%, transparent)` } : undefined}/>
      <path d={path} style={color ? { stroke: color } : undefined}/>
    </svg>
  );
}

// ─────── Budget Bar ───────
function BudgetCell({ spent, budget, unit = "h" }) {
  const pct = pctOf(spent, budget);
  const band = budgetBand(pct);
  const width = Math.min(100, pct);
  return (
    <div className="budget-cell">
      <div className="label">
        <span>
          {unit === "h" ? fmtHours(spent) : fmtMoneyShort(spent)}
          <span className="ascii-dot">/</span>
          <span className="muted">{unit === "h" ? fmtHours(budget) : fmtMoneyShort(budget)}</span>
        </span>
        <span className={`pct ${band === "over" ? "over" : band === "warn" ? "warn" : ""}`}>{pct}%</span>
      </div>
      <div className="budget-bar">
        <div className={`budget-fill ${band === "over" ? "over" : band === "warn" ? "warn" : ""}`} style={{ width: `${width}%` }}/>
        {band === "over" && (
          <div className="budget-fill over" style={{ width: `${Math.min(100, pct - 100)}%`, left: 0, opacity: 0.4 }}/>
        )}
      </div>
    </div>
  );
}

// ─────── App Shell ───────
function App() {
  const [route, setRoute] = useState({ view: "dashboard" });
  const [now, setNow] = useState(Date.now());

  // running timer state
  const [running, setRunning] = useState({
    project: "atlas-fleet",
    task: "Telemetry side-panel",
    seconds: 2543,
  });

  useEffect(() => {
    const t = setInterval(() => {
      setNow(Date.now());
      setRunning(r => r ? { ...r, seconds: r.seconds + 1 } : r);
    }, 1000);
    return () => clearInterval(t);
  }, []);

  // Tweaks
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
  useEffect(() => {
    document.documentElement.setAttribute("data-theme", t.theme);
    document.documentElement.setAttribute("data-density", t.density);
    document.documentElement.style.setProperty("--accent", t.accent || "#2d4a3a");
  }, [t.theme, t.density, t.accent]);

  return (
    <div className="app">
      <Topbar running={running} setRoute={setRoute}/>
      <Sidebar route={route} setRoute={setRoute}/>
      <main className="content">
        {route.view === "dashboard" && <Dashboard setRoute={setRoute}/>}
        {route.view === "project" && <ProjectDetail id={route.id} setRoute={setRoute}/>}
        {route.view === "timer" && <TimerView running={running} setRunning={setRunning}/>}
        {route.view === "clients" && <ClientsView setRoute={setRoute}/>}
        {route.view === "invoices" && <InvoicesView setRoute={setRoute}/>}
        {route.view === "invoice" && <InvoiceDetail id={route.id} setRoute={setRoute}/>}
        {route.view === "reports" && <ReportsView/>}
      </main>
      <Statusbar/>
      <TweaksPanel title="Tweaks">
        <TweakSection label="Theme"/>
        <TweakRadio
          label="Mode"
          value={t.theme}
          options={["paper", "dark"]}
          onChange={(v) => setTweak("theme", v)}
        />
        <TweakRadio
          label="Density"
          value={t.density}
          options={["comfortable", "compact"]}
          onChange={(v) => setTweak("density", v)}
        />
        <TweakSection label="Accent"/>
        <TweakColor
          label="Color"
          value={t.accent}
          options={["#2d4a3a", "#c97b3c", "#1a1a1a", "#b8941f"]}
          onChange={(v) => setTweak("accent", v)}
        />
      </TweaksPanel>
    </div>
  );
}

const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "theme": "paper",
  "density": "comfortable",
  "accent": "#2d4a3a"
}/*EDITMODE-END*/;

// ─────── Topbar ───────
function Topbar({ running, setRoute }) {
  const proj = projectById(running.project);
  return (
    <header className="topbar">
      <div className="wordmark" onClick={() => setRoute({ view: "dashboard" })} style={{ cursor: "pointer" }}>
        <span className="wordmark-mark"/>
        <span>ernte</span>
      </div>
      <div className="mono-tag" title="Workspace">workspace: julian@home</div>
      <div className="topbar-spacer"/>
      <button className="cmdk" title="Command palette">
        <span style={{ color: "var(--ink-4)" }}>›</span>
        <span style={{ flex: 1, textAlign: "left" }}>Jump to project, client, invoice…</span>
        <span className="kbd">⌘K</span>
      </button>
      <div className="topbar-spacer"/>
      <button
        className="running-timer"
        onClick={() => setRoute({ view: "timer" })}
        title="Open timer"
      >
        <span className="pulse"/>
        <span style={{ opacity: 0.8, fontSize: "var(--fs-xs)" }}>{proj.name}</span>
        <span style={{ fontWeight: 700 }}>{fmtHM(running.seconds)}</span>
        <span className="timer-stop" title="Stop"/>
      </button>
      <div className="user-chip">
        <span className="avatar">JK</span>
        <span>julian</span>
      </div>
    </header>
  );
}

// ─────── Sidebar ───────
function Sidebar({ route, setRoute }) {
  const NAV = [
    { id: "dashboard", label: "Projects",  glyph: "▤", count: "8" },
    { id: "timer",     label: "Timer",     glyph: "◐", count: "6.4h" },
    { id: "clients",   label: "Clients",   glyph: "◇", count: "6" },
    { id: "invoices",  label: "Invoices",  glyph: "≡", count: "2" },
    { id: "reports",   label: "Reports",   glyph: "△", count: null },
  ];
  const pinned = PROJECTS.slice(0, 4);
  return (
    <aside className="sidebar">
      <div>
        {NAV.map(n => (
          <div
            key={n.id}
            className="nav-item"
            aria-current={route.view === n.id ? "page" : undefined}
            onClick={() => setRoute({ view: n.id })}
          >
            <span className="glyph">{n.glyph}</span>
            <span>{n.label}</span>
            {n.count && <span className="count">{n.count}</span>}
          </div>
        ))}
      </div>

      <div className="side-section">Pinned</div>
      <div>
        {pinned.map((p, i) => (
          <div
            key={p.id}
            className="pin-row"
            onClick={() => setRoute({ view: "project", id: p.id })}
            style={{ color: i === 0 ? "var(--ink)" : "var(--ink-2)" }}
          >
            <span className={`pin-dot ${i < 2 ? "solid" : ""}`} style={{ color: ["var(--forest)", "var(--rust)", "var(--ink)", "var(--gold)"][i] }}/>
            <span style={{ flex: 1, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{p.name}</span>
          </div>
        ))}
      </div>

      <div className="side-section">Recent</div>
      <div>
        <div className="pin-row muted" style={{ fontSize: "var(--fs-xs)" }}>Atlas Robotics</div>
        <div className="pin-row muted" style={{ fontSize: "var(--fs-xs)" }}>Inv #2026-014</div>
        <div className="pin-row muted" style={{ fontSize: "var(--fs-xs)" }}>Körbel & Söhne</div>
      </div>

      <div style={{ flex: 1 }}/>
      <div style={{ padding: "12px 14px", borderTop: "1px solid var(--border)", marginTop: 8 }}>
        <div style={{ fontSize: "var(--fs-xs)", color: "var(--ink-4)", letterSpacing: ".06em", textTransform: "uppercase", marginBottom: 8 }}>This week</div>
        <div style={{ fontSize: "var(--fs-lg)", fontWeight: 700, color: "var(--ink)", letterSpacing: "-0.02em" }}>
          38.9<span style={{ fontSize: "var(--fs-sm)", color: "var(--ink-3)", fontWeight: 400, marginLeft: 2 }}>h</span>
          <span style={{ color: "var(--ink-4)", fontWeight: 400, fontSize: "var(--fs-sm)", marginLeft: 6 }}>/ 40h</span>
        </div>
        <div style={{ display: "flex", gap: 3, marginTop: 8, alignItems: "flex-end", height: 28 }}>
          {WEEK_HOURS.map((h, i) => (
            <div
              key={i}
              title={`${["M","T","W","T","F","S","S"][i]}: ${h}h`}
              style={{
                flex: 1,
                height: `${Math.max(2, (h / 10) * 100)}%`,
                background: i === 4 ? "var(--accent)" : h === 0 ? "var(--bg-3)" : "var(--ink-3)",
                opacity: i === 5 || i === 6 ? 0.5 : 1,
              }}
            />
          ))}
        </div>
        <div style={{ display: "flex", justifyContent: "space-between", fontSize: 9, color: "var(--ink-4)", marginTop: 4, letterSpacing: ".05em" }}>
          <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
        </div>
      </div>
    </aside>
  );
}

// ─────── Statusbar ───────
function Statusbar() {
  return (
    <footer className="statusbar">
      <span><span className="dot"/>connected</span>
      <span className="sep">│</span>
      <span>localhost<span className="muted">:7878</span></span>
      <span className="sep">│</span>
      <span>v0.4.2 <span className="muted">(self-hosted)</span></span>
      <span className="sep">│</span>
      <span>db <span className="muted">postgres 16.2 · 142mb</span></span>
      <span className="sep">│</span>
      <span>backup <span className="muted">2h ago</span></span>
      <span className="spacer"/>
      <span className="link">docs</span>
      <span className="sep">│</span>
      <span className="link">changelog</span>
      <span className="sep">│</span>
      <span className="muted">uptime 14d 03h</span>
    </footer>
  );
}
