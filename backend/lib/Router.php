<?php

/**
 * @author      Enea Krähenbühl <inquiry@3n3a.ch>
 * @copyright   Copyright (c), 2022 Enea Krähenbühl
 * @license     MIT public license
 */
namespace M133;

class RouterHelper {

    /**
     * getRequestHeaders
     * Returns an array of headers
     */
    public static function getRequestHeaders()
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            if ($headers !== false) {
                return $headers;
            }
        }

        foreach ($_SERVER as $key => $value) {
            if ((substr($key, 0, 5) == 'HTTP_') || ($key == 'CONTENT_TYPE') || ($key == 'CONTENT_LENGTH')) {
                $headers[str_replace(array(' ', 'Http'), array('-', 'HTTP'), ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))))] = $value;
            }
        }

        return $headers;
    }
    
    public static function getRequestMethod()
    {
        $method = $_SERVER['REQUEST_METHOD'];

        if ($_SERVER['REQUEST_METHOD'] == 'HEAD') {
            ob_start();
            $method = 'GET';
        }

        elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $headers = $this->getRequestHeaders();

            // overriding of method via header
            if (isset($headers['X-HTTP-Method-Override']) && in_array($headers['X-HTTP-Method-Override'], array('PUT', 'DELETE', 'PATCH'))) {
                $method = $headers['X-HTTP-Method-Override'];
            }
        }

        return $method;
    }

    public static function isHead() {
        $_SERVER['REQUEST_METHOD'] == 'HEAD'
    }
}

class ExpressRouter
{
    private $routes = array();

    private $middlewareRoutes = array();

    protected $notFoundCallback = [];

    private $baseRoute = '';

    private $requestedMethod = '';

    private $serverBasePath;

    private $namespace = '';

    public function registerMiddleware($methods, $pattern, $function)
    {
        $this->match($methods, $pattern, $function, true);
    }

    public function match($methods, $pattern, $function, $isMiddleware=false)
    {
        $pattern = $this->baseRoute . '/' . trim($pattern, '/');
        $pattern = $this->baseRoute ? rtrim($pattern, '/') : $pattern;

        foreach ($methods as $method) {
            if ( $isMiddleware ) {
                $this->middlewareRoutes[$method][] = array(
                  'pattern' => $pattern,
                  'function' => $function,
                );
            } else {
                $this->routes[$method][] = array(
                  'pattern' => $pattern,
                  'function' => $function,
                );
            }
        }
    }


    public function all($pattern, $function)
    {
        $this->match(['GET','POST','PUT','DELETE','OPTIONS','PATCH','HEAD'], $pattern, $function);
    }


    public function get($pattern, $function)
    {
        $this->match(['GET'], $pattern, $function);
    }


    public function post($pattern, $function)
    {
        $this->match(['POST'], $pattern, $function);
    }


    public function patch($pattern, $function)
    {
        $this->match(['PATCH'], $pattern, $function);
    }


    public function delete($pattern, $function)
    {
        $this->match(['DELETE'], $pattern, $function);
    }


    public function put($pattern, $function)
    {
        $this->match(['PUT'], $pattern, $function);
    }


    public function options($pattern, $function)
    {
        $this->match(['OPTIONS'], $pattern, $function);
    }

    
    /**
     * mount
     * Mounts a given route, allows for separating routers
     * @param  mixed $baseRoute
     * @param  mixed $function
     * @return void
     */
    public function mount($baseRoute, $function)
    {
        $currentBaseRoute = $this->baseRoute;

        $this->baseRoute .= $baseRoute;

        call_user_func($function);

        $this->baseRoute = $currentBaseRoute;
    }

    public function run($callback = null)
    {
        $this->requestedMethod = RouterHelper::getRequestMethod();

        if (isset($this->middlewareRoutes[$this->requestedMethod])) {
            $this->handle($this->middlewareRoutes[$this->requestedMethod]);
        }

        $numberHandled = 0;
        if (isset($this->routes[$this->requestedMethod])) {
            $numberHandled = $this->handle($this->routes[$this->requestedMethod], true);
        }

        if ($numberHandled === 0) {
            $this->triggerNotFoundPage($this->routes[$this->requestedMethod]);
        }
        else {
            if ($callback && is_callable($callback)) {
                $callback();
            }
        }

        if ( RouterHelper::isHead() ) {
            ob_end_clean();
        }

        return $numberHandled !== 0;
    }

