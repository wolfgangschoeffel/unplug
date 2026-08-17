<?php

namespace Em4nl\Unplug;


require_once __DIR__ . '/utils.php';


/**
 * Convenience interface to the default Router instance
 */

if (!function_exists('Em4nl\Unplug\_use')) {
    function _use($middleware) {
        $request_middlewares = &_get_request_middlewares();
        $request_middlewares[] = $middleware;
    }
}

if (!function_exists('Em4nl\Unplug\get')) {
    function get($path, $callback) {
        _get_default_router()->get($path, function($context) use ($callback) {
            _apply_request_middlewares($context);
            $response = $callback($context);
            if ($response) {
                \Em4nl\U\send_content_response($response);
            }
        });
    }
}

if (!function_exists('Em4nl\Unplug\post')) {
    function post($path, $callback) {
        _get_default_router()->post($path, function($context) use ($callback) {
            define('UNPLUG_DO_CACHE', FALSE);
            _apply_request_middlewares($context);
            $response = $callback($context);
            if ($response) {
                \Em4nl\U\send_content_response($response);
            }
        });
    }
}

if (!function_exists('Em4nl\Unplug\catchall')) {
    function catchall($callback) {
        _get_default_router()->catchall(function($context) use ($callback) {
            define('UNPLUG_DO_CACHE', FALSE);
            _apply_request_middlewares($context);
            $response = $callback($context);
            if ($response) {
                \Em4nl\U\send_content_response($response);
            }
        });
    }
}

if (!function_exists('Em4nl\Unplug\dispatch')) {
    function dispatch() {
        if (UNPLUG_CACHE_ON &&
            !(defined('UNPLUG_FRONT_CONTROLLER') && UNPLUG_FRONT_CONTROLLER)) {
            // if caching is on, and the front_controller wasn't
            // used, then we want to do the whole thing, including
            // caching, now
            $cache = _get_default_cache();
            $served_from_cache = $cache->serve();
            if (!$served_from_cache) {
                $cache->start();
                _get_default_router()->run();
                $cache->end(!defined('UNPLUG_DO_CACHE') || UNPLUG_DO_CACHE);
            }
        } else {
            // if either we don't want to use caching, or the
            // front_controller was indeed used, which means that
            // it decided to still load up WordPress, we just run
            // the router
            _get_default_router()->run();
        }
    }
}

if (!function_exists('Em4nl\Unplug\_get_default_router')) {
    function _get_default_router() {
        static $router;
        if (!isset($router)) {
            $router = new \Em4nl\U\Router();
        }
        // TODO set base path if WordPress is installed in subdir
        return $router;
    }
}

if (!function_exists('Em4nl\Unplug\_get_default_cache')) {
    function _get_default_cache() {
        if (defined('UNPLUG_FRONT_CONTROLLER') && UNPLUG_FRONT_CONTROLLER) {
            global $_unplug_cache;
            return $_unplug_cache;
        }
        static $cache;
        if (!isset($cache)) {
            $cache = new \Em4nl\U\Cache(
                UNPLUG_CACHE_DIR,
                NULL,
                _get_cache_options()
            );
        }
        return $cache;
    }
}

if (!function_exists('Em4nl\Unplug\_get_cache_options')) {
    function _get_cache_options() {
        $options = array();

        // key the cache on the path the router matched on, not on
        // REQUEST_URI as it arrived. the two disagree: the router
        // trims any number of leading and trailing slashes and stops
        // at the first ? once the url is decoded, so /projekte,
        // //projekte/// and /projekte%3Fx are all one page to it. if
        // the cache disagrees they're one page and three files, and
        // anyone can keep adding slashes to get more.
        $path = _get_cache_key_path();
        if (isset($path)) {
            $options['cache_key_path'] = $path;
        }

        // set UNPLUG_CACHE_KEY_PARAMS in unplug-config.php to change
        // what part of the query string is in the key. leave it
        // undefined to keep all of it, which is the default.
        if (defined('UNPLUG_CACHE_KEY_PARAMS')) {
            $options['cache_key_params'] = _resolve_cache_key_params(
                UNPLUG_CACHE_KEY_PARAMS, $path);
        }

        return $options;
    }
}

