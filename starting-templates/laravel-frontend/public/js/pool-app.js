/**
 * pool-app.js — Second Game Pool, vanilla JS + IndexedDB port of the
 * original React artifact. No framework/build step required; drop this
 * (plus idb.js, api-sync.js, pool.css) into your Laravel `public/` and
 * point resources/views/pool/index.blade.php at them.
 *
 * State lives in memory, backed by IndexedDB (idb.js) as the local source
 * of truth. Every mutation writes to IndexedDB first (instant, offline-safe)
 * then enqueues a background sync job (api-sync.js) to push it to Laravel.
 */

/* ============================== DOMAIN LOGIC ============================== */
// (Same rules as the spreadsheet / React version — see PoolCalculator on the
// backend, which must match this exactly. Keep both in sync if you change one.)

const DEFAULT_CONFIG = { seasonLabel: "Thursday Night Mixed — 2nd Game Pool", entryFee: 1, startWeek: 1, totalWeeks: 33 };

// League bowls Thursdays. Week 3 = Sep 3, 2026, every Thursday after, skipping
// Nov 26 (Thanksgiving), Dec 24 (Christmas Eve), Dec 31 (New Year's Eve).
// Weeks outside 3–34 have no date — callers fall back to just "Week N".
const WEEK_DATES = {
  3: "Sep 3", 4: "Sep 10", 5: "Sep 17", 6: "Sep 24",
  7: "Oct 1", 8: "Oct 8", 9: "Oct 15", 10: "Oct 22",
  11: "Oct 29", 12: "Nov 5", 13: "Nov 12", 14: "Nov 19",
  15: "Dec 3", 16: "Dec 10", 17: "Dec 17",
  18: "Jan 7", 19: "Jan 14", 20: "Jan 21", 21: "Jan 28",
  22: "Feb 4", 23: "Feb 11", 24: "Feb 18", 25: "Feb 25",
  26: "Mar 4", 27: "Mar 11", 28: "Mar 18", 29: "Mar 25",
  30: "Apr 1", 31: "Apr 8", 32: "Apr 15", 33: "Apr 22",
  34: "Apr 29",
};

function weekDate(week) {
  return WEEK_DATES[week] || "";
}

// "Week 3 · Sep 3" for headings; "Week 3" when there's no date for that week.
function weekHeading(week) {
  const d = weekDate(week);
  return d ? `Week ${week} · ${d}` : `Week ${week}`;
}

function weeksArray(config) {
  let start = Math.trunc(Number(config && config.startWeek));
  let total = Math.trunc(Number(config && config.totalWeeks));
  if (!Number.isFinite(start)) start = DEFAULT_CONFIG.startWeek;
  if (!Number.isFinite(total) || total < 1) total = DEFAULT_CONFIG.totalWeeks;
  const arr = [];
  for (let i = 0; i < total; i++) arr.push(start + i);
  return arr;
}

// A fully-formed, empty week stat — used whenever there's no weekly_results
// row for a week yet, or the requested week falls outside the season range.
function blankStat(week) {
  return { week, count: 0, pot: 0, payout: 0, carry: 0, winnerId: null, score: "", note: "" };
}

function entryKey(playerId, week) {
  return `${playerId}:${week}`;
}

function getEntry(state, playerId, week) {
  return state.entries.get(entryKey(playerId, week)) || null;
}

// counts only real $1-in-the-pot entries (paid or covered) — exempt entries
// are a record-keeping note only and must NOT inflate the pot.
function entryCount(state, week) {
  let n = 0;
  for (const e of state.entries.values()) {
    if (e.week === week && (e.status === "paid" || e.status === "covered")) n++;
  }
  return n;
}

function computeStats(state) {
  const weeks = weeksArray(state.config);
  const stats = {};
  let prevCarry = 0;
  for (const w of weeks) {
    const count = entryCount(state, w);
    const pot = prevCarry + count;
    const r = state.results.get(w) || state.results.get(String(w));
    const payout = r && r.winner_player_id ? (r.payout ?? pot) : 0;
    const carry = pot - payout;
    stats[w] = {
      ...blankStat(w),
      count, pot, payout, carry,
      winnerId: r?.winner_player_id || null,
      score: r?.score ?? "",
      note: r?.note || "",
    };
    prevCarry = carry;
  }
  return { weeks, stats };
}

function lastWinnerWeekBefore(stats, weeks, week) {
  let last = null;
  for (const w of weeks) {
    if (w >= week) break;
    if (stats[w].winnerId) last = w;
  }
  return last;
}

function weeksOwed(state, playerId, weeks, uptoWeek, lastWinnerWeek) {
  const floor = lastWinnerWeek || weeks[0] - 1;
  return weeks.filter((w) => w > floor && w <= uptoWeek && !getEntry(state, playerId, w));
}

function playerTotal(state, playerId, weeks) {
  let sum = 0;
  for (const w of weeks) {
    const e = getEntry(state, playerId, w);
    if (e && e.status === "paid" && typeof e.amount === "number") sum += e.amount;
  }
  return sum;
}

// Per-player standing for the current week. Mirrors the backend's
// PoolCalculator::weeksOwed / lastWinnerWeekBefore (there is no per-player
// stat object — the dashboard is offline and computes this locally).
//   owed        - list of week numbers still owed, floor..uptoWeek
//   owesAmount  - owed.length * entry fee
//   paid        - owed.length === 0
//   paidThru    - last week in an unbroken run of entries from the floor
//                 (can be > uptoWeek if they've paid ahead), or null
//   currentStatus - this week's entry status (paid|covered|exempt|null)
function playerStanding(state, playerId, weeks, uptoWeek, lastWinnerWeek, entryFee) {
  const owed = weeksOwed(state, playerId, weeks, uptoWeek, lastWinnerWeek);
  const floor = lastWinnerWeek || weeks[0] - 1;
  const lastWeek = weeks[weeks.length - 1];
  let thru = floor;
  for (let w = floor + 1; w <= lastWeek; w++) {
    if (getEntry(state, playerId, w)) thru = w;
    else break;
  }
  return {
    owed,
    owesAmount: owed.length * (Number(entryFee) || 1),
    paid: owed.length === 0,
    paidThru: thru > floor ? thru : null,
    currentStatus: getEntry(state, playerId, uptoWeek)?.status || null,
  };
}

// Sort key for the "TEAMS" grouping: numeric team number first (blank/non-
// numeric sink to the end), then the raw string (so "1A" beats "1B").
function teamSortKey(p) {
  const s = String(p.team_number ?? "").trim();
  const n = s === "" ? Infinity : (parseInt(s, 10) || Infinity);
  return { n, s };
}

function fmtMoney(n) {
  return `$${(Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 0 })}`;
}

// "#12 · Team Name" — either part is optional; falls back to "—".
function teamLabel(p) {
  const parts = [];
  if (p.team_number !== null && p.team_number !== undefined && String(p.team_number).trim() !== "") {
    parts.push(`#${String(p.team_number).trim()}`);
  }
  if (p.team && p.team !== "—") parts.push(p.team);
  return parts.join(" · ") || "—";
}

function uuid() {
  return crypto.randomUUID ? crypto.randomUUID() : "id-" + Math.random().toString(36).slice(2, 12);
}

