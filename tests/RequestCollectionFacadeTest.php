<?php
namespace InterNations\Component\HttpMock\Tests;

use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use InterNations\Component\HttpMock\RequestCollectionFacade;
use InterNations\Component\Testing\AbstractTestCase;
use InterNations\Component\HttpMock\Tests\Fixtures\Request as TestRequest;
use PHPUnit\Framework\MockObject\MockObject;

class RequestCollectionFacadeTest extends AbstractTestCase
{
    /** @var ClientInterface|MockObject */
    private $client;

    private Request $request;

    private RequestCollectionFacade $facade;

    public function setUp(): void
    {
        $this->client = $this->createMock(ClientInterface::class);
        $this->facade = new RequestCollectionFacade($this->client);
        $this->request = new Request('GET', '/_request/last');
        $this->request->setClient($this->client);
    }

    /** @return array<array{0:string,1:string,2:array<mixed>,3:string}> */
    public static function getMethodAndUrls(): array
    {
        return [
            ['latest', '/_request/last'],
            ['first', '/_request/first'],
            ['last', '/_request/last'],
            ['at', '/_request/0', [0]],
            ['shift', '/_request/first', [], 'delete'],
            ['pop', '/_request/last', [], 'delete'],
        ];
    }

    /**
     * @dataProvider getMethodAndUrls
     * @param array<mixed> $args
     */
    public function testRequestingLatestRequest(
        string $method,
        string $path,
        array $args = [],
        string $httpMethod = 'get'
    ): void
    {
        $this->mockClient($path, $this->createSimpleResponse(), $httpMethod);

        $request = call_user_func_array([$this->facade, $method], $args);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/foo', $request->getRequestUri());
        self::assertSame('RECORDED=1', $request->getContent());
    }

    /**
     * @dataProvider getMethodAndUrls
     * @param array<mixed> $args
     */
    public function testRequestLatestResponseWithHttpAuth(
        string $method,
        string $path,
        array $args = [],
        string $httpMethod = 'get'
    ): void
    {
        $this->mockClient($path, $this->createComplexResponse(), $httpMethod);

        /** @var \Symfony\Component\HttpFoundation\Request $request */
        $request = call_user_func_array([$this->facade, $method], $args);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/foo', $request->getRequestUri());
        self::assertSame('RECORDED=1', $request->getContent());
        self::assertSame('host', $request->getHost());
        self::assertSame(1234, $request->getPort());
        self::assertSame('username', $request->getUser());
        self::assertSame('password', $request->getPassword());
        self::assertSame('CUSTOM UA', $request->headers->get('User-Agent'));
    }

    /**
     * @dataProvider getMethodAndUrls
     * @param array<mixed> $args
     */
    public function testRequestResponseWithInvalidStatusCode(
        string $method,
        string $path,
        array $args = [],
        string $httpMethod = 'get'
    ): void
    {
        $this->mockClient($path, $this->createResponseWithInvalidStatusCode(), $httpMethod);

        $this->expectException('UnexpectedValueException');

        $this->expectExceptionMessage('Expected status code 200 from "' . $path . '", got 404');
        call_user_func_array([$this->facade, $method], $args);
    }

    /**
     * @dataProvider getMethodAndUrls
     * @param array<mixed> $args
     */
    public function testRequestResponseWithEmptyContentType(
        string $method,
        string $path,
        array $args = [],
        string $httpMethod = 'get'
    ): void
    {
        $this->mockClient($path, $this->createResponseWithEmptyContentType(), $httpMethod);

        $this->expectException('UnexpectedValueException');

        $this->expectExceptionMessage('Expected content type "text/plain" from "' . $path . '", got ""');
        call_user_func_array([$this->facade, $method], $args);
    }

    /**
     * @dataProvider getMethodAndUrls
     * @param array<mixed> $args
     */
    public function testRequestResponseWithInvalidContentType(
        string $method,
        string $path,
        array $args = [],
        string $httpMethod = 'get'
    ): void
    {
        $this->mockClient($path, $this->createResponseWithInvalidContentType(), $httpMethod);

        $this->expectException('UnexpectedValueException');

        $this->expectExceptionMessage('Expected content type "text/plain" from "' . $path . '", got "text/html"');
        call_user_func_array([$this->facade, $method], $args);
    }

    /**
     * @dataProvider getMethodAndUrls
     * @param array<mixed> $args
     */
    public function testRequestResponseWithDeserializationError(
        string $method,
        string $path,
        array $args = [],
        string $httpMethod = 'get'
    ): void
    {
        $this->mockClient($path, $this->createResponseThatCannotBeDeserialized(), $httpMethod);

        $this->expectException('UnexpectedValueException');

        $this->expectExceptionMessage('Cannot deserialize response from "' . $path . '": "invalid response"');
        call_user_func_array([$this->facade, $method], $args);
    }

    private function mockClient(string $path, Response $response, string $method): void
    {
        $this->client
            ->expects(self::once())
            ->method($method)
            ->with($path)
            ->willReturn($this->request);

        $this->client
            ->expects(self::once())
            ->method('send')
            ->with($this->request)
            ->willReturn($response);
    }

    private function createSimpleResponse(): Response
    {
        $recordedRequest = new TestRequest();
        $recordedRequest->setMethod('POST');
        $recordedRequest->setRequestUri('/foo');
        $recordedRequest->setContent('RECORDED=1');

        return new Response(
            '200',
            ['Content-Type' => 'text/plain'],
            serialize($recordedRequest)
        );
    }

    private function createComplexResponse(): Response
    {
        $recordedRequest = new TestRequest();
        $recordedRequest->setMethod('POST');
        $recordedRequest->setRequestUri('/foo');
        $recordedRequest->setContent('RECORDED=1');
        $recordedRequest->server->set('SERVER_NAME', 'host');
        $recordedRequest->server->set('SERVER_PORT', 1234);
        $recordedRequest->headers->set('Php-Auth-User', 'username');
        $recordedRequest->headers->set('Php-Auth-Pw', 'password');
        $recordedRequest->headers->set('User-Agent', 'CUSTOM UA');


        return new Response(
            '200',
            ['Content-Type' => 'text/plain; charset=UTF-8'],
            serialize($recordedRequest)
        );
    }

    private function createResponseWithInvalidStatusCode(): Response
    {
        return new Response(404);
    }

    private function createResponseWithInvalidContentType(): Response
    {
        return new Response(200, ['Content-Type' => 'text/html']);
    }

    private function createResponseWithEmptyContentType(): Response
    {
        return new Response(200, []);
    }

    private function createResponseThatCannotBeDeserialized(): Response
    {
        return new Response(200, ['Content-Type' => 'text/plain'], 'invalid response');
    }
}
