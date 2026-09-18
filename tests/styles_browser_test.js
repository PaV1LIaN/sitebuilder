#!/usr/bin/env node
'use strict';

/**
 * Browser CSS contract checks using real entry-point stylesheet order and small
 * representative PHP-output fixtures. This is not an end-to-end Bitrix test.
 *
 * node tests/styles_browser_test.js
 * node tests/styles_browser_test.js --baseline /path/to/original --report /tmp/styles.json
 * CHROMIUM_EXECUTABLE_PATH=/path/to/chromium node tests/styles_browser_test.js
 *
 * --baseline makes computed-style differences fail as well. During a deliberate
 * visual change, --allow-differences reports those differences without failing.
 */
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const args = process.argv.slice(2);
function option(name, fallback) {
    const index = args.indexOf(name);
    if (index === -1) return fallback;
    if (!args[index + 1] || args[index + 1].startsWith('--')) throw new Error(`${name} needs a value`);
    return args[index + 1];
}
const projectRoot = path.resolve(option('--root', path.join(__dirname, '..')));
const baselineRoot = option('--baseline', null);
const reportPath = option('--report', null);
const screenshots = option('--screenshots', null);
const widths = [1440, 768, 390];
const properties = [
    'display', 'position', 'width', 'maxWidth', 'minWidth', 'height', 'maxHeight',
    'overflowX', 'overflowY', 'gridTemplateColumns', 'gap', 'alignItems',
    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
    'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
    'fontSize', 'fontWeight', 'lineHeight', 'textAlign', 'textOverflow', 'whiteSpace',
    'color', 'backgroundColor', 'borderRadius', 'opacity', 'transform',
    'transitionDuration', 'animationDuration', 'zIndex', 'pointerEvents', 'cursor',
];
const fixture = name => fs.readFileSync(path.join(__dirname, 'fixtures', `styles_${name}.html`), 'utf8');
const publicHTML = fixture('public').replace('<!-- DISK_FIXTURE -->', fixture('disk'));
const editorHTML = fixture('editor');

function entrySource(root, entry) {
    const source = fs.readFileSync(path.join(root, entry), 'utf8');
    // editor.php contains a separate access-denied document before its main one.
    return source.slice(source.toLowerCase().lastIndexOf('<!doctype html>'))
        .replace(/<\?[\s\S]*?\?>/g, '');
}

