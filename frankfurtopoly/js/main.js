// Frankfurtopoly UI: name choice, lobby, game screen, dialogs, polling.
const $ = (id) => document.getElementById(id);
const eur = (v) => v.toLocaleString('de-DE') + ' €';
const DICE = ['', '⚀', '⚁', '⚂', '⚃', '⚄', '⚅'];
const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
const dot = (n) => `<span class="pdot" style="background:${PLAYER_COLORS[n]}">${n[0]}</span>`;

let S = null;          // last state from the server
let busy = false;
let lastCard = 0;
let dialogField = null; // field shown in the open field dialog (refreshed on updates)
let pollTimer = null;

// ---------------------------------------------------------------- server
async function poll() {
  clearTimeout(pollTimer);
  try {
    const data = await apiCall(null, S && S.version);
    $('offline').classList.add('hidden');
    if (!data.same && !data.error) { S = data; render(); }
  } catch (e) {
    $('offline').classList.remove('hidden');
  }
  pollTimer = setTimeout(poll, document.hidden ? 5000 : 1500);
}

async function send(action, extra = {}) {
  if (busy) return;
  busy = true;
  try {
    const data = await apiCall({ action, ...extra });
    if (data.error) toast(data.error);
    else { S = data; render(); }
  } catch (e) {
    toast('Keine Verbindung zum Server');
  } finally {
    busy = false;
  }
}

function toast(text, ms = 2600) {
  const t = $('toast');
  t.textContent = text;
  t.classList.remove('hidden');
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.add('hidden'), ms);
}

// ---------------------------------------------------------------- screens
function show(id) {
  for (const s of ['screen-name', 'screen-lobby', 'screen-game']) $(s).classList.toggle('hidden', s !== id);
}

function showNameScreen() {
  show('screen-name');
  $('name-buttons').innerHTML = HUMANS
    .map((n) => `<button class="big" data-name="${n}" style="background:${PLAYER_COLORS[n]}">${n}</button>`).join('');
}

function render() {
  if (!S) return;
  renderChat();
  const inGame = (S.phase === 'playing' || S.phase === 'finished') && S.game;
  if (inGame && (S.joined || !S.players.some((p) => p.name === S.me))) renderGame();
  else renderLobby();
}

function renderLobby() {
  show('screen-lobby');
  $('lobby-me').textContent = S.me;
  const me = S.me;
  const inRoom = S.players.some((p) => p.name === me);
  const isHost = S.joined && S.host === me && S.phase === 'lobby';
  const list = S.players.map((p) => `<li>${dot(p.name)} ${p.bot ? '🤖 ' : ''}${p.name}${p.name === S.host ? ' <span class="tag">Host</span>' : ''}`
    + `${p.online ? '' : ' <span class="muted">(offline)</span>'}${p.bot && isHost ? ` <button class="ghost mini" data-act="removebot" data-bot="${p.name}">entfernen</button>` : ''}</li>`).join('');
  let html = '';
  if (S.phase === 'none') {
    html = '<p>Gerade ist keine Runde offen.</p><button class="big" data-act="host">Runde hosten</button>';
  } else if (S.phase === 'lobby') {
    html = `<p><b>${S.host}</b> hostet eine Runde.</p><ul class="plist">${list}</ul>`;
    if (S.joined) {
      if (S.host === me) html += (S.players.length < 4 ? '<button class="ghost" data-act="addbot">🤖 Computergegner hinzufügen</button><br>' : '')
        + `<button class="big" data-act="start" ${S.players.length < 2 ? 'disabled' : ''}>Runde starten</button>`
        + (S.players.length < 2 ? '<p class="muted">Warte auf mindestens einen Mitspieler …</p>' : '');
      else html += '<p class="muted">Warte, bis der Host startet …</p>';
      html += '<button data-act="leave" class="ghost">Verlassen</button>';
    } else {
      html += '<button class="big" data-act="join">Mitspielen</button>';
    }
  } else if (inRoom) {
    html = `<p>Die Runde läuft, du bist dabei.</p><button class="big" data-act="join">Wieder einsteigen</button>`;
  } else {
    html = `<p>Gerade läuft eine Runde (${S.players.map((p) => p.name).join(', ')}).</p>`;
  }
  $('lobby-body').innerHTML = html;
  $('change-name').classList.toggle('hidden', S.joined);
}

