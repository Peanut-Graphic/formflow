<?php
/**
 * Tests for VisitorTracker
 */

namespace ISF\Tests\Unit\Analytics;

use ISF\Tests\Unit\TestCase;
use ISF\Analytics\VisitorTracker;
use Brain\Monkey\Functions;

class VisitorTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset superglobals
        $_COOKIE = [];
        $_GET = [];
        $_SERVER = [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
            'HTTP_ACCEPT_ENCODING' => 'gzip, deflate, br',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/enroll',
        ];

        $this->mockWpdb([
            'insert' => true,
            'insert_id' => 1,
            'get_row' => null,
            'query' => 1,
            // visitors_table_ready() probes "SHOW TABLES LIKE %s" and expects
            // the table name back when the table exists. Return the prefixed
            // visitors table so the write-guard passes in tests.
            'get_var' => 'wp_' . ISF_TABLE_VISITORS,
        ]);
    }

    /**
     * Test visitor ID is generated when no cookie exists
     */
    public function testGeneratesVisitorIdWhenNoCookieExists(): void
    {
        $tracker = new VisitorTracker();
        $visitorId = $tracker->get_or_create_visitor_id();

        $this->assertNotNull($visitorId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $visitorId);
    }

    /**
     * Test visitor ID is returned from valid cookie
     */
    public function testReturnsVisitorIdFromValidCookie(): void
    {
        $expectedId = 'abc123def456789012345678901234ab';
        $_COOKIE['isf_visitor'] = $expectedId;

        $tracker = new VisitorTracker();
        $visitorId = $tracker->get_or_create_visitor_id();

        $this->assertEquals($expectedId, $visitorId);
    }

    /**
     * Test invalid cookie format generates new visitor ID
     */
    public function testInvalidCookieFormatGeneratesNewId(): void
    {
        $_COOKIE['isf_visitor'] = 'invalid-format';

        $tracker = new VisitorTracker();
        $visitorId = $tracker->get_or_create_visitor_id();

        // Should generate a new valid ID, not return the invalid one
        $this->assertNotEquals('invalid-format', $visitorId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $visitorId);
    }

    /**
     * Test get_visitor_id returns null when no visitor set
     */
    public function testGetVisitorIdReturnsNullWhenNotSet(): void
    {
        $tracker = new VisitorTracker();
        $visitorId = $tracker->get_visitor_id();

        $this->assertNull($visitorId);
    }

    /**
     * Test get_visitor_id returns value from cookie
     */
    public function testGetVisitorIdReturnsCookieValue(): void
    {
        $expectedId = 'abc123def456789012345678901234ab';
        $_COOKIE['isf_visitor'] = $expectedId;

        $tracker = new VisitorTracker();
        $visitorId = $tracker->get_visitor_id();

        $this->assertEquals($expectedId, $visitorId);
    }

    /**
     * Test UTM parameters are captured
     */
    public function testUtmParametersAreCaptured(): void
    {
        $_GET['utm_source'] = 'google';
        $_GET['utm_medium'] = 'cpc';
        $_GET['utm_campaign'] = 'winter_promo';
        $_GET['utm_term'] = 'energy savings';
        $_GET['utm_content'] = 'banner_ad';

        $tracker = new VisitorTracker();
        $attribution = $tracker->get_current_attribution();

        $this->assertEquals('google', $attribution['utm_source']);
        $this->assertEquals('cpc', $attribution['utm_medium']);
        $this->assertEquals('winter_promo', $attribution['utm_campaign']);
        $this->assertEquals('energy savings', $attribution['utm_term']);
        $this->assertEquals('banner_ad', $attribution['utm_content']);
    }

    /**
     * Test click IDs are captured
     */
    public function testClickIdsAreCaptured(): void
    {
        $_GET['gclid'] = 'google_click_id_123';
        $_GET['fbclid'] = 'facebook_click_id_456';
        $_GET['msclkid'] = 'microsoft_click_id_789';

        $tracker = new VisitorTracker();
        $attribution = $tracker->get_current_attribution();

        $this->assertEquals('google_click_id_123', $attribution['gclid']);
        $this->assertEquals('facebook_click_id_456', $attribution['fbclid']);
        $this->assertEquals('microsoft_click_id_789', $attribution['msclkid']);
    }

    /**
     * Test promo code is captured
     */
    public function testPromoCodeIsCaptured(): void
    {
        $_GET['promo'] = 'SAVE20';

        $tracker = new VisitorTracker();
        $attribution = $tracker->get_current_attribution();

        $this->assertEquals('SAVE20', $attribution['promo_code']);
    }

    /**
     * Test external referrer is captured
     */
    public function testExternalReferrerIsCaptured(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://www.google.com/search?q=energy+savings';

        $tracker = new VisitorTracker();
        $attribution = $tracker->get_current_attribution();

        $this->assertEquals('https://www.google.com/search?q=energy+savings', $attribution['referrer']);
        $this->assertEquals('google.com', $attribution['referrer_domain']);
    }

    /**
     * Test internal referrer is not captured
     */
    public function testInternalReferrerIsNotCaptured(): void
    {
        $_SERVER['HTTP_REFERER'] = 'http://example.com/other-page';

        $tracker = new VisitorTracker();
        $attribution = $tracker->get_current_attribution();

        $this->assertArrayNotHasKey('referrer', $attribution);
        $this->assertArrayNotHasKey('referrer_domain', $attribution);
    }

    /**
     * Test landing page is captured
     */
    public function testLandingPageIsCaptured(): void
    {
        $_SERVER['REQUEST_URI'] = '/enroll?utm_source=google';

        $tracker = new VisitorTracker();
        $attribution = $tracker->get_current_attribution();

        $this->assertArrayHasKey('landing_page', $attribution);
        $this->assertStringContainsString('/enroll', $attribution['landing_page']);
    }

    /**
     * Test browser parsing from user agent
     */
    public function testBrowserParsingFromUserAgent(): void
    {
        $testCases = [
            ['Mozilla/5.0 Chrome/120.0.0.0', 'Chrome'],
            ['Mozilla/5.0 Firefox/120.0', 'Firefox'],
            ['Mozilla/5.0 Safari/605.1.15', 'Safari'],
            ['Mozilla/5.0 Edg/120.0.0.0', 'Edge'],
            ['Mozilla/5.0 MSIE 11.0', 'Internet Explorer'],
            ['Mozilla/5.0 Opera/9.80', 'Opera'],
            ['Unknown Browser', 'Unknown'],
        ];

        foreach ($testCases as [$userAgent, $expectedBrowser]) {
            $_SERVER['HTTP_USER_AGENT'] = $userAgent;

            $tracker = new VisitorTracker();
            $reflection = new \ReflectionClass($tracker);
            $method = $reflection->getMethod('parse_browser');
            $method->setAccessible(true);

            $result = $method->invoke($tracker, $userAgent);
            $this->assertEquals($expectedBrowser, $result, "Failed for user agent: {$userAgent}");
        }
    }

    /**
     * Test OS parsing from user agent
     */
    public function testOsParsingFromUserAgent(): void
    {
        $testCases = [
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'Windows'],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 'macOS'],
            ['Mozilla/5.0 (X11; Linux x86_64)', 'Linux'],
            ['Mozilla/5.0 (Linux; Android 13)', 'Android'],
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)', 'iOS'],
            ['Unknown OS', 'Unknown'],
        ];

        foreach ($testCases as [$userAgent, $expectedOs]) {
            $tracker = new VisitorTracker();
            $reflection = new \ReflectionClass($tracker);
            $method = $reflection->getMethod('parse_os');
            $method->setAccessible(true);

            $result = $method->invoke($tracker, $userAgent);
            $this->assertEquals($expectedOs, $result, "Failed for user agent: {$userAgent}");
        }
    }

    /**
     * Test mobile detection from user agent
     */
    public function testMobileDetectionFromUserAgent(): void
    {
        $testCases = [
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64)', false],
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)', true],
            ['Mozilla/5.0 (Linux; Android 13; Pixel 7)', true],
            ['Mozilla/5.0 (iPad; CPU OS 17_0)', true],
            ['Mozilla/5.0 Mobile Safari/605.1.15', true],
        ];

        foreach ($testCases as [$userAgent, $expectedMobile]) {
            $tracker = new VisitorTracker();
            $reflection = new \ReflectionClass($tracker);
            $method = $reflection->getMethod('is_mobile');
            $method->setAccessible(true);

            $result = $method->invoke($tracker, $userAgent);
            $this->assertEquals($expectedMobile, $result, "Failed for user agent: {$userAgent}");
        }
    }

    /**
     * Test www prefix is stripped from referrer domain
     */
    public function testWwwPrefixStrippedFromReferrerDomain(): void
    {
        $tracker = new VisitorTracker();
        $reflection = new \ReflectionClass($tracker);
        $method = $reflection->getMethod('extract_domain');
        $method->setAccessible(true);

        $result = $method->invoke($tracker, 'https://www.google.com/search');
        $this->assertEquals('google.com', $result);

        $result = $method->invoke($tracker, 'https://google.com/search');
        $this->assertEquals('google.com', $result);
    }

    /**
     * Test cookie days can be configured
     */
    public function testCookieDaysCanBeConfigured(): void
    {
        $tracker = new VisitorTracker();

        // Test minimum boundary
        $tracker->set_cookie_days(0);
        $reflection = new \ReflectionClass($tracker);
        $property = $reflection->getProperty('cookie_days');
        $property->setAccessible(true);
        $this->assertEquals(1, $property->getValue($tracker));

        // Test maximum boundary
        $tracker->set_cookie_days(1000);
        $this->assertEquals(730, $property->getValue($tracker));

        // Test normal value
        $tracker->set_cookie_days(365);
        $this->assertEquals(365, $property->getValue($tracker));
    }

    /**
     * Test empty attribution when no params present
     */
    public function testEmptyAttributionWhenNoParams(): void
    {
        $_GET = [];
        unset($_SERVER['HTTP_REFERER']);

        $tracker = new VisitorTracker();
        $attribution = $tracker->get_current_attribution();

        // Should only have landing_page
        $this->assertArrayHasKey('landing_page', $attribution);
        $this->assertArrayNotHasKey('utm_source', $attribution);
        $this->assertArrayNotHasKey('gclid', $attribution);
        $this->assertArrayNotHasKey('referrer', $attribution);
    }
}
