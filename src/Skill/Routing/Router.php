<?php

declare(strict_types=1);

namespace Jarvis\Skill\Routing;

use FastRoute\DataGenerator\GroupCountBased as DataGenerator;
use FastRoute\Dispatcher\GroupCountBased as Dispatcher;
use FastRoute\RouteParser\Std as Parser;
use FastRoute\RouteCollector;
use Jarvis\Skill\Core\ScopeManager;
use Jarvis\Jarvis;

/**
 * @author Eric Chau <eriic.chau@gmail.com>
 */
class Router extends Dispatcher
{
    private $rawRoutes = [];
    private $routeCollector;
    private $compilationKey;
    private $scopeManager;

    public function __construct(ScopeManager $scopeManager)
    {
        $this->scopeManager = $scopeManager;
    }

    /**
     * Alias to Router's route collector ::addRoute method.
     * @see RouteCollector::addRoute
     */
    public function addRoute(string $httpMethod, string $route, $handler, string $scope = Jarvis::DEFAULT_SCOPE) : Router
    {
        $this->rawRoutes[$scope] = $this->rawRoutes[$scope] ?? [];
        $this->rawRoutes[$scope][] = [strtolower($httpMethod), $route, $handler];
        $this->compilationKey = null;

        return $this;
    }

    /**
     * Alias of GroupCountBased::dispatch.
     * {@inheritdoc}
     */
    public function match(string $httpMethod, string $uri)
    {
        return $this->dispatch($httpMethod, $uri);
    }

    public function dispatch($httpMethod, $uri)
    {
        list($this->staticRouteMap, $this->variableRouteData) = $this->getRouteCollector()->getData();

        return parent::dispatch(strtolower($httpMethod), $uri);
    }

    private function getRouteCollector() : RouteCollector
    {
        $key = $this->generateCompilationKey();
        if (null === $this->compilationKey || $this->compilationKey !== $key) {
            $this->compilationKey = $key;
            $this->routeCollector = new RouteCollector(new Parser(), new DataGenerator());

            $enabledRoutes = [];
            foreach ($this->rawRoutes as $scope => $rawRoutes) {
                if ($this->scopeManager->isEnabled($scope)) {
                    $enabledRoutes = array_merge($enabledRoutes, $rawRoutes);
                }
            }

            foreach ($enabledRoutes as $rawRoute) {
                list($httpMethod, $route, $handler) = $rawRoute;
                $this->routeCollector->addRoute($httpMethod, $route, $handler);
            }
        }

        return $this->routeCollector;
    }

    private function generateCompilationKey() : string
    {
        return md5(implode(',', $this->scopeManager->getAll()));
    }
}
