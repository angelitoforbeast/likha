// The staff layout loads Tailwind from its CDN. The timing script makes no outside connection, so it serves a
// stylesheet built here instead, with the Tailwind compiler already in node_modules (found by walking up from this
// folder, so a worktree uses the main checkout's), from the classes used in the views and page scripts.
import {readFileSync, readdirSync, statSync} from 'node:fs';
import {join, dirname} from 'node:path';
import {createRequire} from 'node:module';

const require = createRequire(import.meta.url);

function files(dir, out = []) {
    for (const name of readdirSync(dir)) {
        const p = join(dir, name);
        if (statSync(p).isDirectory()) files(p, out); else if (/\.(blade\.php|js)$/.test(name)) out.push(p);
    }
    return out;
}

export async function tailwindCss(root) {
    const pkg = require.resolve('tailwindcss/package.json');
    const {compile} = await import('file://' + join(dirname(pkg), 'dist/lib.mjs').replace(/\\/g, '/'));
    const base = dirname(pkg);
    const compiler = await compile('@import "tailwindcss";', {
        base,
        loadStylesheet: async (id, from) => {
            const file = id === 'tailwindcss' ? join(base, 'index.css') : join(base, id.replace(/^tailwindcss\//, '') + (id.endsWith('.css') ? '' : '.css'));
            return {path: file, base: dirname(file), content: readFileSync(file, 'utf8')};
        },
    });
    // Every word-like token is a candidate; the compiler keeps only the real utilities.
    const candidates = new Set();
    for (const f of [...files(join(root, 'resources/views')), ...files(join(root, 'public/js'))]) {
        for (const m of readFileSync(f, 'utf8').matchAll(/[A-Za-z0-9_\-:./\[\]%#()!,'"=&>+~*@]+/g)) {
            for (const t of m[0].split(/["'`]/)) if (t && t.length < 120) candidates.add(t.replace(/^[.,]+|[.,]+$/g, ''));
        }
    }
    return compiler.build([...candidates]);
}
