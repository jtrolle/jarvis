<?php

declare(strict_types=1);

namespace Jarvis\Skill\EventBroadcaster;

use Symfony\Component\HttpFoundation\Response;

/**
 * @author Eric Chau <eriic.chau@gmail.com>
 */
class ExceptionEvent extends SimpleEvent
{
    private $exception;
    private $response;

    public function __construct(\Throwable $exception)
    {
        $this->exception = $exception;
    }

    public function exception(): \Throwable
    {
        return $this->exception;
    }

    public function response(): ?Response
    {
        return $this->response;
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
        $this->stopPropagation();
    }

    /**
     * Forbids stop of current event propagation if response is not setted.
     *
     * {@inheritdoc}
     */
    public function stopPropagation(): void
    {
        if (null === $this->response) {
            return;
        }

        parent::stopPropagation();
    }
}
