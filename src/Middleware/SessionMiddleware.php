<?php

namespace Swoft\Session\Middleware;

use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoft\App;
use Swoft\Bean\Annotation\Bean;
use Swoft\Bean\Annotation\Inject;
use Swoft\Helper\ArrayHelper;
use Swoft\Middleware\MiddlewareInterface;
use Swoft\Web\Cookie\Cookie;
use Swoft\Http\Message\Server\Request;
use Swoft\Http\Message\Server\Response;
use Swoft\Session\SessionInterface;
use Swoft\Session\SessionManager;
use Swoft\Session\SessionStore;


/**
 * @Bean()
 * @uses      StartSession
 * @version   2017年12月05日
 * @author    huangzhhui <huangzhwork@gmail.com>
 * @copyright Copyright 2010-2017 Swoft software
 * @license   PHP Version 7.x {@link http://www.php.net/license/3_0.txt}
 */
class StartSession implements MiddlewareInterface
{

    /**
     * Default session name
     *
     * @var string
     */
    CONST DEFAULT_SESSION_NAME = 'SWOFT_SESSION_ID';

    /**
     * Session configs
     *
     * @var array
     */
    protected $config = [];

    /**
     * Indicates if the session was handled for the current request.
     *
     * @var bool
     */
    protected $sessionHandled = false;

    /**
     * @Inject("sessionManager")
     * @var SessionManager
     */
    protected $sessionManager;

    /**
     * @var SessionInterface
     */
    protected $sessionStore;

    /**
     * Process an incoming server request and return a response, optionally delegating
     * response creation to a handler.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @param \Psr\Http\Server\RequestHandlerInterface $handler
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->sessionHandled = true;

        // If a session driver has been configured, we will need to start the session here
        // so that the data is ready for an application. Note that the Laravel sessions
        // do not make use of PHP "native" sessions in any way since they are crappy.
        if ($request instanceof Request && $this->sessionConfigured()) {
            $this->sessionManager->setSession($session = $this->startSession($request));
            // TODO move collect garbage to timer
            $this->collectGarbage($session);
        }

        $response = $handler->handle($request);

        // Again, if the session has been configured we will need to close out the session
        // so that the attributes may be persisted to some storage medium. We will also
        // add the session identifier cookie to the application response headers now.
        if ($request instanceof Request && $this->sessionConfigured()) {
            $this->storeCurrentUrl($request, $session);

            $response = $this->addCookieToResponse($request, $response, $session);

            // Save session after response
            // TODO use coroutine task to save the session data
            $this->save();
        }

        return $response;
    }

    /**
     * @param Request $request
     * @return SessionInterface
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    private function startSession(Request $request): SessionInterface
    {
        $name = $this->config['name'] ?? self::DEFAULT_SESSION_NAME;
        // Get session id from request cookies
        $id = $request->cookie($name, null);
        $handler = $this->sessionManager->createHandlerByConfig();
        $this->sessionStore = new SessionStore($name, $handler, $id);
        $this->sessionStore->start();
        return $this->sessionStore;
    }

    /**
     * @return bool
     */
    private function sessionConfigured(): bool
    {
        return true;
    }

    /**
     * Remove the garbage from the session.
     * This method should call in swoole timer
     *
     * @param  SessionInterface $session
     * @return void
     */
    protected function collectGarbage(SessionInterface $session)
    {
        $session->getHandler()->gc($this->getSessionLifetimeInSeconds());
    }

    /**
     * Store the current URL for the request if necessary.
     *
     * @param Request          $request
     * @param SessionInterface $session
     */
    protected function storeCurrentUrl(Request $request, SessionInterface $session)
    {
        if ($request->getMethod() === 'GET') {
            $session->setPreviousUrl($request->fullUrl());
        }
    }

    /**
     * Add the session cookie to the response·
     *
     * @param Request          $request
     * @param Response         $response
     * @param SessionInterface $session
     * @return Response
     */
    private function addCookieToResponse(Request $request, Response $response, SessionInterface $session): Response
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $domain = $uri->getHost();
        $secure = strtolower($uri->getScheme()) === 'https';
        $httpOnly = true;
        return $response->withCookie(new Cookie($session->getName(), $session->getId(), $this->getCookieExpirationDate(), $path, $domain, $secure, $httpOnly));
    }

    /**
     * Get the session lifetime in seconds.
     *
     * @return \DateTimeInterface|int
     */
    protected function getCookieExpirationDate()
    {
        return (isset($this->config['expire_on_close']) && $this->config['expire_on_close']) ? 0 : Carbon::now()
                                                                                                         ->addMinutes(5 * 60);
    }

    /**
     * Save session data after response
     *
     * @return void
     */
    protected function save()
    {
        if ($this->sessionHandled && $this->sessionConfigured()) {
            $this->sessionStore->save();
        }
    }

    /**
     * Get the cookie lifetime in seconds.
     *
     * @return int
     */
    protected function getSessionLifetimeInSeconds(): int
    {
        return ArrayHelper::get($this->config, 'lifetime', 120) * 60;
    }

}