/**
 * http.js – lichtgewicht HTTP-wrapper voor catalog-volledig.
 *
 * Gebruikt Node's ingebouwde https/http modules. Ondersteunt redirects,
 * JSON-parsing, configureerbare timeout en concurrentielimiet.
 */

const https = require('https');
const http  = require('http');
const { URL } = require('url');

const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

const DEFAULT_HEADERS = {
  'User-Agent': UA,
  'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
  'Accept-Language': 'nl-NL,nl;q=0.9',
};

/**
 * Eenvoudige HTTP GET met redirects en timeout.
 * @returns {Promise<{status: number, text: string, headers: object}>}
 */
async function get(url, { timeout = 20000, maxRedirects = 6, extraHeaders = {} } = {}) {
  return new Promise((resolve, reject) => {
    let parsed;
    try { parsed = new URL(url); } catch (e) { return reject(e); }
    const lib = parsed.protocol === 'https:' ? https : http;
    const options = {
      hostname: parsed.hostname,
      port: parsed.port || undefined,
      path: parsed.pathname + parsed.search,
      headers: { ...DEFAULT_HEADERS, ...extraHeaders },
      timeout,
    };
    const req = lib.request(options, res => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        if (maxRedirects <= 0) return reject(new Error(`Too many redirects for ${url}`));
        const next = new URL(res.headers.location, url).href;
        res.resume();
        return get(next, { timeout, maxRedirects: maxRedirects - 1, extraHeaders })
          .then(resolve, reject);
      }
      if (res.statusCode >= 400) {
        res.resume();
        return reject(new Error(`HTTP ${res.statusCode} for ${url}`));
      }
      const chunks = [];
      res.on('data', c => chunks.push(c));
      res.on('end', () => resolve({
        status: res.statusCode,
        text: Buffer.concat(chunks).toString('utf8'),
        headers: res.headers,
      }));
      res.on('error', reject);
    });
    req.on('timeout', () => { req.destroy(); reject(new Error(`Timeout: ${url}`)); });
    req.on('error', reject);
    req.end();
  });
}

/** GET en parse als JSON. */
async function getJson(url, opts) {
  const { text } = await get(url, opts);
  return JSON.parse(text);
}

/** GET en geef alleen de tekst terug. */
async function getText(url, opts) {
  const { text } = await get(url, opts);
  return text;
}

/** POST met form-encoded body. */
async function post(url, form, { timeout = 15000, extraHeaders = {} } = {}) {
  return new Promise((resolve, reject) => {
    let parsed;
    try { parsed = new URL(url); } catch (e) { return reject(e); }
    const body = Object.entries(form).map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
    const lib = parsed.protocol === 'https:' ? https : http;
    const options = {
      hostname: parsed.hostname,
      port: parsed.port || undefined,
      path: parsed.pathname + parsed.search,
      method: 'POST',
      headers: {
        ...DEFAULT_HEADERS,
        ...extraHeaders,
        'Content-Type': 'application/x-www-form-urlencoded',
        'Content-Length': Buffer.byteLength(body),
      },
      timeout,
    };
    const req = lib.request(options, res => {
      const chunks = [];
      res.on('data', c => chunks.push(c));
      res.on('end', () => resolve({
        status: res.statusCode,
        text: Buffer.concat(chunks).toString('utf8'),
      }));
      res.on('error', reject);
    });
    req.on('timeout', () => { req.destroy(); reject(new Error(`Post timeout: ${url}`)); });
    req.on('error', reject);
    req.write(body);
    req.end();
  });
}

/** Simpele concurrentie-limiter: maximaal `limit` gelijktijdige Promise's. */
function createQueue(limit = 8) {
  let running = 0;
  const queue = [];
  function next() {
    while (running < limit && queue.length) {
      running++;
      const { fn, resolve, reject } = queue.shift();
      fn().then(v => { running--; resolve(v); next(); }, e => { running--; reject(e); next(); });
    }
  }
  return function enqueue(fn) {
    return new Promise((resolve, reject) => {
      queue.push({ fn, resolve, reject });
      next();
    });
  };
}

const sleep = ms => new Promise(r => setTimeout(r, ms));

module.exports = { get, getJson, getText, post, createQueue, sleep };
