<?php

/** @noinspection PhpUnusedParameterInspection */

declare(strict_types=1);

namespace Nelexa\HttpClient\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
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
 *
 * @small
 */
final class HttpClientTest extends TestCase
{
    public function testConfig(): void
    {
        $client = new HttpClient();
        self::assertArrayHasKey('Accept-Language', $client->getConfig()[Options::HEADERS]);

        $client
            ->setHttpHeader('DNT', '1')
            ->setHttpHeader(
                'User-Agent',
                'Mozilla/5.0 (Linux; Android 4.4.2; Nexus 4 Build/KOT49H) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/34.0.1847.114 Mobile Safari/537.36'
            )
            ->setHttpHeader('Accept-Language', null)
            ->setProxy('socks5://127.0.0.1:9050')
        ;

        $config = $client->getConfig();
        self::assertSame($config[Options::HEADERS]['DNT'], '1');
        self::assertSame(
            $config[Options::HEADERS]['User-Agent'],
            'Mozilla/5.0 (Linux; Android 4.4.2; Nexus 4 Build/KOT49H) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/34.0.1847.114 Mobile Safari/537.36'
        );
        self::assertArrayNotHasKey('Accept-Language', $config[Options::HEADERS]);
        self::assertSame($config[Options::PROXY], 'socks5://127.0.0.1:9050');

        $ttl = \DateInterval::createFromDateString('5 min');
        $client->setCacheTtl($ttl);
        self::assertSame($client->getConfig(Options::CACHE_TTL), $ttl);
    }

    public function testException(): void
    {
        $client = new HttpClient();
        $httpCode = 500;
        $url = 'https://httpbin.org/status/' . $httpCode;

        try {
            $client->request('GET', $url);
        } catch (ServerException $e) {
            self::assertNotNull($e->getResponse());
            self::assertSame($e->getResponse()->getStatusCode(), $httpCode);
            $contents = $e->getResponse()->getBody()->getContents();
            self::assertEmpty($contents);
            self::assertSame((string) $e->getRequest()->getUri(), $url);
        }
    }

    public function testInvalidHandlerResponseOption(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'handler_response' option is not callable");

        $client = new HttpClient();
        $client->request(
            'GET',
            'https://httpbin.org/status/200',
            [
                Options::HANDLER_RESPONSE => '_no_callable_',
            ]
        );
    }

    public function testRetryLimit(): void
    {
        $retryLimit = 2;

        $count = 0;
        $client = new HttpClient(
            [
                HttpClient::OPTION_RETRY_LIMIT => $retryLimit,
                Options::HTTP_ERRORS => false,
            ]
        );

        $client->request(
            'GET',
            'https://httpbin.org/status/500',
            [
                Options::ON_STATS => static function (TransferStats $stats) use (&$count): void {
                    $response = $stats->getResponse();
                    self::assertNotNull($response);
                    self::assertEquals($response->getStatusCode(), 500);
                    $count++;
                },
            ]
        );
        self::assertEquals($count, $retryLimit + 1);
    }

    public function testRetryLimitConnectException(): void
    {
        $retryLimit = 1;

        $count = 0;
        $client = new HttpClient(
            [
                HttpClient::OPTION_RETRY_LIMIT => $retryLimit,
            ]
        );

        try {
            $client->request(
                'GET',
                'https://httpbin.org/delay/3',
                [
                    Options::TIMEOUT => 1,
                    Options::ON_STATS => static function () use (&$count): void {
                        $count++;
                    },
                ]
            );
            self::fail('an exception was expected ' . ConnectException::class);
        } catch (\Throwable $e) {
            self::assertInstanceOf(ConnectException::class, $e);
        }
        self::assertEquals($count, $retryLimit + 1);
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
        self::assertSame(300.300, $client->getConfig()[Options::TIMEOUT]);
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
        self::assertSame(300.300, $client->getConfig()[Options::CONNECT_TIMEOUT]);
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
        self::assertFalse($debug);

        $client->setDebug(true);
        self::assertTrue($client->getConfig(Options::DEBUG));
    }

    public function testCacheResponse(): void
    {
        $cache = new Psr16Cache(new FilesystemAdapter('test.cache.response.v' . time()));

        $requestCacheUUID = static function () use ($cache) {
            $client = new HttpClient([], $cache);

            return $client->get(
                'https://httpbin.org/uuid',
                [
                    Options::HEADERS => [
                        'Accept' => 'application/json',
                    ],
                    Options::CACHE_TTL => \DateInterval::createFromDateString('2 second'),
                    Options::HANDLER_RESPONSE => static function (
                        RequestInterface $request,
                        ResponseInterface $response
                    ) {
                        $contents = $response->getBody()->getContents();
                        $json = \GuzzleHttp\json_decode($contents, true);

                        return $json['uuid'];
                    },
                ]
            );
        };

        $uuid = $requestCacheUUID();
        self::assertSame($requestCacheUUID(), $uuid);

        sleep(2);
        self::assertNotSame($requestCacheUUID(), $uuid);
    }

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
        $response = $client->requestAsyncPool(
            'GET',
            $urls,
            [
                Options::HEADERS => [
                    'User-Agent' => $userAgent,
                ],
                Options::HANDLER_RESPONSE => static function (RequestInterface $request, ResponseInterface $response) {
                    return $response->getBody()->getContents();
                },
            ],
            $concurrency
        );

