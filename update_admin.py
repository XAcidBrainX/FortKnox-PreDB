import sys

html_content = r"""<!DOCTYPE html>
<html lang="de"><head>
<meta http-equiv="content-type" content="text/html; charset=UTF-8">
  <meta charset="UTF-8">
  <title>FortKnox Control Center</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    :root {
      --bg: #0b0f17; --card-bg: #111827; --border: #1f2937;
      --primary: #0284c7; --text: #f8fafc; --muted: #94a3b8;
      --danger: #dc2626; --success: #16a34a; --warning: #ca8a04;
    }
    body { margin: 0; padding: 20px; background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .header h1 { margin: 0; font-size: 1.3rem; display: flex; align-items: center; gap: 10px; }
    .header a { color: var(--primary); text-decoration: none; font-size: 0.85rem; }
    .stats-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; }
    .stat-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 6px; padding: 16px; }
    .stat-label { font-size: 0.75rem; color: var(--muted); text-transform: uppercase; }
    .stat-val { font-size: 1.4rem; font-weight: 700; margin-top: 4px; }
    .nav-tabs-bar { display: flex; gap: 8px; margin: 20px 0; border-bottom: 1px solid var(--border); padding-bottom: 10px; flex-wrap: wrap; }
    .tab-btn { background: #1e293b; border: 1px solid var(--border); color: var(--muted); padding: 8px 16px; font-size: 0.9rem; font-weight: 600; cursor: pointer; border-radius: 6px; }
    .tab-btn.active { background: var(--primary); border-color: #38bdf8; color: #fff; }
    .card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 6px; padding: 20px; margin-bottom: 20px; }
    .panel-view { display: none; }
    .panel-view.active { display: block; }
    .table-responsive { overflow-x: auto; margin-top: 12px; }
    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 10px; font-size: 0.8rem; color: var(--muted); border-bottom: 1px solid var(--border); text-transform: uppercase; }
    td { padding: 12px 10px; font-size: 0.9rem; border-bottom: 1px solid rgba(255,255,255,0.05); }
    .action-btn { background: #334155; border: none; color: #fff; padding: 6px 12px; border-radius: 4px; font-size: 0.8rem; cursor: pointer; }
    .action-btn.danger { background: var(--danger); }
    .action-btn.success { background: var(--success); }
    .action-btn.primary { background: var(--primary); font-weight: bold; }
    .hidden { display: none !important; }
    .badge-active { color: #4ade80; font-weight: 600; font-size: 0.8rem; }
    .badge-inactive { color: #f87171; font-weight: 600; font-size: 0.8rem; }
  </style>
</head>
<body>

  <div class="header">
    <div>
      <h1>🛡️ FortKnox Control Center</h1>
      <a href="https://fk-predb.com/" target="_blank">↗ Zum Live Dashboard</a>
    </div>
    <button class="action-btn" onclick="logout()">Abmelden</button>
  </div>

  <div class="stats-bar">
    <div class="stat-card"><div class="stat-label">Aktive IRC-Bots</div><div class="stat-val" id="statBots">...</div></div>
    <div class="stat-card"><div class="stat-label">Releases Heute</div><div class="stat-val" id="statToday">...</div></div>
    <div class="stat-card"><div class="stat-label">Releases Gesamt</div><div class="stat-val" id="statTotal">...</div></div>
    <div class="stat-card"><div class="stat-label">MariaDB Grösse</div><div class="stat-val" id="statDbSize">...</div></div>
  </div>

  <div class="nav-tabs-bar">
    <button class="tab-btn" onclick="switchView('bots', this)">🤖 IRC Bots</button>
    <button class="tab-btn" onclick="switchView('sources', this)">🌐 Externe Quellen</button>
    <button class="tab-btn" onclick="switchView('moderation', this)">🛡️ Moderation</button>
    <button class="tab-btn" onclick="switchView('users', this)">👥 Benutzer</button>
    <button class="tab-btn" onclick="switchView('backups', this)">💾 Backups</button>
    <button class="tab-btn active" onclick="switchView('monitor', this)">📡 IRC Monitor</button>
  </div>

  <!-- PANEL: BOTS -->
  <div id="panel-bots" class="panel-view">
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h2 style="margin:0; font-size:1.15rem;">IRC Bots &amp; Netzwerke</h2>
        <button id="newBotBtn" class="action-btn primary">+ Neues Netzwerk</button>
      </div>

      <div id="botFormContainer" class="hidden" style="background:#0a0f1d; border:1px solid #1e293b; border-radius:6px; padding:16px; margin-bottom:20px;">
        <h3 style="margin-top:0; color:#38bdf8;" id="botFormTitle">Netzwerk konfigurieren</h3>
        <form id="botForm">
          <input type="hidden" id="botId">
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
            <div><label>Netzwerk-Name</label><input type="text" id="botName" required="" style="width:100%; padding:8px; background:#020617; border:1px solid #334155; color:#fff;"></div>
            <div><label>Host</label><input type="text" id="botHost" required="" style="width:100%; padding:8px; background:#020617; border:1px solid #334155; color:#fff;"></div>
            <div><label>Port</label><input type="number" id="botPort" required="" style="width:100%; padding:8px; background:#020617; border:1px solid #334155; color:#fff;"></div>
            <div><label>TLS (SSL)</label><select id="botTls" style="width:100%; padding:8px; background:#020617; border:1px solid #334155; color:#fff;"><option value="1" selected="selected">Ja (6697)</option><option value="0">Nein (6667)</option></select></div>
            <div><label>Nickname</label><input type="text" id="botNick" required="" style="width:100%; padding:8px; background:#020617; border:1px solid #334155; color:#fff;"></div>
            <div><label>Username (Ident)</label><input type="text" id="botUser" required="" style="width:100%; padding:8px; background:#020617; border:1px solid #334155; color:#fff;"></div>
            <div><label>NS Password</label><input type="password" id="botPass" style="width:100%; padding:8px; background:#020617; border:1px solid #334155; color:#fff;"></div>
            <div><label>Channels (kommagetrennt)</label><input type="text" id="botChannels" required="" style="width:100%; padding:8px; background:#020617; border:1px solid #334155; color:#fff;"></div>
            <div><label>Erlaubte Sektionen (leer = alle)</label><input type="text" id="botSections" placeholder="z. B. GAMES,X264" style="width:100%; padding:8px; background:#020617; border:1px solid #334155; color:#fff;"></div>
            <div><label>Erlaubte Sprachen (leer = alle)</label><input type="text" id="botLanguages" placeholder="z. B. GERMAN,MULTi" style="width:100%; padding:8px; background:#020617; border:1px solid #334155; color:#fff;"></div>
          </div>
          <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
            <button type="button" id="cancelBotBtn" class="action-btn">Abbrechen</button>
            <button type="submit" class="action-btn primary">Speichern</button>
          </div>
        </form>
      </div>

      <div class="table-responsive">
        <table>
          <thead><tr><th>ID</th><th>Netzwerk</th><th>Server</th><th>Nick</th><th>Channels</th><th>Status</th><th style="text-align:right;">Aktionen</th></tr></thead>
          <tbody id="botTableBody"><tr><td colspan="7" style="text-align:center;">Lade Bots...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- PANEL: QUELLEN -->
  <div id="panel-sources" class="panel-view">
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h2 style="margin:0; font-size:1.15rem;">Externe PreDB Quellen</h2>
        <button id="newSourceBtn" class="action-btn primary">+ Neue Quelle hinzufügen</button>
      </div>

      <div id="sourceFormContainer" class="hidden" style="background:#0a0f1d; border:1px solid #1e293b; border-radius:6px; padding:16px; margin-bottom:20px;">
        <h3 style="margin-top:0; color:#38bdf8;" id="sourceFormTitle">Quelle konfigurieren</h3>
        <form id="sourceForm">
          <input type="hidden" id="sourceId">
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
            <div><label style="display:block; font-size:0.8rem; color:var(--muted); margin-bottom:4px;">Quellen Name</label><input type="text" id="sourceName" placeholder="z. B. predb.me" required="" style="width:100%; box-sizing:border-box; padding:8px; background:#020617; border:1px solid #334155; color:#fff; border-radius:4px;"></div>
            <div style="grid-column: 1 / -1;"><label style="display:block; font-size:0.8rem; color:var(--muted); margin-bottom:4px;">URL / Feed / API Endpunkt</label><input type="url" id="sourceUrl" placeholder="https://..." required="" style="width:100%; box-sizing:border-box; padding:8px; background:#020617; border:1px solid #334155; color:#fff; border-radius:4px;"></div>
            <div><label style="display:block; font-size:0.8rem; color:var(--muted); margin-bottom:4px;">Parser Typ</label><select id="sourceType" style="width:100%; box-sizing:border-box; padding:8px; background:#020617; border:1px solid #334155; color:#fff; border-radius:4px;">
              <option value="predb_net" selected="selected">predb.net (JSON API)</option>
              <option value="predb_org">predb.org (Next.js Data API)</option>
              <option value="rss">RSS / XML Feed</option>
              <option value="html_regex">HTML Regex Scraper</option>
            </select></div>
            <div><label style="display:block; font-size:0.8rem; color:var(--muted); margin-bottom:4px;">Intervall (Minuten)</label><input type="number" id="sourceInterval" value="5" required="" style="width:100%; box-sizing:border-box; padding:8px; background:#020617; border:1px solid #334155; color:#fff; border-radius:4px;"></div>
            <div style="display:flex; align-items:center; gap:8px; margin-top:22px;">
              <input type="checkbox" id="sourceActive" checked="checked" style="width:18px; height:18px;"> <label>Aktiv</label>
            </div>
          </div>
          <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
            <button type="button" id="cancelSourceBtn" class="action-btn">Abbrechen</button>
            <button type="submit" class="action-btn primary">Speichern</button>
          </div>
        </form>
      </div>

      <div class="table-responsive">
        <table>
          <thead><tr><th>ID</th><th>Name / URL</th><th>Typ</th><th>Intervall</th><th>Status</th><th>Letzter Sync</th><th style="text-align:right;">Aktionen</th></tr></thead>
          <tbody id="sourceTableBody"><tr><td colspan="7" style="text-align:center;">Lade Quellen...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- PANEL: MODERATION -->
  <div id="panel-moderation" class="panel-view">
    <div class="card">
      <h2 style="margin:0 0 16px 0; font-size:1.15rem;">🛡️ Release Moderation</h2>
      
      <div style="display:flex; gap:8px; margin-bottom:20px;">
        <input type="text" id="searchQuery" placeholder="Release suchen (z. B. Name oder Group)..." style="flex:1; padding:10px; background:#020617; border:1px solid #334155; color:#fff; border-radius:4px; font-size:0.95rem;">
        <button id="searchBtn" class="action-btn primary" style="padding:0 20px;">Suchen</button>
      </div>

      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Release Name</th>
              <th>Sektion</th>
              <th>Status</th>
              <th style="text-align:right;">Aktionen</th>
            </tr>
          </thead>
          <tbody id="releaseTableBody">
            <tr><td colspan="5" style="text-align:center; color:var(--muted); padding:20px;">Bitte Suchbegriff eingeben...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- PANEL: USER -->
  <div id="panel-users" class="panel-view">
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h2 style="margin:0; font-size:1.15rem;">👥 Benutzerverwaltung</h2>
        <button id="newUserBtn" class="action-btn primary">+ Neuer Benutzer</button>
      </div>
      
      <div id="userFormContainer" class="hidden" style="background:#0a0f1d; border:1px solid #1e293b; border-radius:6px; padding:16px; margin-bottom:20px;">
        <h3 style="margin-top:0; color:#38bdf8;" id="userFormTitle">Benutzer anlegen</h3>
        <form id="userForm">
          <input type="hidden" id="userId">
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
            <div><label>Benutzername</label><input type="text" id="userName" required="" style="width:100%; padding:8px; background:#020617; border:1px solid #334155; color:#fff;"></div>
            <div><label>Passwort</label><input type="password" id="userPass" placeholder="Leer = unverändert" style="width:100%; padding:8px; background:#020617; border:1px solid #334155; color:#fff;"></div>
            <div><label>Rolle</label><select id="userRole" style="width:100%; padding:8px; background:#020617; border:1px solid #334155; color:#fff;"><option value="admin" selected="selected">Admin</option><option value="operator">Operator</option></select></div>
            <div style="display:flex; align-items:center; gap:8px; margin-top:22px;">
              <input type="checkbox" id="userActive" checked="checked" style="width:18px; height:18px;"> <label>Aktiv</label>
            </div>
          </div>
          <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
            <button type="button" id="cancelUserBtn" class="action-btn">Abbrechen</button>
            <button type="submit" class="action-btn primary">Speichern</button>
          </div>
        </form>
      </div>

      <div class="table-responsive">
        <table>
          <thead><tr><th>ID</th><th>Benutzername</th><th>Rolle</th><th>Status</th><th>Login</th><th style="text-align:right;">Aktionen</th></tr></thead>
          <tbody id="usersTableBody"><tr><td colspan="6" style="text-align:center;">Keine Benutzer.</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- PANEL: BACKUPS -->
  <div id="panel-backups" class="panel-view">
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2 style="margin:0; font-size:1.15rem;">💾 Backup &amp; Wiederherstellung</h2>
        <div style="display:flex; gap:10px;">
          <button id="btnConfigBackup" class="action-btn primary">⚡ Config Backup</button>
          <button id="btnFullBackup" class="action-btn success">📦 Full DB-Dump</button>
        </div>
      </div>
      <div id="backupAlert" class="hidden" style="padding:12px; margin-bottom:16px; border-radius:6px;"></div>
      <div class="table-responsive">
        <table>
          <thead><tr><th>Dateiname</th><th>Größe</th><th>Datum</th><th style="text-align:right;">Aktionen</th></tr></thead>
          <tbody id="backupsTableBody"><tr><td colspan="4" style="text-align:center;">Lade Backups...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- PANEL: MONITOR -->
  <div id="panel-monitor" class="panel-view active">
    <div class="card" style="padding:10px; margin-bottom:0;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; padding:0 10px;">
        <h2 style="margin:0; font-size:1.15rem;">📡 Live IRC Monitor</h2>
        <a href="https://fk-predb.com/monitor/irc.php" target="_blank" class="action-btn" style="text-decoration:none;">↗ Neuer Tab</a>
      </div>
      <iframe src="/monitor/irc.php" style="width:100%; height:calc(100vh - 280px); min-height:600px; border:1px solid var(--border); border-radius:4px; background:#000;"></iframe>
    </div>
  </div>

  <!-- JAVASCRIPT LOGIC -->
  <script>
    let botCache = [];

    // Global fetch wrapper, damit Cookies (Session) bei allen API-Requests automatisch mitsendegesendet werden
    const originalFetch = window.fetch;
    window.fetch = function(url, options = {}) {
      if (typeof url === 'string' && url.startsWith('/api/')) {
        options.credentials = 'include';
      }
      return originalFetch(url, options);
    };

    function escapeHtml(text) {
      if (!text) return "";
      return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
    }

    function switchView(tabKey, btn) {
      document.querySelectorAll('.panel-view').forEach(p => p.classList.remove('active'));
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      const target = document.getElementById('panel-' + tabKey);
      if (target) target.classList.add('active');
      if (btn) btn.classList.add('active');

      if (tabKey === 'bots') loadBots();
      if (tabKey === 'sources') loadSources();
      if (tabKey === 'users') loadUsers();
      if (tabKey === 'backups') loadBackups();
    }

    /* --- BOTS LOGIC --- */
    async function loadBots() {
      const tbody = document.getElementById('botTableBody');
      try {
        const res = await fetch('/api/admin/bots');
        if (res.status === 401) return;
        const d = await res.json();
        botCache = d.bots || [];
        if (!botCache.length) {
          tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">Keine IRC-Bots konfiguriert.</td></tr>';
          return;
        }
        tbody.innerHTML = botCache.map(b => {
          const isUp = b.service_status === 'active';
          return `<tr>
            <td>#${b.id}</td>
            <td><strong>${escapeHtml(b.name)}</strong></td>
            <td>${escapeHtml(b.host)}:${b.port} ${b.tls ? '🔒' : ''}</td>
            <td>${escapeHtml(b.nick)}</td>
            <td><code style="color:var(--primary);">${escapeHtml(b.channels)}</code></td>
            <td><span class="${isUp ? 'badge-active' : 'badge-inactive'}">${isUp ? 'ACTIVE' : 'INACTIVE'}</span></td>
            <td style="text-align:right;">
              ${isUp ? `<button class="action-btn danger" onclick="botAction(${b.id}, 'stop')">Stop</button><button class="action-btn" onclick="botAction(${b.id}, 'restart')">Restart</button>` 
                     : `<button class="action-btn success" onclick="botAction(${b.id}, 'start')">Start</button>`}
              <button class="action-btn" onclick="editBot(${b.id})">Edit</button>
              <button class="action-btn danger" onclick="deleteBot(${b.id})">X</button>
            </td>
          </tr>`;
        }).join("");
      } catch (err) { tbody.innerHTML = `<tr><td colspan="7" style="color:red; text-align:center;">Fehler: ${err.message}</td></tr>`; }
    }

    async function botAction(id, action) {
      const res = await fetch(`/api/admin/bots/${id}/${action}`, { method: 'POST' });
      if (res.ok) setTimeout(loadBots, 500);
      else alert(`Aktion ${action} fehlgeschlagen.`);
    }

    async function deleteBot(id) {
      if (!confirm(`Bot #${id} wirklich löschen?`)) return;
      const res = await fetch(`/api/admin/bots/${id}/delete`, { method: 'POST' });
      if (res.ok) loadBots();
    }

    function editBot(id) {
      const b = botCache.find(x => Number(x.id) === Number(id));
      if (!b) return;
      document.getElementById('botId').value = b.id;
      document.getElementById('botName').value = b.name;
      document.getElementById('botHost').value = b.host;
      document.getElementById('botPort').value = b.port;
      document.getElementById('botTls').value = b.tls ? "1" : "0";
      document.getElementById('botNick').value = b.nick;
      document.getElementById('botUser').value = b.username;
      document.getElementById('botPass').value = '';
      document.getElementById('botChannels').value = b.channels;
      document.getElementById('botSections').value = b.announce_sections || '';
      document.getElementById('botLanguages').value = b.announce_languages || '';
      document.getElementById('botFormTitle').innerText = `Bot bearbeiten: ${b.name}`;
      document.getElementById('botFormContainer').classList.remove('hidden');
    }

    /* --- SOURCES LOGIC --- */
    let cachedSources = [];

    async function loadSources() {
      const tbody = document.getElementById("sourceTableBody");
      if (!tbody) return;
      try {
        const res = await fetch("/api/admin/sources");
        if (res.status === 401) return;
        const data = await res.json();
        cachedSources = data.sources || [];
        
        if (!cachedSources.length) {
          tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; color:var(--muted);">Keine Quellen konfiguriert.</td></tr>';
          return;
        }
        
        tbody.innerHTML = cachedSources.map(s => {
          const isAct = parseInt(s.enabled, 10) === 1;
          return `<tr>
            <td>#${s.id}</td>
            <td><strong>${escapeHtml(s.name)}</strong><br><small style="color:var(--muted); word-break:break-all;">${escapeHtml(s.url)}</small></td>
            <td><span style="background:#1e293b; padding:4px 8px; border-radius:4px; font-size:0.8rem; color:#fff;">${escapeHtml(s.type)}</span></td>
            <td>${s.sync_interval_min}m</td>
            <td><span class="${isAct ? 'badge-active' : 'badge-inactive'}">${isAct ? 'AKTIV' : 'INAKTIV'}</span></td>
            <td><small style="color:var(--muted);">${escapeHtml(s.last_sync) || 'Nie'}<br>${escapeHtml(s.last_status) || ''}</small></td>
            <td style="text-align:right;">
              <button class="action-btn" onclick="editSource(${s.id})">Edit</button>
              <button class="action-btn ${isAct ? 'danger' : 'success'}" onclick="toggleSource(${s.id}, ${isAct ? 0 : 1})">${isAct ? 'Stop' : 'Start'}</button>
              <button class="action-btn danger" onclick="deleteSource(${s.id})">X</button>
            </td>
          </tr>`;
        }).join("");
      } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" style="color:red; text-align:center;">Fehler: ${err.message}</td></tr>`;
      }
    }

    function editSource(id) {
      const s = cachedSources.find(x => Number(x.id) === Number(id));
      if (!s) return;
      document.getElementById('sourceId').value = s.id;
      document.getElementById('sourceName').value = s.name;
      document.getElementById('sourceUrl').value = s.url;
      document.getElementById('sourceType').value = s.type;
      document.getElementById('sourceInterval').value = s.sync_interval_min;
      document.getElementById('sourceActive').checked = (parseInt(s.enabled, 10) === 1);
      document.getElementById('sourceFormTitle').innerText = 'Quelle bearbeiten: ' + s.name;
      document.getElementById('sourceFormContainer').classList.remove('hidden');
    }

    async function toggleSource(id, targetState) {
      await fetch(`/api/admin/sources/${id}/toggle`, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ enabled: targetState }) });
      loadSources();
    }

    async function deleteSource(id) {
      if (!confirm(`Quelle #${id} wirklich löschen?`)) return;
      await fetch(`/api/admin/sources/${id}/delete`, { method: 'POST' });
      loadSources();
    }

    /* --- USERS LOGIC --- */
    async function loadUsers() {
      const tbody = document.getElementById("usersTableBody");
      try {
        const res = await fetch("/api/admin/users");
        if (res.status === 401) return;
        const data = await res.json();
        const users = data.users || [];
        if (!users.length) { tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Keine Benutzer.</td></tr>'; return; }
        tbody.innerHTML = users.map(u => `<tr>
          <td>#${u.id}</td>
          <td><strong>${escapeHtml(u.username)}</strong></td>
          <td>${escapeHtml(u.role).toUpperCase()}</td>
          <td><span class="${u.is_active == 1 ? 'badge-active' : 'badge-inactive'}">${u.is_active == 1 ? 'ACTIVE' : 'INACTIVE'}</span></td>
          <td>${u.last_login || '-'}</td>
          <td style="text-align:right;">
            <button class="action-btn" onclick='editUser(${JSON.stringify(u)})'>Edit</button>
            <button class="action-btn" onclick="toggleUser(${u.id})">${u.is_active == 1 ? 'Sperren' : 'Aktivieren'}</button>
            <button class="action-btn danger" onclick="deleteUser(${u.id}, '${escapeHtml(u.username)}')">X</button>
          </td>
        </tr>`).join("");
      } catch (err) { tbody.innerHTML = `<tr><td colspan="6" style="color:red; text-align:center;">Fehler: ${err.message}</td></tr>`; }
    }

    function editUser(u) {
      document.getElementById("userFormContainer").classList.remove("hidden");
      document.getElementById("userFormTitle").innerText = "Benutzer bearbeiten: " + u.username;
      document.getElementById("userId").value = u.id;
      document.getElementById("userName").value = u.username;
      document.getElementById("userPass").value = "";
      document.getElementById("userRole").value = u.role;
      document.getElementById("userActive").checked = (u.is_active == 1);
    }
    
    async function toggleUser(id) { await fetch(`/api/admin/users/${id}/toggle`, { method: 'POST' }); loadUsers(); }
    async function deleteUser(id, name) { if(confirm(`Benutzer "${name}" löschen?`)) { await fetch(`/api/admin/users/${id}/delete`, { method: 'POST' }); loadUsers(); } }

    /* --- MODERATION LOGIC --- */
    document.getElementById('searchBtn')?.addEventListener('click', async () => {
      const q = document.getElementById('searchQuery').value.trim();
      if (!q) return;
      const tbody = document.getElementById('releaseTableBody');
      tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--muted);">Suche läuft...</td></tr>';
      
      try {
        const res = await fetch(`/api/admin/releases/search?q=${encodeURIComponent(q)}`);
        const data = await res.json();
        const releases = data.releases || data.data || (Array.isArray(data) ? data : []);
        
        if (!releases.length) {
          tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#f87171;">Keine Releases gefunden.</td></tr>';
          return;
        }
        
        tbody.innerHTML = releases.map(r => {
          const isNuked = (r.status && r.status.toLowerCase() === 'nuked');
          return `<tr>
            <td>#${r.id}</td>
            <td><strong>${escapeHtml(r.name)}</strong></td>
            <td><span style="background:#1e293b; padding:2px 6px; border-radius:4px; font-size:0.8rem;">${escapeHtml(r.category || r.section || 'N/A')}</span></td>
            <td><span class="${isNuked ? 'badge-inactive' : 'badge-active'}">${isNuked ? 'NUKED' : 'PRE'}</span></td>
            <td style="text-align:right;">
              ${isNuked 
                ? `<button class="action-btn success" onclick="unnukeRelease(${r.id})">Unnuke</button>`
                : `<button class="action-btn danger" onclick="nukeRelease(${r.id})">Nuke</button>`
              }
            </td>
          </tr>`;
        }).join('');
      } catch (err) {
        tbody.innerHTML = `<tr><td colspan="5" style="color:red; text-align:center;">Fehler: ${err.message}</td></tr>`;
      }
    });

    document.getElementById('searchQuery')?.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') document.getElementById('searchBtn').click();
    });

    async function nukeRelease(id) {
      const reason = prompt("Nuke Grund (optional):");
      if (reason === null) return;
      const res = await fetch(`/api/releases/${id}/nuke`, { 
        method: 'POST', 
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ reason }) 
      });
      if (res.ok) document.getElementById('searchBtn').click();
      else alert('Fehler beim Nuken!');
    }

    async function unnukeRelease(id) {
      if (!confirm(`Release #${id} wirklich unnuken?`)) return;
      const res = await fetch(`/api/releases/${id}/unnuke`, { method: 'POST' });
      if (res.ok) document.getElementById('searchBtn').click();
      else alert('Fehler beim Unnuken!');
    }

    /* --- BACKUPS LOGIC --- */
    async function loadBackups() {
      const tbody = document.getElementById("backupsTableBody");
      try {
        const res = await fetch("/api/admin/backups");
        if (res.status === 401) return;
        const data = await res.json();
        if (data.dump_running) showBackupAlert("Dump läuft im Hintergrund...", "#0284c7");
        const backups = data.backups || [];
        if (!backups.length) { tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Keine Backups vorhanden.</td></tr>'; return; }
        tbody.innerHTML = backups.map(b => `<tr>
          <td><strong>${escapeHtml(b.name)}</strong></td>
          <td style="color:#38bdf8;">${b.size}</td>
          <td style="color:var(--muted);">${b.created_at}</td>
          <td style="text-align:right;">
            <a href="/api/admin/backups/download/${encodeURIComponent(b.name)}" class="action-btn primary" style="text-decoration:none;">Download</a>
            <button class="action-btn danger" onclick="deleteBackup('${escapeHtml(b.name)}')">Löschen</button>
          </td>
        </tr>`).join("");
      } catch (err) { tbody.innerHTML = `<tr><td colspan="4" style="color:red; text-align:center;">Fehler: ${err.message}</td></tr>`; }
    }

    function showBackupAlert(msg, bg) {
      const el = document.getElementById("backupAlert");
      el.innerText = msg; el.style.background = bg; el.style.color = "#fff";
      el.classList.remove("hidden");
      setTimeout(() => el.classList.add("hidden"), 6000);
    }
    
    async function deleteBackup(name) { if(confirm(`Backup "${name}" löschen?`)) { await fetch(`/api/admin/backups/delete/${encodeURIComponent(name)}`, { method: 'POST' }); loadBackups(); } }

    async function loadStats() {
      try {
        const res = await fetch("/api/admin/stats");
        const data = await res.json();
        if (data && data.total_releases !== undefined) {
          document.getElementById('statBots').innerText = data.active_bots + ' Online';
          document.getElementById('statToday').innerText = new Intl.NumberFormat('de-DE').format(data.today_releases);
          document.getElementById('statTotal').innerText = new Intl.NumberFormat('de-DE').format(data.total_releases);
          document.getElementById('statDbSize').innerText = data.db_size_mb + ' MB';
        }
      } catch (err) {
        console.error("Stats Error:", err);
      }
    }

    /* --- EVENT LISTENERS --- */
    document.addEventListener("DOMContentLoaded", () => {
      loadStats();
      loadBots();

      document.getElementById('newBotBtn')?.addEventListener('click', () => {
        document.getElementById('botForm').reset();
        document.getElementById('botId').value = '';
        document.getElementById('botFormTitle').innerText = 'Neues IRC Netzwerk';
        document.getElementById('botFormContainer').classList.remove('hidden');
      });
      document.getElementById('cancelBotBtn')?.addEventListener('click', () => document.getElementById('botFormContainer').classList.add('hidden'));
      document.getElementById('botForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
          id: document.getElementById('botId').value || null, name: document.getElementById('botName').value,
          host: document.getElementById('botHost').value, port: parseInt(document.getElementById('botPort').value),
          tls: document.getElementById('botTls').value === "1", nick: document.getElementById('botNick').value,
          username: document.getElementById('botUser').value, password: document.getElementById('botPass').value,
          channels: document.getElementById('botChannels').value,
          announce_sections: document.getElementById('botSections').value,
          announce_languages: document.getElementById('botLanguages').value
        };
        const res = await fetch('/api/admin/bots', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
        if (res.ok) { document.getElementById('botFormContainer').classList.add('hidden'); loadBots(); }
        else alert('Fehler beim Speichern');
      });

      document.getElementById('newSourceBtn')?.addEventListener('click', () => {
        document.getElementById('sourceForm').reset();
        document.getElementById('sourceId').value = '';
        document.getElementById('sourceFormTitle').innerText = 'Neue Quelle hinzufügen';
        document.getElementById('sourceFormContainer').classList.remove('hidden');
      });
      document.getElementById('cancelSourceBtn')?.addEventListener('click', () => {
        document.getElementById('sourceFormContainer').classList.add('hidden');
      });
      document.getElementById('sourceForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
          id: document.getElementById('sourceId').value || null,
          name: document.getElementById('sourceName').value,
          url: document.getElementById('sourceUrl').value,
          type: document.getElementById('sourceType').value,
          sync_interval_min: parseInt(document.getElementById('sourceInterval').value),
          enabled: document.getElementById('sourceActive').checked ? 1 : 0
        };
        const res = await fetch('/api/admin/sources', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
        if (res.ok) { 
          document.getElementById('sourceFormContainer').classList.add('hidden'); 
          loadSources(); 
        } else {
          alert('Fehler beim Speichern der Quelle.');
        }
      });

      document.getElementById("newUserBtn")?.addEventListener("click", () => {
        document.getElementById("userForm").reset();
        document.getElementById("userId").value = "";
        document.getElementById("userFormTitle").innerText = "Neuen Benutzer anlegen";
        document.getElementById("userFormContainer").classList.remove("hidden");
      });
      document.getElementById("cancelUserBtn")?.addEventListener("click", () => document.getElementById("userFormContainer").classList.add("hidden"));
      document.getElementById("userForm")?.addEventListener("submit", async (e) => {
        e.preventDefault();
        const payload = {
          id: document.getElementById("userId").value || null, username: document.getElementById("userName").value.trim(),
          password: document.getElementById("userPass").value, role: document.getElementById("userRole").value,
          is_active: document.getElementById("userActive").checked ? 1 : 0
        };
        const res = await fetch("/api/admin/users", { method: "POST", headers: {"Content-Type": "application/json"}, body: JSON.stringify(payload) });
        if (res.ok) { document.getElementById("userFormContainer").classList.add("hidden"); loadUsers(); }
      });

      document.getElementById("btnConfigBackup")?.addEventListener("click", async () => {
        showBackupAlert("Erstelle Config-Backup...", "#334155");
        const res = await fetch("/api/admin/backups/config", { method: "POST" });
        if (res.ok) { showBackupAlert("Backup erfolgreich!", "#059669"); loadBackups(); }
        else showBackupAlert("Fehler beim Erstellen", "#dc2626");
      });
      document.getElementById("btnFullBackup")?.addEventListener("click", async () => {
        if (!confirm("Vollständigen DB-Dump starten?")) return;
        await fetch("/api/admin/backups/full", { method: "POST" });
        showBackupAlert("Backup im Hintergrund gestartet.", "#059669");
        setTimeout(loadBackups, 2000);
      });
    });

    async function logout() { await fetch('/api/admin/logout', { method: 'POST' }); window.location.reload(); }
  </script>
</body></html>
"""

with open("/var/www/FortKnox-PreDB/public/admin.html", "w", encoding="utf-8") as f:
    f.write(html_content)

print("[OK] public/admin.html erfolgreich im Originalzustand mit automatischem Cookie-Credential-Wrapper geschrieben!")
