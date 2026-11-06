<?php

namespace App\Sesame\Dataplane;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Sleep;
use Psr\Http\Message\ResponseInterface;

/**
 * A fixed pool of concurrent HTTP POSTs driven without blocking: the data
 * plane loop calls tick() and reads finished attempts back, each timed from
 * request start to response.
 */
final class DeliveryPool
{
    private readonly Client $client;

    private readonly ?CurlMultiHandler $multi;

    private int $inFlight = 0;

    /** @var list<array{key: string, status: ?int, error: ?string, started_ms: float, duration_ms: float}> */
    private array $finished = [];

    /** @param callable|null $handler a Guzzle handler, for tests */
    public function __construct(private readonly int $concurrency, float $timeoutSeconds, ?callable $handler = null)
    {
        $this->multi = $handler === null ? new CurlMultiHandler(['select_timeout' => 0.001]) : null;
        $this->client = new Client([
            'handler' => HandlerStack::create($handler ?? $this->multi),
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => $timeoutSeconds,
            'connect_timeout' => min(5.0, $timeoutSeconds),
            'verify' => true,
        ]);
    }

    public function capacity(): int
    {
        return max(0, $this->concurrency - $this->inFlight);
    }

    public function inFlight(): int
    {
        return $this->inFlight;
    }

    /** @param array<string, string> $headers */
    public function post(string $key, string $url, array $headers, string $body): void
    {
        $this->inFlight++;
        $startedMs = microtime(true) * 1000;
        $start = hrtime(true);
        $this->client->postAsync($url, ['headers' => $headers, 'body' => $body])->then(
            function (ResponseInterface $response) use ($key, $startedMs, $start): void {
                $this->finish($key, $response->getStatusCode(), null, $startedMs, $start);
            },
            function (\Throwable $error) use ($key, $startedMs, $start): void {
                $this->finish($key, null, $error instanceof TransferException ? substr($error->getMessage(), 0, 300) : $error::class, $startedMs, $start);
            },
        );
    }

    /**
     * Progress transfers without blocking and return attempts that finished.
     *
     * @return list<array{key: string, status: ?int, error: ?string, started_ms: float, duration_ms: float}>
     */
    public function tick(): array
    {
        if ($this->multi !== null && $this->inFlight > 0) {
            $this->multi->tick();
        } else {
            Utils::queue()->run();
        }
        $done = $this->finished;
        $this->finished = [];

        return $done;
    }

    /**
     * Wait until all in-flight attempts finish.
     *
     * @return list<array{key: string, status: ?int, error: ?string, started_ms: float, duration_ms: float}>
     */
    public function drain(float $maxSeconds = 30): array
    {
        $done = [];
        $deadline = microtime(true) + $maxSeconds;
        while ($this->inFlight > 0 && microtime(true) < $deadline) {
            $done = array_merge($done, $this->tick());
            Sleep::usleep(1000);
        }

        return array_merge($done, $this->tick());
    }

    private function finish(string $key, ?int $status, ?string $error, float $startedMs, int $start): void
    {
        $this->inFlight--;
        $this->finished[] = ['key' => $key, 'status' => $status, 'error' => $error, 'started_ms' => $startedMs, 'duration_ms' => (hrtime(true) - $start) / 1e6];
    }
}
