# {{FILL: project name}}

> **Scaffolded from the `artisan-build/laravel-nodeless` starter kit.** This file arrived as a
> generic stub. Everything from "No frontend build step" down is true of every nodeless app and
> should stay as-is. Replace each `{{FILL: ...}}` marker, then delete this blockquote.

{{FILL: one or two paragraphs — what this app is, who it is for, and what it deliberately is NOT.
Be specific and be blunt about non-goals ("do not add auth/billing/multi-tenancy", "keep it small").
This is the first thing an agent reads, and it is the cheapest place to prevent scope creep.}}

## Workflow

See `.solo/workflow.md` for merge policy, the hard gate, CI, and coordination details. Feature builds
use the `multi-agent-build` skill.

Hard gate before any PR: `composer ready` (ide-helper + rector + pint + phpstan + pest + audit).

## No frontend build step

Non-negotiable, inherited from the starter kit: **no Node, no npm, no Vite, no frontend build step.**
Do not introduce one.

Tailwind CSS is served from the committed bundle at `public/build/assets/app.css`. **A class that is
not in that bundle silently does nothing** — no error, no warning, no console message. It simply
does not apply, which reads as "my markup is wrong" and burns an hour.

To use new classes, regenerate the bundle with `php artisan tailwind:optimize` (it downloads the
standalone Tailwind binary — still no Node) and **commit the regenerated file**.

**Delete `public/build/assets/app.css` before you regenerate.** Tailwind v4 scans the committed
output as one of its own sources, so anything junk in there re-seeds itself forever if you regenerate
in place.

Do not naively `grep` the bundle to check whether a class landed: it is minified and its selectors
are backslash-escaped (`.md\:flex`). Strip the backslashes and use `grep -F`, or you will get a
false negative and "fix" a bug that was never there.

## IDE helper files

`_ide_helper.php` and `_ide_helper_models.php` are committed on purpose (PHPStan `scanFiles` needs
`_ide_helper_models.php` to resolve model types). `.phpstorm.meta.php` is gitignored on purpose (it
embeds absolute local paths). This asymmetry is deliberate — do not make them consistent in either
direction.

## Static analysis

The PHPStan baseline lives in `phpstan-baseline.neon`. Keep it shrinking; never grow it silently.

## Object storage

The starter kit ships `league/flysystem-aws-s3-v3` as a runtime dependency because Laravel Cloud
**refuses to deploy** an application that has a bucket attached without it — not a warning, a failed
deployment, with nothing shippable until the package lands. Most apps end up wanting a bucket, so it
is cheaper to inherit it than to rediscover this during a deploy.

If this project has no bucket and never will, `composer remove league/flysystem-aws-s3-v3` is safe.
