<?php

namespace Fideloper\Proxy;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class TrustProxies
{
    /**
     * The config repository instance.
     *
     * @var \Illuminate\Contracts\Config\Repository
     */
    protected $config;

    /**
     * The cache repository instance.
     *
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * The trusted proxies for the application.
     *
     * @var array
     */
    protected $proxies;

    /**
     * The proxy header mappings.
     *
     * @var array
     */
    protected $headers;

    /**
     * Create a new trusted proxies middleware instance.
     *
     * @param  \Illuminate\Contracts\Config\Repository $config
     * @param  \Illuminate\Contracts\Cache\Repository  $cache
     */
    public function __construct(ConfigRepository $config,
                                CacheRepository $cache)
    {
        $this->cache = $cache;
        $this->config = $config;
    }

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $request::setTrustedProxies([], $this->getTrustedHeaderNames()); // Reset trusted proxies between requests
        $this->setTrustedProxyIpAddresses($request);

        return $next($request);
    }

    /**
     * Sets the trusted proxies on the request to the value of trustedproxy.proxies
     *
     * @param \Illuminate\Http\Request $request
     */
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        $trustedIps = $this->proxies ?: $this->getTrustedProxies();

        // Only trust specific IP addresses
        if (is_array($trustedIps)) {
            $this->setTrustedProxyIpAddressesToSpecificIps($request, $trustedIps);
        }

        // Trust any IP address that calls us
        // `**` for backwards compatibility, but is depreciated
        if ($trustedIps === '*' || $trustedIps === '**') {
            return $this->setTrustedProxyIpAddressesToTheCallingIp($request);
        }

    }

    /**
     * Specify the IP addresses to trust explicitly.
     *
     * @param \Illuminate\Http\Request $request
     * @param array                    $trustedIps
     */
    private function setTrustedProxyIpAddressesToSpecificIps(Request $request, $trustedIps)
    {
        $request->setTrustedProxies((array) $trustedIps, $this->getTrustedHeaderNames());
    }

    /**
     * Get trusted proxies list.
     *
     * @return array
     */
    private function getTrustedProxies()
    {
        $proxies = $this->config->get('trustedproxy.proxies');

        if (!is_array($proxies)) {
            return $proxies;
        }

        $trustedIps = [];

        foreach ($proxies as $item) {
            if (filter_var($item, FILTER_VALIDATE_URL) !== false) {
                // Merge content of the list into trusted ips array
                $trustedIps = array_merge($this->parseList($item), $trustedIps);
                continue;
            }

            $trustedIps[] = $item;
        }

        return $trustedIps;
    }

    /**
     * Parse IP list.
     *
     * @return array
     */
    private function parseList($url)
    {
        $ttl = $this->config->get('trustedproxy.cache_ttl');

        // Cache remote ip lists
        return $this->cache
            ->remember($url, $ttl, function () use ($url) {
                return file($url, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            });
    }

	/**
     * Set the trusted proxy to be the IP address calling this servers
     *
     * @param \Illuminate\Http\Request $request
     */
    private function setTrustedProxyIpAddressesToTheCallingIp(Request $request)
    {
        $request->setTrustedProxies([$request->server->get('REMOTE_ADDR')], $this->getTrustedHeaderNames());
    }

    /**
     * Retrieve trusted header name(s), falling back to defaults if config not set.
     *
     * @return array
     */
    protected function getTrustedHeaderNames()
    {
        return $this->headers ?: $this->config->get('trustedproxy.headers');
    }
}
