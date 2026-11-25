// Anchor Fragment: second-opinion service. A Cloudflare Worker (Workers AI binding "AI", secret "KEY").
//
// The plugin sends one quotation, the cited document and the paragraph number. This Worker reads the document, finds
// which paragraph is closest in meaning to the quotation (BGE-M3 embeddings), and asks a language model to read the
// cited paragraph against the quotation. It answers with signals; the plugin applies its own rule to them.
//
// Request  POST, JSON, header x-key: <KEY>
//   { "version": 1, "quote": "...", "document": { "id", "title", "short", "url" }, "locator": "25", "language": "en" }
//   { "version": 1, "ping": true }
// Answer
//   { "version": 1, "ok": true, "signals": { "rank", "score", "lead", "judge", "reason", "better" }, "models": { ... } }
//   { "version": 1, "ok": false, "error": "..." }
//
// Limits: only pages on vatican.va are read, and only documents whose paragraphs are numbered "12." at the start of a
// paragraph (the Vatican II texts, most encyclicals). Nothing is stored except a short-lived copy of each document's
// embeddings in memory. The quotation is not logged.

const EMBED_MODEL = '@cf/baai/bge-m3';
const DEFAULT_JUDGE = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
const MAX_QUOTE = 4000;
const CACHE_MS = 60 * 60 * 1000;
const docs = new Map(); // url -> { at, paras, wins, vecs }

