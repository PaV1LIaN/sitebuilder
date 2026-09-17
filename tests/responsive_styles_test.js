#!/usr/bin/env node
'use strict';

/**
 * Browser checks for the PHP-output CSS contract of configurable content blocks.
 * Fixtures use the public renderer's custom properties and presence tokens, and
 * load the actual public entry point's stylesheets in their production order.
 *
 * CHROMIUM_EXECUTABLE_PATH=/path/to/chromium node tests/responsive_styles_test.js
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const entry = fs.readFileSync(path.join(root, 'views/layout/public_page.php'), 'utf8')
    .replace(/<\?[\s\S]*?\?>/g, '');
const styles = [...entry.matchAll(/<link\b[^>]*rel="stylesheet"[^>]*href="([^"]+)"[^>]*>/g)]
    .map(match => match[1].match(/((?:assets|components)\/[^?]+\.css)/)?.[1])
    .filter(Boolean)
    .map(file => fs.readFileSync(path.join(root, file), 'utf8')).join('\n');

// Property names and element structure match components/*/render.php. Each
// partial configuration intentionally leaves other editable settings absent.
const blocks = {
    heading: {
        markup: '<section class="sb-block sb-block--heading"><h2 data-probe class="sb-heading sb-heading--h2" style="--sb-heading-size:48px;--sb-heading-align:center;--sb-heading-max-width:680px;--sb-heading-margin:auto;font-weight:700">Heading</h2></section>',
        full: {'tablet-size':'36px','tablet-align':'right','tablet-max-width':'480px','tablet-margin-inline':'auto 0','mobile-size':'28px','mobile-align':'left','mobile-max-width':'260px','mobile-margin-inline':'0 auto'},
        partial: {'tablet-size':'36px','mobile-size':'28px'},
        alignment: {'tablet-align':'right','tablet-margin-inline':'auto 0','mobile-align':'left','mobile-margin-inline':'0 auto'},
        width: {'tablet-max-width':'480px','mobile-max-width':'260px'},
    },
    text: {
        markup: '<div class="sb-block sb-block--text"><div data-probe class="sb-text sb-text-block" style="--sb-text-size:22px;--sb-text-align:justify;--sb-text-line-height:1.8;--sb-text-max-width:700px"><p>Configurable text</p></div></div>',
        full: {'tablet-size':'18px','tablet-align':'left','tablet-line-height':'1.6','tablet-max-width':'500px','tablet-margin-inline':'0 auto','mobile-size':'16px','mobile-align':'right','mobile-line-height':'1.4','mobile-max-width':'280px','mobile-margin-inline':'auto 0'},
        partial: {'tablet-size':'18px','mobile-size':'16px'},
        alignment: {'tablet-align':'right','tablet-margin-inline':'auto 0','mobile-align':'left','mobile-margin-inline':'0 auto'},
        width: {'tablet-max-width':'500px','mobile-max-width':'280px'},
    },
    image: {
        markup: '<figure data-probe class="sb-block sb-block--image sb-media sb-media--align-center" style="--sb-media-config-width:80%;--sb-media-config-radius:24px;--sb-media-fit:cover"><div data-detail class="sb-media__placeholder">Image</div></figure>',
        full: {'tablet-image-width':'60%','tablet-image-radius':'16px','mobile-image-width':'40%','mobile-image-radius':'8px'},
        partial: {'tablet-image-radius':'16px'},
    },
    hero: {
        markup: '<section data-probe class="sb-block sb-block--hero sb-hero sb-hero--light sb-hero--align-left sb-hero--image-none" style="--sb-hero-config-min-height:520px;--sb-hero-config-radius:32px"><div class="sb-hero__content"><h2 class="sb-hero__title">Hero</h2><div class="sb-hero__actions"></div></div></section>',
        full: {'tablet-hero-height':'420px','tablet-hero-radius':'24px','mobile-hero-height':'320px','mobile-hero-radius':'12px'},
        partial: {'tablet-hero-height':'420px'},
    },
    divider: {
        markup: '<div data-probe class="sb-block sb-block--divider sb-divider sb-divider--solid" style="--sb-divider-config-width:80%;--sb-divider-config-thickness:4px;--sb-divider-config-margin:32px"><span data-detail class="sb-divider__line"></span></div>',
        full: {'tablet-divider-width':'60%','tablet-divider-thickness':'3px','tablet-divider-margin':'18px','mobile-divider-width':'40%','mobile-divider-thickness':'2px','mobile-divider-margin':'8px'},
        partial: {'tablet-divider-width':'60%','mobile-divider-width':'40%'},
    },
};

