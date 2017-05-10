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
    public function handle($request, Closure $next)
    {
        $this->setTrustedProxyHeaderNames($request);
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

        // We only trust specific IP addresses
        if(is_array($trustedIps)) {
            $this->setTrustedProxyIpAddressesToSpecificIps($request, $trustedIps);
        }

        // We trust any IP address that calls us, but not proxies further
        // up the forwarding chain.
        if ($trustedIps === '*') {
            $this->setTrustedProxyIpAddressesToTheCallingIp($request);
        }

        // We trust all proxies. Those that call us, and those that are
        // further up the calling chain (e.g., where the X-FORWARDED-FOR
        // header has multiple IP addresses listed);
        if ($trustedIps === '**') {
            $this->setTrustedProxyIpAddressesToAllIps($request);
        }
    }

    private function setTrustedProxyIpAddressesToSpecificIps(Request $request, $trustedIps)
    {
        $request->setTrustedProxies((array) $trustedIps);
    }

    private function setTrustedProxyIpAddressesToTheCallingIp(Request $request) {
        $request->setTrustedProxies($request->getClientIps());
    }

    private function setTrustedProxyIpAddressesToAllIps(Request $request)
    {
        // 0.0.0.0/0 is the CIDR for all ipv4 addresses
        // 2000:0:0:0:0:0:0:0/3 is the CIDR for all ipv6 addresses currently
        // allocated http://www.iana.org/assignments/ipv6-unicast-address-assignments/ipv6-unicast-address-assignments.xhtml
        $request->setTrustedProxies(['0.0.0.0/0', '2000:0:0:0:0:0:0:0/3']);
    }

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
     * Set the trusted header names based on teh content of trustedproxy.headers
     *
     * @param \Illuminate\Http\Request $request
     */
    protected function setTrustedProxyHeaderNames(Request $request)
    {
        $trustedHeaderNames = $this->headers ?: $this->config->get('trustedproxy.headers');

        if(!is_array($trustedHeaderNames)) { return; } // Leave the defaults

        foreach ($trustedHeaderNames as $headerKey => $headerName) {
            $request->setTrustedHeaderName($headerKey, $headerName);
        }
    }
}
