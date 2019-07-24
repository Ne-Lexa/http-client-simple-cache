<?php
declare(strict_types=1);

namespace Nelexa\HttpClient\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\TransferStats;
use Nelexa\HttpClient\HttpClient;
use Nelexa\HttpClient\Options;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * @internal
 */
final class HttpClientTest extends TestCase
{
    public function testConfig(): void
    {
        $client = new HttpClient();
        $this->assertArrayHasKey('Accept-Language', $client->getConfig()[Options::HEADERS]);

        $client
            ->setHttpHeader('DNT', '1')
            ->setHttpHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 4.4.2; Nexus 4 Build/KOT49H) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/34.0.1847.114 Mobile Safari/537.36')
            ->setHttpHeader('Accept-Language', null)
            ->setProxy('socks5://127.0.0.1:9050')
        ;

        $config = $client->getConfig();
        $this->assertSame($config[Options::HEADERS]['DNT'], '1');
        $this->assertSame($config[Options::HEADERS]['User-Agent'], 'Mozilla/5.0 (Linux; Android 4.4.2; Nexus 4 Build/KOT49H) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/34.0.1847.114 Mobile Safari/537.36');
        $this->assertArrayNotHasKey('Accept-Language', $config[Options::HEADERS]);
        $this->assertSame($config[Options::PROXY], 'socks5://127.0.0.1:9050');

