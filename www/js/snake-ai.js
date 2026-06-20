/**
 * Snake AI — sdílená logika chatu pro widget i full-page verzi.
 *
 * Použití:
 *   SnakeAI.init({ messagesEl, inputEl, sendEl, [newChatEl], [historyEl], [apiUrl] })
 */

const SnakeAI = (() => {

  // ── Konfigurace ─────────────────────────────────────────────────────────────
  const CONFIG = {
    apiUrl:       'api/chat.php',
    welcomeMsg:   'Kept you waiting, huh? 🐍\nTady Otacon — zeptej se mě na cokoliv o Metal Gear univerzu, hrách nebo merchandise!',
    botName:      'Codec (Frequency 140.85)',
    botAvatar:    '🐍',
    userAvatar:   '👤',
    maxSessions:  20,
    storageKey:   'mgs-sessions',
    currentKey:   'mgs-current',
  };

  // ── init ────────────────────────────────────────────────────────────────────
  function init(opts) {
    const messagesEl = opts.messagesEl;
    const inputEl    = opts.inputEl;
    const sendEl     = opts.sendEl;
    const newChatEl  = opts.newChatEl  ?? null;
    const historyEl  = opts.historyEl  ?? null;

    let history  = [];
    let busy     = false;
    let sessions = JSON.parse(localStorage.getItem(CONFIG.storageKey) || '[]');

    // ── obnov aktivní konverzaci, nebo zobraz uvítání ────────────────────────
    const saved = JSON.parse(localStorage.getItem(CONFIG.currentKey) || 'null');
    if (saved && saved.length) {
      history = saved;
      appendMsg('bot', CONFIG.welcomeMsg);
      history.forEach(h => appendMsg(h.role === 'user' ? 'user' : 'bot', h.text));
    } else {
      appendMsg('bot', CONFIG.welcomeMsg);
    }

    // ── suggestion buttons ───────────────────────────────────────────────────
    document.querySelectorAll('.suggestion').forEach(btn => {
      btn.addEventListener('click', () => send(btn.textContent.trim()));
    });

    // ── new conversation ─────────────────────────────────────────────────────
    newChatEl?.addEventListener('click', () => {
      if (history.length) saveSession();
      resetChat();
    });

    // ── send ─────────────────────────────────────────────────────────────────
    sendEl.addEventListener('click', () => send());
    inputEl.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });

    // auto-resize textarea
    inputEl.addEventListener('input', () => {
      inputEl.style.height = 'auto';
      inputEl.style.height = Math.min(inputEl.scrollHeight, 120) + 'px';
    });

    // ── render history sidebar ───────────────────────────────────────────────
    if (historyEl) renderHistory();

    // ── funkce ───────────────────────────────────────────────────────────────

    function send(text) {
      text = (text ?? inputEl.value).trim();
      if (!text || busy) return;

      appendMsg('user', text);
      history.push({ role: 'user', text });
      inputEl.value = '';
      inputEl.style.height = 'auto';
      setLoading(true);

      const typingId = appendTyping();

      fetch(CONFIG.apiUrl, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ message: text, history: history.slice(0, -1) }),
      })
        .then(r => r.json())
        .then(data => {
          removeEl(typingId);
          const reply = data.reply || 'Omlouvám se, nepodařilo se získat odpověď.';
          appendMsg('bot', reply);
          history.push({ role: 'model', text: reply });
          persistCurrent();
          if (historyEl) renderHistory();
        })
        .catch(err => {
          removeEl(typingId);
          appendMsg('bot', 'Chyba připojení. Zkus to znovu.');
          fetch(CONFIG.apiUrl.replace('chat.php', 'log-error.php'), {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ message: text, error: String(err) }),
          }).catch(() => {});
        })
        .finally(() => setLoading(false));
    }

    function appendMsg(role, text) {
      const isBot = role === 'bot';
      const time  = new Date().toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
      const div   = document.createElement('div');
      div.className = `msg ${role}`;

      // full-page vs widget — full-page má msg-content wrapper se senderem a časem
      if (messagesEl.closest('.chat-main')) {
        div.innerHTML = `
          <div class="msg-avatar">${isBot ? CONFIG.botAvatar : CONFIG.userAvatar}</div>
          <div class="msg-content">
            <div class="msg-sender">${isBot ? CONFIG.botName : 'Ty'}</div>
            <div class="msg-bubble">${isBot ? renderMd(text) : escHtml(text)}</div>
            <div class="msg-time">${time}</div>
          </div>`;
      } else {
        div.innerHTML = `
          <div class="msg-avatar">${isBot ? CONFIG.botAvatar : CONFIG.userAvatar}</div>
          <div>
            <div class="msg-bubble">${isBot ? renderMd(text) : escHtml(text)}</div>
          </div>`;
      }

      messagesEl.appendChild(div);
      messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function appendTyping() {
      const id  = 'typing-' + Date.now();
      const div = document.createElement('div');
      div.id = id;

      if (messagesEl.closest('.chat-main')) {
        div.className = 'typing-wrap';
        div.innerHTML = `
          <div class="msg-avatar" style="width:32px;height:32px;border-radius:50%;background:#1a1a1a;border:1px solid #2a2a2a;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0;">${CONFIG.botAvatar}</div>
          <div class="typing-bubble"><span></span><span></span><span></span></div>`;
      } else {
        div.className = 'msg bot';
        div.innerHTML = `
          <div class="msg-avatar">${CONFIG.botAvatar}</div>
          <div><div class="msg-bubble"><div class="typing-indicator"><span></span><span></span><span></span></div></div></div>`;
      }

      messagesEl.appendChild(div);
      messagesEl.scrollTop = messagesEl.scrollHeight;
      return id;
    }

    function removeEl(id)      { document.getElementById(id)?.remove(); }
    function setLoading(state) { busy = inputEl.disabled = sendEl.disabled = state; }
    function persistCurrent()  { localStorage.setItem(CONFIG.currentKey, JSON.stringify(history)); }

    function resetChat() {
      history = [];
      localStorage.removeItem(CONFIG.currentKey);
      messagesEl.innerHTML = '';
      appendMsg('bot', 'Nová konverzace zahájena. Na co se chceš zeptat?');
    }

    function saveSession() {
      if (!history.length) return;
      const firstMsg = history.find(h => h.role === 'user')?.text ?? '';
      const label    = firstMsg.length > 45 ? firstMsg.slice(0, 45) + '…' : firstMsg;
      sessions.unshift({ label, ts: Date.now(), history: [...history] });
      sessions = sessions.slice(0, CONFIG.maxSessions);
      localStorage.setItem(CONFIG.storageKey, JSON.stringify(sessions));
      if (historyEl) renderHistory();
    }

    function renderHistory() {
      if (!historyEl) return;
      historyEl.innerHTML = sessions.map((s, i) => `
        <div class="history-item" data-idx="${i}">
          <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
          ${escHtml(s.label)}
        </div>`).join('');

      historyEl.querySelectorAll('.history-item').forEach(el => {
        el.addEventListener('click', () => loadSession(+el.dataset.idx));
      });
    }

    function loadSession(idx) {
      const s = sessions[idx];
      if (!s) return;
      history = [...s.history];
      persistCurrent();
      messagesEl.innerHTML = '';
      appendMsg('bot', CONFIG.welcomeMsg);
      history.forEach(h => appendMsg(h.role === 'user' ? 'user' : 'bot', h.text));
    }
  }

  // ── markdown renderer ──────────────────────────────────────────────────────
  function renderMd(text) {
    const lines  = text.split('\n');
    const out    = [];
    let inList   = false;
    let listTag  = 'ul';

    for (const line of lines) {
      const bullet   = line.match(/^[\*\-]\s+(.+)/);
      const numbered = line.match(/^\d+\.\s+(.+)/);

      if (bullet) {
        if (!inList) { listTag = 'ul'; out.push('<ul>'); inList = true; }
        out.push('<li>' + inlineFmt(bullet[1]) + '</li>');
        continue;
      }
      if (numbered) {
        if (!inList) { listTag = 'ol'; out.push('<ol>'); inList = true; }
        out.push('<li>' + inlineFmt(numbered[1]) + '</li>');
        continue;
      }
      if (inList) { out.push(`</${listTag}>`); inList = false; }

      if (line.trim() === '') { out.push('<br>'); continue; }
      out.push('<p>' + inlineFmt(line) + '</p>');
    }

    if (inList) out.push(`</${listTag}>`);
    return out.join('');
  }

  function inlineFmt(str) {
    return escHtml(str)
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g,     '<em>$1</em>')
      .replace(/`(.+?)`/g,       '<code>$1</code>');
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;');
  }

  return { init, CONFIG };

})();