function escapeHtml(s) {
  return String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

/* ============================== EMBEDDED ROSTER SEED ============================== */
// Same dataset extracted from last season's spreadsheet — lets the operator
// import the whole roster instead of retyping ~100 names.
const ROSTER_SEED = [{"name": "Aponte, Juan", "team": "Team 13", "active": false}, {"name": "Austile, Darian", "team": "Terminators", "active": true}, {"name": "Austile, Jr", "team": "Team 12", "active": true}, {"name": "Barber, Jb", "team": "Team 13", "active": false}, {"name": "Barber, Tj", "team": "Unbalanced", "active": true}, {"name": "Bell, Bob", "team": "Team 4", "active": true}, {"name": "Bell, Ingrid", "team": "Team 4", "active": true}, {"name": "Bibby, Loretta", "team": "Team 21", "active": true}, {"name": "Brogdon, Denise", "team": "Team 6", "active": true}, {"name": "Cambell, John", "team": "Too Much Fun Crew", "active": true}, {"name": "Christie, Cindy", "team": "Team 21", "active": true}, {"name": "Christie, Hugh", "team": "Team 21", "active": true}, {"name": "Collins, Billy", "team": "Team Spirit", "active": true}, {"name": "Crenshaw, Dion Sr.", "team": "Crenshaw Moving", "active": true}, {"name": "Crenshaw, Rebecca", "team": "Crenshaw Moving", "active": true}, {"name": "Criswell, Doug", "team": "Shots & Strikes", "active": true}, {"name": "Dailey, Geraldine", "team": "Jacob's Ladder", "active": false}, {"name": "Davis, Clayton", "team": "Team 17", "active": true}, {"name": "Davis, Gregory", "team": "Terminators", "active": false}, {"name": "Davis, Kaitlyn", "team": "Team 17", "active": true}, {"name": "Davis, Sylvester", "team": "Team 3", "active": false}, {"name": "Delk, Robbie", "team": "Team 10", "active": false}, {"name": "Desautels, Mikey", "team": "Team 10", "active": false}, {"name": "Edmund, Deshawn", "team": "Team 3", "active": false}, {"name": "Foyt, Paul", "team": "Outta Pocket", "active": false}, {"name": "Foyt, Zachary", "team": "Outta Pocket", "active": false}, {"name": "French, Jeff", "team": "Team 17", "active": false}, {"name": "Fukes, Kim", "team": "Team 17", "active": false}, {"name": "Gaskins, Justin", "team": "Team Spirit", "active": false}, {"name": "Gaudry, Ashley *", "team": "Team 7", "active": true}, {"name": "Goodwin, David", "team": "Team 3", "active": false}, {"name": "Gutierrez, Jessica", "team": "Too Much Fun Crew", "active": false}, {"name": "Gutierrez, Ken", "team": "Too Much Fun Crew", "active": true}, {"name": "Hamel, John", "team": "Substitute", "active": true}, {"name": "Hamlett, Ace", "team": "Team Spirit", "active": true}, {"name": "Hamlett, Ham", "team": "Team Spirit", "active": true}, {"name": "Harmon, Mandy", "team": "Team 12", "active": true}, {"name": "Harrison, Annie", "team": "Team 11", "active": false}, {"name": "Hartley, Bob", "team": "Team 15", "active": true}, {"name": "Hartley, Lauri", "team": "Team 15", "active": false}, {"name": "Henson, Alyssa", "team": "Team 15", "active": false}, {"name": "Jackson, Buford", "team": "Team 2", "active": false}, {"name": "Jacobs, Daniel", "team": "Jacob's Ladder", "active": false}, {"name": "Jacobs, Glenn", "team": "Jacob's Ladder", "active": false}, {"name": "Johnson, Bill", "team": "Team 10", "active": false}, {"name": "Johnson, Colleen *", "team": "Team 4", "active": true}, {"name": "Jones, Chareyes", "team": "Terminators", "active": true}, {"name": "Jones, Daryl *", "team": "Unbalanced", "active": true}, {"name": "Jones, Debra *", "team": "Unbalanced", "active": true}, {"name": "Kellogg-Yocom, Cassandra", "team": "Shots & Strikes", "active": false}, {"name": "Kemp, Bob", "team": "Team 2", "active": false}, {"name": "Lewis, John Sr", "team": "Crenshaw Moving", "active": true}, {"name": "Ludlow, Chris", "team": "Team 6", "active": false}, {"name": "Macklin, Charles Sr", "team": "Team 2", "active": false}, {"name": "Major, Beatrice", "team": "Team 3", "active": true}, {"name": "Mann, Anthony", "team": "Crenshaw Moving", "active": false}, {"name": "Martin, Chris", "team": "Shots & Strikes", "active": true}, {"name": "Mcmullen, James Iii", "team": "Jacob's Ladder", "active": false}, {"name": "Midgett, Nik", "team": "Outta Pocket", "active": false}, {"name": "Mollay, Bernice", "team": "Team 2", "active": true}, {"name": "Myers, Barbara", "team": "Outta Pocket", "active": true}, {"name": "Nelson, Jennifer", "team": "Team 13", "active": false}, {"name": "Nelson, Kirk", "team": "Team 13", "active": false}, {"name": "Oelkers, Julie", "team": "Team 12", "active": false}, {"name": "Oelkers, Rick Iii", "team": "Team 12", "active": false}, {"name": "Padberg, Sue", "team": "Shots & Strikes", "active": false}, {"name": "Phillips, Forris", "team": "Team 7", "active": true}, {"name": "Pichardo, Jonathan", "team": "Team 7", "active": false}, {"name": "Pietrafesa, Chris", "team": "Too Much Fun Crew", "active": false}, {"name": "Ranger, Frank", "team": "Team 11", "active": false}, {"name": "Renfro, John", "team": "Jacob's Ladder", "active": false}, {"name": "Rhodes, Candi", "team": "Rhodes Clan", "active": true}, {"name": "Rhodes, Nick", "team": "Rhodes Clan", "active": true}, {"name": "Rhodes, Reese", "team": "Rhodes Clan", "active": true}, {"name": "Rhodes, Will", "team": "Rhodes Clan", "active": true}, {"name": "Riddle, Dawn", "team": "Team 6", "active": false}, {"name": "Riley, Wayne", "team": "Team 6", "active": true}, {"name": "Rumph, Cc", "team": "Team 21", "active": true}, {"name": "Rumph, Christine", "team": "Team 7", "active": true}, {"name": "Rumph, Dalton", "team": "Unbalanced", "active": false}, {"name": "Rumph, Hayley", "team": "Unbalanced", "active": false}, {"name": "Rumph, Kevin Sr.", "team": "Team 11", "active": true}, {"name": "Schweitzer, Keith", "team": "Unbalanced", "active": false}, {"name": "Skapetis, Carl", "team": "Terminators", "active": true}, {"name": "Stacks, John", "team": "Crenshaw Moving", "active": false}, {"name": "Sternberg, Eric", "team": "Team 4", "active": true}, {"name": "Stevens, Cody", "team": "Team 11", "active": false}, {"name": "Stokes, Joe", "team": "Team 2", "active": true}, {"name": "Taylor, Faith", "team": "Team 15", "active": true}, {"name": "Thomas, Bruce", "team": "Team 10", "active": false}, {"name": "Thomas, Calvin", "team": "Team 21", "active": false}, {"name": "Usic, Alex", "team": "Unbalanced", "active": false}, {"name": "Vancleave, Charles", "team": "Team 9", "active": false}, {"name": "Vancleave, Shanna", "team": "Team 9", "active": false}, {"name": "Vancleave, Timothy", "team": "Team 9", "active": false}, {"name": "Vercelletto, John *", "team": "Team 4", "active": false}, {"name": "Vu, Hai", "team": "Team 3", "active": false}, {"name": "Walker, Brenda", "team": "Jacob's Ladder", "active": false}, {"name": "Wetherington, Miquel", "team": "Too Much Fun Crew", "active": false}, {"name": "Whitmer, Cynthia", "team": "Team 7", "active": true}, {"name": "Wright, Anthony", "team": "Team 9", "active": false}];

/* ============================== STATE ============================== */
const state = {
  config: { ...DEFAULT_CONFIG },
  players: [],              // [{id, name, team, active}]
  entries: new Map(),       // key `${player_id}:${week}` -> {id?, player_id, week, amount, status, note}
  results: new Map(),       // week -> {week, score, winner_player_id, payout, note}
  tab: "dashboard",
  week: null,
  openPlayerId: null,
  modal: null,
  pending: 0,
  syncStatus: "synced",     // 'synced' | 'syncing' | 'offline' | 'pending'
};

async function loadState() {
  const [cfgRow, players, entries, results] = await Promise.all([
    idb.get("config", "main"),
    idb.getAll("players"),
    idb.getAll("entries"),
    idb.getAll("results"),
  ]);
  if (cfgRow) {
    const startWeek = Math.trunc(Number(cfgRow.startWeek));
    const totalWeeks = Math.trunc(Number(cfgRow.totalWeeks));
    const entryFee = Number(cfgRow.entryFee);
    state.config = {
      seasonLabel: cfgRow.seasonLabel || DEFAULT_CONFIG.seasonLabel,
      entryFee: Number.isFinite(entryFee) ? entryFee : DEFAULT_CONFIG.entryFee,
      startWeek: Number.isFinite(startWeek) ? startWeek : DEFAULT_CONFIG.startWeek,
      totalWeeks: Number.isFinite(totalWeeks) && totalWeeks >= 1 ? totalWeeks : DEFAULT_CONFIG.totalWeeks,
    };
  }
  state.players = players || [];
  state.entries = new Map((entries || []).map((e) => [entryKey(e.player_id, e.week), e]));
  state.results = new Map((results || []).map((r) => [r.week, r]));
  state.pending = await Sync.pendingCount();
}

function pickDefaultWeek() {
  const { weeks, stats } = computeStats(state);
  if (!weeks.length) return DEFAULT_CONFIG.startWeek;
  let target = weeks[0];
  for (const w of weeks) if (stats[w] && (stats[w].count > 0 || stats[w].winnerId)) target = w;
  return target;
}

// state.week must always be a real week in the current season range, or the
// dashboard/draw lookups get an undefined stat. Snap it back if it drifts.
function currentWeek() {
  const weeks = weeksArray(state.config);
  if (weeks.includes(state.week)) return state.week;
  const guess = pickDefaultWeek();
  state.week = weeks.includes(guess) ? guess : (weeks[0] ?? DEFAULT_CONFIG.startWeek);
  return state.week;
}

/* ============================== MUTATIONS ============================== */
async function saveConfig(next) {
  state.config = next;
  await idb.put("config", { key: "main", ...next });
  Sync.enqueue({ type: "config.update", payload: next });
  render();
}

async function addPlayer(name, teamNumber, team) {
  const p = { id: uuid(), name, team_number: teamNumber || null, team: team || "—", active: true };
  state.players.push(p);
  await idb.put("players", p);
  Sync.enqueue({ type: "player.create", payload: p });
  render();
}

async function setActive(id, active) {
  const p = state.players.find((x) => x.id === id);
  if (!p) return;
  p.active = active;
  await idb.put("players", p);
  Sync.enqueue({ type: "player.update", payload: { id, active } });
  render();
}

async function updatePlayer(id, patch) {
  const p = state.players.find((x) => x.id === id);
  if (!p) return;
  Object.assign(p, patch);
  await idb.put("players", p);
  Sync.enqueue({ type: "player.update", payload: { id, ...patch } });
}

// Set the team NAME on every player who shares this team number.
async function renameTeam(teamNumber, team) {
  const tn = String(teamNumber);
  for (const p of state.players) {
    if (String(p.team_number ?? "") === tn) {
      p.team = team;
      await idb.put("players", p);
    }
  }
  Sync.enqueue({ type: "team.rename", payload: { team_number: teamNumber, team } });
}

async function saveEditPlayer(id, { name, teamNumber, team }) {
  const p = state.players.find((x) => x.id === id);
  if (!p) return;
  const tn = String(teamNumber ?? "").trim();
  const prevTeamName = p.team && p.team !== "—" ? p.team : "";
  const teamNameChanged = team.trim() !== prevTeamName;
  const others = tn
    ? state.players.filter((x) => x.id !== id && String(x.team_number ?? "").trim() === tn)
    : [];

  await updatePlayer(id, {
    name: name.trim() || p.name,
    team_number: tn || null,
    team: team.trim() || "—",
  });

  if (teamNameChanged && team.trim() && others.length &&
      confirm(`${others.length} other player${others.length === 1 ? " is" : "s are"} on Team #${tn}. Update their team name to "${team.trim()}" too?`)) {
    await renameTeam(tn, team.trim());
  }

  closeModal();
  render();
}

async function importPlayersFromFile(file) {
  if (!file) return;
  state._importMsg = { text: "Importing…" };
  render();
  try {
    const res = await Sync.uploadPlayerImport(file);
    // The server merges by name + team_number: `players` holds every row it
    // added, updated, or deactivated — all keep/keyed by their real id.
    const incoming = res.players || [];
    if (incoming.length) {
      await idb.putMany("players", incoming);
      const byId = new Map(state.players.map((p) => [p.id, p]));
      incoming.forEach((p) => byId.set(p.id, p));
      state.players = [...byId.values()];
    }
    await reconcileOrphanEntries();
    const bits = [`${res.imported || 0} added`];
    if (res.updated) bits.push(`${res.updated} updated`);
    if (res.deactivated) bits.push(`${res.deactivated} deactivated`);
    if (res.skipped) bits.push(`${res.skipped} skipped`);
    state._importMsg = { ok: true, text: bits.join(", ") };
  } catch (e) {
    state._importMsg = { ok: false, text: e.message || "Import failed" };
  }
  render();
}

// Drop any locally-cached entry whose player is no longer in the roster, so
// stale rows can't keep inflating the pot after a roster change.
async function reconcileOrphanEntries() {
  const known = new Set(state.players.map((p) => p.id));
  const stale = [...state.entries.values()].filter((e) => !known.has(e.player_id));
  for (const e of stale) {
    state.entries.delete(entryKey(e.player_id, e.week));
    await idb.delete("entries", entryKey(e.player_id, e.week));
  }
}

async function importRoster() {
  const newPlayers = ROSTER_SEED.map((p) => ({ id: uuid(), ...p }));
  state.players = newPlayers;
  await idb.putMany("players", newPlayers);
  newPlayers.forEach((p) => Sync.enqueue({ type: "player.create", payload: p }));
  render();
}

async function setEntry(playerId, week, { amount, status, note }) {
  const rec = { id: entryKey(playerId, week), player_id: playerId, week, amount: amount ?? null, status, note: note || "" };
  state.entries.set(entryKey(playerId, week), rec);
  await idb.put("entries", rec);
  Sync.enqueue({ type: "entry.upsert", payload: { player_id: playerId, week, amount: rec.amount, status, note: rec.note } });
}

async function clearEntry(playerId, week) {
  state.entries.delete(entryKey(playerId, week));
  await idb.delete("entries", entryKey(playerId, week));
  Sync.enqueue({ type: "entry.delete", payload: { player_id: playerId, week } });
}

async function saveResult(week, payload) {
  const rec = { week, ...payload };
  state.results.set(week, rec);
  await idb.put("results", rec);
  Sync.enqueue({ type: "result.upsert", payload: rec });
  render();
}

/* ============================== RENDER HELPERS ============================== */
const $app = () => document.getElementById("app-view");
const $modalRoot = () => document.getElementById("modal-root");

function statusPill(status, amount) {
  if (!status) return `<span class="pill pill-owe">OWES</span>`;
  if (status === "covered") return `<span class="pill pill-paid">PAID</span>`;
  if (status === "exempt") return `<span class="pill pill-exempt">EXEMPT</span>`;
  return `<span class="pill pill-paid">PAID ${fmtMoney(amount)}</span>`;
}

// The right-hand tag on a This Week player card, driven by playerStanding().
function standingPill(st) {
  if (!st.paid) {
    // Only show a dollar figure once they're behind more than the current
    // week — a lone $1 isn't worth the clutter.
    const amt = st.owed.length > 1 ? ` ${fmtMoney(st.owesAmount)}` : "";
    return `<span class="pill pill-owe">OWES${amt}</span>`;
  }
  if (st.currentStatus === "exempt") return `<span class="pill pill-exempt">EXEMPT</span>`;
  const thru = st.paidThru ? `<span class="pill-thru">thru Wk ${st.paidThru}</span>` : "";
  return `<span class="pill-stack"><span class="pill pill-paid">PAID</span>${thru}</span>`;
}

/* ============================== DASHBOARD ============================== */
function renderDashboard() {
  const { weeks, stats } = computeStats(state);
  const week = currentWeek();
  const stat = stats[week] || blankStat(week);
  const query = state._query || "";
  const filterMode = state._dashFilter || "all";   // all | owes | paid
  const sortMode = state._dashSort || "alpha";      // alpha | teams
  const lastWinner = lastWinnerWeekBefore(stats, weeks, week);
  const entryFee = state.config.entryFee;

  const active = state.players.filter((p) => p.active !== false);
  const standings = active.map((p) => ({ p, st: playerStanding(state, p.id, weeks, week, lastWinner, entryFee) }));
  const oweCount = standings.filter(({ st }) => !st.paid).length;
  const paidCount = standings.length - oweCount;

  const rows = standings
    .filter(({ p }) => p.name.toLowerCase().includes(query.toLowerCase()))
    .filter(({ st }) => filterMode === "owes" ? !st.paid : filterMode === "paid" ? st.paid : true);

  rows.sort((a, b) => {
    if (sortMode === "teams") {
      const ka = teamSortKey(a.p), kb = teamSortKey(b.p);
      if (ka.n !== kb.n) return ka.n - kb.n;
      if (ka.s !== kb.s) return ka.s.localeCompare(kb.s);
    }
    return a.p.name.localeCompare(b.p.name);
  });

  const filterChip = (val, label, count) =>
    `<button class="chip ${filterMode === val ? "chip-active" : ""}" data-action="dash-filter" data-filter="${val}">${label} <span class="chip-count">${count}</span></button>`;
  const sortChip = (val, label) =>
    `<button class="chip ${sortMode === val ? "chip-active" : ""}" data-action="dash-sort" data-sort="${val}">${label}</button>`;

  let lastTeam = null;
  const listBody = rows.map(({ p, st }) => {
    let header = "";
    if (sortMode === "teams") {
      const key = teamLabel(p);
      if (key !== lastTeam) { header = `<div class="list-group-label">${escapeHtml(key)}</div>`; lastTeam = key; }
    }
    return `${header}
        <button class="row-card" data-action="open-pay" data-player="${p.id}" data-week="${week}">
          <div>
            <div class="row-title">${escapeHtml(p.name)}</div>
            <div class="dim-sm">${escapeHtml(teamLabel(p))}</div>
          </div>
          ${standingPill(st)}
        </button>`;
  }).join("");

  const winner = stat.winnerId ? state.players.find((p) => p.id === stat.winnerId) : null;

  return `
  <div class="px-4 pt-4 pb-24">
    <div class="week-nav">
      <button class="icon-btn" data-action="week-prev" ${week <= weeks[0] ? "disabled" : ""}>&#8592;</button>
      <div class="week-nav-label">
        <div class="label-xs">Week</div>
        <div class="week-num">${week}</div>
        ${weekDate(week) ? `<div class="dim-sm">${weekDate(week)}</div>` : ""}
      </div>
      <button class="icon-btn" data-action="week-next" ${week >= weeks[weeks.length - 1] ? "disabled" : ""}>&#8594;</button>
    </div>

    <div class="card pot-card">
      <div class="pot-card-top">
        <div>
          <div class="label-xs">This week's pot</div>
          <div class="pot-amount">${fmtMoney(stat.pot)}</div>
          <div class="dim-sm">${stat.count} paid this week${stat.pot - stat.count > 0 ? ` · ${fmtMoney(stat.pot - stat.count)} carried over` : ""}</div>
        </div>
        ${winner ? `
          <div class="winner-box">
            <div class="dim-sm">won</div>
            <div class="winner-name">${escapeHtml(winner.name)}</div>
            <div class="dim-sm">${fmtMoney(stat.payout)}</div>
          </div>` : ""}
      </div>
      ${stat.score !== "" ? `<div class="dim-sm mt-2">Score pulled: <b>${escapeHtml(stat.score)}</b></div>` : ""}
      <button class="btn btn-outline w-full mt-3" data-action="open-draw" data-week="${week}">
        ${stat.winnerId ? "Edit score / winner" : "Enter score & winner"}
      </button>
    </div>

    <div class="controls-row mt-3">
      <div class="chip-row">
        ${filterChip("all", "ALL", standings.length)}${filterChip("owes", "OWES", oweCount)}${filterChip("paid", "PAID", paidCount)}
      </div>
      <div class="chip-row">
        ${sortChip("alpha", "ALPHA")}${sortChip("teams", "TEAMS")}
      </div>
    </div>
    <input class="input mt-2" placeholder="Find a name…" value="${escapeHtml(query)}" data-action="search" />

    <div class="list mt-2">
      ${listBody || (active.length === 0
        ? `<div class="empty-note">No roster yet — head to the Roster tab to import last season's names or add players.</div>`
        : `<div class="empty-note">No players match this view.</div>`)}
    </div>
  </div>`;
}

/* ============================== PAY MODAL ============================== */
function modalShell(title, body) {
  return `<div class="modal-header"><h2>${escapeHtml(title)}</h2><button class="icon-btn" data-action="close-modal">&times;</button></div>
    <div class="modal-body">${body}<button class="btn btn-outline w-full mt-3" data-action="close-modal">Close</button></div>`;
}

function renderPayModal(playerId, week) {
  const player = state.players.find((p) => p.id === playerId);
  if (!player) return modalShell("Player not found", `<p class="dim-sm">That player isn't in the roster on this device.</p>`);
  if (!Number.isFinite(week)) week = currentWeek();
  const { weeks, stats } = computeStats(state);
  const existing = getEntry(state, playerId, week);
  const lastWinner = lastWinnerWeekBefore(stats, weeks, week);
  const owed = weeksOwed(state, playerId, weeks, week, lastWinner);
  const hasAnyHistory = weeks.some((w) => getEntry(state, playerId, w));
  const isCatchUp = !hasAnyHistory && owed.length > 1;
  const startWeek = owed.length ? owed[0] : week;

  if (existing) {
    return `
    <div class="modal-header"><h2>${escapeHtml(player.name)}</h2><button class="icon-btn" data-action="close-modal">&times;</button></div>
    <div class="modal-body text-center">
      <div class="dim-sm">${weekHeading(week)} — already recorded</div>
      <div class="big-amount mt-1 mb-3">${existing.status === "covered" ? "Covered (P)" : existing.status === "exempt" ? "Exempt" : fmtMoney(existing.amount)}</div>
      <p class="dim-sm mb-4">To fix a mistake, use the player's week grid on their detail page — it lets you edit any single week directly.</p>
      <button class="btn btn-outline w-full" data-action="close-modal">Close</button>
    </div>`;
  }

  const quickOptions = [...new Set([1, 5, 10, owed.length > 1 ? owed.length : null, weeks.length - weeks.indexOf(startWeek)].filter(Boolean))].sort((a, b) => a - b);
  const defaultN = Math.max(1, owed.length || 1);

  return `
  <div class="modal-header"><h2>${escapeHtml(player.name)}</h2><button class="icon-btn" data-action="close-modal">&times;</button></div>
  <div class="modal-body">
    <div class="dim-sm mb-3">${escapeHtml(teamLabel(player))} · ${weekHeading(week)}</div>
    ${isCatchUp ? `
      <div class="alert-box">
        No entries on record for ${escapeHtml(player.name)}.
        ${lastWinner ? `Last winner was week ${lastWinner}.` : "This is the start of the season."}
        To join the pot fairly, they owe <b>${owed.length}</b> week${owed.length !== 1 ? "s" : ""}
        (weeks ${owed[0]}–${owed[owed.length - 1]}).
      </div>` : ""}

    <label class="label-xs">Weeks to pay for (starting week ${startWeek})</label>
    <div class="chip-row" id="week-chips">
      ${quickOptions.map((n) => `<button class="chip ${n === defaultN ? "chip-active" : ""}" data-action="set-numweeks" data-n="${n}">${n} wk${n !== 1 ? "s" : ""}</button>`).join("")}
      <input type="number" min="1" placeholder="#" class="chip-input" data-action="custom-numweeks" />
    </div>

    <label class="label-xs">Amount (${state.config.entryFee}/wk suggested)</label>
    <input type="number" class="input input-amount" id="pay-amount" value="${defaultN * state.config.entryFee}" />

    <label class="checkbox-row">
      <input type="checkbox" id="pay-exempt" />
      Exempt these weeks (e.g. out sick / COVID — no charge)
    </label>

    <input type="text" class="input" id="pay-note" placeholder="Note (optional)" />

    <div class="dim-sm mt-3 mb-3" id="pay-preview">Will mark weeks <b>${previewWeeks(startWeek, defaultN, weeks, playerId).join(", ")}</b></div>

    <div class="btn-row">
      <button class="btn btn-outline flex-1" data-action="close-modal">Cancel</button>
      <button class="btn btn-green flex-1" data-action="submit-pay"
              data-player="${playerId}" data-week="${week}" data-start="${startWeek}" data-n="${defaultN}">Record</button>
    </div>
  </div>`;
}