function stylesheets(root, entry) {
    const result = [];
    for (const match of entrySource(root, entry).matchAll(/<link\b[^>]*>/gi)) {
        if (!/rel=["']stylesheet["']/.test(match[0])) continue;
        const href = match[0].match(/href=["']([^"']+)["']/)?.[1];
        const relative = href?.match(/(?:^|\/)((?:assets|components)\/[^?]+\.css)(?:\?.*)?$/)?.[1];
        if (relative) {
            if (!fs.existsSync(path.join(root, relative))) throw new Error(`Missing linked stylesheet: ${entry} → ${relative}`);
            result.push(relative);
        }
    }
    if (!result.length) throw new Error(`No local stylesheets found in ${entry}`);
    return result;
}

function bodyClass(root, entry) {
    return entrySource(root, entry).match(/<body\b[^>]*class=["']([^"']*)/)?.[1] || '';
}

function check(condition, message, failures) { if (!condition) failures.push(message); }
function columns(value) { return value === 'none' ? 0 : value.trim().split(/\s+/).length; }

async function capture(page) {
    await page.evaluate(() => Promise.all(document.getAnimations().filter(animation => animation.effect.getComputedTiming().iterations !== Infinity).map(animation => animation.finished.catch(() => {}))));
    return page.evaluate(properties => Object.fromEntries([...document.querySelectorAll('[data-probe]')].map(element => {
        const style = getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        // Styling of a non-rendered subtree has no visual effect. Check that it
        // stays hidden; modal-open states below check its actual rendered style.
        if (!element.getClientRects().length) return [element.dataset.probe, {
            display: style.display,
            rectWidth: Math.round(rect.width * 100) / 100,
            rectHeight: Math.round(rect.height * 100) / 100,
        }];
        return [element.dataset.probe, {
            ...Object.fromEntries(properties.map(property => [property, style[property]])),
            rectWidth: Math.round(rect.width * 100) / 100,
            rectHeight: Math.round(rect.height * 100) / 100,
            scrollWidth: element.scrollWidth,
            clientWidth: element.clientWidth,
        }];
    })), properties);
}

async function run(page, root, label) {
    const snapshots = {};
    const failures = [];
    const linked = {};
    for (const kind of ['public', 'editor']) {
        const entry = kind === 'public' ? 'views/layout/public_page.php' : 'editor.php';
        linked[kind] = stylesheets(root, entry);
        for (const width of widths) {
            await page.setViewportSize({width, height: 1000});
            await page.emulateMedia({reducedMotion: 'no-preference'});
            const loadErrors = [];
            await page.unroute('http://styles.test/**');
            await page.route('http://styles.test/**', async route => {
                const relative = decodeURIComponent(new URL(route.request().url()).pathname).replace(/^\//, '');
                const file = path.resolve(root, relative);
                if (!file.startsWith(root + path.sep) || !fs.existsSync(file)) {
                    loadErrors.push(relative);
                    return route.fulfill({status: 404, body: ''});
                }
                return route.fulfill({status: 200, contentType: 'text/css', body: fs.readFileSync(file)});
            });
            const links = linked[kind].map(file => `<link rel="stylesheet" href="http://styles.test/${file}">`).join('');
            await page.setContent(`<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${links}</head><body class="${bodyClass(root, entry)}" data-editor-inspector-tab="page">${kind === 'public' ? publicHTML : editorHTML}</body></html>`, {waitUntil: 'networkidle'});
            check(loadErrors.length === 0, `${kind}/${width}: failed CSS imports: ${loadErrors.join(', ')}`, failures);
            const key = `${kind}/${width}`;
            let state = await capture(page);
            snapshots[key] = state;
            for (const [probe, style] of Object.entries(state)) {
                if (probe.startsWith('hidden-') || probe.endsWith('-modal')) {
                    check(style.display === 'none', `${key}: ${probe} must be hidden (got ${style.display})`, failures);
                }
            }
            if (kind === 'public') {
                const count = width > 1024 ? 3 : width > 640 ? 2 : 1;
                check(columns(state['section-grid'].gridTemplateColumns) === count, `${key}: section requires ${count} columns`, failures);
                check(columns(state['cards-grid'].gridTemplateColumns) === count, `${key}: cards require ${count} columns`, failures);
                check(state.logo.rectWidth > 0 && state.logo.rectHeight > 0, `${key}: logo must remain visible`, failures);
                check(state['brand-text'].rectWidth <= width, `${key}: long brand title exceeds viewport`, failures);
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
                check(overflow <= 1, `${key}: page overflows viewport by ${overflow}px`, failures);
                await page.locator('[data-probe="disk-modal"]').evaluate(element => { element.hidden = false; });
                state = await capture(page);
                snapshots[`${key}/modal`] = {'disk-modal': state['disk-modal'], 'disk-modal-dialog': state['disk-modal-dialog']};
                check(state['disk-modal'].display !== 'none', `${key}: disk settings modal must open`, failures);
                check(state['disk-modal-dialog'].rectWidth <= width, `${key}: disk settings dialog exceeds viewport`, failures);
                await page.locator('[data-probe="disk-modal"]').evaluate(element => { element.hidden = true; });
                await page.emulateMedia({reducedMotion: 'reduce'});
                state = await capture(page);
                snapshots[`${key}/reduced`] = {motion: state.motion};
                check(state.motion.opacity === '1' && state.motion.transform === 'none' && state.motion.transitionDuration === '0s', `${key}: reduced motion must show content immediately`, failures);
                // layout_preview.php injects this guard into rendered public
                // HTML. The fixture deliberately runs no preview JavaScript.
                const previewSource = fs.readFileSync(path.join(root, 'layout_preview.php'), 'utf8');
                const legacyGuard = previewSource.match(/<style id="sb-layout-preview-guard">([\s\S]*?)<\/style>/);
                await page.emulateMedia({reducedMotion: 'no-preference'});
                if (legacyGuard) {
                    await page.addStyleTag({content: legacyGuard[1]});
                } else {
                    check(previewSource.includes('/assets/public/preview.css'), `${key}: preview entry must load its guard stylesheet`, failures);
                    check(fs.readFileSync(path.join(root, entry), 'utf8').includes("$isLayoutPreview ? ' class=\"sb-layout-preview-mode\"'"), `${key}: preview class must be present in server-rendered HTML`, failures);
                    await page.locator('html').evaluate(element => element.classList.add('sb-layout-preview-mode'));
                    await page.addStyleTag({url: 'http://styles.test/assets/public/preview.css'});
                }
                state = await capture(page);
                snapshots[`${key}/preview`] = {motion: state.motion, 'menu-link': state['menu-link']};
                check(state.motion.opacity === '1' && state.motion.transform === 'none', `${key}: static preview must show animated content without JavaScript`, failures);
                check(state['menu-link'].pointerEvents === 'none', `${key}: preview links must reject pointer interaction`, failures);
            } else {
                check(state['active-inspector'].display !== 'none', `${key}: active inspector must be shown`, failures);
                check(state['inactive-inspector'].display === 'none', `${key}: inactive inspector must be hidden`, failures);
                check(columns(state['preview-grid'].gridTemplateColumns) === 3, `${key}: desktop preview requires three columns`, failures);
                await page.locator('#editorViewport').evaluate(element => { element.classList.replace('is-desktop', 'is-tablet'); });
                state = await capture(page);
                snapshots[`${key}/tablet-preview`] = {'editor-viewport': state['editor-viewport'], 'preview-grid': state['preview-grid']};
                check(columns(state['preview-grid'].gridTemplateColumns) === 2, `${key}: tablet preview requires two columns`, failures);
                await page.locator('#editorViewport').evaluate(element => { element.classList.replace('is-tablet', 'is-mobile'); });
                state = await capture(page);
                snapshots[`${key}/mobile-preview`] = {'editor-viewport': state['editor-viewport'], 'preview-grid': state['preview-grid']};
                check(columns(state['preview-grid'].gridTemplateColumns) === 1, `${key}: mobile preview requires one column`, failures);
                await page.locator('body').evaluate(element => element.classList.add('sb-editor-theme-dark'));
                state = await capture(page);
                snapshots[`${key}/dark`] = state;
                check(state.appbar.backgroundColor !== snapshots[key].appbar.backgroundColor, `${key}: dark theme must change app bar background`, failures);
                await page.locator('[data-probe="template-modal"]').evaluate(element => { element.hidden = false; });
                state = await capture(page);
                snapshots[`${key}/modal`] = {'template-modal': state['template-modal'], 'template-dialog': state['template-dialog']};
                check(state['template-modal'].display !== 'none', `${key}: template modal must open`, failures);
                check(state['template-dialog'].rectWidth <= width, `${key}: template dialog exceeds viewport`, failures);
                await page.locator('[data-probe="template-modal"]').evaluate(element => { element.hidden = true; });
                await page.emulateMedia({reducedMotion: 'reduce'});
                state = await capture(page);
                snapshots[`${key}/reduced`] = {'editor-viewport': state['editor-viewport'], 'editor-shell': state['editor-shell']};
                check(state['editor-viewport'].transitionDuration === '0s' && state['editor-shell'].transitionDuration === '0s', `${key}: reduced motion must disable workspace transitions`, failures);
            }
            if (screenshots) {
                fs.mkdirSync(screenshots, {recursive: true});
                await page.screenshot({path: path.join(screenshots, `${label}-${kind}-${width}.png`), fullPage: true});
            }
        }
    }
    return {linked, failures, snapshots};
}

function differences(before, after) {
    const result = [];
    for (const [scenario, probes] of Object.entries(after)) {
        for (const [probe, values] of Object.entries(probes)) {
            for (const [property, value] of Object.entries(values)) {
                const old = before[scenario]?.[probe]?.[property];
                if (value !== old) result.push({scenario, probe, property, before: old, after: value});
            }
        }
    }
    return result;
}

(async () => {
    const executablePath = process.env.CHROMIUM_EXECUTABLE_PATH;
    const browser = await chromium.launch({headless: true, executablePath, args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--single-process', '--no-zygote']});
    try {
        const page = await browser.newPage();
        const baseline = baselineRoot ? await run(page, path.resolve(baselineRoot), 'baseline') : null;
        const current = await run(page, projectRoot, 'current');
        const changes = baseline ? differences(baseline.snapshots, current.snapshots) : [];
        if (reportPath) {
            fs.mkdirSync(path.dirname(path.resolve(reportPath)), {recursive: true});
            fs.writeFileSync(reportPath, JSON.stringify({baseline, current, differences: changes}, null, 2) + '\n');
        }
        if (baseline?.failures.length) console.log(`Baseline has ${baseline.failures.length} contract issue(s):\n${baseline.failures.join('\n')}`);
        if (changes.length) {
            console.log(`${changes.length} computed-style difference(s).${reportPath ? ` Full report: ${reportPath}` : ''}`);
            for (const change of changes.slice(0, 40)) console.log(`${change.scenario} ${change.probe}.${change.property}: ${change.before} → ${change.after}`);
        }
        if (current.failures.length) console.error(current.failures.join('\n'));
        console.log(`${current.failures.length ? 'FAIL' : 'PASS'}: ${Object.keys(current.snapshots).length} fixture states at ${widths.join('/')}px. Live Bitrix rendering and JavaScript integration are outside this test.`);
        if (current.failures.length || (changes.length && !args.includes('--allow-differences'))) process.exitCode = 1;
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
