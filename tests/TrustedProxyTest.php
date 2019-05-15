<?php

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Fideloper\Proxy\TrustProxies;

class TrustedProxyTest extends TestCase
{
    /**
     * Test that Symfony does indeed NOT trust X-Forwarded-*
     * headers when not given trusted proxies
     *
     * This re-tests Symfony's Request class, but hopefully provides
     * some clarify to developers looking at the tests.
     *
     * Also, thanks for looking at the tests.
     */
    public function test_request_does_not_trust()
    {
        $req = $this->createProxiedRequest();

        $this->assertEquals('192.168.10.10', $req->getClientIp(), 'Assert untrusted proxy x-forwarded-for header not used');
        $this->assertEquals('http', $req->getScheme(), 'Assert untrusted proxy x-forwarded-proto header not used');
        $this->assertEquals('localhost', $req->getHost(), 'Assert untrusted proxy x-forwarded-host header not used');
        $this->assertEquals(8888, $req->getPort(), 'Assert untrusted proxy x-forwarded-port header not used');
    }

    /**
     * Test that Symfony DOES indeed trust X-Forwarded-*
     * headers when given trusted proxies
     *
     * Again, this re-tests Symfony's Request class.
     */
    public function test_does_trust_trusted_proxy()
    {
        $req = $this->createProxiedRequest();
        $req->setTrustedProxies(['192.168.10.10'], Request::HEADER_X_FORWARDED_ALL);

        $this->assertEquals('173.174.200.38', $req->getClientIp(), 'Assert trusted proxy x-forwarded-for header used');
        $this->assertEquals('https', $req->getScheme(), 'Assert trusted proxy x-forwarded-proto header used');
        $this->assertEquals('serversforhackers.com', $req->getHost(), 'Assert trusted proxy x-forwarded-host header used');
        $this->assertEquals(443, $req->getPort(), 'Assert trusted proxy x-forwarded-port header used');
    }