function previewWeeks(startWeek, numWeeks, weeks, playerId) {
  const out = [];
  let w = startWeek;
  while (out.length < numWeeks && w <= weeks[weeks.length - 1]) {
    if (!getEntry(state, playerId, w)) out.push(w);
    w++;
  }
  return out;
}

/* ============================== DRAW MODAL ============================== */
function renderDrawModal(week) {
  const { stats } = computeStats(state);
  const stat = stats[week] || blankStat(week);
  const query = state._drawQuery || "";
  const eligible = state.players.filter((p) => p.name.toLowerCase().includes(query.toLowerCase())).slice(0, 30);
  const selected = state._drawWinnerId || stat.winnerId || "";

  return `
  <div class="modal-header"><h2>${weekHeading(week)} — Draw &amp; Winner</h2><button class="icon-btn" data-action="close-modal">&times;</button></div>
  <div class="modal-body">
    <div class="card center mb-4">
      <div class="label-xs">Pot this week</div>
      <div class="pot-amount">${fmtMoney(stat.pot)}</div>
    </div>

    <label class="label-xs">Pulled score</label>
    <input type="text" class="input input-amount" id="draw-score" placeholder="e.g. 187" value="${escapeHtml(stat.score)}" />

    <label class="label-xs">Winner (bowled a matching 2nd game)</label>
    <input type="text" class="input" placeholder="Search name…" value="${escapeHtml(query)}" data-action="draw-search" />
    <div class="scroll-list mb-3">
      ${eligible.map((p) => `
        <button class="pick-row ${selected === p.id ? "pick-row-active" : ""}" data-action="pick-winner" data-id="${p.id}">
          <span>${escapeHtml(p.name)}</span><span class="dim-sm">${escapeHtml(teamLabel(p))}</span>
        </button>`).join("") || `<div class="empty-note">No matches</div>`}
    </div>

    ${selected ? `
      <label class="label-xs">Payout amount</label>
      <input type="number" class="input input-amount" id="draw-payout" value="${stat.winnerId === selected ? stat.payout : stat.pot}" />
    ` : ""}

    <div class="btn-row">
      <button class="btn btn-outline flex-1" data-action="close-modal">Cancel</button>
      <button class="btn btn-red flex-1" data-action="submit-draw-none" data-week="${week}">No winner — roll over</button>
      <button class="btn btn-green flex-1" data-action="submit-draw-winner" data-week="${week}" ${!selected ? "disabled" : ""}>Confirm winner</button>
    </div>
  </div>`;
}

