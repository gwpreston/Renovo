<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Security\ScopeViolationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Handlers\ErrorHandler;
use Slim\Views\Twig;
use Throwable;

/**
 * Renders errors as pages rather than stack traces.
 *
 * Two rules matter here. A scope violation becomes a 404, not a 403, so that
 * probing for another household's row ids tells the prober nothing. And in
 * production the message shown is always generic: the detail goes to the log,
 * where it is useful, rather than to the browser, where it is a disclosure.
 */
final class HttpErrorHandler extends ErrorHandler
{
    public function __construct(
        \Slim\Interfaces\CallableResolverInterface $callableResolver,
        \Psr\Http\Message\ResponseFactoryInterface $responseFactory,
        private readonly Twig $view,
        private readonly bool $debug,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($callableResolver, $responseFactory, $logger);
    }

    protected function respond(): ResponseInterface
    {
        $exception = $this->exception;
        $status = 500;
        $title = 'Something went wrong';
        $message = 'An unexpected error occurred. The details have been logged.';

        if ($exception instanceof ScopeViolationException) {
            // Indistinguishable from "no such row" on purpose.
            $status = 404;
            $title = 'Not found';
            $message = 'That page does not exist, or you do not have access to it.';
        } elseif ($exception instanceof HttpNotFoundException) {
            $status = 404;
            $title = 'Not found';
            $message = 'That page does not exist.';
        } elseif ($exception instanceof HttpForbiddenException) {
            $status = 403;
            $title = 'Not allowed';
            $message = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'Your role does not permit that action.';
        } elseif ($exception instanceof HttpMethodNotAllowedException) {
            $status = 405;
            $title = 'Method not allowed';
            $message = 'That address does not accept this kind of request.';
        } elseif ($exception instanceof HttpException) {
            $status = $exception->getCode() >= 400 && $exception->getCode() < 600
                ? (int) $exception->getCode()
                : 400;
            $title = $exception->getTitle();
            $message = $exception->getMessage();
        }

        if ($this->displayErrorDetails && $status === 500) {
            $message = $exception->getMessage();
        }

        $response = $this->responseFactory->createResponse($status);

        // An htmx request gets a plain body: swapping a full error page into a
        // table fragment would leave the user looking at a nested layout.
        if ($this->request->getHeaderLine('HX-Request') === 'true') {
            $response->getBody()->write($title . ': ' . $message);

            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $this->view->render($response, 'error.twig', [
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'trace' => $this->debug ? $exception->getTraceAsString() : null,
            'flashes' => [],
            'current_user' => null,
            'scope' => null,
            'current_path' => $this->request->getUri()->getPath(),
        ]);
    }
}
