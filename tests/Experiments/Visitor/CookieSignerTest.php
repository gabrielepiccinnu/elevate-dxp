<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Experiments\Visitor;

use ElevateDxp\Experiments\Visitor\CookieSigner;
use ElevateDxp\Experiments\Visitor\VisitorIdResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CookieSignerTest extends TestCase
{
    public function testRoundTripAndTamperDetection(): void
    {
        $signer = new CookieSigner('s3cret');
        $id = CookieSigner::generateId();
        self::assertSame($id, $signer->verify($signer->sign($id)));
        self::assertNull($signer->verify($id.'.forged'));
        self::assertNull((new CookieSigner('other'))->verify($signer->sign($id)));
        self::assertNull($signer->verify('nodot'));
        self::assertNull($signer->verify($signer->sign('NOT-HEX')));
    }

    public function testResolverIssuesOnceAndReadsCookie(): void
    {
        $signer = new CookieSigner('s3cret');
        $stack = new RequestStack();
        $stack->push(Request::create('/'));
        $resolver = new VisitorIdResolver($signer, $stack, 'edxp_vid', ['^/admin']);
        $id = $resolver->getVisitorId();
        self::assertTrue($resolver->wasIssued());
        self::assertSame($id, $resolver->getVisitorId());

        $stack2 = new RequestStack();
        $stack2->push(Request::create('/', 'GET', [], ['edxp_vid' => $signer->sign($id)]));
        $resolver2 = new VisitorIdResolver($signer, $stack2, 'edxp_vid');
        self::assertSame($id, $resolver2->getVisitorId());
        self::assertFalse($resolver2->wasIssued());
    }

    public function testTrackability(): void
    {
        $resolver = new VisitorIdResolver(new CookieSigner('x'), new RequestStack(), 'edxp_vid', ['^/admin'], 'consent');
        self::assertFalse($resolver->isTrackable(Request::create('/page')), 'no consent cookie');
        self::assertTrue($resolver->isTrackable(Request::create('/page', 'GET', [], ['consent' => '1'])));
        self::assertFalse($resolver->isTrackable(Request::create('/admin/x', 'GET', [], ['consent' => '1'])));
        $bot = Request::create('/page', 'GET', [], ['consent' => '1'], [], ['HTTP_USER_AGENT' => 'Googlebot/2.1']);
        self::assertFalse($resolver->isTrackable($bot));
    }
}
