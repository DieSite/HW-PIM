/**
 * helpers.js – shared utilities so every competitor spec is robust:
 *  - never hangs (short, swallowed timeouts)
 *  - accepts cookie walls
 *  - normalises a scraped price string
 *
 * Design rule: a spec must ALWAYS finish quickly and record SOMETHING –
 * either a real price or an honest label – it must never throw or hang on a
 * missing selector against a live third-party site.
 */

const PRICE_RE = /€\s*[\d][\d.\s]*,\s?\d{2}|€\s*[\d][\d.\s]*,-|€\s*[\d][\d.]*/;

/** Normalise a raw scraped string to "€ 273,00" style, or null if no price. */
function normalizePrice(raw) {
  if (!raw) return null;
  const m = String(raw).replace(/ /g, ' ').match(PRICE_RE);
  if (!m) return null;
  let s = m[0].replace(/\s+/g, ' ').trim();
  // collapse "€273,00" -> "€ 273,00"
  s = s.replace(/^€\s*/, '€ ');
  return s;
}

/** Click the first cookie-accept control we can find. Best-effort, swallowed.
 *  Tries known consent-plugin selectors first, then role=button/link, then text. */
async function acceptCookies(page) {
  const selectors = [
    '#cookie_action_close_header',                                   // GDPR Cookie Consent
    '.cky-btn-accept', '[data-cky-tag="accept-button"]',            // CookieYes
    '.cmplz-accept', '.cc-allow', '.cc-dismiss',                    // Complianz / cookieconsent
    '#CybotCookiebotDialogBodyLevelButtonLevelOptinAllowAll',
    '#CybotCookiebotDialogBodyButtonAccept',                        // Cookiebot
    '#onetrust-accept-btn-handler',                                 // OneTrust
    '.js-cookie-accept-all', '.cookie-accept', '.accept-cookies',
    'button[name="accept"]',
  ];
  for (const s of selectors) {
    try {
      const l = page.locator(s).first();
      if (await l.count()) { await l.click({ timeout: 1500 }); await page.waitForTimeout(300); return true; }
    } catch { /* next */ }
  }
  const names = [/alles accepteren/i, /accepteer alles/i, /alle cookies/i, /accepteer/i,
    /accepteren/i, /akkoord/i, /alles toestaan/i, /accept all/i, /ik ga akkoord/i, /toestaan/i];
  for (const re of names) {
    for (const role of ['button', 'link']) {
      try { await page.getByRole(role, { name: re }).first().click({ timeout: 1000 }); await page.waitForTimeout(300); return true; } catch {}
    }
    try { await page.getByText(re).first().click({ timeout: 800 }); await page.waitForTimeout(300); return true; } catch {}
  }
  return false;
}

/** Click a custom-styled radio/checkbox via its <label for=id>. Swallowed. */
async function clickLabelById(page, id, timeout = 5000) {
  const lab = page.locator(`label[for="${id}"]`);
  try {
    await lab.first().waitFor({ state: 'visible', timeout });
    await lab.first().click({ timeout: 3000 });
    return true;
  } catch {
    try { await page.locator(`#${id}`).check({ force: true, timeout: 2000 }); return true; } catch {}
    return false;
  }
}

/**
 * True als een maat-input de waarde toestaat volgens zijn eigen min/max-
 * attributen. Zonder attributen (of bij een fout) geven we true terug: dan
 * moet de configurator zelf valideren. Belangrijk voor de grote dubbele
 * maten (tot 3800 mm) — een JS-gezette waarde buiten het bereik zou anders
 * een niet-bestaande prijs opleveren.
 */
async function inputAllowsValue(locator, value) {
  try {
    const { min, max } = await locator.first().evaluate(el => ({ min: el.min, max: el.max }));
    if (min !== '' && Number(value) < Number(min)) return false;
    if (max !== '' && Number(value) > Number(max)) return false;
    return true;
  } catch { return true; }
}

/**
 * Select an <option> whose visible text matches a RegExp.
 * (Playwright's selectOption does NOT accept a RegExp for label.)
 */
async function selectOptionByText(selectLocator, re, timeout = 5000) {
  try {
    const sel = selectLocator.first();
    await sel.waitFor({ state: 'attached', timeout });
    const texts = await sel.locator('option').allTextContents();
    const match = texts.find(t => re.test(t));
    if (!match) return false;
    await sel.selectOption({ label: match.trim() });
    return true;
  } catch { return false; }
}

module.exports = { normalizePrice, acceptCookies, clickLabelById, selectOptionByText, inputAllowsValue, PRICE_RE };
