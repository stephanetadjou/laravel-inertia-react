/* Assistant Menentia : bulle de discussion en bas à droite du site.
 * Sans dépendance. Réglages par attributs de la balise <script> :
 *   data-api : adresse du service IA (POST, réponse en flux SSE). Vide = réponses de secours seules.
 *   data-kb  : base de connaissances (JSON produit par /assistant/kb.json).
 *   data-base : préfixe des liens internes (aperçu statique).
 * Si le service IA ne répond pas, l’assistant répond à partir de la base locale. */
(function () {
  'use strict';
  if (window.__menentiaAssistant) return;
  window.__menentiaAssistant = true;

  var script = document.currentScript || document.querySelector('script[data-menentia-assistant]');
  var API = (script && script.getAttribute('data-api')) || '';
  var KB_URL = (script && script.getAttribute('data-kb')) || '/assistant/kb.json';
  var BASE = (script && script.getAttribute('data-base')) || '';
  var STORE = 'menentia-assistant-v1';
  var MAX_TURNS = 12;

  var kb = null;
  var history = [];
  var busy = false;

  try { history = JSON.parse(sessionStorage.getItem(STORE) || '[]') || []; } catch (e) { history = []; }
  function save() { try { sessionStorage.setItem(STORE, JSON.stringify(history.slice(-MAX_TURNS * 2))); } catch (e) {} }

  function loadKb() {
    if (kb) return Promise.resolve(kb);
    return fetch(KB_URL).then(function (r) { return r.json(); }).then(function (d) { kb = d; return d; })
      .catch(function () { kb = { answers: [], suggestions: [] }; return kb; });
  }

  // ---- Styles ---------------------------------------------------------------
  var css = [
    '.mna{--mna-navy:#0B2D4A;--mna-blue:#0E7BD6;--mna-blue-text:#0A66B2;--mna-green:#00C896;--mna-ink:#10243A;--mna-muted:#4A5B6E;--mna-soft:#F4F8FB;--mna-line:#DCE6EF;--mna-bg:#fff;',
    'font-family:var(--font-body,"Inter"),ui-sans-serif,system-ui,sans-serif;color:var(--mna-ink);font-size:15px;line-height:1.5}',
    '.mna *{box-sizing:border-box}',
    '.mna-launch{position:fixed;z-index:60;right:calc(20px + env(safe-area-inset-right,0px));bottom:calc(20px + env(safe-area-inset-bottom,0px));display:flex;align-items:center;gap:10px;border:0;border-radius:999px;background:var(--mna-navy);color:#fff;padding:10px 18px 10px 10px;font:inherit;font-weight:600;cursor:pointer;box-shadow:0 18px 40px -12px rgba(11,45,74,.55);transition:transform .2s}',
    '.mna-launch:hover{transform:translateY(-2px)}',
    '.mna-launch:focus-visible,.mna button:focus-visible,.mna textarea:focus-visible,.mna a:focus-visible{outline:3px solid var(--mna-green);outline-offset:2px}',
    '.mna-orb{position:relative;display:grid;place-items:center;width:38px;height:38px;border-radius:999px;background:linear-gradient(135deg,var(--mna-blue),var(--mna-green))}',
    '.mna-orb svg{width:20px;height:20px}',
    '.mna-dot{position:absolute;right:-1px;top:-1px;width:11px;height:11px;border-radius:999px;background:var(--mna-green);border:2px solid var(--mna-navy)}',
    '.mna-open .mna-launch{display:none}',
    '.mna-panel{position:fixed;z-index:61;right:calc(20px + env(safe-area-inset-right,0px));bottom:calc(20px + env(safe-area-inset-bottom,0px));width:min(400px,calc(100vw - 32px));height:min(620px,calc(100vh - 40px));display:flex;flex-direction:column;border-radius:22px;background:var(--mna-bg);border:1px solid var(--mna-line);box-shadow:0 30px 80px -20px rgba(11,45,74,.45);overflow:hidden;opacity:0;transform:translateY(12px) scale(.98);pointer-events:none;transition:opacity .18s,transform .18s}',
    '.mna-open .mna-panel{opacity:1;transform:none;pointer-events:auto}',
    '@media (max-width:520px){.mna-panel{right:0;bottom:0;width:100vw;height:100%;height:100dvh;border-radius:0;border:0;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}.mna-launch{padding-right:10px}.mna-launch-label{display:none}}',
    '.mna-head{display:flex;align-items:center;gap:12px;padding:14px 14px 14px 16px;background:var(--mna-navy);color:#fff}',
    '.mna-head .mna-orb{width:36px;height:36px;flex:none}',
    '.mna-title{margin:0;font-weight:700;font-size:15.5px;line-height:1.2}',
    '.mna-sub{margin:2px 0 0;font-size:12.5px;color:#B7CADB}',
    '.mna-close{margin-left:auto;display:grid;place-items:center;width:36px;height:36px;border:0;border-radius:999px;background:rgba(255,255,255,.1);color:#fff;cursor:pointer}',
    '.mna-close:hover{background:rgba(255,255,255,.18)}',
    '.mna-log{flex:1;min-height:0;overflow-y:auto;padding:18px 16px 8px;display:flex;flex-direction:column;gap:12px;background:var(--mna-soft)}',
    '.mna-msg{max-width:88%;padding:10px 13px;border-radius:16px;white-space:normal;word-wrap:break-word;overflow-wrap:anywhere}',
    '.mna-msg p{margin:0}.mna-msg p+p,.mna-msg ul+p,.mna-msg p+ul,.mna-msg ol+p,.mna-msg p+ol{margin-top:8px}',
    '.mna-msg ul,.mna-msg ol{margin:0;padding-left:20px}.mna-msg li+li{margin-top:4px}',
    '.mna-bot{align-self:flex-start;background:#fff;border:1px solid var(--mna-line);border-bottom-left-radius:6px}',
    '.mna-user{align-self:flex-end;background:var(--mna-blue);color:#fff;border-bottom-right-radius:6px}',
    '.mna-msg a{color:var(--mna-blue-text);font-weight:600;text-decoration:underline;text-underline-offset:3px}',
    '.mna-links{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}',
    '.mna-links a{display:inline-flex;align-items:center;border-radius:999px;background:#E4F0FB;padding:5px 11px;font-size:13px;text-decoration:none}',
    '.mna-note{margin-top:8px;font-size:11.5px;color:var(--mna-muted)}',
    '.mna-typing{display:inline-flex;gap:4px;padding:4px 0}.mna-typing i{width:7px;height:7px;border-radius:9px;background:#9FB4C8;animation:mna-b 1s infinite}.mna-typing i:nth-child(2){animation-delay:.15s}.mna-typing i:nth-child(3){animation-delay:.3s}',
    '@keyframes mna-b{0%,80%,100%{opacity:.3;transform:none}40%{opacity:1;transform:translateY(-3px)}}',
    '@media (prefers-reduced-motion:reduce){.mna-panel,.mna-launch{transition:none}.mna-typing i{animation:none}}',
    '.mna-chips{display:flex;flex-wrap:wrap;gap:8px;padding:4px 0 6px}',
    '.mna-chip{border:1px solid var(--mna-line);background:#fff;color:var(--mna-navy);border-radius:999px;padding:7px 12px;font:inherit;font-size:13.5px;font-weight:600;cursor:pointer;text-align:left}',
    '.mna-chip:hover{border-color:var(--mna-blue);color:var(--mna-blue-text)}',
    '.mna-form{display:flex;align-items:flex-end;gap:8px;padding:10px 12px;border-top:1px solid var(--mna-line);background:#fff}',
    '.mna-input{flex:1;min-width:0;resize:none;max-height:120px;border:1px solid var(--mna-line);border-radius:14px;padding:10px 12px;font:inherit;font-size:16px;line-height:1.4;color:var(--mna-ink);background:#fff}',
    '.mna-send{display:grid;place-items:center;flex:none;width:42px;height:42px;border:0;border-radius:999px;background:var(--mna-navy);color:#fff;cursor:pointer}',
    '.mna-send:disabled{opacity:.45;cursor:default}',
    '.mna-foot{margin:0;padding:0 14px 10px;background:#fff;font-size:11.5px;color:var(--mna-muted)}',
    '.mna-foot a{color:var(--mna-blue-text);font-weight:600}',
    '.mna-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}'
  ].join('');

  var ICON_CHAT = '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12Z"/><path d="M8.5 11h.01M12 11h.01M15.5 11h.01"/></svg>';
  var ICON_CLOSE = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
  var ICON_SEND = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';

  // ---- Interface ------------------------------------------------------------
  var root = document.createElement('div');
  root.className = 'mna';
  root.innerHTML =
    '<style>' + css + '</style>' +
    '<button type="button" class="mna-launch" aria-controls="mna-panel" aria-expanded="false">' +
      '<span class="mna-orb">' + ICON_CHAT + '<span class="mna-dot"></span></span>' +
      '<span class="mna-launch-label">Une question&nbsp;?</span><span class="mna-sr"> Ouvrir l’assistant Menentia</span>' +
    '</button>' +
    '<section id="mna-panel" class="mna-panel" role="dialog" aria-modal="false" aria-labelledby="mna-title" aria-hidden="true">' +
      '<header class="mna-head">' +
        '<span class="mna-orb">' + ICON_CHAT + '</span>' +
        '<div><p id="mna-title" class="mna-title">Assistant Menentia</p><p class="mna-sub">Assistant automatique · réponse immédiate</p></div>' +
        '<button type="button" class="mna-close" aria-label="Fermer l’assistant">' + ICON_CLOSE + '</button>' +
      '</header>' +
      '<div class="mna-log" aria-live="polite"></div>' +
      '<form class="mna-form">' +
        '<label class="mna-sr" for="mna-input">Votre question</label>' +
        '<textarea id="mna-input" class="mna-input" rows="1" maxlength="600" placeholder="Posez votre question sur Menentia…"></textarea>' +
        '<button type="submit" class="mna-send" aria-label="Envoyer">' + ICON_SEND + '</button>' +
      '</form>' +
      '<p class="mna-foot">Réponses générées automatiquement, à vérifier pour une décision. <a href="' + link('/contact') + '">Parler à l’équipe</a></p>' +
    '</section>';

  var launch = root.querySelector('.mna-launch');
  var panel = root.querySelector('.mna-panel');
  var log = root.querySelector('.mna-log');
  var form = root.querySelector('.mna-form');
  var input = root.querySelector('.mna-input');
  var send = root.querySelector('.mna-send');

  function link(href) {
    if (!href || href.charAt(0) !== '/') return href;
    if (!BASE) return href;
    var path = href.split('#')[0], frag = href.indexOf('#') > -1 ? href.slice(href.indexOf('#')) : '';
    return BASE + (path === '/' ? 'index' : path.slice(1)) + '.html' + frag;
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }

  // Mise en forme minimale : paragraphes, listes, **gras**, [liens](url) vers Menentia seulement.
  function format(text) {
    var safe = esc(text);
    safe = safe.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    safe = safe.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (m, label, url) {
      var u = url.replace(/&amp;/g, '&');
      var ok = u.charAt(0) === '/' || /^https:\/\/([a-z0-9-]+\.)?menentia\.com(\/|$)/i.test(u);
      if (!ok) return label;
      var internal = u.charAt(0) === '/';
      return '<a href="' + esc(internal ? link(u) : u) + '"' + (internal ? '' : ' target="_blank" rel="noopener"') + '>' + label + '</a>';
    });
    var out = [], list = null, tagName = '';
    var flush = function () { if (list) { out.push('<' + tagName + '>' + list.join('') + '</' + tagName + '>'); list = null; } };
    safe.split(/\n/).forEach(function (line) {
      var ul = line.match(/^\s*[-•*]\s+(.*)$/), ol = line.match(/^\s*\d+[.)]\s+(.*)$/);
      var item = ul || ol;
      if (item) {
        var t = ul ? 'ul' : 'ol';
        if (list && t !== tagName) flush();
        if (!list) { list = []; tagName = t; }
        list.push('<li>' + item[1] + '</li>'); return;
      }
      flush();
      if (line.trim()) out.push('<p>' + line + '</p>');
    });
    flush();
    return out.join('');
  }

  function bubble(role, html) {
    var el = document.createElement('div');
    el.className = 'mna-msg ' + (role === 'user' ? 'mna-user' : 'mna-bot');
    el.innerHTML = html;
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
    return el;
  }

  function linksHtml(links) {
    if (!links || !links.length) return '';
    return '<div class="mna-links">' + links.map(function (l) {
      return '<a href="' + esc(link(l[1])) + '">' + esc(l[0]) + '</a>';
    }).join('') + '</div>';
  }

  function renderHistory() {
    log.innerHTML = '';
    bubble('assistant', format('Bonjour, je suis l’assistant automatique de Menentia. Posez-moi vos questions sur l’application : prix, WhatsApp, agenda, fichier client, votre métier…'));
    history.forEach(function (m) {
      bubble(m.role, m.role === 'user' ? esc(m.content) : format(m.content) + linksHtml(m.links));
    });
    if (!history.length) showSuggestions();
  }

  function showSuggestions() {
    loadKb().then(function (d) {
      if (history.length || log.querySelector('.mna-chips')) return;
      var box = document.createElement('div');
      box.className = 'mna-chips';
      (d.suggestions || []).forEach(function (s) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'mna-chip'; b.textContent = s;
        b.addEventListener('click', function () { ask(s); });
        box.appendChild(b);
      });
      log.appendChild(box);
    });
  }

  function open() {
    root.classList.add('mna-open');
    launch.setAttribute('aria-expanded', 'true');
    panel.setAttribute('aria-hidden', 'false');
    if (!log.childNodes.length) renderHistory();
    setTimeout(function () { input.focus(); }, 60);
  }
  function close() {
    root.classList.remove('mna-open');
    launch.setAttribute('aria-expanded', 'false');
    panel.setAttribute('aria-hidden', 'true');
    launch.focus();
  }

  launch.addEventListener('click', open);
  root.querySelector('.mna-close').addEventListener('click', close);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && root.classList.contains('mna-open')) close(); });
  input.addEventListener('input', function () { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 120) + 'px'; });
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : submit(e); } });
  form.addEventListener('submit', submit);

  function submit(e) {
    if (e) e.preventDefault();
    var q = input.value.trim();
    if (!q || busy) return;
    input.value = ''; input.style.height = 'auto';
    ask(q);
  }

  function ask(q) {
    if (busy) return;
    busy = true; send.disabled = true;
    var chips = log.querySelector('.mna-chips'); if (chips) chips.remove();
    history.push({ role: 'user', content: q });
    bubble('user', esc(q));
    var out = bubble('assistant', '<span class="mna-typing" aria-label="L’assistant écrit"><i></i><i></i><i></i></span>');

    var done = function (text, links, note) {
      history.push({ role: 'assistant', content: text, links: links || undefined });
      save();
      out.innerHTML = format(text) + linksHtml(links) + (note ? '<p class="mna-note">' + esc(note) + '</p>' : '');
      log.scrollTop = log.scrollHeight;
      busy = false; send.disabled = false;
    };
    var fallback = function () {
      loadKb().then(function (d) {
        var hit = localAnswer(q, d);
        if (hit) done(hit.a, hit.links);
        else done(d.unknown || 'Je n’ai pas de réponse précise à cette question. L’équipe Menentia vous répond lors d’une courte démonstration.', [['Parler à l’équipe', '/contact']]);
      });
    };

    if (!API) return fallback();
    streamAnswer(out).then(function (text) {
      if (text && text.trim()) done(text.trim());
      else fallback();
    }).catch(fallback);
  }

  // Service IA : POST { messages }, réponse en flux SSE « data: {"text": "..."} ».
  function streamAnswer(out) {
    var messages = history.slice(-MAX_TURNS * 2).map(function (m) { return { role: m.role, content: m.content }; });
    var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, 45000);
    return fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
      body: JSON.stringify({ messages: messages, page: location.pathname }),
      signal: ctrl ? ctrl.signal : undefined
    }).then(function (res) {
      if (!res.ok || !res.body) throw new Error('assistant ' + res.status);
      var reader = res.body.getReader(), dec = new TextDecoder(), buf = '', text = '', failed = false;
      function pump() {
        return reader.read().then(function (r) {
          if (r.done) { clearTimeout(timer); if (failed && !text) throw new Error('stream'); return text; }
          buf += dec.decode(r.value, { stream: true });
          var parts = buf.split('\n\n'); buf = parts.pop();
          parts.forEach(function (chunk) {
            var ev = 'message', data = '';
            chunk.split('\n').forEach(function (l) {
              if (l.indexOf('event:') === 0) ev = l.slice(6).trim();
              else if (l.indexOf('data:') === 0) data += l.slice(5).trim();
            });
            if (!data) return;
            try { data = JSON.parse(data); } catch (e) { return; }
            if (ev === 'error') { failed = true; return; }
            if (data.text) { text += data.text; out.innerHTML = format(text); log.scrollTop = log.scrollHeight; }
          });
          return pump();
        });
      }
      return pump();
    }, function (err) { clearTimeout(timer); throw err; });
  }

  // Réponses de secours : la fiche de la base dont les mots-clés couvrent le mieux la question.
  function norm(s) {
    return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[\u2019']/g, ' ').replace(/[^a-z0-9+ ]+/g, ' ');
  }
  var STOP = ' le la les un une des de du et ou a au aux en est ce ces mon ma mes vos votre nous vous je il elle on pour par sur avec dans que qui quoi comment combien pas ne se sa son ses y t il ca c d l j qu est-ce '.split(' ');
  function localAnswer(q, d) {
    var words = norm(q).split(/\s+/).filter(function (w) { return w.length > 1 && STOP.indexOf(w) < 0; });
    if (!words.length) return null;
    var best = null, bestScore = 0;
    (d.answers || []).forEach(function (a) {
      var keys = [];
      (a.keys || []).forEach(function (k) { norm(k).split(/\s+/).forEach(function (w) { if (w.length > 1) keys.push(w); }); });
      var score = 0;
      words.forEach(function (w) {
        for (var i = 0; i < keys.length; i++) {
          var k = keys[i];
          if (w === k) { score += 2; break; }
          if (k.length >= 4 && w.length >= 4 && (w.indexOf(k) === 0 || k.indexOf(w) === 0)) { score += 1.5; break; }
        }
      });
      if (score > bestScore) { bestScore = score; best = a; }
    });
    return bestScore >= 1.5 ? best : null;
  }

  document.body.appendChild(root);
})();