function renderGame() {
  show('screen-game');
  const g = S.game;
  Board.update(g);
  const cur = g.order[g.turn];
  const sharedStatus = g.stage === 'over' || S.phase === 'finished' ? 'Spiel beendet' : `${cur} ist dran`;
  $('dice').textContent = g.dice ? DICE[g.dice[0]] + DICE[g.dice[1]] : '';
  $('status').innerHTML = `${dot(cur)} ${sharedStatus}`;
  $('cardview').innerHTML = g.card ? `<div class="card-${g.card.deck}"><b>${g.card.deck === 'chance' ? 'Ereignis' : 'Gemeinschaft'}</b> · ${g.card.who}<br>${esc(g.card.text)}</div>` : '';
  if (g.card && g.card.n !== lastCard) {
    if (lastCard) toast(`${g.card.who}: ${g.card.text}`, 5000);
    lastCard = g.card.n;
  }

  $('players').innerHTML = g.order.map((n) => {
    const flags = [];
    if (g.jail[n] >= 0) flags.push('🔒 JVA');
    if (g.jailCards[n].length) flags.push('🎫×' + g.jailCards[n].length);
    const p = S.players.find((x) => x.name === n);
    if (p && p.bot) flags.push('🤖 Computer');
    else if (!g.out[n] && (!p || !p.online)) flags.push('offline');
    return `<div class="prow${n === cur ? ' current' : ''}${g.out[n] ? ' out' : ''}">${dot(n)}<b>${n}${n === S.me ? ' (du)' : ''}</b>`
      + `<span class="money">${g.out[n] ? 'pleite' : eur(g.money[n])}</span><span class="flags">${flags.join(' ')}</span></div>`;
  }).join('');

  $('actions').innerHTML = actionsHtml(g, cur);
  $('log').innerHTML = g.log.slice(-8).reverse().map((l) => `<div>${esc(l)}</div>`).join('');
  if (dialogField !== null && $('dlg').open) openField(dialogField);
}