    /**
     * Test the next most typical usage of TrustedProxies:
     * Trusted X-Forwarded-For header, wilcard for TrustedProxies
     */
    public function test_trusted_proxy_sets_trusted_proxies_with_wildcard()
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_X_FORWARDED_ALL, '*');
        $request = $this->createProxiedRequest();

        $trustedProxy->handle($request, function ($request) {
            $this->assertEquals('173.174.200.38', $request->getClientIp(), 'Assert trusted proxy x-forwarded-for header used with wildcard proxy setting');
        });
    }

    /**
     * Test the next most typical usage of TrustedProxies:
     * Trusted X-Forwarded-For header, wilcard for TrustedProxies
     */
    public function test_trusted_proxy_sets_trusted_proxies_with_double_wildcard_for_backwards_compat()
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_X_FORWARDED_ALL, '**');
        $request = $this->createProxiedRequest();

        $trustedProxy->handle($request, function ($request) {
            $this->assertEquals('173.174.200.38', $request->getClientIp(), 'Assert trusted proxy x-forwarded-for header used with wildcard proxy setting');
        });
    }

    /**
     * Test the most typical usage of TrustProxies:
     * Trusted X-Forwarded-For header
     */
    public function test_trusted_proxy_sets_trusted_proxies()
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_X_FORWARDED_ALL, ['192.168.10.10']);
        $request = $this->createProxiedRequest();

        $trustedProxy->handle($request, function ($request) {
            $this->assertEquals('173.174.200.38', $request->getClientIp(), 'Assert trusted proxy x-forwarded-for header used');
        });
    }

    /**
     * Test X-Forwarded-For header with multiple IP addresses
     */
    public function test_get_client_ips()
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_X_FORWARDED_ALL, ['192.168.10.10']);

        $forwardedFor = [
            '192.0.2.2',
            '192.0.2.2, 192.0.2.199',
            '192.0.2.2, 192.0.2.199, 99.99.99.99',
            '192.0.2.2,192.0.2.199',
        ];

        foreach($forwardedFor as $forwardedForHeader) {
            $request = $this->createProxiedRequest(['HTTP_X_FORWARDED_FOR' => $forwardedForHeader]);

            $trustedProxy->handle($request, function ($request) use ($forwardedForHeader) {
                $ips = $request->getClientIps();
                $this->assertEquals('192.0.2.2', end($ips), 'Assert sets the '.$forwardedForHeader);
            });
        }
    }

    /**
     * Test X-Forwarded-For header with remote ip lists.
     */
    public function test_get_client_ip_with_remote_list()
    {
        $trustedProxy = $this->createTrustedProxy([Illuminate\Http\Request::HEADER_CLIENT_IP => 'X_FORWARDED_FOR'], ['https://www.cloudflare.com/ips-v4']);
        $request = $this->createProxiedRequest(['REMOTE_ADDR' => '103.21.244.2']);

        $trustedProxy->handle($request, function ($request) {
            $this->assertEquals('173.174.200.38', $request->getClientIp(), 'Assert trusted proxy x-forwarded-for header used');
        });
    }

    /**
     * Test X-Forwarded-For header with multiple remote ip lists some of which contain ipv6.
     */
    public function test_get_client_ip_with_multiple_remote_lists_some_of_which_are_ipv6()
    {
        $trustedProxy = $this->createTrustedProxy([Illuminate\Http\Request::HEADER_CLIENT_IP => 'X_FORWARDED_FOR'], [
            'https://www.cloudflare.com/ips-v4',
            'https://www.cloudflare.com/ips-v6',
        ]);

        $remoteAddrs = [
            '103.21.244.2',
            '2400:cb00::1',
        ];

        foreach ($remoteAddrs as $remoteAddr) {
            $request = $this->createProxiedRequest(['REMOTE_ADDR' => '103.21.244.2']);

            $trustedProxy->handle($request, function ($request) {
                $this->assertEquals('173.174.200.38', $request->getClientIp(), 'Assert trusted proxy x-forwarded-for header used');
            });
        }
    }

    /**
     * Test X-Forwarded-For header with the mix of remote list and ip.
     */
    public function test_get_client_ip_with_ip_and_remote_list()
    {
        $trustedProxy = $this->createTrustedProxy([Illuminate\Http\Request::HEADER_CLIENT_IP => 'X_FORWARDED_FOR'], [
            'https://www.cloudflare.com/ips-v4',
            'https://www.cloudflare.com/ips-v6',
            '192.168.10.10',
        ]);

        $remoteAddrs = [
            '103.21.244.2',
            '2400:cb00::1',
            '192.168.10.10',
        ];

        foreach ($remoteAddrs as $remoteAddr) {
            $request = $this->createProxiedRequest(['REMOTE_ADDR' => '103.21.244.2']);

            $trustedProxy->handle($request, function ($request) {
                $this->assertEquals('173.174.200.38', $request->getClientIp(), 'Assert trusted proxy x-forwarded-for header used');
            });
        }
    }

    /**
     * Test X-Forwarded-For header with multiple IP addresses, with some of those being trusted
     */
    public function test_get_client_ip_with_muliple_ip_addresses_some_of_which_are_trusted()
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_X_FORWARDED_ALL, ['192.168.10.10', '192.0.2.199']);

        $forwardedFor = [
            '192.0.2.2',
            '192.0.2.2, 192.0.2.199',
            '99.99.99.99, 192.0.2.2, 192.0.2.199',
            '192.0.2.2,192.0.2.199',
        ];

        foreach($forwardedFor as $forwardedForHeader) {
            $request = $this->createProxiedRequest(['HTTP_X_FORWARDED_FOR' => $forwardedForHeader]);

            $trustedProxy->handle($request, function ($request) use ($forwardedForHeader) {
                $this->assertEquals('192.0.2.2', $request->getClientIp(), 'Assert sets the '.$forwardedForHeader);
            });
        }
    }

    /**
     * Test X-Forwarded-For header with multiple IP addresses, with * wildcard trusting of all proxies
     */
    public function test_get_client_ip_with_muliple_ip_addresses_all_proxies_are_trusted()
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_X_FORWARDED_ALL, '*');

        $forwardedFor = [
            '192.0.2.2',
            '192.0.2.199, 192.0.2.2',
            '192.0.2.199,192.0.2.2',
            '99.99.99.99,192.0.2.199,192.0.2.2',
        ];

        foreach($forwardedFor as $forwardedForHeader) {
            $request = $this->createProxiedRequest(['HTTP_X_FORWARDED_FOR' => $forwardedForHeader]);

            $trustedProxy->handle($request, function ($request) use ($forwardedForHeader) {
                $this->assertEquals('192.0.2.2', $request->getClientIp(), 'Assert sets the '.$forwardedForHeader);
            });
        }
    }

    /**
     * Test distrusting a header.
     */
    public function test_can_distrust_headers()
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_FORWARDED, ['192.168.10.10']);

        $request = $this->createProxiedRequest([
            'HTTP_FORWARDED' => 'for=173.174.200.40:443; proto=https; host=serversforhackers.com',
            'HTTP_X_FORWARDED_FOR' => '173.174.200.38',
            'HTTP_X_FORWARDED_HOST' => 'svrs4hkrs.com',
            'HTTP_X_FORWARDED_PORT' => '80',
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ]);

        $trustedProxy->handle($request, function ($request) {
            $this->assertEquals('173.174.200.40', $request->getClientIp(),
                'Assert trusted proxy used forwarded header for IP');
            $this->assertEquals('https', $request->getScheme(),
                'Assert trusted proxy used forwarded header for scheme');
            $this->assertEquals('serversforhackers.com', $request->getHost(),
                'Assert trusted proxy used forwarded header for host');
            $this->assertEquals(443, $request->getPort(), 'Assert trusted proxy used forwarded header for port');
        });
    }

    /**
     * Test to ensure it's reading text-based configurations and converting it correctly.
     */
    public function test_is_reading_text_based_configurations()
    {
        $request = $this->createProxiedRequest();

        // trust *all* "X-Forwarded-*" headers
        $trustedProxy = $this->createTrustedProxy('HEADER_X_FORWARDED_ALL', '192.168.1.1, 192.168.1.2');
        $trustedProxy->handle($request, function (Request $request) {
            $this->assertEquals($request->getTrustedHeaderSet(), Request::HEADER_X_FORWARDED_ALL,
                'Assert trusted proxy used all "X-Forwarded-*" header');

            $this->assertEquals($request->getTrustedProxies(), ['192.168.1.1', '192.168.1.2'],
                'Assert trusted proxy using proxies as string separated by comma.');
        });

        // or, if your proxy instead uses the "Forwarded" header
        $trustedProxy = $this->createTrustedProxy('HEADER_FORWARDED', '192.168.1.1, 192.168.1.2');
        $trustedProxy->handle($request, function (Request $request) {
            $this->assertEquals($request->getTrustedHeaderSet(), Request::HEADER_FORWARDED,
                'Assert trusted proxy used forwarded header');

            $this->assertEquals($request->getTrustedProxies(), ['192.168.1.1', '192.168.1.2'],
                'Assert trusted proxy using proxies as string separated by comma.');
        });

        // or, if you're using AWS ELB
        $trustedProxy = $this->createTrustedProxy('HEADER_X_FORWARDED_AWS_ELB', '192.168.1.1, 192.168.1.2');
        $trustedProxy->handle($request, function (Request $request) {
            $this->assertEquals($request->getTrustedHeaderSet(), Request::HEADER_X_FORWARDED_AWS_ELB,
                'Assert trusted proxy used AWS ELB header');

            $this->assertEquals($request->getTrustedProxies(), ['192.168.1.1', '192.168.1.2'],
                'Assert trusted proxy using proxies as string separated by comma.');
        });
    }

    ################################################################
    # Utility Functions
    ################################################################

    /**
     * Fake an HTTP request by generating a Symfony Request object.
     *
     * @param array $serverOverRides
     *
     * @return \Symfony\Component\HttpFoundation\Request
     */
    protected function createProxiedRequest($serverOverRides = [])
    {
        // Add some X-Forwarded headers and over-ride
        // defaults, simulating a request made over a proxy
        $serverOverRides = array_replace([
            'HTTP_X_FORWARDED_FOR' => '173.174.200.38',         // X-Forwarded-For   -- getClientIp()
            'HTTP_X_FORWARDED_HOST' => 'serversforhackers.com', // X-Forwarded-Host  -- getHosts()
            'HTTP_X_FORWARDED_PORT' => '443',                   // X-Forwarded-Port  -- getPort()
            'HTTP_X_FORWARDED_PROTO' => 'https',                // X-Forwarded-Proto -- getScheme() / isSecure()
            'SERVER_PORT' => 8888,
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '192.168.10.10',
        ], $serverOverRides);

        // Create a fake request made over "http", one that we'd get over a proxy
        // which is likely something like this:
        $request = Request::create('http://localhost:8888/tag/proxy', 'GET', [], [], [], $serverOverRides, null);
        // Need to make sure these haven't already been set
        $request->setTrustedProxies([], Request::HEADER_X_FORWARDED_ALL);

        return $request;
    }

    /**
     * Retrieve a TrustProxies object, with dependencies mocked.
     *
     * @param null|string|int $trustedHeaders
     * @param null|array|string $trustedProxies
     *
     * @return \Fideloper\Proxy\TrustProxies
     */
    protected function createTrustedProxy($trustedHeaders, $trustedProxies)
    {
        // Mock TrustProxies dependencies and calls for config values
        $config = Mockery::mock('Illuminate\Contracts\Config\Repository')
            ->shouldReceive('get')
            ->with('trustedproxy.headers')
            ->andReturn($trustedHeaders)
            ->shouldReceive('get')
            ->with('trustedproxy.proxies')
            ->andReturn($trustedProxies)
            ->shouldReceive('get')
            ->with('trustedproxy.cache_ttl')
            ->andReturn(24*60)
            ->getMock();

        $cache = Mockery::mock('Illuminate\Contracts\Cache\Repository');

        if (is_array($trustedProxies)) {
            // Mock remote lists cache
            foreach($trustedProxies as $proxy) {
                if (filter_var($proxy, FILTER_VALIDATE_URL) !== false) {
                    $contents = file($proxy, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                    $cache->shouldReceive('remember')
                          ->with($proxy, 24*60, Mockery::on(function ($closure) {
                              return is_callable($closure);
                          }))
                          ->andReturn($contents);
                }
            }
        }

        return new TrustProxies($config, $cache);
    }
}
