<?php

use App\CrmHarness\CrmHarnessServiceProvider;
use App\Models\SesameAccount;
use App\Sesame\Delivery\DeliveryPool;
use App\Sesame\Delivery\Sweeper;
use App\Sesame\Delivery\Wakeups;
use App\Sesame\Ingest\AccountDirectory;
use App\Sesame\Ingest\IngestBuffer;
use Aws\SesV2\SesV2Client;
use Aws\Sns\MessageValidator;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Sesame helpers
|--------------------------------------------------------------------------
*/

/** A fresh ingest buffer, a fixed public host, and a validator bound to it. */
function sesameSetUp(): string
{
    $dir = sys_get_temp_dir().'/sesame-test-'.bin2hex(random_bytes(6));
    config([
        'sesame.public_url' => 'https://sesame.test',
        'sesame.buffer.path' => $dir.'/buffer.sqlite',
        'services.sns.cert_host_pattern' => '/^sesame\.test$/',
        // Tests drive sweeps themselves (runDelivery); a request's periodic
        // repair sweep would deliver mid-request through the real HTTP pool.
        'sesame.housekeeping_seconds' => 0,
    ]);
    IngestBuffer::reset();
    Wakeups::reset();
    AccountDirectory::forget();
    CrmHarnessServiceProvider::flushCertificates();
    // The real AWS validator; only certificate fetching is routed in-process.
    app()->instance(MessageValidator::class, new MessageValidator(
        fn (string $url): string => (string) SesameAccount::findOrFail(explode('/', (string) parse_url($url, PHP_URL_PATH))[2])->certificate,
        '/^sesame\.test$/',
    ));

    return $dir;
}

/** An AWS SDK HTTP handler that serves requests through the Laravel test kernel. */
function inProcessHandler(TestCase $test, bool $awsErrors = false): callable
{
    return function (RequestInterface $request) use ($test, $awsErrors) {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $uri = $request->getUri();
        $response = $test->call($request->getMethod(), $uri->getPath().($uri->getQuery() !== '' ? '?'.$uri->getQuery() : ''), [], [], [], (fn () => $this->transformHeadersToServerVars($headers))->call($test), (string) $request->getBody());

        $psr = new Response($response->getStatusCode(), $response->headers->all(), (string) $response->getContent());
        if ($awsErrors && $psr->getStatusCode() >= 400) {
            // The AWS SDK expects HTTP errors as a rejection, the way its Guzzle handler reports them.
            return Create::rejectionFor([
                'exception' => new RequestException('HTTP '.$psr->getStatusCode(), $request),
                'response' => $psr,
                'connection_error' => false,
            ]);
        }

        return Create::promiseFor($psr);
    };
}

function sesClient(TestCase $test, string $accessKeyId): SesV2Client
{
    return new SesV2Client([
        'version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'https://sesame.test', 'retries' => 0,
        'credentials' => ['key' => $accessKeyId, 'secret' => 'any-secret'],
        'http_handler' => inProcessHandler($test, true),
    ]);
}

/**
 * What is still in this instance's ingest buffer, waiting to be drained.
 *
 * @return array{sends: list<array<string, mixed>>, refusals: list<array<string, mixed>>}
 */
function bufferRows(): array
{
    $pdo = new PDO('sqlite:'.config('sesame.buffer.path'));

    return [
        'sends' => array_map(fn (array $r): array => ['seq' => (int) $r['seq']] + json_decode($r['body'], true), $pdo->query('SELECT seq, body FROM sends ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC)),
        'refusals' => $pdo->query('SELECT kind, account_id FROM refusals ORDER BY at_ms')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/** Keep after-response callbacks from running, as if the process died right after answering. */
function holdDrains(TestCase $test): void
{
    (fn () => $this->swap(DeferredCallbackCollection::class, new class extends DeferredCallbackCollection
    {
        public function invoke(): void {}

        public function invokeWhen(?Closure $when = null): void {}
    }))->call($test);
}

/** Drain, sweep and deliver until nothing is pending or the deadline passes. */
function runDelivery(TestCase $test, float $seconds = 15): void
{
    app()->bind(DeliveryPool::class, fn () => new DeliveryPool(16, 5, inProcessHandler($test)));
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        IngestBuffer::drain();
        resolve(Sweeper::class)->sweep();
        if (IngestBuffer::depth() === 0 && DB::table('sesame_events')->where('status', 'pending')->doesntExist()) {
            return;
        }
        Sleep::usleep(10000);
    }
}