/* ============================== ADD PLAYER MODAL ============================== */
function renderAddPlayerModal() {
  return `
  <div class="modal-header"><h2>Add player</h2><button class="icon-btn" data-action="close-modal">&times;</button></div>
  <div class="modal-body">
    <label class="label-xs">Name (Last, First)</label>
    <input class="input" id="new-player-name" autofocus />
    <label class="label-xs">Team number</label>
    <input class="input" id="new-player-team-number" inputmode="numeric" />
    <label class="label-xs">Team name</label>
    <input class="input" id="new-player-team" />
    <button class="btn btn-green w-full mt-2" data-action="submit-add-player">Add to roster</button>
  </div>`;
}

/* ============================== CELL EDIT MODAL ============================== */
function renderCellModal(playerId, week) {
  const e = getEntry(state, playerId, week);
  return `
  <div class="modal-header"><h2>${weekHeading(week)}</h2><button class="icon-btn" data-action="close-modal">&times;</button></div>
  <div class="modal-body">
    <div class="btn-row mb-3">
      <button class="btn ${e?.status === "covered" ? "btn-green" : "btn-outline"} flex-1" data-action="cell-set" data-player="${playerId}" data-week="${week}" data-status="covered">Mark P (covered)</button>
      <button class="btn ${e?.status === "exempt" ? "btn-green" : "btn-outline"} flex-1" data-action="cell-set" data-player="${playerId}" data-week="${week}" data-status="exempt">Mark exempt</button>
    </div>
    <label class="label-xs">Or set a dollar amount</label>
    <input type="number" class="input input-amount" id="cell-amount" value="${e && e.status === "paid" ? e.amount : ""}" />
    <div class="btn-row mt-3">
      <button class="btn btn-red flex-1" data-action="cell-clear" data-player="${playerId}" data-week="${week}">Clear cell</button>
      <button class="btn btn-green flex-1" data-action="cell-save-amount" data-player="${playerId}" data-week="${week}">Save</button>
    </div>
  </div>`;
}