function actionsHtml(g, cur) {
  const me = S.me;
  const mine = cur === me && !g.out[me];
  if (S.phase === 'finished') {
    const rank = (S.result || []).map((r, k) => `<li>${k + 1}. ${dot(r.name)} ${r.name} – ${r.out ? 'pleite' : eur(r.worth)}</li>`).join('');
    return `<h3>🏆 ${S.result && S.result[0] ? S.result[0].name : ''} gewinnt!</h3><ol class="plist">${rank}</ol>`
      + (S.host === me ? '<button data-act="lobby">Neue Runde</button><button class="ghost" data-act="end">Schließen</button>'
        : '<button class="ghost" data-act="leave">Verlassen</button>');
  }
  if (!S.joined) return '<p class="muted">Du schaust zu.</p>';
  if (g.out[me]) return '<p>Du bist pleite und schaust zu.</p>';
  let h = '';
  const t = g.trade;
  if (t && t.to === me) {
    h += `<div class="box"><b>${t.from}</b> bietet dir:<br>${tradeText(t.give, t.giveMoney)}<br>und möchte:<br>${tradeText(t.get, t.getMoney)}`
      + '<div class="row"><button data-act="accept">Annehmen</button><button class="ghost" data-act="reject">Ablehnen</button></div></div>';
  } else if (t && t.from === me) {
    h += `<div class="box">Angebot an <b>${t.to}</b> wartet auf Antwort. <button class="ghost" data-act="reject">Zurückziehen</button></div>`;
  }
  switch (g.stage) {
    case 'roll':
      if (!mine) return h + `<p class="muted">Warte auf ${cur} …</p>`;
      if (g.jail[me] >= 0) {
        h += `<p>Du sitzt in der JVA (Versuch ${g.jail[me] + 1} von 3).</p><button data-act="roll">Pasch würfeln</button>`
          + `<button data-act="payjail">50 € zahlen</button>`
          + (g.jailCards[me].length ? '<button data-act="usecard">Freikarte nutzen</button>' : '');
      } else {
        h += `<button class="big" data-act="roll">🎲 Würfeln</button>`;
      }
      return h + '<button class="ghost" data-act="trade">Handeln</button>';
    case 'buy': {
      const f = Board.field(g.buyField);
      if (!mine) return h + `<p class="muted">${cur} überlegt, ob ${cur} ${esc(f.name)} kauft …</p>`;
      return h + `<p>${esc(f.name)} kaufen für <b>${eur(f.price)}</b>?</p>`
        + `<button class="big" data-act="buy" ${g.money[me] < f.price ? 'disabled' : ''}>Kaufen</button>`
        + `<button data-act="decline">Versteigern</button><button class="ghost" data-field="${g.buyField}">Details</button>`;
    }
    case 'auction': {
      const a = g.auction;
      const f = Board.field(a.field);
      h += `<p>Versteigerung: <b>${esc(f.name)}</b> (Wert ${eur(f.price)})<br>Höchstgebot: <b>${a.leader ? eur(a.bid) + ' von ' + a.leader : '–'}</b></p>`;
      if (a.passed.length) h += `<p class="muted">Ausgestiegen: ${a.passed.join(', ')}</p>`;
      if (a.passed.includes(me)) return h + '<p class="muted">Du bist ausgestiegen.</p>';
      h += '<div class="row">' + [10, 50, 100].map((x) => `<button data-act="bid" data-amount="${a.bid + x}" ${a.bid + x > g.money[me] ? 'disabled' : ''}>${eur(a.bid + x)}</button>`).join('') + '</div>';
      return h + (a.leader === me ? '<p class="muted">Du hast das Höchstgebot.</p>' : '<button class="ghost" data-act="pass">Aussteigen</button>');
    }
    case 'debt': {
      const d = g.debts[0];
      if (d.from !== me) return h + `<p class="muted">${d.from} muss ${eur(d.amount)} auftreiben …</p>`;
      return h + `<div class="box warn">Du musst <b>${eur(d.amount)}</b> ${d.to ? 'an ' + d.to : 'an die Bank'} zahlen (${esc(d.why)}). `
        + `Dir fehlen ${eur(d.amount - g.money[me])}. Nimm Hypotheken auf oder verkaufe Häuser.</div>`
        + '<button data-act="props">Meine Felder</button><button class="ghost danger" data-act="bankrupt">Bankrott erklären</button>';
    }
    case 'end':
      if (!mine) return h + `<p class="muted">Warte auf ${cur} …</p>`;
      return h + '<button class="big" data-act="endturn">Zug beenden</button><button class="ghost" data-act="trade">Handeln</button>';
  }
  return h;
}

function tradeText(fields, money) {
  const parts = fields.map((i) => esc(Board.field(i).name));
  if (money) parts.push(eur(money));
  return parts.length ? parts.join(', ') : 'nichts';
}

// ---------------------------------------------------------------- dialogs
function openDialog(html) {
  $('dlg-body').innerHTML = html + '<button class="ghost close" data-close>Schließen</button>';
  if (!$('dlg').open) $('dlg').showModal();
}
function closeDialog() { dialogField = null; $('dlg').close(); }

