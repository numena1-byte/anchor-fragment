// The reference second-opinion Worker, run against a fake Workers AI and a fake Vatican page.
// Usage: node tests/worker.mjs
import worker from '../worker/af-semantic-worker.js';

let fail = 0;
const check = (label, cond, detail = '') => { console.log((cond ? '  ok   ' : '  FAIL ') + label + (cond ? '' : '  -> ' + detail)); if (!cond) fail++; };

const PARAS = [
  'The Church receives the revelation of God through Scripture and Tradition, which flow from the same divine wellspring and together form one sacred deposit of the word of God.',
  'Sacred Tradition and Sacred Scripture are bound closely together, and communicate one with the other, and in a certain way merge into a unity and tend toward the same end.',
  'The task of authentically interpreting the word of God has been entrusted exclusively to the living teaching office of the Church, whose authority is exercised in the name of Jesus Christ.',
  'Everything that the inspired authors or sacred writers affirm should be regarded as affirmed by the Holy Spirit, so the books of Scripture teach firmly and faithfully the truth for our salvation.',
];
const html = '<html><body>' + PARAS.map((t, i) => `<p>${i + 1}. ${t}</p>`).join('\n') + '</body></html>';

let fetched = 0;
globalThis.fetch = async (url) => { fetched++; return new Response(html, { status: 200 }); };

// A bag-of-words embedding: texts that share words are close.
const vec = (t) => { const v = new Array(64).fill(0); for (const w of t.toLowerCase().match(/[a-z]+/g) || []) { let h = 0; for (const c of w) h = (h * 31 + c.charCodeAt(0)) % 64; v[h] += 1; } return v; };
let judgeSays = { verdict: 'faithful_translation', reason: 'same meaning' };
const AI = { run: async (model, input) => model.includes('bge') ? { data: input.text.map(vec) } : { response: JSON.stringify(judgeSays) } };
const env = { AI, KEY: 'secret' };
const call = (body, key = 'secret', method = 'POST') => worker.fetch(new Request('https://w.example/', { method, headers: { 'x-key': key, 'content-type': 'application/json' }, body: method === 'POST' ? JSON.stringify(body) : undefined }), env).then(async (r) => ({ status: r.status, j: await r.json().catch(() => null) }));
const doc = { id: 'dei-verbum', title: 'Dei Verbum', short: 'DV', url: 'https://www.vatican.va/dv.html' };

let r = await call({ version: 1, ping: true });
check('ping answers ok with the models and reads nothing', r.j.ok && r.j.ping && r.j.models.embedding === 'bge-m3' && fetched === 0, JSON.stringify(r));
r = await call({ version: 1, ping: true }, 'nope');
check('a wrong key is refused', r.status === 401 && r.j.ok === false);
r = await worker.fetch(new Request('https://w.example/', { method: 'GET' }), env);
check('GET is refused', r.status === 405);
r = await call({ version: 1, quote: 'x'.repeat(30), document: { ...doc, url: 'https://evil.example/dv.html' }, locator: '1' });
check('only vatican.va is read', r.j.ok === false && /vatican\.va/.test(r.j.error) && fetched === 0, JSON.stringify(r.j));
r = await call({ version: 1, quote: 'short', document: doc, locator: '1' });
check('a very short quotation is refused', r.j.ok === false);

r = await call({ version: 1, quote: 'Sacred Tradition and Sacred Scripture are bound closely together and communicate one with the other', document: doc, locator: '2' });
check('the cited paragraph ranks first with signals in thousandths', r.j.ok && r.j.signals.rank === 1 && Number.isInteger(r.j.signals.score) && r.j.signals.score > 500 && r.j.signals.lead > 0 && r.j.signals.better === null, JSON.stringify(r.j));
check('the model reading and names come back', r.j.signals.judge === 'faithful_translation' && r.j.models.embedding === 'bge-m3' && r.j.models.judge === 'llama-3.3-70b', JSON.stringify(r.j));
const before = fetched;
r = await call({ version: 1, quote: 'Sacred Tradition and Sacred Scripture are bound closely together and communicate one with the other', document: doc, locator: '3' });
check('another paragraph cited: rank is lower and the closer one is named', r.j.ok && r.j.signals.rank > 1 && r.j.signals.better === '2', JSON.stringify(r.j));
check('the document is read once and kept for the next request', fetched === before, `${before} -> ${fetched}`);
r = await call({ version: 1, quote: 'Sacred Tradition and Sacred Scripture are bound closely together and communicate one with the other', document: doc, locator: '99' });
check('a paragraph that is not there is an error answer', r.j.ok === false && /99/.test(r.j.error), JSON.stringify(r.j));
judgeSays = 'not json at all';
r = await call({ version: 1, quote: 'Sacred Tradition and Sacred Scripture are bound closely together and communicate one with the other', document: doc, locator: '2' });
check('a model answer that cannot be read gives an empty reading, which never agrees', r.j.ok && r.j.signals.judge === '', JSON.stringify(r.j));

console.log(fail ? `\n${fail} FAILED` : '\nAll passed');
process.exit(fail ? 1 : 0);