const ent = (s) => s.replace(/&#8217;|&rsquo;/g, '’').replace(/&#8216;|&lsquo;/g, '‘').replace(/&#8220;|&ldquo;/g, '“').replace(/&#8221;|&rdquo;/g, '”')
  .replace(/&#8211;|&ndash;/g, '–').replace(/&#8212;|&mdash;/g, '—').replace(/&nbsp;|&#160;/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
  .replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&#(\d+);/g, (m, n) => String.fromCharCode(+n));

// Numbered paragraphs of a document page. A paragraph starts where the next expected number (or the one after, since a
// few documents skip or mislabel one) is followed by a capital letter or an opening quote.
function parseParagraphs(html) {
  const text = ent(html.replace(/<(script|style)[\s\S]*?<\/\1>/gi, ' ')
    .replace(/<\/?(p|div|h\d|li|tr|td|blockquote|center|table|ul|ol)\b[^>]*>|<br\s*\/?>/gi, '\n')
    .replace(/<[^>]*>/g, ''));
  const paras = {};
  let last = 0, cur = null;
  const startAt = (line) => {
    for (const k of [last + 1, last + 2]) {
      const m = new RegExp('(?:^|\\s)(' + k + ')\\s?[.\\u00b7]\\s*(?=[A-Z\\u201c"\\u2018\\u2019(])').exec(line);
      if (m) return { k, at: m.index, len: m[0].length };
    }
    return null;
  };
  for (const raw of text.split('\n')) {
    let line = raw.replace(/\s+/g, ' ').trim();
    if (!line) continue;
    for (let guard = 0; guard < 6; guard++) {
      const st = startAt(line);
      if (!st) break;
      if (st.at > 0 && cur !== null) paras[cur] += ' ' + line.slice(0, st.at).trim();
      last = st.k; cur = last; line = line.slice(st.at + st.len);
      paras[cur] = '';
    }
    if (cur !== null && paras[cur].length < 6000) paras[cur] += (paras[cur] ? ' ' : '') + line;
  }
  return paras;
}

// Long paragraphs are cut into overlapping windows so that one sentence is not drowned by the rest.
function windows(t) {
  if (t.length <= 500) return [t];
  const w = [t.slice(0, 1200)];
  for (let i = 0; i < t.length; i += 300) w.push(t.slice(i, i + 600));
  return w.slice(0, 14);
}

async function embed(env, texts) {
  const out = [];
  for (let i = 0; i < texts.length; i += 40) {
    const r = await env.AI.run(EMBED_MODEL, { text: texts.slice(i, i + 40).map((t) => t.slice(0, 1800)) });
    out.push(...r.data);
  }
  return out;
}

const cos = (a, b) => { let d = 0, x = 0, y = 0; for (let i = 0; i < a.length; i++) { d += a[i] * b[i]; x += a[i] * a[i]; y += b[i] * b[i]; } return d / Math.sqrt(x * y); };

async function loadDoc(env, url) {
  const hit = docs.get(url);
  if (hit && Date.now() - hit.at < CACHE_MS) return hit;
  const r = await fetch(url, { headers: { 'user-agent': UA } });
  if (!r.ok) throw new Error('the document page answered HTTP ' + r.status);
  const paras = parseParagraphs(await r.text());
  const nums = Object.keys(paras).map(Number);
  if (nums.length < 3) throw new Error('could not read numbered paragraphs on that page');
  const wins = [];
  nums.forEach((n) => windows(paras[n]).forEach((w) => wins.push({ n, w })));
  const vecs = await embed(env, wins.map((x) => x.w));
  const d = { at: Date.now(), paras, wins, vecs };
  docs.set(url, d);
  if (docs.size > 12) docs.delete(docs.keys().next().value);
  return d;
}

async function judge(env, model, quote, para) {
  const r = await env.AI.run(model, {
    messages: [
      { role: 'system', content: 'You compare a quotation from a blog post to one numbered paragraph of a Church document. The blogger may have translated the original Latin himself, so the wording can differ a lot from the official English even when the meaning is the same. If the quotation says the opposite of the paragraph, or credits it with something it does not say, it is not a faithful translation. Reply with one line of JSON only: {"verdict":"faithful_translation|loose_paraphrase|related_but_different|unrelated","reason":"at most 15 words"}.' },
      { role: 'user', content: `QUOTATION:\n${quote}\n\nPARAGRAPH:\n${para}` },
    ],
    max_tokens: 80,
  });
  if (r.response && typeof r.response === 'object') return r.response;
  const t = String(r.response || (r.choices && r.choices[0] && r.choices[0].message && r.choices[0].message.content) || '').trim();
  const m = t.match(/\{[\s\S]*\}/);
  try { return JSON.parse(m ? m[0] : t); } catch (e) { return { verdict: '', reason: '' }; }
}

const modelName = (m) => m.split('/').pop().replace(/-instruct.*$/, '');
const reply = (o, status = 200) => Response.json({ version: 1, ...o }, { status });

async function check(env, body) {
  const quote = String(body.quote || '').replace(/\s+/g, ' ').trim();
  const doc = body.document || {};
  const loc = parseInt(body.locator, 10);
  if (quote.length < 20 || quote.length > MAX_QUOTE) return reply({ ok: false, error: 'the quotation must be 20 to ' + MAX_QUOTE + ' characters' });
  let host = '';
  try { const u = new URL(doc.url); host = u.protocol === 'https:' ? u.hostname : ''; } catch (e) { /* falls through */ }
  if (!(host === 'vatican.va' || host.endsWith('.vatican.va'))) return reply({ ok: false, error: 'only pages on vatican.va are read' });
  if (!Number.isInteger(loc) || loc < 1) return reply({ ok: false, error: 'the paragraph number must be a whole number' });

  const d = await loadDoc(env, doc.url);
  if (!d.paras[loc]) return reply({ ok: false, error: `paragraph ${loc} was not found (${Object.keys(d.paras).length} paragraphs read)` });
  const [qv] = await embed(env, [quote]);

  // The best window of each paragraph stands for it.
  const per = {}, bestWin = {};
  d.wins.forEach((x, i) => { const s = cos(qv, d.vecs[i]); if (per[x.n] === undefined || s > per[x.n]) { per[x.n] = s; bestWin[x.n] = x.w; } });
  const ranked = Object.entries(per).map(([n, s]) => ({ n: +n, s })).sort((a, b) => b.s - a.s);
  const at = ranked.findIndex((x) => x.n === loc);
  const rank = at + 1;
  const score = ranked[at].s;
  const lead = ranked.length > 1 ? ranked[0].s - ranked[1].s : 1;

  // The model reads the cited paragraph. A long one is shown as its opening and its closest passage.
  const model = env.JUDGE_MODEL || DEFAULT_JUDGE;
  const para = d.paras[loc];
  const view = para.length <= 1800 ? para : para.slice(0, 300) + ' [...] ' + bestWin[loc];
  const j = await judge(env, model, quote, view);
  const ms = (x) => Math.max(0, Math.min(1000, Math.round(x * 1000)));
  return reply({
    ok: true,
    signals: { rank, score: ms(score), lead: ms(lead), judge: String(j.verdict || ''), reason: String(j.reason || '').slice(0, 160), better: rank > 1 ? String(ranked[0].n) : null },
    models: { embedding: 'bge-m3', judge: modelName(model) },
  });
}

export default {
  async fetch(req, env) {
    if (req.method !== 'POST') return new Response('Anchor Fragment second-opinion service. POST only.', { status: 405 });
    if (!env.KEY) return reply({ ok: false, error: 'the Worker has no KEY secret' }, 401);
    if ((req.headers.get('x-key') || '').trim() !== String(env.KEY).trim()) return reply({ ok: false, error: 'wrong key' }, 401);
    let body;
    try { body = await req.json(); } catch (e) { return reply({ ok: false, error: 'the request was not JSON' }, 400); }
    if (body.ping) return reply({ ok: true, ping: true, models: { embedding: 'bge-m3', judge: modelName(env.JUDGE_MODEL || DEFAULT_JUDGE) } });
    try { return await check(env, body); } catch (e) { return reply({ ok: false, error: String(e && e.message || e).slice(0, 160) }); }
  },
};
