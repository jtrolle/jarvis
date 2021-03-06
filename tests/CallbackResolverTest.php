<?php

namespace Jarvis\Tests;

use Jarvis\Jarvis;
use Jarvis\Skill\DependencyInjection\Reference;
use PHPUnit\Framework\TestCase;

/**
 * @author Eric Chau <eriic.chau@gmail.com>
 */
class CallbackResolverTest extends TestCase
{
    public function test_replace_Reference_by_value_from_container()
    {
        $app = new Jarvis();

        $app['datetime'] = new \DateTime();

        $callback = [new Reference('datetime'), 'getTimestamp'];

        $this->assertNotInstanceOf(\Closure::class, $callback);
        $callback = $app['callbackResolver']->toClosure($callback);
        $this->assertInstanceOf(\Closure::class, $callback);
    }

    public function test_resolve_accept_any_callable_as_callback()
    {
        $app = new Jarvis();

        try {
            $app['callbackResolver']->toClosure([new \DateTime(), 'getTimestamp']);
            $app['callbackResolver']->toClosure(['DateTime', 'createFromFormat']);
            $app['callbackResolver']->toClosure('rand');
            $app['callbackResolver']->toClosure(function () {});
            $this->assertTrue(true);
        } catch (\InvalidArgumentException $e) {
            if ('Provided callback is not callable.' === $e->getMessage()) {
                $this->fail('Php valid callable must successfully be resolved by CallbackResolver.');
            }

            throw $e;
        }
    }

    /**
     * @expectedException        \TypeError
     */
    public function testResolveRaisesExceptionOnInvalidCallback()
    {
        $app = new Jarvis();

        $app['callbackResolver']->toClosure([new \DateTime(), 'hello']);
    }
}
