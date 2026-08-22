#!/usr/bin/env node
// Zero-click audit of a page carrying the Agreely banner, in a real headless browser.
//
//   npm i -D playwright && npx playwright install chromium
//   node zero-click-check.mjs https://your-site.example
//
// It loads the page, CLICKS NOTHING, and reports what actually happened:
//
//   1. Is the Agreely loader present, and is it SYNCHRONOUS? (an `async` loader
//      is the single most common silent misinstall: the banner still appears,
//      trackers still leak.)
//   2. Did Consent Mode v2 install a default-deny signal before anything else ran?
//   3. Which cookies and storage keys exist before any interaction?
//   4. Does the dynamic-injection guard actually neutralize an injected tracker?
//   5. Is the banner serving (active:true) at all?
//
// HONEST SCOPE: this is a point-in-time observation of ONE url, exactly like
// Agreely's own scan. It is not a guarantee of runtime behaviour and it is not a
// compliance certificate. Exit 0 clean, 1 findings, 2 usage.

import { chromium } from 'playwright';

const url = process.argv[2];
if (!url) {
  console.error('usage: node zero-click-check.mjs <url>');
  process.exit(2);
}

const findings = [];
const note = (s) => console.log('  ' + s);

const browser = await chromium.launch({ headless: true });   // ALWAYS headless.
const ctx = await browser.newContext();
try {
  const page = await ctx.newPage();
  const seen = [];
  page.on('request', (r) => seen.push(r.url()));
  await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(1500);

  console.log(`\nZero-click audit: ${url}\n`);

  // --- 1. the tag -----------------------------------------------------------
  const tag = await page.evaluate(() => {
    const s = [...document.querySelectorAll('script[src]')]
      .find((x) => /\/e\/v[\d.]+\/loader(\.min)?\.js/.test(x.src));
    if (!s) return null;
    return {
      src: s.src,
      siteKey: s.getAttribute('data-agreely') || '',
      async: s.async || s.hasAttribute('async'),
      defer: s.defer || s.hasAttribute('defer'),
      firstScript: document.querySelector('script[src]') === s,
    };
  });
  if (!tag) {
    findings.push('The Agreely loader tag is not on this page at all.');
    note('loader tag              MISSING');
  } else {
    note('loader tag              ' + tag.src);
    note('site key                ' + (tag.siteKey || '(EMPTY: data-agreely is missing)'));
    note('first <script src>      ' + (tag.firstScript ? 'yes' : 'no (put it first in <head>)'));
    note('async / defer           ' + (tag.async || tag.defer ? 'YES  <-- BUG' : 'no (correct)'));
    if (!tag.siteKey) findings.push('data-agreely is empty, so no config is ever fetched.');
    if (tag.async || tag.defer) {
      findings.push(
        'The loader carries async/defer. It then runs AFTER other tags, so the ' +
        'Consent Mode default-deny and the injection guard are installed too late ' +
        'and trackers can fire on zero click. Remove the attribute.'
      );
    }
    if (!tag.firstScript) {
      findings.push('The loader is not the first script; anything before it boots unguarded.');
    }
  }

  // --- 2. Consent Mode v2 default ------------------------------------------
  const cm = await page.evaluate(() => ({
    installed: !!window.__agreelyConsentDefault,
    gtag: typeof window.gtag === 'function',
    entries: (window.dataLayer || [])
      .map((a) => Array.from(a))
      .filter((a) => a[0] === 'consent'),
  }));
  note('consent-default flag    ' + cm.installed);
  note('consent calls in queue  ' + JSON.stringify(cm.entries.map((e) => [e[1], e[2]])));
  if (!cm.installed) {
    findings.push('No Consent Mode v2 default-deny was installed before the page settled.');
  } else {
    const def = cm.entries.find((e) => e[1] === 'default');
    const granted = def ? Object.entries(def[2] || {})
      .filter(([k, v]) => v === 'granted' && k !== 'security_storage')
      .map(([k]) => k) : [];
    if (granted.length) findings.push('Consent Mode defaults are GRANTED for: ' + granted.join(', '));
    const upd = cm.entries.find((e) => e[1] === 'update');
    if (upd) findings.push('A consent UPDATE fired without any interaction: ' + JSON.stringify(upd[2]));
  }

  // --- 3. state before any click -------------------------------------------
  const cookies = await ctx.cookies();
  const nonConsent = cookies.filter((c) => c.name !== 'agreely_consent');
  const storage = await page.evaluate(() => ({
    local: Object.keys(localStorage || {}),
    session: Object.keys(sessionStorage || {}),
  }));
  note('cookies at rest         ' + (nonConsent.map((c) => c.name).join(', ') || '(none)'));
  note('localStorage at rest    ' + (storage.local.join(', ') || '(none)'));
  note('sessionStorage at rest  ' + (storage.session.join(', ') || '(none)'));
  // Count by NATURE, never collapse a storage key into "a cookie" (Agreely's own
  // reports are careful about this and so should yours).
  if (nonConsent.length) {
    findings.push(`${nonConsent.length} cookie(s) present before any consent: ` +
      nonConsent.map((c) => c.name).join(', ') + '. Confirm each is strictly necessary.');
  }
  if (storage.local.length) {
    findings.push(`${storage.local.length} localStorage key(s) before any consent: ` +
      storage.local.join(', ') + '.');
  }

  // --- 4. the injection guard, tested for real ------------------------------
  const guard = await page.evaluate(() => {
    const probe = (src, id) => {
      const s = document.createElement('script');
      s.src = src; s.id = id;
      document.head.appendChild(s);
      const el = document.getElementById(id);
      const t = el ? el.type : '';
      if (el) el.remove();
      return t || '(executable)';
    };
    return {
      knownTracker: probe('https://www.google-analytics.com/analytics.js', '__agr_probe_a'),
      unrelated: probe('https://cdn.jsdelivr.net/npm/nothing.js', '__agr_probe_b'),
    };
  });
  note('injected GA tag becomes ' + guard.knownTracker + '  (want: text/plain)');
  note('injected non-tracker    ' + guard.unrelated + '  (want: executable)');
  if (guard.knownTracker !== 'text/plain') {
    findings.push('The dynamic-injection guard did NOT neutralize an injected google-analytics.com script.');
  }

  // --- 5. is the banner actually serving? -----------------------------------
  const cfgReq = seen.find((u) => /\/e\/v1\/config\//.test(u));
  if (cfgReq) {
    const cfg = await page.evaluate(async (u) => {
      try { return await (await fetch(u)).json(); } catch { return null; }
    }, cfgReq);
    note('config active           ' + (cfg ? cfg.active : '(unreadable)'));
    note('config version          ' + (cfg ? cfg.configVersion : '?'));
    if (cfg && cfg.active !== true) {
      findings.push(
        'The edge serves active:false, so the client renders NOTHING. Usually: the ' +
        'banner has never been PUBLISHED (installing the tag and verifying the ' +
        'domain is not enough), or the domain is unverified/removed.'
      );
    }
  } else {
    note('config fetch            never happened');
  }

  // --- verdict --------------------------------------------------------------
  console.log('\n' + (findings.length ? `  ${findings.length} finding(s):` : '  No findings.'));
  findings.forEach((f, i) => console.log(`   ${i + 1}. ${f}`));
  console.log(
    '\n  Honest scope: one page, one moment, one browser. This documents a posture;' +
    '\n  it does not guarantee runtime behaviour and it certifies nothing.\n'
  );
} catch (err) {
  console.error('  audit failed:', err.message);
  findings.push('audit error: ' + err.message);
} finally {
  await ctx.close();
  await browser.close();      // always, including on failure.
}

process.exit(findings.length ? 1 : 0);
