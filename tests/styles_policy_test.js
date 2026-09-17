#!/usr/bin/env node
'use strict';

// Run with Node only. Add --parse to also validate every stylesheet with PostCSS.
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const failures = [];
let cssCount = 0;
let fileCount = 0;
let postcss;
if (process.argv.includes('--parse')) {
    try { postcss = require('postcss'); }
    catch (_) { throw new Error('CSS syntax checking requires PostCSS: install the project development dependencies.'); }
}

function walk(dir) {
    return fs.readdirSync(dir, {withFileTypes: true}).flatMap(entry => {
        if (entry.name.startsWith('.') || ['node_modules', 'vendor', 'tests'].includes(entry.name)) return [];
        const file = path.join(dir, entry.name);
        return entry.isDirectory() ? walk(file) : /\.(css|php|js)$/i.test(entry.name) ? [file] : [];
    });
}

for (const file of walk(root)) {
    fileCount++;
    const source = fs.readFileSync(file, 'utf8');
    // Keep line numbers stable while excluding explanatory block comments.
    const code = source.replace(/\/\*[\s\S]*?\*\//g, comment => comment.replace(/[^\n]/g, ' '));
    const forbidden = [/!\s*important\b/gi, /\.setProperty\s*\([^;]{0,500},\s*(['"`])important\1\s*\)/gi];
    for (const pattern of forbidden) {
        for (const match of code.matchAll(pattern)) {
            const line = code.slice(0, match.index).split('\n').length;
            failures.push(`${path.relative(root, file)}:${line}: priority override is forbidden`);
        }
    }
    if (/\.(php|js)$/.test(file)) {
        for (const match of code.matchAll(/\b(?:assets|components)\/[a-zA-Z0-9_./-]+\.css\b/g)) {
            if (!fs.existsSync(path.join(root, match[0]))) {
                const line = code.slice(0, match.index).split('\n').length;
                failures.push(`${path.relative(root, file)}:${line}: missing stylesheet ${match[0]}`);
            }
        }
    }
    if (file.endsWith('.css')) {
        cssCount++;
        if (postcss) {
            try {
                const ast = postcss.parse(source, {from: file});
                ast.walkDecls(declaration => {
                    if (declaration.important) failures.push(`${path.relative(root, file)}:${declaration.source.start.line}: parsed declaration contains a priority override`);
                });
            }
            catch (error) { failures.push(error.message); }
        }
    }
}

if (failures.length) {
    console.error(failures.join('\n'));
    process.exitCode = 1;
} else {
    console.log(`PASS: no priority overrides in ${fileCount} runtime files; ${cssCount} stylesheets${postcss ? ' parsed' : ' scanned'}; literal PHP/JS stylesheet references resolve.`);
}
