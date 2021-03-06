<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace ZfrEbWorkerTest\Middleware;

use Interop\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response;
use Zend\Diactoros\ServerRequest;
use Zend\Diactoros\Stream;
use ZfrEbWorker\Exception\InvalidArgumentException;
use ZfrEbWorker\Exception\RuntimeException;
use ZfrEbWorker\Middleware\WorkerMiddleware;

class WorkerMiddlewareTest extends \PHPUnit_Framework_TestCase
{
    public function testThrowsExceptionIfNoMappedMiddleware()
    {
        $middleware = new WorkerMiddleware([], $this->prophesize(ContainerInterface::class)->reveal());

        $this->setExpectedException(
            RuntimeException::class,
            'No middleware was mapped for message "message-name". Did you fill the "zfr_eb_worker" configuration?'
        );

        $middleware($this->createRequest(), new Response(), function() {
            $this->fail('$next should not be called');
        });
    }

    public function testThrowsExceptionIfInvalidMappedMiddlewareType()
    {
        $middleware = new WorkerMiddleware(['message-name' => 10], $this->prophesize(ContainerInterface::class)->reveal());

        $this->setExpectedException(
            InvalidArgumentException::class,
            'Mapped middleware must be either a string or an array of strings, integer given.'
        );

        $middleware($this->createRequest(), new Response(), function() {
            $this->fail('$next should not be called');
        });
    }

    public function testThrowsExceptionIfInvalidMappedMiddlewareClass()
    {
        $middleware = new WorkerMiddleware(['message-name' => new \stdClass()], $this->prophesize(ContainerInterface::class)->reveal());

        $this->setExpectedException(
            InvalidArgumentException::class,
            'Mapped middleware must be either a string or an array of strings, stdClass given.'
        );

        $middleware($this->createRequest(), new Response(), function() {
            $this->fail('$next should not be called');
        });
    }

    /**
     * @dataProvider mappedMiddlewaresProvider
     *
     * @param array|string $mappedMiddlewares
     * @param int          $expectedCounter
     */
    public function testDispatchesMappedMiddlewares($mappedMiddlewares, int $expectedCounter)
    {
        $container  = $this->prophesize(ContainerInterface::class);
        $middleware = new WorkerMiddleware(['message-name' => $mappedMiddlewares], $container->reveal());
        $request    = $this->createRequest();
        $response   = new Response();

        if (is_string($mappedMiddlewares)) {
            $mappedMiddlewares = (array) $mappedMiddlewares;
        }

        foreach ($mappedMiddlewares as $mappedMiddleware) {
            $container->get($mappedMiddleware)->shouldBeCalled()->willReturn([$this, 'incrementMiddleware']);
        }

        $outWasCalled    = false;
        $responseFromOut = new Response();

        $out = function ($request, ResponseInterface $response) use (&$outWasCalled, $expectedCounter, $responseFromOut) {
            $outWasCalled = true;

            $this->assertEquals('default-queue', $request->getAttribute(WorkerMiddleware::MATCHED_QUEUE_ATTRIBUTE));
            $this->assertEquals('123abc', $request->getAttribute(WorkerMiddleware::MESSAGE_ID_ATTRIBUTE));
            $this->assertEquals('message-name', $request->getAttribute(WorkerMiddleware::MESSAGE_NAME_ATTRIBUTE));
            $this->assertEquals(['id' => 123], $request->getAttribute(WorkerMiddleware::MESSAGE_PAYLOAD_ATTRIBUTE));
            $this->assertEquals($expectedCounter, $request->getAttribute('counter', 0));
            $this->assertEquals($expectedCounter, $response->hasHeader('counter') ? $response->getHeaderLine('counter') : 0);

            return $responseFromOut;
        };

        $returnedResponse = $middleware($request, $response, $out);

        $this->assertTrue($outWasCalled, 'Make sure that $out middleware was called');
        $this->assertSame($responseFromOut, $returnedResponse, 'Make sure that it returns response from $out');
    }

    public function testReturnsResponseIfNoOutMiddlewareIsProvided()
    {
        $middleware = new WorkerMiddleware(['message-name' => []], $this->prophesize(ContainerInterface::class)->reveal());
        $request    = $this->createRequest();
        $response   = new Response();

        $returnedResponse = $middleware($request, $response);

        $this->assertSame($response, $returnedResponse);
    }

    public function incrementMiddleware(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
    {
        $counter  = $request->getAttribute('counter', 0) + 1;
        $request  = $request->withAttribute('counter', $counter);
        $response = $response->withHeader('counter', (string) $counter);

        return $next($request, $response);
    }

    public function mappedMiddlewaresProvider(): array
    {
        return [
            [[], 0],
            ['FooMiddleware', 1],
            [['FooMiddleware'], 1],
            [['FooMiddleware', 'BarMiddleware'], 2],
            [['FooMiddleware', 'BarMiddleware', 'BazMiddleware'], 3],
        ];
    }

    private function createRequest(): ServerRequestInterface
    {
        $request = new ServerRequest();
        $request = $request->withHeader('X-Aws-Sqsd-Queue', 'default-queue');
        $request = $request->withHeader('X-Aws-Sqsd-Msgid', '123abc');
        $request = $request->withBody(new Stream('php://temp', 'w'));

        $request->getBody()->write(json_encode(['name' => 'message-name', 'payload' => ['id' => 123]]));

        return $request;
    }
}