        $ttl = \DateInterval::createFromDateString('5 min');
        $client->setCacheTtl($ttl);
        $this->assertSame($client->getConfig(Options::CACHE_TTL), $ttl);
    }

    public function testException(): void
    {
        $client = new HttpClient();
        $httpCode = 500;
        $url = 'https://httpbin.org/status/' . $httpCode;

        try {
            $client->request('GET', $url);
        } catch (GuzzleException | ServerException $e) {
            $this->assertNotNull($e->getResponse());
            $this->assertSame($e->getResponse()->getStatusCode(), $httpCode);
            $contents = $e->getResponse()->getBody()->getContents();
            $this->assertEmpty($contents);
            $this->assertSame((string)$e->getRequest()->getUri(), $url);
        }
    }

    /**
     * @throws GuzzleException
     */
    public function testInvalidHandlerResponseOption(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'handler_response' option is not callable");

        $client = new HttpClient();
        $client->request('GET', 'https://httpbin.org/status/200', [
            Options::HANDLER_RESPONSE => '_no_callable_',
        ]);
    }

    public function testRetryLimit(): void
    {
        $retryLimit = 2;

        $count = 0;
        $client = new HttpClient([
            HttpClient::OPTION_RETRY_LIMIT => $retryLimit,
        ]);

        try {
            $client->request('GET', 'https://httpbin.org/status/500', [
                Options::ON_STATS => static function (TransferStats $stats) use (&$count) {
                    $response = $stats->getResponse();
                    self::assertNotNull($response);
                    self::assertEquals($response->getStatusCode(), 500);
                    $count++;
                },
            ]);
        } catch (GuzzleException $e) {
        }
        $this->assertEquals($count, $retryLimit + 1);
    }

    public function testRetryLimitConnectException(): void
    {
        $retryLimit = 1;

        $count = 0;
        $client = new HttpClient([
            HttpClient::OPTION_RETRY_LIMIT => $retryLimit,
        ]);

        try {
            $client->request('GET', 'https://httpbin.org/delay/3', [
                Options::TIMEOUT => 1,
                Options::ON_STATS => static function () use (&$count) {
                    $count++;
                },
            ]);
            $this->fail('an exception was expected ' . ConnectException::class);
        } catch (GuzzleException $e) {
            $this->assertInstanceOf(ConnectException::class, $e);
        }
        $this->assertEquals($count, $retryLimit + 1);
    }

    public function testSetInvalidTtlCache(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cache ttl value. Supported \DateInterval, int and null.');

        $client = new HttpClient();
        $client->setCacheTtl('1 day');
    }

    public function testSetTimeout(): void
    {
        $client = new HttpClient();
        $client->setTimeout(300.300);
        $this->assertSame(300.300, $client->getConfig()[Options::TIMEOUT]);
    }

    public function testSetInvalidTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('negative timeout');

        $client = new HttpClient();
        $client->setTimeout(-3.14);
    }

    public function testSetConnectTimeout(): void
    {
        $client = new HttpClient();
        $client->setConnectTimeout(300.300);
        $this->assertSame(300.300, $client->getConfig()[Options::CONNECT_TIMEOUT]);
    }

    public function testSetInvalidConnectTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('negative connect timeout');

        $client = new HttpClient();
        $client->setConnectTimeout(-3.14);
    }

    public function testMergeConfig(): void
    {
        $client = new class() extends HttpClient {
            /**
             * @param bool $debug
             */
            public function setDebug(bool $debug): void
            {
                $this->mergeConfig([Options::DEBUG => $debug]);
            }
        };

        $debug = $client->getConfig(Options::DEBUG) ?? false;
        $this->assertFalse($debug);

        $client->setDebug(true);
        $this->assertTrue($client->getConfig(Options::DEBUG));
    }

    public function testCacheResponse(): void
    {
        $cache = new Psr16Cache(new FilesystemAdapter('test.cache.response.v' . time()));

        $requestCacheUUID = static function () use ($cache) {
            $client = new HttpClient([], $cache);

            return $client->get('https://httpbin.org/uuid', [
                Options::HEADERS => [
                    'Accept' => 'application/json',
                ],
                Options::CACHE_TTL => \DateInterval::createFromDateString('2 second'),
                Options::HANDLER_RESPONSE => static function (RequestInterface $request, ResponseInterface $response) {
                    $contents = $response->getBody()->getContents();
                    $json = \GuzzleHttp\json_decode($contents, true);

                    return $json['uuid'];
                },
            ]);
        };

        $uuid = $requestCacheUUID();
        $this->assertSame($requestCacheUUID(), $uuid);

        sleep(2);
        $this->assertNotSame($requestCacheUUID(), $uuid);
    }

    /**
     * @throws GuzzleException
     */
    public function testRequestAsyncPool(): void
    {
        $urls = [
            'uuid' => 'https://httpbin.org/uuid',
            'bytes' => 'https://httpbin.org/bytes/30',
            'base64' => 'https://httpbin.org/base64/' . rawurlencode(base64_encode('test string')),
            // 0 =>
            'https://httpbin.org/user-agent',
        ];

        $concurrency = 2;

        $client = new HttpClient();
        $userAgent = 'Test-Agent';
        $response = $client->requestAsyncPool('GET', $urls, [
            Options::HEADERS => [
                'User-Agent' => $userAgent,
            ],
            Options::HANDLER_RESPONSE => static function (RequestInterface $request, ResponseInterface $response) {
                return $response->getBody()->getContents();
            },
        ], $concurrency);

        $this->assertArrayHasKey('uuid', $response);
        $this->assertArrayHasKey('bytes', $response);
        $this->assertArrayHasKey('base64', $response);
        $this->assertArrayHasKey(0, $response);
    }

    /**
     * @throws GuzzleException
     */
    public function testHandlerStack(): void
    {
        $stack = HandlerStack::create();
        $stack->unshift(Middleware::mapRequest(static function (RequestInterface $r) {
            return $r->withHeader('X-Foo', 'Bar');
        }, 'add_foo'));

        $client = new HttpClient([
            HttpClient::OPTION_HANDLER => $stack,
        ]);

        $client->request('GET', 'https://httpbin.org/headers', [
            Options::HANDLER_RESPONSE => static function (RequestInterface $request, ResponseInterface $response) {
                self::assertArrayHasKey('X-Foo', $request->getHeaders());
                self::assertSame($request->getHeaders()['X-Foo'], ['Bar']);

                $contents = $response->getBody()->getContents();
                $json = \GuzzleHttp\json_decode($contents, true);
                self::assertSame($json['headers']['X-Foo'], 'Bar');
            },
        ]);
    }
}
