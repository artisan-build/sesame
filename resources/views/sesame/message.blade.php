<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $subject ?? 'Message' }} · Sesame</title>
    <link rel="stylesheet" href="{{ asset('build/assets/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('build/assets/app.css') }}">
</head>
<body class="min-h-screen bg-zinc-100 font-sans text-zinc-900 antialiased">
<header class="border-b border-zinc-200 bg-white px-4 py-3 sm:px-6">
    <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Sesame · simulated delivery, staging only</p>
    <h1 class="mt-1 text-lg font-semibold break-words">{{ $subject ?? '(no subject)' }}</h1>
    <p class="mt-1 text-sm text-zinc-600">
        Accepted {{ \Illuminate\Support\Carbon::parse($acceptedAt)->toDayDateTimeString() }} UTC ·
        @foreach ($outcomes as $recipient => $outcome)
            {{ $recipient }}: <span class="font-medium">{{ $outcome['k'] }}{{ $outcome['s'] ? ' ('.$outcome['s'].')' : '' }}</span>@if (! $loop->last), @endif
        @endforeach
    </p>
    <p class="mt-2 text-xs text-zinc-500">Links, images and unsubscribe work as they would for the recipient.</p>
    <nav class="mt-3 flex flex-wrap items-center gap-2 text-sm">
        <span class="inline-flex overflow-hidden rounded-md border border-zinc-300" role="group" aria-label="Layout">
            <button type="button" data-layout="both" class="px-3 py-1.5">Side by side</button>
            <button type="button" data-layout="desktop" class="border-l border-zinc-300 px-3 py-1.5">Desktop</button>
            <button type="button" data-layout="mobile" class="border-l border-zinc-300 px-3 py-1.5">Mobile</button>
        </span>
        <span class="inline-flex overflow-hidden rounded-md border border-zinc-300" role="group" aria-label="View">
            <button type="button" data-tab="message" class="px-3 py-1.5">Message</button>
            @if ($text !== null)
                <button type="button" data-tab="text" class="border-l border-zinc-300 px-3 py-1.5">Plain text</button>
            @endif
            <button type="button" data-tab="headers" class="border-l border-zinc-300 px-3 py-1.5">Headers</button>
        </span>
        @if ($oneClick !== null)
            <form method="post" action="{{ $oneClick }}" target="_blank">
                <input type="hidden" name="List-Unsubscribe" value="One-Click">
                <button type="submit" class="rounded-md border border-zinc-300 bg-white px-3 py-1.5">One-click unsubscribe</button>
            </form>
        @endif
    </nav>
</header>

<main class="p-4 sm:p-6">
    <section data-panel="message" class="flex flex-wrap items-start justify-center gap-6">
        <figure data-pane="desktop" class="max-w-full">
            <figcaption class="mb-2 text-xs font-medium text-zinc-500">Desktop · 640 px</figcaption>
            <iframe title="Desktop rendering" srcdoc="{{ $desktop }}" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"
                    class="block max-w-full rounded-md border border-zinc-300 bg-white shadow-sm" style="width: 640px; height: 900px"></iframe>
        </figure>
        <figure data-pane="mobile" class="max-w-full">
            <figcaption class="mb-2 text-xs font-medium text-zinc-500">Mobile · 375 px</figcaption>
            <iframe title="Mobile rendering" srcdoc="{{ $mobile }}" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"
                    class="block max-w-full rounded-3xl border-8 border-zinc-800 bg-white shadow-sm" style="width: 391px; height: 900px"></iframe>
        </figure>
    </section>
    @if ($text !== null)
        <section data-panel="text" hidden class="mx-auto max-w-3xl rounded-md border border-zinc-300 bg-white p-4">
            <pre class="whitespace-pre-wrap break-words font-mono text-sm">{{ $text }}</pre>
        </section>
    @endif
    <section data-panel="headers" hidden class="mx-auto max-w-3xl rounded-md border border-zinc-300 bg-white p-4">
        <dl class="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
            @foreach ($headers as $h)
                <dt class="font-medium text-zinc-500">{{ $h['name'] }}</dt>
                <dd class="break-all">{{ $h['value'] }}</dd>
            @endforeach
        </dl>
    </section>
</main>

<script>
    // Scripts never run inside the message (sandbox without allow-scripts); this only drives the page.
    const select = (attr, value) => document.querySelectorAll(`[data-${attr}]`).forEach((b) => {
        const on = b.dataset[attr] === value;
        b.classList.toggle('bg-zinc-900', on); b.classList.toggle('text-white', on); b.setAttribute('aria-pressed', on);
    });
    const layout = (value) => {
        document.querySelector('[data-pane=desktop]').hidden = value === 'mobile';
        document.querySelector('[data-pane=mobile]').hidden = value === 'desktop';
        document.querySelectorAll('iframe').forEach(fit);
        select('layout', value);
    };
    const tab = (value) => {
        document.querySelectorAll('[data-panel]').forEach((p) => p.hidden = p.dataset.panel !== value);
        select('tab', value);
    };
    document.querySelectorAll('[data-layout]').forEach((b) => b.addEventListener('click', () => layout(b.dataset.layout)));
    document.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => tab(b.dataset.tab)));
    // Fit each frame to its message, as a mail client shows it in full.
    // A srcdoc frame can finish loading before this script runs, so fit it now as well as on load.
    const fit = (f) => {
        const body = f.contentDocument?.body;
        if (!body || !f.offsetWidth) return;
        const style = f.contentWindow.getComputedStyle(body);
        const content = body.scrollHeight + parseFloat(style.marginTop) + parseFloat(style.marginBottom);
        // The frame's height includes its own border (the phone bezel), so add it back.
        f.style.height = Math.max(content, 200) + (f.offsetHeight - f.clientHeight) + 'px';
    };
    document.querySelectorAll('iframe').forEach((f) => { f.addEventListener('load', () => fit(f)); fit(f); });
    window.addEventListener('resize', () => document.querySelectorAll('iframe').forEach(fit));
    layout(window.innerWidth < 1100 ? 'mobile' : 'both');
    tab('message');
</script>
</body>
</html>