if (!function_exists('Em4nl\Unplug\_get_cache_key_path')) {
    function _get_cache_key_path() {
        // NULL when there's no request to speak of - wp-cli, wp-cron -
        // where the cache is only ever flushed, never keyed
        if (!isset($_SERVER['REQUEST_URI'])) {
            return NULL;
        }
        // exactly what Router::run does to pick a route, so that two
        // urls it routes the same can't end up under two keys
        $parts = explode('?', _get_default_router()->get_request_path(), 2);
        return '/' . trim($parts[0], '/');
    }
}

if (!function_exists('Em4nl\Unplug\_resolve_cache_key_params')) {
    function _resolve_cache_key_params($config, $path=NULL) {
        if (!isset($config)) {
            // NULL: upstream behaviour, whole query string in the key
            return NULL;
        }
        if (!is_array($config)) {
            throw new \Exception(
                'UNPLUG_CACHE_KEY_PARAMS must be an array or NULL');
        }
        if (_is_list($config)) {
            // a flat list of parameter names: one whitelist that
            // applies to every path
            foreach ($config as $param) {
                if (is_string($param) && isset($param[0])
                        && $param[0] === '/') {
                    throw new \Exception(
                        "UNPLUG_CACHE_KEY_PARAMS contains '$param', which"
                            . ' looks like a path, but this is a flat list'
                            . ' of parameter names applied to every path.'
                            . " to declare parameters per path, write"
                            . " array('$param' => array(...)) instead.");
                }
            }
            return $config;
        }

        // a map of path pattern => parameter list. resolved once,
        // here, from the static config and the current request path -
        // never from the live application router, whose routes may
        // not exist yet (front controller mode looks up the cache
        // before WordPress loads). so this always gives the same
        // answer regardless of when it's called, and serve() and
        // add() can never disagree about the key.
        if (!isset($path)) {
            // no request to match against (wp-cli, wp-cron)
            return array();
        }
        return _match_cache_key_params($config, $path);
    }
}

if (!function_exists('Em4nl\Unplug\_match_cache_key_params')) {
    function _match_cache_key_params($config, $path) {
        // matched with a throwaway Router, so that ':slug', '*' and
        // optional 'segment?' patterns mean exactly what they mean in
        // a route definition, instead of a second, subtly different
        // matcher. a path that isn't listed drops the query string
        // entirely - the safe default for a route nobody has said
        // needs anything from it.
        $router = new \Em4nl\U\Router();
        $result = array();
        $matched = FALSE;
        foreach ($config as $pattern => $params) {
            if (isset($params) && !is_array($params)) {
                throw new \Exception(
                    "UNPLUG_CACHE_KEY_PARAMS['$pattern'] must be an array"
                        . ' or NULL');
            }
            $router->get($pattern, function($ctx) use (
                $params, &$result, &$matched
            ) {
                $result = $params;
                $matched = TRUE;
            });
        }
        $router->run($path, 'GET');
        return $matched ? $result : array();
    }
}

if (!function_exists('Em4nl\Unplug\_is_list')) {
    function _is_list($array) {
        $i = 0;
        foreach ($array as $key => $value) {
            if ($key !== $i++) {
                return FALSE;
            }
        }
        return TRUE;
    }
}

if (!function_exists('Em4nl\Unplug\_get_request_middlewares')) {
    function &_get_request_middlewares() {
        static $request_middlewares;
        if (!isset($request_middlewares)) {
            $request_middlewares = array();
            $request_middlewares[] = function(&$context) {
                $site_url = get_site_url();
                while (substr($site_url, -1) === '/') {
                    $site_url = substr($site_url, 0, -1);
                }
                $context['site_url'] = $site_url;
                $context['current_url'] = $site_url . $context['path'];
                $context['theme_url'] = get_template_directory_uri();
                $context['site_title'] = get_bloginfo();
                $context['site_description'] = get_bloginfo('description');
            };
        }
        return $request_middlewares;
    }
}

if (!function_exists('Em4nl\Unplug\_apply_request_middlewares')) {
    function _apply_request_middlewares(&$context) {
        foreach (_get_request_middlewares() as $middleware) {
            $res = $middleware($context);
            if ($res !== NULL) {
                $context = $res;
            }
        }
    }
}
