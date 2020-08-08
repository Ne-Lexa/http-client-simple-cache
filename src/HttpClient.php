<?php

declare(strict_types=1);

namespace Nelexa\HttpClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Nelexa\HttpClient\Utils\HashUtil;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use function GuzzleHttp\Promise\each_limit;
use function GuzzleHttp\Promise\each_limit_all;

/**
 * HTTP client.
 */
class HttpClient extends Client
{
    /** handler: (HandlerStack) Handler stack. */
    public const OPTION_HANDLER = 'handler';

    /** retry_limit: (int) number of attempts with HTTP error (except 404). */
    public const OPTION_RETRY_LIMIT = 'retry_limit';

    /** @var string Cache key default namespace */
    private const CACHE_KEY = 'http_cache.v1.%s.%s';

    /** @var CacheInterface|null PSR Simple Cache implementation */
    private $cache;

    /**
     * Clients accept an array of constructor parameters.
     *
     * @param array               $config client configuration settings
     * @param CacheInterface|null $cache  PSR simple-cache implementation
     *
     * @see Options for a list of available request options.
     * @see https://packagist.org/providers/psr/simple-cache-implementation Simple cache implementation packages
     */
    public function __construct(array $config = [], ?CacheInterface $cache = null)
    {
        $this->setCache($cache);

        if (isset($config[self::OPTION_HANDLER])) {
            if (!$config[self::OPTION_HANDLER] instanceof HandlerStack) {
                throw new \InvalidArgumentException(
                    'Invalid option "' . self::OPTION_HANDLER .
                    '". Expected ' . HandlerStack::class
                );
            }
            $handlerStack = $config[self::OPTION_HANDLER];
            unset($config[self::OPTION_HANDLER]);
        } else {
            $handlerStack = HandlerStack::create();
        }

        $handlerSimpleCache = $this->handlerSimpleCacheMiddleware();

        try {
            $handlerStack->before('http_errors', $handlerSimpleCache);
        } catch (\InvalidArgumentException $e) {
            $handlerStack->push($handlerSimpleCache);
        }

        if (
            isset($config[self::OPTION_RETRY_LIMIT]) &&
            \is_int($config[self::OPTION_RETRY_LIMIT]) &&
            $config[self::OPTION_RETRY_LIMIT] > 0
        ) {
            $handlerStack->push(self::handlerRetryMiddleware($config[self::OPTION_RETRY_LIMIT]));
        }

        $config = array_replace_recursive(
            [
                self::OPTION_HANDLER => $handlerStack,
                Options::HEADERS => [
                    'User-Agent' => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:72.0) Gecko/20100101 Firefox/72.0',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Connection' => 'keep-alive',
                ],
            ],
            $config
        );
        parent::__construct($config);
    }

    /**
     * @param CacheInterface|null $cache PSR simple-cache implementation
     *
     * @return self
     *
     * @see https://packagist.org/providers/psr/simple-cache-implementation Simple cache implementation packages
     */
    public function setCache(?CacheInterface $cache): self
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * @return \Closure
     */
    private function handlerSimpleCacheMiddleware(): \Closure
    {
        return function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                if (!isset($options[Options::HANDLER_RESPONSE])) {
                    return $handler($request, $options);
                }

                $cacheSupport = $this->cache !== null && isset($options[Options::CACHE_TTL]);

                if (!\is_callable($options[Options::HANDLER_RESPONSE])) {
                    throw new \RuntimeException("'" . Options::HANDLER_RESPONSE . "' option is not callable");
                }

                if ($cacheSupport) {
                    if (!isset($options[Options::CACHE_KEY])) {
                        $options[Options::CACHE_KEY] = sprintf(
                            self::CACHE_KEY,
                            HashUtil::hashCallable($options[Options::HANDLER_RESPONSE]),
                            HashUtil::getRequestHash($request)
                        );
                    }

                    $value = $this->cache->get($options[Options::CACHE_KEY]);

                    if ($value !== null) {
                        return $value;
                    }
                }

                return $handler($request, $options)
                    ->then(
                        function (ResponseInterface $response) use ($options, $request, $cacheSupport) {
                            $result = \call_user_func(
                                $options[Options::HANDLER_RESPONSE],
                                $request,
                                $response
                            );

                            if ($cacheSupport && $result !== null) {
                                $ttl = $options[Options::CACHE_TTL];
                                $this->cache->set(
                                    $options[Options::CACHE_KEY],
                                    $result,
                                    $ttl
                                );
                            }

                            return $result;
                        }
                    )
                ;
            };
        };
    }

    /**
     * @param int $retryLimit
     *
     * @return callable
     */
    private static function handlerRetryMiddleware(int $retryLimit): callable
    {
        return Middleware::retry(
            static function (
                $retries,
                /** @noinspection PhpUnusedParameterInspection */
                RequestInterface $request,
                ?ResponseInterface $response = null,
                ?RequestException $exception = null
            ) use ($retryLimit) {
                // retry decider
                if ($retries >= $retryLimit) {
                    return false;
                }

                // Retry connection exceptions
                if ($exception instanceof ConnectException) {
                    return true;
                }

                if (
                    $response !== null &&
                    (
                        $response->getStatusCode() !== 404 &&
                        $response->getStatusCode() >= 400
                    )
                ) {
                    return true;
                }

                return false;
            },
            static function (int $numberOfRetries) {
                // retry delay
                return 1000 * $numberOfRetries;
            }
        );
    }

    /**
     * @param string|null $proxy
     *
     * @return self
     */
    public function setProxy(?string $proxy): self
    {
        $config = $this->getConfig();
        $config[Options::PROXY] = $proxy;
        $this->setConfig($config);

        return $this;
    }

    /**
     * @param string $key
     * @param string $value
     *
     * @return HttpClient
     */
    public function setHttpHeader(string $key, ?string $value): self
    {
        $config = $this->getConfig();

        if ($value === null) {
            if (isset($config[Options::HEADERS][$key])) {
                unset($config[Options::HEADERS][$key]);
                $this->setConfig($config);
            }
        } else {
            $config[Options::HEADERS][$key] = $value;
            $this->setConfig($config);
        }

        return $this;
    }

    /**
     * @param \DateInterval|int|null $ttl
     *
     * @return HttpClient
     */
    public function setCacheTtl($ttl): self
    {
        if ($ttl !== null && !\is_int($ttl) && !$ttl instanceof \DateInterval) {
            throw new \InvalidArgumentException('Invalid cache ttl value. Supported \DateInterval, int and null.');
        }
        $config = $this->getConfig();
        $config[Options::CACHE_TTL] = $ttl;
        $this->setConfig($config);

        return $this;
    }

    /**
     * @param float $connectTimeout
     *
     * @return HttpClient
     */
    public function setConnectTimeout(float $connectTimeout): self
    {
        if ($connectTimeout < 0) {
            throw new \InvalidArgumentException('negative connect timeout');
        }
        $config = $this->getConfig();
        $config[Options::CONNECT_TIMEOUT] = $connectTimeout;
        $this->setConfig($config);

        return $this;
    }

    /**
     * @param float $timeout
     *
     * @return HttpClient
     */
    public function setTimeout(float $timeout): self
    {
        if ($timeout < 0) {
            throw new \InvalidArgumentException('negative timeout');
        }
        $config = $this->getConfig();
        $config[Options::TIMEOUT] = $timeout;
        $this->setConfig($config);

        return $this;
    }

    /**
     * @param array $config
     */
    protected function setConfig(array $config): void
    {
        static $property;

        try {
            if ($property === null) {
                $property = new \ReflectionProperty(parent::class, 'config');
                $property->setAccessible(true);
            }
            $property->setValue($this, $config);
        } catch (\ReflectionException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @param string        $method
     * @param iterable      $urls
     * @param array         $options
     * @param int           $concurrency
     * @param callable|null $onRejected
     *
     * @return array
     */
    public function requestAsyncPool(string $method, iterable $urls, array $options = [], int $concurrency = 4, ?callable $onRejected = null): array
    {
        $results = [];

        if (!$urls instanceof \Generator) {
            $urls = $this->requestGenerator($method, $urls, $options);
        }

        if ($onRejected === null) {
            each_limit_all(
                $urls,
                $concurrency,
                static function ($response, $index) use (&$results): void {
                    $results[$index] = $response;
                }
            )->wait();
        } else {
            each_limit(
                $urls,
                $concurrency,
                static function ($response, $index) use (&$results): void {
                    $results[$index] = $response;
                },
                $onRejected
            )->wait();
        }

        return $results;
    }

    /**
     * @param string   $method
     * @param iterable $urls
     * @param array    $options
     *
     * @return \Generator
     */
    private function requestGenerator(string $method, iterable $urls, array $options): \Generator
    {
        foreach ($urls as $key => $url) {
            yield $key => $this->requestAsync($method, $url, $options);
        }
    }

    /**
     * @param array $config
     */
    protected function mergeConfig(array $config): void
    {
        if (!empty($config)) {
            $this->setConfig(
                array_replace_recursive(
                    $this->getConfig(),
                    $config
                )
            );
        }
    }
}
