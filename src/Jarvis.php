<?php

declare(strict_types=1);

namespace Jarvis;

use FastRoute\Dispatcher;
use Jarvis\Skill\DependencyInjection\Container;
use Jarvis\Skill\DependencyInjection\ContainerProvider;
use Jarvis\Skill\DependencyInjection\ContainerProviderInterface;
use Jarvis\Skill\EventBroadcaster\AnalyzeEvent;
use Jarvis\Skill\EventBroadcaster\ControllerEvent;
use Jarvis\Skill\EventBroadcaster\EventInterface;
use Jarvis\Skill\EventBroadcaster\ExceptionEvent;
use Jarvis\Skill\EventBroadcaster\ResponseEvent;
use Jarvis\Skill\EventBroadcaster\JarvisEvents;
use Jarvis\Skill\EventBroadcaster\SimpleEvent;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jarvis. Minimalist dependency injection container.
 *
 * @author Eric Chau <eriic.chau@gmail.com>
 */
class Jarvis extends Container
{
    const DEFAULT_DEBUG = false;
    const CONTAINER_PROVIDER_FQCN = ContainerProvider::class;
    const DEFAULT_SCOPE = 'default';

    const RECEIVER_HIGH_PRIORITY = 2;
    const RECEIVER_NORMAL_PRIORITY = 1;
    const RECEIVER_LOW_PRIORITY = 0;

    private $receivers = [];
    private $computedReceivers = [];
    private $masterEmitter = false;
    private $masterSet = false;

    /**
     * Creates an instance of Jarvis. It can take settings as first argument.
     * List of accepted options:
     *   - container_provider (type: string|array): fqcn of your container provider
     *
     * @param  array $settings Your own settings to modify Jarvis behavior
     */
    public function __construct(array $settings = [])
    {
        parent::__construct();

        $this['settings'] = new ParameterBag($settings);
        $this->lock('settings');

        $this['debug'] = $this->settings->getBoolean('debug', static::DEFAULT_DEBUG);
        $this->lock('debug');

        if (!$this->settings->has('container_provider')) {
            $this->settings->set('container_provider', [static::CONTAINER_PROVIDER_FQCN]);
        } else {
            $containerProvider = $this->settings->get('container_provider');
            array_unshift($containerProvider, static::CONTAINER_PROVIDER_FQCN);
            $this->settings->set('container_provider', $containerProvider);
        }

        foreach ($this->settings->get('container_provider') as $classname) {
            $this->hydrate(new $classname());
        }
    }

    public function __destruct()
    {
        $this->masterBroadcast(JarvisEvents::TERMINATE_EVENT);
    }

    /**
     * This method is an another way to get a locked value.
     *
     * Example: $this['foo'] is equal to $this->foo, but it ONLY works for locked values.
     *
     * @param  string $key The key of the locked value
     * @return mixed
     * @throws \InvalidArgumentException if the requested key is not associated to a locked service
     */
    public function __get(string $key)
    {
        if (!isset($this->locked[$key])) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a key of a locked value.', $key));
        }

        $this->masterSetter($key, $this[$key]);

