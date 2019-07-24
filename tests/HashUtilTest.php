<?php
/** @noinspection PhpUnusedParameterInspection */
/** @noinspection PhpMultipleClassesDeclarationsInOneFile */
declare(strict_types=1);

namespace Nelexa\HttpClient\Tests;

use Nelexa\HttpClient\ResponseHandlerInterface;
use Nelexa\HttpClient\Utils\HashUtil;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
final class HashUtilTest extends TestCase
{
    /**
     * @throws \ReflectionException
     */
    public function testHashCallable(): void
    {
        $callable = static function (RequestInterface $request, ResponseInterface $response) {
            return $response->getBody()->getContents();
        };

        $callableStatic = static function (RequestInterface $request, ResponseInterface $response) {
            return $response->getBody()->getContents();
        };

        $responseHandler = new class() implements ResponseHandlerInterface {
            /**
             * @param RequestInterface  $request
             * @param ResponseInterface $response
             *
             * @return mixed
             */
            public function __invoke(RequestInterface $request, ResponseInterface $response)
            {
                return $response->getBody()->getContents();
            }
        };

        $hashes = [
            'callable' => $this->calcHash($callable),
            'callableStatic' => $this->calcHash($callableStatic),
            '[$this, callableMethod]' => $this->calcHash([$this, 'callableMethod']),
            '[__CLASS__, callableMethod]' => $this->calcHash([__CLASS__, 'callableMethod']),
            '[__CLASS__, callableStaticMethod]' => $this->calcHash([__CLASS__, 'callableStaticMethod']),
            '__CLASS__::callableMethod' => $this->calcHash(__CLASS__ . '::callableMethod'),
            '__CLASS__::callableStaticMethod' => $this->calcHash(__CLASS__ . '::callableStaticMethod'),
            'trim' => $this->calcHash('trim'),
            '__NAMESPACE__.\callableFunction' => $this->calcHash(__NAMESPACE__ . '\callableFunction'),
            'new Handler()' => $this->calcHash(new Handler()),
            'anonymous class implements ResponseHandlerInterface{}' => $this->calcHash($responseHandler),
        ];

        foreach ($hashes as $hash) {
            $this->assertNotEmpty($hash);
            $this->assertRegExp('/^[\da-f]{8}$/', $hash);
        }

        $this->assertSame($hashes['[$this, callableMethod]'], $hashes['[__CLASS__, callableMethod]']);
        $this->assertSame($hashes['[$this, callableMethod]'], $hashes['__CLASS__::callableMethod']);
        $this->assertSame($hashes['[__CLASS__, callableStaticMethod]'], $hashes['__CLASS__::callableStaticMethod']);
    }

    /**
     * @param callable $func
     *
     * @throws \ReflectionException
     *
     * @return string
     */
    private function calcHash(callable $func): string
    {
        $hash = HashUtil::hashCallable($func);
        $hash2 = HashUtil::hashCallable($func);

        $this->assertSame($hash, $hash2);

        return $hash;
    }

    /**
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     *
     * @return string
     */
    public function callableMethod(RequestInterface $request, ResponseInterface $response): string
    {
        return $response->getBody()->getContents();
    }

    /**
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     *
     * @return string
     */
    public static function callableStaticMethod(RequestInterface $request, ResponseInterface $response): string
    {
        return $response->getBody()->getContents();
    }
}

/**
 * @param RequestInterface  $request
 * @param ResponseInterface $response
 *
 * @return string
 */
function callableFunction(RequestInterface $request, ResponseInterface $response): string
{
    return $response->getBody()->getContents();
}

/**
 * Class Handler.
 */
class Handler implements ResponseHandlerInterface
{
    /**
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     *
     * @return mixed
     */
    public function __invoke(RequestInterface $request, ResponseInterface $response)
    {
        return $response->getBody()->getContents();
    }
}