function openField(i) {
  dialogField = i;
  const f = Board.field(i);
  const g = S.game;
  const pr = g && g.props[i];
  const grp = Board.group(i);
  let h = `<h3 class="fhead" style="border-color:${grp ? grp.color : '#999'}">${Board.icon(f)} ${esc(f.name)}</h3>`;
  if (f.type === 'street') {
    const r = f.rent;
    h += `<table class="rent"><tr><td>Miete</td><td>${eur(r[0])}</td></tr><tr><td>mit ganzer Farbgruppe</td><td>${eur(r[0] * 2)}</td></tr>`
      + [1, 2, 3, 4].map((k) => `<tr><td>mit ${k} ${k === 1 ? 'Haus' : 'Häusern'}</td><td>${eur(r[k])}</td></tr>`).join('')
      + `<tr><td>mit Hotel</td><td>${eur(r[5])}</td></tr><tr><td>Hauspreis</td><td>${eur(grp.house)}</td></tr></table>`;
  } else if (f.type === 'school') {
    h += '<table class="rent">' + [1, 2, 3, 4].map((k) => `<tr><td>${k} ${k === 1 ? 'Schule' : 'Schulen'}</td><td>${eur(25 * 2 ** (k - 1))}</td></tr>`).join('') + '</table>';
  } else if (f.type === 'utility') {
    h += '<p>Ein Werk: 4× Augenzahl<br>Beide Werke: 10× Augenzahl</p>';
  } else if (f.type === 'tax') {
    h += `<p>Zahle ${eur(f.amount)} an die Bank.</p>`;
  }
  if (f.price) h += `<p>Preis ${eur(f.price)} · Hypothek ${eur(f.price / 2)}</p>`;
  if (pr) {
    h += `<p>Besitzer: ${dot(pr.o)} <b>${pr.o}</b>${pr.m ? ' · <span class="warn-text">mit Hypothek</span>' : ''}${pr.h ? ' · ' + (pr.h === 5 ? 'Hotel' : pr.h + ' Häuser') : ''}</p>`;
    if (pr.o === S.me) {
      h += '<div class="row">';
      if (f.type === 'street') h += `<button data-act="build" data-field="${i}">Haus bauen (${eur(grp.house)})</button><button data-act="sell" data-field="${i}">Haus verkaufen</button>`;
      h += pr.m ? `<button data-act="unmortgage" data-field="${i}">Hypothek ablösen (${eur(Math.round(f.price * 0.55))})</button>`
        : `<button data-act="mortgage" data-field="${i}">Hypothek aufnehmen</button>`;
      h += '</div>';
    }
  }
  if (g) {
    const here = g.order.filter((n) => !g.out[n] && g.pos[n] === i);
    if (here.length) h += `<p class="muted">Hier: ${here.join(', ')}</p>`;
  }
  openDialog(h);
}

function openMyProps() {
  dialogField = null;
  const g = S.game;
  const mine = g.props.map((p, i) => (p && p.o === S.me ? i : -1)).filter((i) => i >= 0);
  const h = '<h3>Meine Felder</h3>' + (mine.length ? '<div class="flist">' + mine.map((i) => {
    const f = Board.field(i); const grp = Board.group(i); const pr = g.props[i];
    return `<button class="fitem" data-field="${i}" style="border-left-color:${grp ? grp.color : '#888'}">${Board.icon(f)} ${esc(f.name)}`
      + `<small>${pr.m ? 'Hypothek' : pr.h === 5 ? 'Hotel' : pr.h ? pr.h + ' Häuser' : ''}</small></button>`;
  }).join('') + '</div>' : '<p class="muted">Du besitzt noch nichts.</p>');
  openDialog(h);
}