        self::assertArrayHasKey('uuid', $response);
        self::assertArrayHasKey('bytes', $response);
        self::assertArrayHasKey('base64', $response);
        self::assertArrayHasKey(0, $response);
    }

    public function testRequestAsyncPoolWithReject(): void
    {
        $urls = [
            'uuid' => 'https://httpbin.org/uuid',
            'bytes' => 'https://httpbin.org/bytes/30',
            'error_404' => 'https://httpbin.org/status/404',
            'error_500' => 'https://httpbin.org/status/500',
            'base64' => 'https://httpbin.org/base64/' . rawurlencode(base64_encode('test string')),
            // 0 =>
            'https://httpbin.org/user-agent',
        ];
        $errorsExpected = [
            'error_404' => 404,
            'error_500' => 500,
        ];

        $concurrency = 2;

        $client = new HttpClient();
        $userAgent = 'Test-Agent';
        $response = $client->requestAsyncPool(
            'GET',
            $urls,
            [
                Options::HEADERS => [
                    'User-Agent' => $userAgent,
                ],
                Options::HANDLER_RESPONSE => static function (RequestInterface $request, ResponseInterface $response) {
                    return $response->getBody()->getContents();
                },
            ],
            $concurrency,
            /**
             * @param \Throwable|RequestException $reason
             * @param string|int                  $idx
             * @param PromiseInterface            $aggregate
             */
            static function (\Throwable $reason, $idx, PromiseInterface $aggregate) use ($errorsExpected): void {
                self::assertInstanceOf(RequestException::class, $reason);
                $response = $reason->getResponse();
                self::assertNotNull($response);
                self::assertArrayHasKey($idx, $errorsExpected);
                self::assertSame($errorsExpected[$idx], $response->getStatusCode());
//                if ($reject === null) {
//                    $aggregate->reject($reason);
//                } else {
//                    $reject($idx, $reason);
//                }
            }
        );

        self::assertArrayHasKey('uuid', $response);
        self::assertArrayHasKey('bytes', $response);
        self::assertArrayHasKey('base64', $response);
        self::assertArrayHasKey(0, $response);
    }

    public function testRequestAsyncPoolDoReject(): void
    {
        $this->expectException(RequestException::class);

        $urls = [
            'error_404' => 'https://httpbin.org/status/404',
            'error_500' => 'https://httpbin.org/status/500',
        ];

        $concurrency = 2;

        $client = new HttpClient();
        $userAgent = 'Test-Agent';
        $client->requestAsyncPool(
            'GET',
            $urls,
            [
                Options::HEADERS => [
                    'User-Agent' => $userAgent,
                ],
                Options::HANDLER_RESPONSE => static function (RequestInterface $request, ResponseInterface $response) {
                    return $response->getBody()->getContents();
                },
            ],
            $concurrency,
            /**
             * @param \Throwable|RequestException $reason
             * @param string|int                  $idx
             * @param PromiseInterface            $aggregate
             */
            static function (\Throwable $reason, $idx, PromiseInterface $aggregate): void {
                $aggregate->reject($reason);
            }
        );
    }

    public function testHandlerStack(): void
    {
        $stack = HandlerStack::create();
        $stack->unshift(
            Middleware::mapRequest(
                static function (RequestInterface $r) {
                    return $r->withHeader('X-Foo', 'Bar');
                }
            ),
            'add_foo'
        );

        $client = new HttpClient(
            [
                HttpClient::OPTION_HANDLER => $stack,
            ]
        );

        $client->request(
            'GET',
            'https://httpbin.org/headers',
            [
                Options::HANDLER_RESPONSE => static function (
                    RequestInterface $request,
                    ResponseInterface $response
                ): void {
                    self::assertArrayHasKey('X-Foo', $request->getHeaders());
                    self::assertSame($request->getHeaders()['X-Foo'], ['Bar']);

                    $contents = $response->getBody()->getContents();
                    $json = \GuzzleHttp\json_decode($contents, true);
                    self::assertSame($json['headers']['X-Foo'], 'Bar');
                },
            ]
        );
    }
}
