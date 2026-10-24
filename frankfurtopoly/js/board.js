// Board data (board.json, shared with the PHP engine) and board rendering.
const HUMANS = ['Daniel', 'Lea', 'Kira'];
const PLAYER_COLORS = { Daniel: '#2563eb', Lea: '#db2777', Kira: '#7c3aed', Bembel: '#ea580c', 'Handkäs': '#0d9488' };
const ICONS = { go: '➜', chance: '❓', chest: '🎁', tax: '💶', school: '🏫', jail: '🔒', parking: '🅿️', gotojail: '🚓' };
const UTIL_ICONS = { 'Mainova': '⚡', 'Main-Wasserwerk': '💧' };

const Board = {
  data: null,
  tiles: [],

  async load() {
    const res = await fetch('board.json');
    this.data = await res.json();
    return this.data;
  },

  field(i) { return this.data.fields[i]; },
  isProp(i) { return ['street', 'school', 'utility'].includes(this.field(i).type); },
  group(i) { const f = this.field(i); return f.type === 'street' ? this.data.groups[f.group] : null; },
  groupFields(group) { return this.data.fields.map((f, i) => (f.group === group ? i : -1)).filter((i) => i >= 0); },
  icon(f) { return f.type === 'utility' ? UTIL_ICONS[f.name] || '🏭' : ICONS[f.type] || ''; },
  short(name) {
    return name.replace('Landstraße', 'Landstr.').replace('Straße', 'Str.').replace('Gymnasium', 'Gymn.')
      .replace('Grundschule', 'Grundsch.').replace('Bockenheimer', 'Bockenh.').replace('Große', 'Gr.');
  },

  // Grid cell (row, col, side) of field i on an 11×11 grid; Los is bottom right.
  cell(i) {
    if (i <= 10) return { r: 11, c: 11 - i, side: 'bottom' };
    if (i <= 20) return { r: 11 - (i - 10), c: 1, side: 'left' };
    if (i <= 30) return { r: 1, c: 1 + (i - 20), side: 'top' };
    return { r: 1 + (i - 30), c: 11, side: 'right' };
  },

  render(el, onTap) {
    el.innerHTML = '';
    this.tiles = this.data.fields.map((f, i) => {
      const { r, c, side } = this.cell(i);
      const t = document.createElement('div');
      const corner = i % 10 === 0;
      t.className = `tile ${corner ? 'corner' : side} t-${f.type}`;
      t.style.gridRow = r; t.style.gridColumn = c;
      const g = this.group(i);
      t.innerHTML = (g ? `<div class="stripe" style="background:${g.color}"><span class="houses"></span></div>` : '')
        + `<div class="tname">${f.type === 'street' ? '' : `<span class="ticon">${this.icon(f)}</span>`}${this.short(f.name)}</div>`
        + (f.price ? `<div class="tprice">${f.price} €</div>` : '')
        + '<div class="owner"></div>';
      t.addEventListener('click', () => onTap(i));
      el.appendChild(t);
      return t;
    });
    const center = document.createElement('div');
    center.id = 'center';
    center.innerHTML = '<div class="logo">Frankfurt<span>opoly</span></div><div id="dice"></div><div id="status"></div><div id="cardview"></div>';
    el.appendChild(center);
    // Overlay above the tiles: dashed line of the last move and the player tokens (absolutely positioned).
    this.svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    this.svg.id = 'moves';
    el.appendChild(this.svg);
    this.layer = document.createElement('div');
    this.layer.id = 'tokens';
    el.appendChild(this.layer);
    this.tokenEls = {};
    this.el = el;
  },

  // Centre of field i in board pixels.
  center(i) {
    const t = this.tiles[i];
    return [t.offsetLeft + t.offsetWidth / 2, t.offsetTop + t.offsetHeight / 2];
  },

  drawMove(game) {
    const m = game.lastMove;
    const w = this.el.clientWidth;
    this.svg.setAttribute('viewBox', `0 0 ${w} ${this.el.clientHeight}`);
    if (!m || !m.segs.length || game.out[m.who]) { this.svg.innerHTML = ''; return; }
    const pts = [this.center(m.segs[0].from)];
    for (const s of m.segs) {
      if (s.kind === 'jump') { pts.push(this.center(s.to)); continue; }
      const step = s.kind === 'back' ? 39 : 1;
      for (let i = (s.from + step) % 40; ; i = (i + step) % 40) { pts.push(this.center(i)); if (i === s.to) break; }
    }
    const d = pts.map((p) => p.map((v) => v.toFixed(1)).join(',')).join(' ');
    const col = PLAYER_COLORS[m.who] || '#000';
    const sw = Math.max(2.5, w * 0.006);
    this.svg.innerHTML = `<defs><marker id="arrow" viewBox="0 0 10 10" refX="7" refY="5" markerWidth="4" markerHeight="4" orient="auto-start-reverse">`
      + `<path d="M0,0 L10,5 L0,10 z" fill="${col}" stroke="#fff" stroke-width="1.5"/></marker></defs>`
      + `<polyline points="${d}" fill="none" stroke="#fff" stroke-width="${sw * 2.2}" stroke-linejoin="round" stroke-linecap="round" opacity="0.85"/>`
      + `<polyline points="${d}" fill="none" stroke="${col}" stroke-width="${sw}" stroke-dasharray="${sw * 2.5} ${sw * 1.8}" stroke-linejoin="round" marker-end="url(#arrow)"/>`
      + `<circle cx="${pts[0][0]}" cy="${pts[0][1]}" r="${sw * 1.6}" fill="#fff" stroke="${col}" stroke-width="${sw * 0.8}"/>`;
  },

  update(game) {
    const tokensAt = {};
    for (const n of game.order) if (!game.out[n]) (tokensAt[game.pos[n]] ||= []).push(n);
    this.tiles.forEach((t, i) => {
      const pr = game.props[i];
      const owner = t.querySelector('.owner');
      t.style.setProperty('--oc', pr ? PLAYER_COLORS[pr.o] : 'transparent');
      owner.classList.toggle('mortgaged', !!(pr && pr.m));
      t.classList.toggle('owned', !!pr);
      const houses = t.querySelector('.houses');
      if (houses) houses.textContent = !pr || !pr.h ? '' : pr.h === 5 ? '🏨' : '🏠'.repeat(pr.h);
    });
    this.drawMove(game);
    // Tokens: one element per player, moved with a CSS transition; several on one field sit side by side.
    const size = this.el.clientWidth * 0.052;
    for (const n of game.order) {
      let tok = this.tokenEls[n];
      if (!tok) {
        tok = this.tokenEls[n] = document.createElement('div');
        tok.className = 'token';
        tok.textContent = n[0];
        tok.style.background = PLAYER_COLORS[n];
        this.layer.appendChild(tok);
      }
      tok.classList.toggle('hidden', !!game.out[n]);
      if (game.out[n]) continue;
      const here = tokensAt[game.pos[n]];
      const k = here.indexOf(n);
      const [x, y] = this.center(game.pos[n]);
      const dx = (k - (here.length - 1) / 2) * size * 0.75;
      const dy = (k % 2 ? 0.35 : -0.15) * size * (here.length > 1 ? 1 : 0);
      tok.style.width = tok.style.height = size + 'px';
      tok.style.fontSize = size * 0.5 + 'px';
      tok.style.transform = `translate(${x + dx - size / 2}px, ${y + dy - size / 2}px)`;
      tok.classList.toggle('active', n === game.order[game.turn] && game.stage !== 'over');
      tok.classList.toggle('jailed', game.pos[n] === 10 && game.jail[n] >= 0);
    }
    for (const n of Object.keys(this.tokenEls)) if (!game.order.includes(n)) { this.tokenEls[n].remove(); delete this.tokenEls[n]; }
  },
};
