# Frontend Assets (Vite + Tailwind CSS)

The skeleton ships a working Vite + Tailwind CSS v4 pipeline for CSS/JS
bundling and hot-reload, wired through three Twig functions
(`vite_asset()`, `vite_dev_mode()`, `vite_client()`) already built into
`Marrow\Template\FrameworkExtension` — the tooling below only needs to
match the two file locations those functions read.

## What's installed

```json
// package.json
{
    "scripts": { "dev": "vite", "build": "vite build" },
    "devDependencies": {
        "@tailwindcss/vite": "^4.0.0",
        "laravel-vite-plugin": "^3.0.0",
        "tailwindcss": "^4.0.0",
        "vite": "^8.0.0"
    }
}
```

`laravel-vite-plugin` isn't Laravel-specific in what it actually does — it
just writes `public/hot` when the dev server starts and
`public/build/manifest.json` when you build, and its `refresh` option
(full-page reload on file change) is glob-pattern based, not tied to
Blade. Both are exactly the file layout `FrameworkExtension` expects, and
`refresh` is pointed at Twig templates in `vite.config.js`:

```js
laravel({
    input: ['resources/css/app.css', 'resources/js/app.js'],
    refresh: ['resources/views/**/*.twig', 'modules/**/Views/**/*.twig'],
})
```

Tailwind v4 needs no `tailwind.config.js`/`postcss.config.js` by default —
`resources/css/app.css` is just:

```css
@import "tailwindcss";
```

Add plain CSS below that import freely (see the file for the skeleton's
base reset); it composes fine with Tailwind's utility classes in your
Twig templates.

## Commands

```bash
npm install
npm run dev      # Vite dev server + HMR, writes public/hot
npm run build    # production bundle, writes public/build/{assets,manifest.json}

php forge serve --watch-css   # runs the PHP server AND `npm run dev` together
```

## How the layout loads assets

```twig
{# resources/views/layouts/app.html.twig #}
{% if vite_dev_mode() %}
    {{ vite_client()|raw }}
    <script type="module" src="{{ vite_asset('resources/css/app.css') }}"></script>
    <script type="module" src="{{ vite_asset('resources/js/app.js') }}"></script>
{% else %}
    <link rel="stylesheet" href="{{ vite_asset('resources/css/app.css') }}">
    <script type="module" src="{{ vite_asset('resources/js/app.js') }}" defer></script>
{% endif %}
```

The branch matters and isn't cosmetic: Vite serves a CSS entry as a
hot-reloadable **JS module** in dev (hence `<script type="module">` for
CSS too, which looks wrong at a glance but is correct), and only emits a
real, separate `.css` file once actually built — a `<link>` tag pointing
at the dev server would 404. `vite_dev_mode()` is what a template checks
to pick the right one; `vite_asset()` alone only resolves the URL, and
`vite_client()` supplies the one thing `vite_asset()` doesn't: the HMR
bootstrap script every page needs exactly once in dev mode for hot-reload
to actually connect.

## Manifest location caveat

`vite_asset()` checks `public/build/manifest.json` first, then falls back
to `public/build/.vite/manifest.json` (vanilla Vite's own default location
when a different plugin/config produces it). This exists because the two
aren't interchangeable across tooling choices: verified against
`laravel-vite-plugin` v3.2 paired with Vite 8 (the combination pinned in
the skeleton's `package.json`), the manifest lands at the flat
`build/manifest.json` — not nested under `.vite/`, despite that being
Vite's own historical default when `build.manifest` is enabled directly.
If you swap in a different bundler setup, confirm which one yours actually
writes before assuming either path.

## Dynamic class names and Tailwind's scanner

Tailwind v4 (via `@tailwindcss/vite`) generates CSS by scanning project files
for literal, complete class-name tokens — it does no templating-language
evaluation, Twig included. A class built at render time from a variable,
e.g.:

```twig
{# Never generates any CSS — nothing to scan for #}
<span class="bg-{{ status }}-500"></span>
```

never appears as `bg-emerald-500`/`bg-red-500`/... anywhere in the raw
`.twig` file, so Tailwind never generates those utilities — the element
renders with **no color at all** in production, a silent failure that
only shows up once you actually build (`npm run dev`'s pre-bundled dev CSS
can mask it, since Tailwind rescans on every change and may have already
generated the class from an earlier, literal usage elsewhere).

Fix it by keeping every candidate class as a complete literal string
somewhere Tailwind scans — a Twig map literal works well and reads cleanly:

```twig
{% set dot = {'ok': 'bg-emerald-500', 'warning': 'bg-amber-400', 'failed': 'bg-red-500'} %}
<span class="{{ dot[status] ?? 'bg-slate-500' }}"></span>
```

Each string inside the map (`'bg-emerald-500'`, ...) is a real, complete
token in the file's raw text, so the scanner finds it regardless of the
Twig syntax wrapped around it — verified against the skeleton's own home
page, which maps health-check status to dot/pill colors this way.

## Adding a page's own JS/CSS

Import it from `resources/js/app.js` (or add a new entry to `vite.config.js`'s
`input` array and load it the same way as the two defaults above) rather
than adding new `<script>`/`<style>` tags by hand — that keeps it inside
Vite's dependency graph, so it hot-reloads in dev and gets bundled/hashed
in production like everything else.

## Not included

This wires up the build pipeline only — no UI component library or design
system ships with it. Alpine.js, a component kit, or anything shadcn/Hero
UI-flavored built on top of this pipeline is a separate, not-yet-built
piece.
