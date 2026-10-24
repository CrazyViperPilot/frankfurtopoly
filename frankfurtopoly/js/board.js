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
        + '<div class="owner"></div><div class="tokens"></div>';
      t.addEventListener('click', () => onTap(i));
      el.appendChild(t);
      return t;
    });
    const center = document.createElement('div');
    center.id = 'center';
    center.innerHTML = '<div class="logo">Frankfurt<span>opoly</span></div><div id="dice"></div><div id="status"></div><div id="cardview"></div>';
    el.appendChild(center);
  },

  update(game) {
    const tokensAt = {};
    for (const n of game.order) if (!game.out[n]) (tokensAt[game.pos[n]] ||= []).push(n);
    this.tiles.forEach((t, i) => {
      const pr = game.props[i];
      const owner = t.querySelector('.owner');
      owner.textContent = pr ? pr.o[0] : '';
      owner.style.background = pr ? PLAYER_COLORS[pr.o] : '';
      owner.classList.toggle('mortgaged', !!(pr && pr.m));
      t.classList.toggle('owned', !!pr);
      const houses = t.querySelector('.houses');
      if (houses) houses.textContent = !pr || !pr.h ? '' : pr.h === 5 ? '🏨' : '🏠'.repeat(pr.h);
      const tok = t.querySelector('.tokens');
      tok.innerHTML = (tokensAt[i] || []).map((n) =>
        `<span class="token${n === game.order[game.turn] ? ' active' : ''}${i === 10 && game.jail[n] >= 0 ? ' jailed' : ''}" style="background:${PLAYER_COLORS[n]}">${n[0]}</span>`).join('');
    });
  },
};
