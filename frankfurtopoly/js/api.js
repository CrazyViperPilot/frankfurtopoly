// Frankfurtopoly API client. Frontend and api.php share the origin (phpexpert.de/games/frankfurtopoly/).
// Settings live in localStorage under the "frankfurtopoly:" prefix.
const store = {
  get(k, d = '') { try { return localStorage.getItem('frankfurtopoly:' + k) ?? d; } catch (e) { return d; } },
  set(k, v) { try { localStorage.setItem('frankfurtopoly:' + k, v); } catch (e) { /* private mode */ } },
  del(k) { try { localStorage.removeItem('frankfurtopoly:' + k); } catch (e) { /* ignore */ } },
};

async function apiCall(body, version) {
  const headers = { 'X-FP-Name': store.get('name'), 'X-FP-Token': store.get('token') };
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 8000);
  try {
    const url = 'api.php' + (version ? '?v=' + version : '');
    const res = await fetch(url, {
      method: body ? 'POST' : 'GET',
      headers: body ? { ...headers, 'Content-Type': 'text/plain' } : headers,
      body: body ? JSON.stringify(body) : undefined,
      signal: ctrl.signal,
      cache: 'no-store',
    });
    const data = await res.json();
    if (!res.ok && !data.error) data.error = 'Serverfehler ' + res.status;
    if (data.token) store.set('token', data.token);
    return data;
  } finally {
    clearTimeout(timer);
  }
}
