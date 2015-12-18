<?php

declare(strict_types=1);

namespace Jarvis\Skill\EventBroadcaster;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author Eric Chau <eriic.chau@gmail.com>
 */
class ResponseEvent extends SimpleEvent
{
    private $request;
    private $response;

    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * @codeCoverageIgnore
     *
     * @return Request
     */
    public function getRequest() : Request
    {
        return $this->request;
    }

    /**
     * @codeCoverageIgnore
     *
     * @return Response
     */
    public function getResponse() : Response
    {
        return $this->response;
    }
}
