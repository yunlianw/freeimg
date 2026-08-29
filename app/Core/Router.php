<?php
namespace App\Core;

/**
 * 简易路由
 */
class Router
{
    private array $routes = [
        'GET'  => [],
        'POST' => [],
    ];

    public function get(string $pattern, $handler): void
    {
        $this->routes['GET'][$pattern] = $handler;
    }

    public function post(string $pattern, $handler): void
    {
        $this->routes['POST'][$pattern] = $handler;
    }

    public function match(array $methods, string $pattern, $handler): void
    {
        foreach ($methods as $m) {
            $this->routes[strtoupper($m)][$pattern] = $handler;
        }
    }

    /**
     * 派发
     */
    public function dispatch(Request $request): void
    {
        $method = $request->method;
        $path = $request->path;

        foreach ($this->routes[$method] ?? [] as $pattern => $handler) {
            $regex = $this->compile($pattern);
            if (preg_match($regex, $path, $matches)) {
                // 提取参数
                $params = [];
                foreach ($matches as $k => $v) {
                    if (!is_int($k)) {
                        $params[$k] = $v;
                    }
                }
                $this->invoke($handler, $params, $request);
                return;
            }
        }

        // 404
        http_response_code(404);
        if ($request->isAjax()) {
            Response::json(['error' => 'Not Found'], 404);
        }
        $template = FREEIMG_ROOT . '/views/errors/404.php';
        if (file_exists($template)) {
            require $template;
        } else {
            echo '<h1>404 Not Found</h1>';
        }
    }

    /**
     * 路径转正则
     */
    private function compile(string $pattern): string
    {
        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }

    /**
     * 调用处理器
     * 支持两种控制器签名：
     *   - (Request $request, array $params = [])
     *   - (array $params, Request $request)
     * 通过反射自动判断
     */
    private function invoke($handler, array $params, Request $request): void
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $instance = new $class();
            $args = $this->resolveArgs($class, $method, $params, $request);
            $instance->$method(...$args);
        } elseif (is_callable($handler)) {
            $args = $this->resolveCallableArgs($handler, $params, $request);
            $handler(...$args);
        } else {
            http_response_code(500);
            echo "Invalid route handler";
        }
    }

    /**
     * 控制器方法的参数解析
     */
    private function resolveArgs(string $class, string $method, array $params, Request $request): array
    {
        try {
            $ref = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException $e) {
            return [$request, $params];
        }
        return $this->orderArgs($ref->getParameters(), $params, $request);
    }

    /**
     * callable 闭包/函数的参数解析
     */
    private function resolveCallableArgs(callable $callable, array $params, Request $request): array
    {
        try {
            $ref = new \ReflectionFunction(\Closure::fromCallable($callable));
        } catch (\ReflectionException $e) {
            return [$params, $request];
        }
        return $this->orderArgs($ref->getParameters(), $params, $request);
    }

    /**
     * 根据参数类型按顺序装配
     */
    private function orderArgs(array $refParams, array $params, Request $request): array
    {
        $args = [];
        foreach ($refParams as $p) {
            $type = $p->getType();
            if ($type && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($typeName === Request::class || is_subclass_of($typeName, Request::class)) {
                    $args[] = $request;
                    continue;
                }
            }
            if ($p->getName() === 'params' || $p->getName() === 'matches') {
                $args[] = $params;
                continue;
            }
            // 默认填充 Request（兼容老式签名）
            $args[] = $request;
        }
        return $args ?: [$request];
    }
}