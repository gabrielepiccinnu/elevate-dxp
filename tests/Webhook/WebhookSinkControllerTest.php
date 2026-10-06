<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Webhook;

use ElevateDxp\Webhook\Controller\WebhookSinkController;
use ElevateDxp\Webhook\Webhook\WebhookSignature;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class WebhookSinkControllerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/edxp-sink-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/var/elevate-dxp/webhook-sink.log');
        @rmdir($this->dir.'/var/elevate-dxp');
        @rmdir($this->dir.'/var');
        @rmdir($this->dir);
    }

    private function request(string $body, ?string $signature): Request
    {
        $server = ['HTTP_'.strtoupper(str_replace('-', '_', WebhookSignature::HEADER_EVENT)) => 'object.update'];
        if ($signature !== null) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', WebhookSignature::HEADER_SIGNATURE))] = $signature;
        }

        return Request::create('/elevate-dxp/webhook-sink', 'POST', [], [], [], $server, $body);
    }

    public function testDisabledByDefaultAnswers404(): void
    {
        $response = (new WebhookSinkController(false, $this->dir))($this->request('{}', null));
        self::assertSame(404, $response->getStatusCode());
        self::assertFileDoesNotExist($this->dir.'/var/elevate-dxp/webhook-sink.log');
    }

    public function testEnabledRecordsDelivery(): void
    {
        $response = (new WebhookSinkController(true, $this->dir))($this->request('{"event":"object.update"}', 'sha256=abc'));
        self::assertSame(204, $response->getStatusCode());
        $line = json_decode((string) file_get_contents($this->dir.'/var/elevate-dxp/webhook-sink.log'), true);
        self::assertSame('object.update', $line['event']);
        self::assertSame('sha256=abc', $line['signature']);
        self::assertNull($line['signatureValid']);
        self::assertSame('{"event":"object.update"}', $line['body']);
    }

    public function testSecretVerifiesSignature(): void
    {
        $body = '{"event":"object.update","data":{}}';
        $controller = new WebhookSinkController(true, $this->dir, 'k');
        self::assertSame(204, $controller($this->request($body, WebhookSignature::sign($body, 'k')))->getStatusCode());
        self::assertSame(401, $controller($this->request($body, 'sha256=forged'))->getStatusCode());
        self::assertSame(401, $controller($this->request($body, null))->getStatusCode());
    }

    public function testLoggedBodyIsCapped(): void
    {
        (new WebhookSinkController(true, $this->dir))($this->request(str_repeat('x', WebhookSinkController::MAX_LOGGED_BODY + 10), null));
        $line = json_decode((string) file_get_contents($this->dir.'/var/elevate-dxp/webhook-sink.log'), true);
        self::assertTrue($line['truncated']);
        self::assertSame(WebhookSinkController::MAX_LOGGED_BODY, \strlen($line['body']));
    }
}