        return $this->$key;
    }

    /**
     * Sets new attributes to Jarvis. Note that this method is reserved to Jarvis itself only.
     *
     * @param string $key   The key name of the new attribute
     * @param mixed  $value The value to associate to provided key
     * @throws \LogicException if this method is not called by Jarvis itself
     */
    public function __set(string $key, $value)
    {
        if (false === $this->masterSet) {
            throw new \LogicException('You are not allowed to set new attribute into Jarvis.');
        }

        $this->$key = $value;
    }

    /**
     * @param  Request|null $request
     * @return Response
     */
    public function analyze(Request $request = null) : Response
    {
        $request = $request ?? $this->request;
        $response = null;

        try {
            $this->masterBroadcast(JarvisEvents::ANALYZE_EVENT, $analyzeEvent = new AnalyzeEvent($request));

            if ($response = $analyzeEvent->getResponse()) {
                return $response;
            }

            $routeInfo = $this->router->match($request->getMethod(), $request->getPathInfo());
            if (Dispatcher::FOUND === $routeInfo[0]) {
                $callback = $this->callback_resolver->resolve($routeInfo[1]);

                $event = new ControllerEvent($callback, $routeInfo[2]);
                $this->masterBroadcast(JarvisEvents::CONTROLLER_EVENT, $event);

                $response = call_user_func_array($event->getCallback(), $event->getArguments());

                if (is_string($response)) {
                    $response = new Response($response);
                }
            } elseif (Dispatcher::NOT_FOUND === $routeInfo[0] || Dispatcher::METHOD_NOT_ALLOWED === $routeInfo[0]) {
                $response = new Response(null, Dispatcher::NOT_FOUND === $routeInfo[0]
                    ? Response::HTTP_NOT_FOUND
                    : Response::HTTP_METHOD_NOT_ALLOWED
                );
            }

            $this->masterBroadcast(JarvisEvents::RESPONSE_EVENT, new ResponseEvent($request, $response));
        } catch (\Exception $exception) {
            $this->masterBroadcast(JarvisEvents::EXCEPTION_EVENT, $exceptionEvent = new ExceptionEvent($exception));
            $response = $exceptionEvent->getResponse();
        }

        return $response;
    }

    /**
     * @param  string  $eventName
     * @param  mixed   $receiver
     * @param  integer $priority
     * @return self
     */
    public function addReceiver(string $eventName, $receiver, int $priority = self::RECEIVER_NORMAL_PRIORITY) : Jarvis
    {
        if (!isset($this->receivers[$eventName])) {
            $this->receivers[$eventName] = [
                self::RECEIVER_LOW_PRIORITY    => [],
                self::RECEIVER_NORMAL_PRIORITY => [],
                self::RECEIVER_HIGH_PRIORITY   => [],
            ];
        }

        $this->receivers[$eventName][$priority][] = $receiver;
        $this->computedReceivers[$eventName] = null;

        return $this;
    }

    /**
     * @param  string              $eventName
     * @param  EventInterface|null $event
     * @return self
     */
    public function broadcast(string $eventName, EventInterface $event = null) : Jarvis
    {
        if (!$this->masterEmitter && in_array($eventName, JarvisEvents::RESERVED_EVENT_NAMES)) {
            throw new \LogicException(sprintf(
                'You\'re trying to broadcast "$eventName" but "%s" are reserved event names.',
                implode('|', JarvisEvents::RESERVED_EVENT_NAMES)
            ));
        }

        if (isset($this->receivers[$eventName])) {
            $event = $event ?? new SimpleEvent();
            foreach ($this->buildEventReceivers($eventName) as $receiver) {
                call_user_func_array($this->callback_resolver->resolve($receiver), [$event]);

                if ($event->isPropagationStopped()) {
                    break;
                }
            }
        }

        return $this;
    }

    /**
     * @param  string $provider
     * @return self
     */
    public function hydrate(ContainerProviderInterface $provider) : Jarvis
    {
        call_user_func([$provider, 'hydrate'], $this);

        return $this;
    }

    /**
     * Enables master emitter mode.
     *
     * @return self
     */
    private function masterBroadcast(string $eventName, EventInterface $event = null) : Jarvis
    {
        $this->masterEmitter = true;
        $this->broadcast($eventName, $event);
        $this->masterEmitter = false;

        return $this;
    }

    /**
     * Sets new attribute into Jarvis.
     *
     * @param  string $key   The name of the new attribute
     * @param  mixed  $value The value of the new attribute
     * @return self
     */
    private function masterSetter(string $key, $value) : Jarvis
    {
        $this->masterSet = true;
        $this->$key = $value;
        $this->masterSet = false;

        return $this;
    }

    /**
     * Builds and returns well ordered receivers collection that match with provided event name.
     *
     * @param  string $eventName The event name we want to get its receivers
     * @return array
     */
    private function buildEventReceivers(string $eventName)
    {
        return $this->computedReceivers[$eventName] = $this->computedReceivers[$eventName] ?? array_merge(
            $this->receivers[$eventName][self::RECEIVER_HIGH_PRIORITY],
            $this->receivers[$eventName][self::RECEIVER_NORMAL_PRIORITY],
            $this->receivers[$eventName][self::RECEIVER_LOW_PRIORITY]
        );
    }
}
