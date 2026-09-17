#!/usr/bin/env node
'use strict';

/**
 * Exercise the real public table renderer and drag listeners against the styles
 * linked by public_page.php. No internal functions or style setters are mocked.
 *
 * CHROMIUM_EXECUTABLE_PATH=/path/to/chromium node tests/table_resize_style_test.js
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const tableScript = fs.readFileSync(path.join(root, 'components/table/edit.js'), 'utf8');
const entry = fs.readFileSync(path.join(root, 'views/layout/public_page.php'), 'utf8')
    .replace(/<\?[\s\S]*?\?>/g, '');
const styles = [...entry.matchAll(/<link\b[^>]*rel="stylesheet"[^>]*href="([^"]+)"[^>]*>/g)]
    .map(match => match[1].match(/((?:assets|components)\/[^?]+\.css)/)?.[1])
    .filter(Boolean);
assert(styles.includes('components/table/styles.css'), 'Public page must load table styles');
assert.equal(tableScript, fs.readFileSync(path.join(root, 'assets/public/table-edit.js'), 'utf8'),
    'Both supported table edit script paths must stay synchronized');

async function dimensions(page) {
    return page.evaluate(() => {
        const root = document.querySelector('[data-public-editable-table]');
        const table = root.querySelector('.sb-public-table');
        const measure = element => {
            const css = getComputedStyle(element);
            return {
                width: element.getBoundingClientRect().width,
                min: css.minWidth,
                max: css.maxWidth,
                widthPriority: element.style.getPropertyPriority('width'),
                minPriority: element.style.getPropertyPriority('min-width'),
                maxPriority: element.style.getPropertyPriority('max-width'),
            };
        };
        return {
            content: JSON.parse(root.dataset.content),
            table: {
                ...measure(table),
                layout: getComputedStyle(table).tableLayout,
                inlineWidth: table.style.width,
                spacing: parseFloat(getComputedStyle(table).borderSpacing) || 0,
                collapsed: getComputedStyle(table).borderCollapse === 'collapse',
                borders: parseFloat(getComputedStyle(table).borderLeftWidth)
                    + parseFloat(getComputedStyle(table).borderRightWidth),
            },
            headers: [...table.querySelectorAll('th[data-column-id]')].map(measure),
            cols: [...table.querySelectorAll('col[data-column-id]')].map(measure),
            control: measure(table.querySelector('.sb-public-table__control-th')),
            controlCol: measure(table.querySelector('.sb-public-table__control-col')),
            dirty: root.classList.contains('is-dirty'),
            resizing: document.body.classList.contains('sb-public-table-resizing'),
        };
    });
}

function checkDimensions(state, widths, label) {
    const near = (actual, expected, subject) => assert(Math.abs(actual - expected) <= 0.75,
        `${label}: ${subject} expected ${expected}px, got ${actual}px`);
    assert.deepEqual(state.content.columns.map(column => column.width), widths,
        `${label}: persisted column dimensions`);
    widths.forEach((width, index) => {
        near(state.headers[index].width, width, `header ${index + 1}`);
        near(state.cols[index].width, width, `col ${index + 1}`);
        assert.equal(state.headers[index].min, `${width}px`, `${label}: header minimum`);
        assert.equal(state.headers[index].max, `${width}px`, `${label}: header maximum`);
    });
    near(state.control.width, 72, 'control header');
    near(state.controlCol.width, 72, 'control col');
    const columnTotal = widths.reduce((sum, width) => sum + width, 72);
    assert.equal(state.table.inlineWidth, `${columnTotal}px`, `${label}: table stores all column widths`);
    assert.equal(state.table.min, `${columnTotal}px`, `${label}: table minimum includes control column`);
    // Existing separate-border tables include a gap before, between and after
    // columns. Account for that geometry without changing the production look.
    const gaps = state.table.collapsed ? 0 : state.table.spacing * (widths.length + 2);
    near(state.table.width, columnTotal + gaps + state.table.borders, 'total table including cell spacing');
    assert.equal(state.table.layout, 'fixed', `${label}: fixed column layout`);
    for (const element of [state.table, ...state.headers, ...state.cols]) {
        assert.equal(element.widthPriority, '', `${label}: width has no priority`);
        assert.equal(element.minPriority, '', `${label}: minimum has no priority`);
        assert.equal(element.maxPriority, '', `${label}: maximum has no priority`);
    }
}

async function dragColumn(page, index, delta) {
    // Dispatch browser events through production document listeners. This also
    // covers columns outside a narrow viewport without fabricating inline sizes.
    const x = await page.locator('[data-column-resizer]').nth(index).evaluate(element =>
        element.getBoundingClientRect().left + 4);
    await page.locator('[data-column-resizer]').nth(index).dispatchEvent('mousedown', {
        button: 0, buttons: 1, clientX: x, bubbles: true,
    });
    assert(await page.locator('body').evaluate(element => element.classList.contains('sb-public-table-resizing')),
        'mousedown must enter the production resize state');
    await page.evaluate(clientX => document.dispatchEvent(new MouseEvent('mousemove', {
        buttons: 1, clientX, bubbles: true, cancelable: true,
    })), x + delta);
    await page.evaluate(() => document.dispatchEvent(new MouseEvent('mouseup', {bubbles: true})));
    assert(!(await page.locator('body').evaluate(element => element.classList.contains('sb-public-table-resizing'))),
        'mouseup must leave the production resize state');
}

(async () => {
    const browser = await chromium.launch({
        headless: true,
        executablePath: process.env.CHROMIUM_EXECUTABLE_PATH,
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--single-process', '--no-zygote'],
    });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('http://styles.test/**', route => {
            const relative = new URL(route.request().url()).pathname.slice(1);
            const file = path.resolve(root, relative);
            assert(file.startsWith(root + path.sep) && fs.existsSync(file), `Missing CSS: ${relative}`);
            return route.fulfill({contentType: 'text/css', body: fs.readFileSync(file)});
        });
        for (const viewportWidth of [1440, 768, 390]) {
            await page.setViewportSize({width: viewportWidth, height: 1000});
            await page.setContent(`<!doctype html><html><head>${styles.map(file =>
                `<link rel="stylesheet" href="http://styles.test/${file}">`).join('')}</head><body>
                <main class="sb-public-shell"><section class="sb-block sb-block--table is-public-editable-table"
                    data-public-editable-table data-block-id="1" data-block-version="1">
                    <button class="sb-public-table-editbar__btn" data-table-save-all>Сохранить</button>
                    <div class="sb-public-table-wrap"><table class="sb-public-table sb-public-table--editable"></table></div>
                </section></main></body></html>`, {waitUntil: 'networkidle'});
            await page.locator('[data-public-editable-table]').evaluate(element => {
                element.dataset.content = JSON.stringify({
                    title: 'Проверка размеров',
                    columns: [
                        {id: 'c1', label: 'Название', width: 180, type: 'text', align: 'left'},
                        {id: 'c2', label: 'Количество', width: 260, type: 'number', align: 'right'},
                    ],
                    rows: [{id: 'r1', cells: {c1: 'Документ', c2: '12'}}],
                    settings: {pagination: false},
                });
            });
            await page.addScriptTag({content: tableScript});
            await page.locator('[data-column-resizer]').first().waitFor();
            checkDimensions(await dimensions(page), [180, 260], `${viewportWidth}/initial`);
            await dragColumn(page, 0, 90);
            checkDimensions(await dimensions(page), [270, 260], `${viewportWidth}/first wider`);
            await dragColumn(page, 1, -60);
            const state = await dimensions(page);
            checkDimensions(state, [270, 200], `${viewportWidth}/second narrower`);
            assert(state.dirty, 'Resizing must mark table changes as unsaved');
            // Resize bounds are applied by real handlers, and the other column
            // must not inherit the resized column's width.
            await dragColumn(page, 1, -300);
            checkDimensions(await dimensions(page), [270, 80], `${viewportWidth}/minimum`);
            assert.deepEqual(errors, [], 'Table runtime must not throw');
        }
        console.log('PASS: real table initialization and 9 resize drags at 1440/768/390px; independent column/header/control/total widths and unprioritized styles.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
