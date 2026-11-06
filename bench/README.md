# Ingest benchmark

The Runtime section of the brief asks for a stub ingest endpoint on Octane with FrankenPHP,
driven at about 300 requests a second for 10 minutes. If p99 stays in single-digit milliseconds
with no long tail, the data plane is Laravel; otherwise it is Rust.

This repo benchmarks the real ingest endpoint, not a throwaway stub. `POST
/v2/email/outbound-emails` verifies the request shape, reads the MIME headers, draws outcomes,
plans events, commits the send to the per-instance SQLite ingest buffer and returns a `MessageId`.
The buffer is drained to Postgres after the response. That is the brief's stub contract (parse,
draw, enqueue, return a `MessageId`). The 10-minute runs in `docs/evidence/benchmark-run-*.json`
predate the buffer: they appended to the old fsynced write-ahead log instead. The buffer runs are
in `docs/evidence/ingest-buffer-loss.json`.

Results and their interpretation are in [`docs/poc-report.md`](../docs/poc-report.md#runtime-benchmark).
Per-minute numbers for both 10-minute runs are in [`docs/evidence/`](../docs/evidence/).

## Reproduce

Needs PHP 8.4+, Postgres, the `frankenphp` binary (`php artisan octane:install --server=frankenphp`)
and [vegeta](https://github.com/tsenart/vegeta) 12.x.

```bash
php artisan migrate
php artisan sesame:account:create bench --max-rate=100000 --seed=42 --json > /tmp/bench.json
php artisan octane:frankenphp --host=127.0.0.1 --port=8000 --workers=4 --max-requests=100000 --log-level=warn

# 1,000 distinct SendEmail requests shaped like Laravel's ses-v2 mailer, ~24 KB MIME each.
php bench/targets.php http://127.0.0.1:8000 "$(jq -r .access_key_id /tmp/bench.json)" 1000 > /tmp/targets.json

vegeta attack -format=json -targets=/tmp/targets.json -rate=300 -duration=600s -max-workers=200 > octane.bin
vegeta report -type=text octane.bin
```

Run a control next to it: a Caddy site that answers a static 200 with no PHP at all, at 100 req/s
for the same 600 s. Any tail the control shows comes from the host, not from Octane:

```caddyfile
{
	admin off
	auto_https off
}
:8100 {
	respond `{"MessageId":"control"}` 200
}
```

```bash
frankenphp run --config Caddyfile.control --adapter caddyfile
vegeta attack -format=json -targets=control-targets.json -rate=100 -duration=600s > control.bin
```

`control-targets.json` is the first 200 lines of `targets.json` with the port changed to 8100.

## Things that distort the result

- **Anything scanning the working tree.** The WAL grows by about 10 MB a minute at 300/s. If it
  is not git-ignored, editor or agent tooling that hashes the tree pegs the CPU and adds
  multi-second stalls. `storage/sesame/` is ignored for this reason.
- **The load generator on the same CPUs.** On a 2-vCPU host, vegeta, Octane and the control
  together kept the CPU about 80% busy. Run the generator on another machine for a decision run.
- **Shared or burstable hosts.** Neighbour noise shows up as simultaneous stalls in Octane and the
  control. Compare the two before blaming either.