/* ============================== ROSTER ============================== */
function renderRoster() {
  if (state.openPlayerId) return renderPlayerDetail(state.openPlayerId);

  const query = state._rosterQuery || "";
  const importMsg = state._importMsg
    ? `<div class="import-note ${state._importMsg.ok === false ? "import-note-err" : ""} ${state._importMsg.ok ? "import-note-ok" : ""}">${escapeHtml(state._importMsg.text)}</div>`
    : "";
  const importBtn = `
    <label class="btn btn-outline import-btn">
      Import from Excel (.xlsx)
      <input type="file" id="xlsx-input" accept=".xlsx" hidden />
    </label>`;

  if (state.players.length === 0) {
    return `
    <div class="px-4 pt-8 pb-24 text-center">
      <h2 class="section-title">No roster yet</h2>
      <p class="dim-sm mb-4">Import a roster spreadsheet (name, team number, team), import last season's ${ROSTER_SEED.length} names, or add players by hand.</p>
      ${importMsg}
      <div class="stack-narrow">
        ${importBtn}
        <button class="btn btn-green" data-action="import-roster">Import last season's roster</button>
        <button class="btn btn-outline" data-action="open-add-player">Add a player manually</button>
      </div>
    </div>`;
  }

  const rosterFilter = state._rosterFilter || "all";   // all | active | inactive
  const activeCount = state.players.filter((p) => p.active !== false).length;
  const counts = { all: state.players.length, active: activeCount, inactive: state.players.length - activeCount };
  const rFilterChip = (val, label) =>
    `<button class="chip ${rosterFilter === val ? "chip-active" : ""}" data-action="roster-filter" data-filter="${val}">${label} <span class="chip-count">${counts[val]}</span></button>`;

  const filtered = state.players
    .filter((p) => p.name.toLowerCase().includes(query.toLowerCase()))
    .filter((p) => rosterFilter === "active" ? p.active !== false : rosterFilter === "inactive" ? p.active === false : true)
    .sort((a, b) => a.name.localeCompare(b.name));

  return `
  <div class="px-4 pt-4 pb-24">
    <div class="row-between mb-3">
      <h2 class="section-title">Roster</h2>
      <button class="btn btn-brass" data-action="open-add-player">+ Add player</button>
    </div>
    <div class="mb-3">
      ${importBtn}
      <p class="dim-sm mt-2">Re-importing is safe — players are matched by name &amp; team number, so payments are kept. Anyone not in the file is set inactive.</p>
    </div>
    ${importMsg}
    <div class="chip-row mt-1">
      ${rFilterChip("all", "ALL")}${rFilterChip("active", "ACTIVE")}${rFilterChip("inactive", "INACTIVE")}
    </div>
    <input class="input mb-3" placeholder="Search roster…" value="${escapeHtml(query)}" data-action="roster-search" />
    <div class="list">
      ${filtered.map((p) => `
        <div class="row-card row-card-static ${p.active === false ? "row-dim" : ""}">
          <button class="row-title-btn" data-action="open-player" data-id="${p.id}">
            <div class="row-title">${escapeHtml(p.name)}</div>
            <div class="dim-sm">${escapeHtml(teamLabel(p))}</div>
          </button>
          <button class="icon-btn icon-btn-sm" data-action="open-edit-player" data-id="${p.id}" title="Edit player">&#9998;</button>
          <label class="switch" title="${p.active === false ? "Inactive" : "Active"}">
            <input type="checkbox" data-action="toggle-active" data-id="${p.id}" ${p.active === false ? "" : "checked"} />
            <span class="switch-slider"></span>
          </label>
        </div>`).join("") || `<div class="empty-note">No players match this view.</div>`}
    </div>
  </div>`;
}

