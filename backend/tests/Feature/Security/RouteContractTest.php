<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

class RouteContractTest extends TestCase
{
    public function test_every_api_controller_action_exists_and_has_its_route_parameters(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! str_contains($route->getActionName(), '@')) {
                continue;
            }
            [$controller, $action] = explode('@', $route->getActionName(), 2);
            $context = implode('|', $route->methods()).' '.$route->uri();
            $this->assertTrue(method_exists($controller, $action), "{$context}: missing {$controller}::{$action}");
            $method = new ReflectionMethod($controller, $action);
            $this->assertTrue($method->isPublic(), "{$context}: action must be public");

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (! $type instanceof ReflectionNamedType || $parameter->isOptional()) {
                    continue;
                }
                if (! $type->isBuiltin() && ! is_a($type->getName(), Model::class, true)) {
                    continue; // Request and service dependencies are container-resolved.
                }
                $names = [$parameter->getName(), Str::snake($parameter->getName())];
                $available = [...$route->parameterNames(), ...array_keys($route->defaults)];
                $this->assertNotEmpty(array_intersect($names, $available), "{$context}: missing binding for \${$parameter->getName()}");
            }
        }
    }
}