function openTrade(partner) {
  dialogField = null;
  const g = S.game;
  const others = g.order.filter((n) => n !== S.me && !g.out[n]);
  partner = partner || others[0];
  const list = (owner, name) => g.props.map((p, i) => (p && p.o === owner ? i : -1)).filter((i) => i >= 0)
    .map((i) => `<label class="chk"><input type="checkbox" name="${name}" value="${i}"> ${esc(Board.field(i).name)}${g.props[i].m ? ' (Hyp.)' : ''}</label>`).join('') || '<p class="muted">keine Felder</p>';
  openDialog('<h3>Handeln</h3><div class="row">' + others.map((n) => `<button class="${n === partner ? '' : 'ghost'}" data-act="tradewith" data-to="${n}">${n}</button>`).join('') + '</div>'
    + `<form id="tradeform" data-to="${partner}"><div class="cols"><div><h4>Du gibst</h4>${list(S.me, 'give')}<label>Geld <input type="number" name="giveMoney" min="0" max="${g.money[S.me]}" value="0"></label></div>`
    + `<div><h4>Du bekommst von ${partner}</h4>${list(partner, 'get')}<label>Geld <input type="number" name="getMoney" min="0" max="${g.money[partner]}" value="0"></label></div></div>`
    + '<button class="big" type="submit">Angebot schicken</button></form><p class="muted small">Felder mit Häusern in der Farbgruppe kann man nicht handeln.</p>');
}

function openMenu() {
  dialogField = null;
  const host = S.host === S.me;
  openDialog('<h3>Menü</h3>'
    + (host && S.phase === 'playing' ? '<button data-act="end">Spiel für alle beenden</button>' : '')
    + (S.joined ? '<button class="ghost danger" data-act="leave">Spiel verlassen</button>' : '')
    + '<details><summary>Regeln kurz</summary><p class="small">Wie Monopoly: Start mit 1.500 €, über Los +200 €. Freie Felder kaufen oder versteigern. '
    + 'Mit allen Straßen einer Farbe doppelte Miete und gleichmäßig Häuser bauen (5. Haus = Hotel). Die vier Schulen ersetzen die Bahnhöfe, '
    + 'Mainova und Main-Wasserwerk die Werke. Pasch: nochmal würfeln, dritter Pasch: JVA. Aus der JVA: Pasch, 50 € oder Freikarte. '
    + 'Wer nicht zahlen kann und nichts mehr belasten kann, ist pleite. Der Letzte gewinnt – oder beim vorzeitigen Ende das größte Vermögen.</p></details>');
}

// ---------------------------------------------------------------- chat
// The chat shows itself for a few seconds when opened or when a new message arrives, then hides again.
// Messages are deleted by the server after 5 minutes; the client hides older ones right away.
const CHAT_OPEN_MS = 6000;
const CHAT_PEEK_MS = 3500;
let chatSeen = -1;     // highest message id already shown (-1 = first load, don't pop up)
let chatTimer = null;
let clockOffset = 0;   // server time minus local time, seconds

function chatShow(ms) {
  $('chat').classList.remove('hidden');
  $('chat-dot').classList.add('hidden');
  clearTimeout(chatTimer);
  chatTimer = setTimeout(chatHideIfIdle, ms);
}
function chatHideIfIdle() {
  if (document.activeElement === $('chat-input') && $('chat-input').value) { chatTimer = setTimeout(chatHideIfIdle, 2000); return; }
  $('chat').classList.add('hidden');
}

function renderChat() {
  if (typeof S.now === 'number') clockOffset = S.now - Date.now() / 1000;
  const now = Date.now() / 1000 + clockOffset;
  const msgs = (S.chat || []).filter((m) => now - m.t < 300);
  const list = $('chat-list');
  list.innerHTML = msgs.map((m) => (m.n
    ? `<div class="msg"><b style="color:${PLAYER_COLORS[m.n] || '#fff'}">${m.n}:</b> ${esc(m.x)}</div>`
    : `<div class="msg sys">${esc(m.x)}</div>`)).join('') || '<div class="msg sys">Noch keine Nachrichten.</div>';
  list.scrollTop = list.scrollHeight;
  const top = msgs.length ? msgs[msgs.length - 1].id : 0;
  if (chatSeen >= 0 && top > chatSeen) {
    const fromMe = msgs.filter((m) => m.id > chatSeen).every((m) => m.n === S.me);
    if (!fromMe || !$('chat').classList.contains('hidden')) chatShow($('chat').classList.contains('hidden') ? CHAT_PEEK_MS : CHAT_OPEN_MS);
  }
  chatSeen = Math.max(chatSeen, top);
  $('chat-input').disabled = !S.joined;
  $('chat-input').placeholder = S.joined ? 'Nachricht …' : 'Erst der Runde beitreten';
}
setInterval(() => { if (S) renderChat(); }, 15000);