/* ============================== EDIT PLAYER MODAL ============================== */
function renderEditPlayerModal(playerId) {
  const p = state.players.find((x) => x.id === playerId);
  if (!p) return modalShell("Player not found", `<p class="dim-sm">That player isn't on the roster on this device.</p>`);
  const teamVal = p.team && p.team !== "—" ? p.team : "";
  return `
  <div class="modal-header"><h2>Edit player</h2><button class="icon-btn" data-action="close-modal">&times;</button></div>
  <div class="modal-body">
    <label class="label-xs">Name (Last, First)</label>
    <input class="input" id="edit-player-name" value="${escapeHtml(p.name)}" />
    <label class="label-xs">Team number</label>
    <input class="input" id="edit-player-team-number" inputmode="numeric" value="${escapeHtml(p.team_number ?? "")}" />
    <label class="label-xs">Team name</label>
    <input class="input" id="edit-player-team" value="${escapeHtml(teamVal)}" />
    <div class="btn-row mt-3">
      <button class="btn btn-outline flex-1" data-action="close-modal">Cancel</button>
      <button class="btn btn-green flex-1" data-action="submit-edit-player" data-id="${p.id}">Save</button>
    </div>
  </div>`;
}

function renderPlayerDetail(playerId) {
  const player = state.players.find((p) => p.id === playerId);
  const { weeks } = computeStats(state);
  const total = playerTotal(state, playerId, weeks);
  return `
  <div class="px-4 pt-4 pb-24">
    <button class="back-link" data-action="close-player">&#8592; Back</button>
    <h2 class="section-title">${escapeHtml(player.name)}</h2>
    <div class="dim-sm mb-3">${escapeHtml(teamLabel(player))}</div>
    <div class="card center mb-4">
      <div class="label-xs">Total paid this season</div>
      <div class="pot-amount">${fmtMoney(total)}</div>
    </div>
    <div class="label-xs mb-2">Week-by-week (tap a cell to correct it)</div>
    <div class="cell-grid">
      ${weeks.map((w) => {
        const e = getEntry(state, playerId, w);
        const cls = e ? (e.status === "paid" ? "cell cell-paid" : "cell") : "cell";
        const label = e ? (e.status === "covered" ? "P" : e.status === "exempt" ? "X" : fmtMoney(e.amount)) : "—";
        return `<button class="${cls}" data-action="open-cell" data-player="${playerId}" data-week="${w}"><div class="cell-week">W${w}</div><div class="cell-val">${label}</div></button>`;
      }).join("")}
    </div>
  </div>`;
}

/* ============================== HISTORY ============================== */
function renderHistory() {
  const { weeks, stats } = computeStats(state);
  const tab = state._historyTab || "weeks";
  return `
  <div class="px-4 pt-4 pb-24">
    <div class="chip-row mb-3">
      <button class="chip ${tab === "weeks" ? "chip-active" : ""}" data-action="history-tab" data-tab="weeks">By week</button>
      <button class="chip ${tab === "totals" ? "chip-active" : ""}" data-action="history-tab" data-tab="totals">Player totals</button>
    </div>
    ${tab === "weeks" ? renderHistoryWeeks(weeks, stats) : renderHistoryTotals(weeks)}
  </div>`;
}

function renderHistoryWeeks(weeks, stats) {
  return `<div class="list">
    ${[...weeks].reverse().map((w) => {
      const s = stats[w];
      const winner = s.winnerId ? state.players.find((p) => p.id === s.winnerId) : null;
      return `
      <button class="row-card" data-action="goto-week" data-week="${w}">
        <div>
          <div class="row-title">${weekHeading(w)}</div>
          <div class="dim-sm">${s.count} entries ${s.score !== "" ? `· score ${escapeHtml(s.score)}` : ""} ${winner ? `· ${escapeHtml(winner.name)} won` : ""}</div>
        </div>
        <div class="text-right">
          <div class="amount-strong">${fmtMoney(s.pot)}</div>
          ${winner ? `<div class="dim-sm green">paid out</div>` : ""}
        </div>
      </button>`;
    }).join("")}
  </div>`;
}

