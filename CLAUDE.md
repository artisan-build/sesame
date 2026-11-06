# Sesame

Sesame is a simulated Amazon SES for staging load runs, built as a standalone Scalpels app. It
accepts SES v2 `SendEmail` (`Content.Raw`) requests, draws a deterministic outcome per recipient,
and posts SNS notifications, signed in the real format, back to the consumer's webhook. It keeps
the ground truth so the consumer can reconcile every event. Growth CRM is the first consumer.
The source specification is `docs/source/3183e720-Sesame-SES-Simulator-Spec-for-CRM-Staging.md`;
`docs/poc-report.md` records what this proof of concept demonstrated and what remains.

Non-goals: real delivery, rendering, inbox placement and deliverability. Never point it at real
recipients or real SES. Production apps must refuse to boot with simulator config.

`app/Sesame` is the simulator. `app/CrmHarness` is a LOCAL CRM-COMPATIBLE HARNESS that stands in for
Growth so the loop can close in one checkout; it is not part of Sesame's contract and must not grow
into a CRM. The data-plane runtime (Octane versus Rust) is not settled: see the benchmark section of
`docs/poc-report.md` before changing it.

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
