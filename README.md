# nelexa/http-client-simple-cache

Guzzle-based HTTP Client with the ability to customize caching of the processed HTTP request results (not based on HTTP headers).

[![Packagist Version](https://img.shields.io/packagist/v/nelexa/http-client-simple-cache.svg?style=popout)](https://packagist.org/packages/nelexa/http-client-simple-cache)
![PHP from Packagist](https://img.shields.io/packagist/php-v/nelexa/http-client-simple-cache.svg?style=popout&color=yellowgreen)
[![License](https://img.shields.io/packagist/l/nelexa/http-client-simple-cache.svg?style=popout&color=01f176)](https://packagist.org/packages/nelexa/http-client-simple-cache)

[![Travis Build Status](https://img.shields.io/travis/Ne-Lexa/http-client-simple-cache/master.svg?label=Travis&style=popout)](https://travis-ci.org/Ne-Lexa/http-client-simple-cache)
![Scrutinizer build](https://img.shields.io/scrutinizer/build/g/Ne-Lexa/http-client-simple-cache/master.svg?label=Scrutinizer&style=popout)
![Scrutinizer code quality](https://img.shields.io/scrutinizer/quality/g/Ne-Lexa/http-client-simple-cache/master.svg?style=popout)
![Scrutinizer coverage](https://img.shields.io/scrutinizer/coverage/g/Ne-Lexa/http-client-simple-cache/master.svg?style=popout)


## Documentation
Guzzle docs: http://docs.guzzlephp.org/en/stable/

### Init
```php
<?php

$client = new \Nelexa\HttpClient\HttpClient();
```
Set default options:
```php
<?php

$client = new \Nelexa\HttpClient\HttpClient([
    \Nelexa\HttpClient\Options::HEADERS => [
        'User-Agent' => 'TestHttpClient/1.0',
    ],
]);
```

## Processing an HTTP request and getting the result
```php
<?php

$client = new \Nelexa\HttpClient\HttpClient();
$result = $client->get($url, [
    \Nelexa\HttpClient\Options::HANDLER_RESPONSE => $callable
]);
```
Callable signature:
```php
function(\Psr\Http\Message\RequestInterface $request, \Psr\Http\Message\ResponseInterface $response){
    return ...;
}
```
Example:
```php
<?php

$client = new \Nelexa\HttpClient\HttpClient();

// use \Closure handler

$base64Contents = $client->get($url, [
    \Nelexa\HttpClient\Options::HANDLER_RESPONSE => static function (Psr\Http\Message\RequestInterface $request, Psr\Http\Message\ResponseInterface $response) {
        return base64_encode($response->getBody()->getContents());
    },
]);

// or use class handler

$base64Contents = $client->get($url, [
    \Nelexa\HttpClient\Options::HANDLER_RESPONSE => new class() implements \Nelexa\HttpClient\ResponseHandlerInterface {
    
        public function __invoke(Psr\Http\Message\RequestInterface $request, Psr\Http\Message\ResponseInterface $response)
        {
            return base64_encode($response->getBody()->getContents());
        }
    },
]);
```

## Use cache results
Install the implementation of the simple cache PSR-16. 

Full list of packages https://packagist.org/providers/psr/simple-cache-implementation

Example install:
```bash
composer require symfony/cache
```

Add option `\Nelexa\HttpClient\Options::CACHE_TTL` with `\DateInterval` value.

Example:
```php
<?php

class Api
{
    /** @var \Nelexa\HttpClient\HttpClient */
    private $httpClient;

    public function __construct(Psr\SimpleCache\CacheInterface $cache)
    {
        $this->httpClient = new \Nelexa\HttpClient\HttpClient([], $cache);
    }

    /**
     * Fetch uuid.
     *
     * @return string
     */
    public function fetchUUID(): string
    {
        return $this->httpClient->request('GET', 'https://httpbin.org/uuid', [
            
            \Nelexa\HttpClient\Options::CACHE_TTL => \DateInterval::createFromDateString('1 min'), // required TTL
            
            \Nelexa\HttpClient\Options::HANDLER_RESPONSE => static function (Psr\Http\Message\RequestInterface $request, Psr\Http\Message\ResponseInterface $response) {
                $contents = $response->getBody()->getContents();
                $json = \GuzzleHttp\json_decode($contents, true);

                return $json['uuid'];
            },
        ]);
    }
}

$cache = new \Symfony\Component\Cache\Psr16Cache(
    new \Symfony\Component\Cache\Adapter\RedisAdapter(
        \Symfony\Component\Cache\Adapter\RedisAdapter::createConnection('redis://localhost')
    )
);

$api = new Api($cache);
$UUID = $api->fetchUUID();

\PHPUnit\Framework\Assert::assertSame($api->fetchUUID(), $UUID);

var_dump($UUID); // string(36) "a72b27c2-7e69-4bc8-8d5b-ccb0e496a7bf"
```

## Async Request Pool Handler
```php
<?php

$urls = [
    'jpeg' => 'https://httpbin.org/image/jpeg',
    'png' => 'https://httpbin.org/image/png',
    'webp' => 'https://httpbin.org/image/webp',
];

$client = new \Nelexa\HttpClient\HttpClient();
$result = $client->requestAsyncPool('GET', $urls, [
    \Nelexa\HttpClient\Options::HANDLER_RESPONSE => static function (Psr\Http\Message\RequestInterface $request, Psr\Http\Message\ResponseInterface $response) {
        return getimagesizefromstring($response->getBody()->getContents());
    },
], $concurrency = 2);

print_r($result);

// Output:
//
//Array
//(
//    [png] => Array
//    (
//            [0] => 100
//            [1] => 100
//            [2] => 3
//            [3] => width="100" height="100"
//            [bits] => 8
//            [mime] => image/png
//    )
//
//    [webp] => Array
//    (
//            [0] => 274
//            [1] => 367
//            [2] => 18
//            [3] => width="274" height="367"
//            [bits] => 8
//            [mime] => image/webp
//    )
//
//    [jpeg] => Array
//    (
//            [0] => 239
//            [1] => 178
//            [2] => 2
//            [3] => width="239" height="178"
//            [bits] => 8
//            [channels] => 3
//            [mime] => image/jpeg
//    )
//)
```

# Changelog

Changes are documented in the [releases page](https://github.com/Ne-Lexa/http-client-simple-cache/releases).

# License

The files in this archive are released under the `MIT License`.
 
You can find a copy of this license in `LICENSE` file.