function renderHistoryTotals(weeks) {
  const totals = state.players
    .map((p) => ({ p, total: playerTotal(state, p.id, weeks) }))
    .filter((t) => t.total > 0)
    .sort((a, b) => b.total - a.total);
  return `<div class="list">
    ${totals.map(({ p, total }, i) => `
      <div class="row-card row-card-static">
        <div class="row-flex">
          <span class="dim-sm rank">${i + 1}</span>
          <div><div class="row-title">${escapeHtml(p.name)}</div><div class="dim-sm">${escapeHtml(teamLabel(p))}</div></div>
        </div>
        <div class="amount-strong">${fmtMoney(total)}</div>
      </div>`).join("")}
  </div>`;
}

/* ============================== SETTINGS ============================== */
function renderSettings() {
  const c = state.config;
  return `
  <div class="px-4 pt-4 pb-24">
    <h2 class="section-title mb-4">Season settings</h2>
    <label class="label-xs">Season name</label>
    <input class="input" id="cfg-label" value="${escapeHtml(c.seasonLabel)}" />
    <label class="label-xs">Entry fee per week ($)</label>
    <input class="input" type="number" id="cfg-fee" value="${c.entryFee}" />
    <label class="label-xs">Starting week number</label>
    <input class="input" type="number" id="cfg-start" value="${c.startWeek}" />
    <label class="label-xs">Total weeks in season</label>
    <input class="input" type="number" id="cfg-total" value="${c.totalWeeks}" />
    <button class="btn btn-green w-full mt-3" data-action="save-settings">Save settings</button>
    <p class="dim-sm mt-4">Changing "total weeks" or "starting week" doesn't erase recorded entries — it just changes how many week columns are shown.</p>
  </div>`;
}

/* ============================== SHELL / NAV ============================== */
function syncBadgeHtml() {
  const ok = state.syncStatus === "synced";
  const label = state.syncStatus === "syncing" ? "Syncing…" : state.syncStatus === "offline" ? "Offline" : ok ? "Synced" : `${state.pending} pending`;
  return `<button class="sync-badge ${ok ? "sync-ok" : "sync-warn"}" data-action="retry-sync">${label}</button>`;
}

function renderShell() {
  return `
  <div class="topbar">
    <div>
      <div class="topbar-eyebrow">2nd Game Pool</div>
      <div class="topbar-title">${escapeHtml(state.config.seasonLabel)}</div>
    </div>
    ${syncBadgeHtml()}
  </div>
  <div id="app-view"></div>
  <div class="bottom-nav">
    ${navBtn("dashboard", "This Week")}
    ${navBtn("roster", "Roster")}
    ${navBtn("history", "History")}
    ${navBtn("settings", "Settings")}
  </div>
  <div id="modal-root"></div>`;
}

function navBtn(id, label) {
  const active = state.tab === id;
  return `<button class="nav-btn ${active ? "nav-btn-active" : ""}" data-action="nav" data-tab="${id}">${label}</button>`;
}

function render() {
  // A thrown exception in a screen renderer must never stop us from drawing
  // the modal — otherwise an open modal shows as an empty sheet with no
  // buttons and the only way out is the backdrop.
  const app = $app();
  if (app) {
    try {
      app.innerHTML = state.tab === "dashboard" ? renderDashboard()
        : state.tab === "roster" ? renderRoster()
        : state.tab === "history" ? renderHistory()
        : renderSettings();
    } catch (err) {
      console.error("Screen render failed:", err);
      app.innerHTML = `<div class="px-4 pt-8 text-center">
        <div class="empty-note">Couldn't draw this screen.
        <button class="btn btn-outline mt-2" data-action="nav" data-tab="dashboard">Try again</button></div></div>`;
    }
  }

  const badge = document.querySelector(".sync-badge");
  if (badge) badge.outerHTML = syncBadgeHtml();

  // The bottom nav lives outside #app-view, so keep its active state in sync
  // with state.tab on every render.
  document.querySelectorAll(".bottom-nav .nav-btn").forEach((b) => {
    b.classList.toggle("nav-btn-active", b.dataset.tab === state.tab);
  });

  const modalRoot = $modalRoot();
  if (modalRoot) {
    // No inline onclick here — a strict CSP would drop it. The delegated
    // 'backdrop' handler already ignores clicks whose target isn't the
    // backdrop itself, so clicks inside the sheet never close the modal.
    modalRoot.innerHTML = state.modal
      ? `<div class="modal-backdrop" data-action="backdrop">
           <div class="modal-sheet">${state.modal.html || ""}</div>
         </div>`
      : "";
  }
}

function openModal(html) {
  if (typeof html !== "string" || html === "") {
    console.error("openModal: expected modal HTML, got", html);
    return;
  }
  state.modal = { html };
  render();
}
function closeModal() {
  state.modal = null;
  state._drawQuery = "";
  state._drawWinnerId = null;
  render();
}

/* ============================== EVENT DELEGATION ============================== */
document.addEventListener("click", async (e) => {
  const el = e.target.closest("[data-action]");
  if (!el) return;
  const action = el.dataset.action;

  switch (action) {
    case "nav":
      state.tab = el.dataset.tab;
      state.openPlayerId = null;
      state._importMsg = null;
      render();
      break;
    case "dash-filter":
      state._dashFilter = el.dataset.filter;
      renderDashboardListOnly();
      break;
    case "dash-sort":
      state._dashSort = el.dataset.sort;
      renderDashboardListOnly();
      break;
    case "roster-filter":
      state._rosterFilter = el.dataset.filter;
      render();
      break;
    case "open-edit-player":
      openModal(renderEditPlayerModal(el.dataset.id));
      break;
    case "submit-edit-player":
      await saveEditPlayer(el.dataset.id, {
        name: document.getElementById("edit-player-name").value,
        teamNumber: document.getElementById("edit-player-team-number").value,
        team: document.getElementById("edit-player-team").value,
      });
      break;
    case "week-prev": case "week-next": {
      const ws = weeksArray(state.config);
      const i = ws.indexOf(currentWeek());
      const next = action === "week-prev" ? i - 1 : i + 1;
      if (next >= 0 && next < ws.length) state.week = ws[next];
      render();
      break;
    }
    case "open-pay":
      openModal(renderPayModal(el.dataset.player, Number(el.dataset.week)));
      break;
    case "open-draw":
      state._drawWinnerId = null;
      openModal(renderDrawModal(Number(el.dataset.week)));
      break;
    case "open-add-player":
      openModal(renderAddPlayerModal());
      break;
    case "open-player":
      state.openPlayerId = el.dataset.id;
      render();
      break;
    case "close-player":
      state.openPlayerId = null;
      render();
      break;
    case "open-cell":
      openModal(renderCellModal(el.dataset.player, Number(el.dataset.week)));
      break;
    case "close-modal":
    case "backdrop":
      if (action === "backdrop" && e.target !== el) return; // only backdrop itself
      closeModal();
      break;
    case "import-roster":
      await importRoster();
      break;
    // "toggle-active" is handled on the 'change' event below (checkbox).
    case "goto-week":
      state.week = Number(el.dataset.week);
      state.tab = "dashboard";
      render();
      break;
    case "history-tab":
      state._historyTab = el.dataset.tab;
      render();
      break;
    case "retry-sync":
      Sync.flush();
      break;

    case "set-numweeks": {
      const n = Number(el.dataset.n);
      applyNumWeeks(n);
      break;
    }
    case "submit-pay": {
      const playerId = el.dataset.player;
      let startWeek = Number(el.dataset.start);
      if (!Number.isFinite(startWeek)) startWeek = currentWeek();
      await submitPay(playerId, startWeek, Number(el.dataset.n) || undefined);
      break;
    }
    case "pick-winner":
      state._drawWinnerId = el.dataset.id;
      openModal(renderDrawModal(currentDrawWeek()));
      break;
    case "submit-draw-none":
      await saveResult(Number(el.dataset.week), { score: document.getElementById("draw-score")?.value || "", winner_player_id: null, payout: 0, note: "" });
      closeModal();
      break;
    case "submit-draw-winner": {
      const week = Number(el.dataset.week);
      const score = document.getElementById("draw-score")?.value || "";
      const payout = Number(document.getElementById("draw-payout")?.value || 0);
      await saveResult(week, { score, winner_player_id: state._drawWinnerId, payout, note: "" });
      closeModal();
      break;
    }
    case "submit-add-player": {
      const name = document.getElementById("new-player-name").value.trim();
      const teamNumber = document.getElementById("new-player-team-number").value.trim();
      const team = document.getElementById("new-player-team").value.trim();
      if (!name) return;
      await addPlayer(name, teamNumber, team);
      closeModal();
      state.tab = "roster";
      render();
      break;
    }
    case "cell-set": {
      const status = el.dataset.status;
      await setEntry(el.dataset.player, Number(el.dataset.week), { amount: null, status, note: "" });
      closeModal();
      render();
      break;
    }
    case "cell-save-amount": {
      const val = document.getElementById("cell-amount").value;
      if (val === "") { closeModal(); break; }
      await setEntry(el.dataset.player, Number(el.dataset.week), { amount: parseFloat(val), status: "paid", note: "" });
      closeModal();
      render();
      break;
    }
    case "cell-clear":
      await clearEntry(el.dataset.player, Number(el.dataset.week));
      closeModal();
      render();
      break;
    case "save-settings": {
      const next = {
        seasonLabel: document.getElementById("cfg-label").value,
        entryFee: parseFloat(document.getElementById("cfg-fee").value || "1"),
        startWeek: parseInt(document.getElementById("cfg-start").value || "1", 10),
        totalWeeks: parseInt(document.getElementById("cfg-total").value || "33", 10),
      };
      await saveConfig(next);
      break;
    }
  }
});