function fixture() {
    return '<main class="sb-public-shell" style="width:calc(100% - 40px);max-width:1000px;margin:auto;--sb-body-font:Arial;--sb-heading-font:Arial;--sb-accent:#2563eb">'
        + Object.entries(blocks).flatMap(([type, block]) => Object.keys(block).filter(key => key !== 'markup').map(mode => {
            const config = block[mode];
            return `<div id="${type}-${mode}" class="sb-content-block sb-responsive-block" data-sb-responsive-type="${type}" data-sb-responsive="${Object.keys(config).join(' ')}" style="${Object.entries(config).map(([key,value]) => `--sb-r-${key}:${value}`).join(';')}">${block.markup}<span></span></div>`;
        })).join('') + '</main>';
}

const expected = [
    {width:1440, headingSize:48, textSize:22, imageWidth:80, heroHeight:520, heroRadius:32, dividerWidth:80, dividerThickness:4, dividerMargin:32},
    {width:768, headingSize:36, textSize:18, imageWidth:60, heroHeight:420, heroRadius:24, dividerWidth:60, dividerThickness:3, dividerMargin:18},
    {width:390, headingSize:28, textSize:16, imageWidth:40, heroHeight:320, heroRadius:12, dividerWidth:40, dividerThickness:2, dividerMargin:8},
];

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
        await page.setContent(`<style>${styles}</style>${fixture()}`);
        for (const [device, target] of expected.entries()) {
            await page.setViewportSize({width: target.width, height: 1000});
            const values = await page.locator('[data-sb-responsive-type]').evaluateAll(wrappers => Object.fromEntries(wrappers.map(wrapper => {
                const element = wrapper.querySelector('[data-probe]');
                const css = getComputedStyle(element);
                const detail = wrapper.querySelector('[data-detail]');
                const detailCSS = detail && getComputedStyle(detail);
                return [wrapper.id, {
                    fontSize: parseFloat(css.fontSize),
                    textAlign: css.textAlign,
                    maxWidth: css.maxWidth,
                    lineHeight: parseFloat(css.lineHeight),
                    widthPercent: element.getBoundingClientRect().width / wrapper.getBoundingClientRect().width * 100,
                    minHeight: parseFloat(css.minHeight),
                    radius: parseFloat(css.borderRadius),
                    marginTop: parseFloat(css.marginTop),
                    marginBottom: parseFloat(css.marginBottom),
                    marginLeft: parseFloat(css.marginLeft),
                    marginRight: parseFloat(css.marginRight),
                    detailHeight: detailCSS && parseFloat(detailCSS.height),
                    detailRadius: detailCSS && parseFloat(detailCSS.borderRadius),
                }];
            })));
            const near = (actual, wanted, name) => assert(Math.abs(actual - wanted) < .1,
                `${target.width}px ${name}: expected ${wanted}, got ${actual}`);
            const same = (actual, wanted, name) => assert.equal(actual, wanted, `${target.width}px ${name}`);
            for (const mode of ['full','partial']) {
                near(values[`heading-${mode}`].fontSize, target.headingSize, `${mode} heading font size`);
                near(values[`text-${mode}`].fontSize, target.textSize, `${mode} text font size`);
            }
            same(values['heading-full'].textAlign, ['center','right','left'][device], 'heading alignment');
            same(values['heading-full'].maxWidth, ['680px','480px','260px'][device], 'heading maximum width');
            same(values['heading-partial'].textAlign, 'center', 'omitted heading alignment preserves base');
            same(values['heading-partial'].maxWidth, '680px', 'omitted heading maximum width preserves base');
            same(values['text-full'].textAlign, ['justify','left','right'][device], 'text alignment');
            same(values['text-full'].maxWidth, ['700px','500px','280px'][device], 'text maximum width');
            near(values['text-full'].lineHeight, target.textSize * [1.8,1.6,1.4][device], 'text line height');
            same(values['text-partial'].textAlign, 'justify', 'omitted text alignment preserves base');
            same(values['text-partial'].maxWidth, '700px', 'omitted text maximum width preserves base');
            near(values['text-partial'].lineHeight, target.textSize * 1.8, 'omitted text line height preserves base');
            for (const [type, baseSize, baseAlign, baseWidth] of [['heading',48,'center','680px'], ['text',22,'justify','700px']]) {
                same(values[`${type}-alignment`].maxWidth, baseWidth, `${type} alignment-only keeps maximum width`);
                near(values[`${type}-alignment`].fontSize, baseSize, `${type} alignment-only keeps font size`);
                same(values[`${type}-alignment`].textAlign, [baseAlign,'right','left'][device], `${type} alignment-only value`);
                same(values[`${type}-width`].textAlign, baseAlign, `${type} width-only keeps alignment`);
                near(values[`${type}-width`].fontSize, baseSize, `${type} width-only keeps font size`);
                if (device === 1) {
                    near(values[`${type}-alignment`].marginRight, 0, `${type} right alignment has no right margin`);
                    assert(values[`${type}-alignment`].marginLeft > 0, `${type} right alignment uses the available left margin`);
                }
                if (device === 2) near(values[`${type}-alignment`].marginLeft, 0, `${type} left alignment has no left margin`);
            }
            same(values['heading-width'].maxWidth, ['680px','480px','260px'][device], 'heading width-only maximum');
            same(values['text-width'].maxWidth, ['700px','500px','280px'][device], 'text width-only maximum');
            near(values['image-full'].widthPercent, target.imageWidth, 'image width');
            near(values['image-full'].detailRadius, [24,16,8][device], 'image radius');
            near(values['image-partial'].detailRadius, [24,16,16][device], 'image radius tablet fallback');
            // The component has a full-width mobile default when width is unset.
            near(values['image-partial'].widthPercent, [80,80,100][device], 'omitted image width preserves component defaults');
            near(values['hero-full'].minHeight, target.heroHeight, 'hero minimum height');
            near(values['hero-full'].radius, target.heroRadius, 'hero radius');
            near(values['hero-partial'].minHeight, [520,420,420][device], 'hero height tablet fallback');
            // The ordinary hero has a compact 20px mobile corner radius.
            near(values['hero-partial'].radius, [32,32,20][device], 'omitted hero radius preserves component defaults');
            near(values['divider-full'].widthPercent, target.dividerWidth, 'divider width');
            near(values['divider-full'].detailHeight, target.dividerThickness, 'divider thickness');
            near(values['divider-full'].marginTop, target.dividerMargin, 'divider top margin');
            near(values['divider-full'].marginBottom, target.dividerMargin, 'divider bottom margin');
            near(values['divider-partial'].widthPercent, target.dividerWidth, 'partial divider width');
            near(values['divider-partial'].detailHeight, 4, 'omitted divider thickness preserves base');
            near(values['divider-partial'].marginTop, 32, 'omitted divider margin preserves base');
        }
        assert.deepEqual(errors, [], 'No browser errors');
        console.log('PASS: heading/text/image/hero/divider configuration and omitted-setting fallbacks at 1440/768/390px.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
