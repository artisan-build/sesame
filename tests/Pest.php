<?php

use App\CrmHarness\CrmHarnessServiceProvider;
use App\Models\SesameAccount;
use App\Sesame\Ingest\RateGate;
use Aws\SesV2\SesV2Client;
use Aws\Sns\MessageValidator;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

/** Fresh WAL/snapshot paths, a fixed public host, and a validator bound to it. */
function sesameSetUp(): string
{
    $dir = sys_get_temp_dir().'/sesame-test-'.bin2hex(random_bytes(6));
    config([
        'sesame.public_url' => 'https://sesame.test',
        'sesame.wal.path' => $dir.'/wal',
        'sesame.wal.snapshot' => $dir.'/accounts.json',
        'sesame.wal.fsync' => false,
        'services.sns.cert_host_pattern' => '/^sesame\.test$/',
    ]);
    RateGate::reset();
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

/** @return array<string, mixed> */
function walRecords(): array
{
    $records = [];
    foreach (glob(config('sesame.wal.path').'/*.wal') ?: [] as $file) {
        foreach (file($file) ?: [] as $line) {
            $records[] = json_decode($line, true);
        }
    }

    return $records;
}