// live search / input bindings (delegated 'input' event, since these fire on every keystroke)
document.addEventListener("input", (e) => {
  const el = e.target;
  if (el.dataset && el.dataset.action === "search") { state._query = el.value; renderDashboardListOnly(); }
  if (el.dataset && el.dataset.action === "roster-search") { state._rosterQuery = el.value; render(); }
  if (el.dataset && el.dataset.action === "draw-search") { state._drawQuery = el.value; refreshDrawList(); }
  if (el.dataset && el.dataset.action === "custom-numweeks") { const v = parseInt(el.value || "0", 10); if (v > 0) applyNumWeeks(v); }
  if (el.id === "pay-amount") { /* custom amount typed — leave as-is, read at submit time */ }
});

// 'change' bindings — checkboxes and file inputs (they don't fire click-vs-label
// cleanly, and file pickers only report a selection via 'change').
document.addEventListener("change", async (e) => {
  const el = e.target;
  if (el.dataset && el.dataset.action === "toggle-active") {
    await setActive(el.dataset.id, el.checked);
  }
  if (el.id === "xlsx-input") {
    const file = el.files && el.files[0];
    el.value = ""; // let the same file be re-picked later
    await importPlayersFromFile(file);
  }
});

// Re-render just the dashboard view (search keystrokes, filter/sort chips) so
// we don't disturb the shell or an open modal.
function renderDashboardListOnly() {
  const container = $app();
  if (!container) return;
  const searchHadFocus = document.activeElement?.dataset?.action === "search";
  container.innerHTML = renderDashboard();
  if (searchHadFocus) {
    const input = container.querySelector('[data-action="search"]');
    if (input) { input.focus(); input.setSelectionRange(input.value.length, input.value.length); }
  }
}

function refreshDrawList() {
  if (!state.modal) return;
  const week = currentDrawWeek();
  state.modal.html = renderDrawModal(week);
  const sheet = document.querySelector(".modal-sheet");
  if (sheet) {
    sheet.innerHTML = state.modal.html;
    const input = sheet.querySelector('[data-action="draw-search"]');
    if (input) { input.focus(); input.setSelectionRange(input.value.length, input.value.length); }
  }
}

function currentDrawWeek() {
  const btn = document.querySelector('[data-action="submit-draw-winner"]');
  return btn ? Number(btn.dataset.week) : state.week;
}

function applyNumWeeks(n) {
  const chips = document.querySelectorAll(".chip");
  chips.forEach((c) => c.classList.toggle("chip-active", Number(c.dataset.n) === n));
  const amountInput = document.getElementById("pay-amount");
  if (amountInput) amountInput.value = n * state.config.entryFee;
  const startBtn = document.querySelector("[data-action='submit-pay']");
  if (startBtn) {
    const { weeks } = computeStats(state);
    const preview = previewWeeks(Number(startBtn.dataset.start), n, weeks, startBtn.dataset.player);
    const previewEl = document.getElementById("pay-preview");
    if (previewEl) previewEl.innerHTML = `Will mark weeks <b>${preview.join(", ")}</b>`;
    startBtn.dataset.n = n;
  }
}

async function submitPay(playerId, startWeek, numWeeks) {
  const { weeks } = computeStats(state);
  const startBtn = document.querySelector("[data-action='submit-pay']");
  const n = Number.isFinite(numWeeks) ? numWeeks : Number(startBtn?.dataset.n || 1);
  const exempt = document.getElementById("pay-exempt")?.checked;
  const note = document.getElementById("pay-note")?.value || "";
  const amount = exempt ? 0 : parseFloat(document.getElementById("pay-amount")?.value || "0");
  const coverWeeks = previewWeeks(startWeek, n, weeks, playerId);

  if (!coverWeeks.length) {
    console.warn("submitPay: nothing to record", { playerId, startWeek, n, lastWeek: weeks[weeks.length - 1] });
    const preview = document.getElementById("pay-preview");
    if (preview) preview.innerHTML = `<span class="green">Nothing to record — those weeks are already on this player's card.</span>`;
    return;
  }

  for (let i = 0; i < coverWeeks.length; i++) {
    const w = coverWeeks[i];
    const status = exempt ? "exempt" : i === 0 ? "paid" : "covered";
    const amt = exempt ? null : i === 0 ? amount : null;
    await setEntry(playerId, w, { amount: amt, status, note });
  }
  closeModal();
  render();
}

/* ============================== INIT ============================== */
async function init() {
  if ("serviceWorker" in navigator) {
    navigator.serviceWorker.register("/service-worker.js").catch((e) => console.warn("SW registration failed", e));
  }

  await loadState();

  // First-ever load with a connection: pull from server if local is empty.
  if (state.players.length === 0 && navigator.onLine && window.POOL_SEASON_ID) {
    try {
      await Sync.pullBundleAndSeed(window.POOL_SEASON_ID);
      await loadState();
    } catch (e) {
      console.warn("Initial bundle pull failed — starting from local/empty state.", e);
    }
  }

  state.week = pickDefaultWeek();
  Sync.onStatus(async (s) => {
    if (s.event === "syncing") state.syncStatus = "syncing";
    else if (s.event === "offline") state.syncStatus = "offline";
    else {
      state.pending = await Sync.pendingCount();
      state.syncStatus = state.pending > 0 ? "pending" : "synced";
    }
    const badge = document.querySelector(".sync-badge");
    if (badge) badge.outerHTML = syncBadgeHtml();
  });
  Sync.init();

  document.getElementById("root").innerHTML = renderShell();
  render();
}

document.addEventListener("DOMContentLoaded", init);
