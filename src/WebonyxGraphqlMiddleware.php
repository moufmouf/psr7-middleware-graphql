<?php

declare(strict_types=1);

namespace PsCs\Middleware\Graphql;

use GraphQL\Error\Debug;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Server\StandardServer;
use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use const JSON_ERROR_NONE;
use function array_map;
use function explode;
use function in_array;
use function is_array;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;

final class WebonyxGraphqlMiddleware implements MiddlewareInterface
{
    /** @var StandardServer */
    private $standardServer;
    /** @var string */
    private $graphqlUri;
    /** @var string[] */
    private $graphqlHeaderList = ['application/graphql'];
    /** @var string[] */
    private $allowedMethods = [
        'GET',
        'POST',
    ];

    /** @var bool|int */
    protected $debug = false;
    /** @var ResponseFactoryInterface */
    private $responseFactory;
    /** @var StreamFactoryInterface */
    private $streamFactory;

    /**
     * @param bool|int $debug
     */
    public function __construct(
        StandardServer $handler,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        string $graphqlUri = '/graphql',
        $debug = Debug::RETHROW_UNSAFE_EXCEPTIONS
    ) {
        $this->standardServer  = $handler;
        $this->responseFactory = $responseFactory;
        $this->streamFactory   = $streamFactory;
        $this->graphqlUri      = $graphqlUri;
        $this->debug           = $debug;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface
    {
        if (! $this->isGraphqlRequest($request)) {
            return $handler->handle($request);
        }
        /*if (strtoupper($request->getMethod()) === 'GET') {
            $params              = $request->getQueryParams();
            $params['variables'] = empty($params['variables']) ? null : $params['variables'];
            $request             = $request->withQueryParams($params);
        } else {
            $params              = $request->getParsedBody();
            $params['variables'] = empty($params['variables']) ? null : $params['variables'];
            $request             = $request->withParsedBody($params);
        }*/

        $result = $this->standardServer->executePsrRequest($request);

        return $this->getJsonResponse($this->processResult($result));
    }

    /**
     * @param ExecutionResult|ExecutionResult[]|Promise $result
     *
     * @return mixed[]
     */
    private function processResult($result) : array
    {
        if ($result instanceof ExecutionResult) {
            return $result->toArray($this->debug);
        }

        if (is_array($result)) {
            return array_map(function (ExecutionResult $executionResult) {
                return $executionResult->toArray($this->debug);
            }, $result);
        }

        if ($result instanceof Promise) {
            throw new RuntimeException('Only SyncPromiseAdapter is supported');
        }

        throw new RuntimeException('Unexpected response from StandardServer::executePsrRequest'); // @codeCoverageIgnore
    }

    private function isGraphqlRequest(ServerRequestInterface $request) : bool
    {
        return $this->isMethodAllowed($request) && ($this->hasUri($request) || $this->hasGraphQLHeader($request));
    }

    private function isMethodAllowed(ServerRequestInterface $request) : bool
    {
        return in_array($request->getMethod(), $this->allowedMethods, true);
    }

    private function hasUri(ServerRequestInterface $request) : bool
    {
        return $this->graphqlUri === $request->getUri()->getPath();
    }

    private function hasGraphQLHeader(ServerRequestInterface $request) : bool
    {
        if (! $request->hasHeader('content-type')) {
            return false;
        }

        $requestHeaderList = array_map('trim', explode(',', $request->getHeaderLine('content-type')));

        foreach ($this->graphqlHeaderList as $allowedHeader) {
            if (in_array($allowedHeader, $requestHeaderList, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed[] $array
     */
    private function getJsonResponse(array $array) : ResponseInterface
    {
        $response = $this->responseFactory->createResponse();
        $data     = json_encode($array);

        if (json_last_error() !== JSON_ERROR_NONE || $data === false) {
            throw new InvalidArgumentException(json_last_error_msg()); // @codeCoverageIgnore
        }

        $stream   = $this->streamFactory->createStream($data);
        $response = $response->withBody($stream)
            ->withHeader('Content-Type', 'application/json');

        return $response;
    }
}