$('chat-btn').addEventListener('click', () => {
  if ($('chat').classList.contains('hidden')) chatShow(CHAT_OPEN_MS); else $('chat').classList.add('hidden');
});
$('chat').addEventListener('pointerdown', () => chatShow(CHAT_OPEN_MS));
$('chat-input').addEventListener('input', () => chatShow(CHAT_OPEN_MS));
$('chat-form').addEventListener('submit', (e) => {
  e.preventDefault();
  const text = $('chat-input').value.trim();
  if (!text) return;
  $('chat-input').value = '';
  send('chat', { text });
  chatShow(CHAT_OPEN_MS);
});

// ---------------------------------------------------------------- events
const confirmTexts = {
  leave: 'Wirklich verlassen? Im laufenden Spiel gehen dein Geld und deine Felder an die Bank.',
  end: 'Spiel für alle beenden?',
  bankrupt: 'Wirklich Bankrott erklären?',
};

document.addEventListener('click', (e) => {
  const nameBtn = e.target.closest('[data-name]');
  if (nameBtn) { store.set('name', nameBtn.dataset.name); store.del('token'); S = null; show('screen-lobby'); poll(); return; }
  if (e.target.closest('[data-close]')) { closeDialog(); return; }
  const btn = e.target.closest('[data-act]');
  if (btn) {
    const act = btn.dataset.act;
    if (act === 'trade') return openTrade();
    if (act === 'tradewith') return openTrade(btn.dataset.to);
    if (act === 'props') return openMyProps();
    if (confirmTexts[act] && !confirm(confirmTexts[act])) return;
    if (act === 'leave' || act === 'end') closeDialog();
    const extra = {};
    if (btn.dataset.field) extra.field = +btn.dataset.field;
    if (btn.dataset.amount) extra.amount = +btn.dataset.amount;
    if (btn.dataset.bot) extra.bot = btn.dataset.bot;
    send(act, extra);
    return;
  }
  const fb = e.target.closest('[data-field]');
  if (fb) openField(+fb.dataset.field);
});

document.addEventListener('submit', (e) => {
  if (e.target.id !== 'tradeform') return;
  e.preventDefault();
  const fd = new FormData(e.target);
  send('offer', {
    to: e.target.dataset.to,
    give: fd.getAll('give').map(Number), get: fd.getAll('get').map(Number),
    giveMoney: Math.max(0, parseInt(fd.get('giveMoney'), 10) || 0), getMoney: Math.max(0, parseInt(fd.get('getMoney'), 10) || 0),
  });
  closeDialog();
});

$('dlg').addEventListener('close', () => { dialogField = null; });
$('btn-props').addEventListener('click', () => S && S.game && openMyProps());
$('btn-menu').addEventListener('click', () => S && openMenu());
$('change-name').addEventListener('click', (e) => { e.preventDefault(); showNameScreen(); });
document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

// ---------------------------------------------------------------- start
(async function init() {
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
  try {
    await Board.load();
  } catch (e) {
    $('offline').classList.remove('hidden');
    show('screen-lobby');
    return;
  }
  Board.render($('board'), (i) => S && openField(i));
  if (!store.get('name')) showNameScreen();
  else { show('screen-lobby'); poll(); }
})();