    public function setNotFoundPage($match_function, $function = null)
    {
      if (!is_null($function)) {
        $this->notFoundCallback[$match_function] = $function;
      } else {
        $this->notFoundCallback['/'] = $match_function;
      }
    }

    public function triggerNotFoundPage($match = null){

        $numberHandled = 0;

        if (count($this->notFoundCallback) > 0)
        {
            foreach ($this->notFoundCallback as $route_pattern => $route_callable) {

              $matches = [];

              $is_match = $this->patternMatches($route_pattern, $this->getCurrentUri(), $matches, PREG_OFFSET_CAPTURE);

              if ($is_match) {

                $matches = array_slice($matches, 1);

                $params = array_map(function ($match, $index) use ($matches) {

                  if (isset($matches[$index + 1]) && isset($matches[$index + 1][0]) && is_array($matches[$index + 1][0])) {
                    if ($matches[$index + 1][0][1] > -1) {
                      return trim(substr($match[0][0], 0, $matches[$index + 1][0][1] - $match[0][1]), '/');
                    }
                  }

                  return isset($match[0][0]) && $match[0][1] != -1 ? trim($match[0][0], '/') : null;
                }, $matches, array_keys($matches));

                $this->invoke($route_callable);

                ++$numberHandled;
              }
            }
        }
        if (($numberHandled == 0) && (isset($this->notFoundCallback['/']))) {
            $this->invoke($this->notFoundCallback['/']);
        } elseif ($numberHandled == 0) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
        }
    }

    private function patternMatches($pattern, $uri, &$matches, $flags)
    {
      $pattern = preg_replace('/\/{(.*?)}/', '/(.*?)', $pattern);

      return boolval(preg_match_all('#^' . $pattern . '$#', $uri, $matches, PREG_OFFSET_CAPTURE));
    }
    
    /**
     * handle
     * Handles routes
     * @param  mixed $routes Routes
     * @param  mixed $quitAfterRun Quit after handling
     * @return void
     */
    private function handle($routes, $quitAfterRun = false)
    {
        $numberHandled = 0;

        $uri = $this->getCurrentUri();

        foreach ($routes as $route) {

            $is_match = $this->patternMatches($route['pattern'], $uri, $matches, PREG_OFFSET_CAPTURE);

            if ($is_match) {

                $matches = array_slice($matches, 1);

                $params = array_map(function ($match, $index) use ($matches) {

                    if (isset($matches[$index + 1]) && isset($matches[$index + 1][0]) && is_array($matches[$index + 1][0])) {
                        if ($matches[$index + 1][0][1] > -1) {
                            return trim(substr($match[0][0], 0, $matches[$index + 1][0][1] - $match[0][1]), '/');
                        }
                    }

                    return isset($match[0][0]) && $match[0][1] != -1 ? trim($match[0][0], '/') : null;
                }, $matches, array_keys($matches));

                $this->invoke($route['function'], $params);

                ++$numberHandled;

                        if ($quitAfterRun) {
                    break;
                }
            }
        }

        return $numberHandled;
    }
    
    /**
     * invoke
     * Invokes a lambda function
     * @param  mixed $function
     * @param  mixed $params
     * @return void
     */
    private function invoke($function, $params = array())
    {
        if (is_callable($function)) {
            call_user_func_array($function, $params);
        }
    }


    public function getCurrentUri()
    {
        $uri = substr(
            rawurldecode($_SERVER['REQUEST_URI']), 
            strlen($this->getBasePath())
        );

        if (strstr($uri, '?')) {
            $uri = substr($uri, 0, strpos($uri, '?'));
        }

        return '/' . trim($uri, '/');
    }


    public function getBasePath()
    {
        if ($this->serverBasePath === null) {
            $this->serverBasePath = implode(
                '/', 
                array_slice(
                    explode('/', $_SERVER['SCRIPT_NAME']), 0, -1
                )
            ) . '/';
        }

        return $this->serverBasePath;
    }
}
