<?php

namespace Tests\Unit;

use App\Support\EmailInlineImages;
use Illuminate\Mail\Message;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailInlineImagesTest extends TestCase
{
    private const LOGO = 'images/Kingsbury-Personnel-Logo.png';

    private function message(): Message
    {
        return new Message((new Email())->from('from@example.test')->to('to@example.test')->subject('Test'));
    }

    public function test_signature_logo_is_embedded_for_any_host(): void
    {
        foreach (['http://localhost/', 'https://crm.example.test/', '/', 'http://10.0.0.5/crm/'] as $base) {
            $html = EmailInlineImages::embed($this->message(), '<img alt="x" src="' . $base . self::LOGO . '" width="100">');

            $this->assertMatchesRegularExpression('/<img alt="x" src="cid:[^"]+" width="100">/', $html);
        }
    }

    public function test_embedded_signature_logo_is_an_inline_mime_part(): void
    {
        $message = $this->message();
        $message->html(EmailInlineImages::embed($message, '<p>Hi</p><img src="https://crm.example.test/' . self::LOGO . '">'));

        $mime = $message->getSymfonyMessage()->toString();

        $this->assertStringContainsString('multipart/related', $mime);
        $this->assertStringContainsString('Content-Disposition: inline', $mime);
        $this->assertStringContainsString('Content-Type: image/png', $mime);
        $this->assertStringContainsString('src=3D"cid:', $mime);
    }

    public function test_repeated_signature_logo_is_embedded_once(): void
    {
        $message = $this->message();
        $url = 'https://crm.example.test/' . self::LOGO;
        EmailInlineImages::embed($message, '<img src="' . $url . '"><img src="' . $url . '">');

        $this->assertCount(1, $message->getSymfonyMessage()->getAttachments());
    }

    public function test_signature_logo_on_a_private_address_is_embedded(): void
    {
        $message = $this->message();
        $html = EmailInlineImages::embed($message, '<img src="http://127.0.0.1/' . self::LOGO . '">');

        $this->assertStringContainsString('src="cid:', $html);
        $this->assertCount(1, $message->getSymfonyMessage()->getAttachments());
    }

    public function test_signature_logo_on_a_public_address_stays_a_link(): void
    {
        foreach (['https://crm.kingsburypersonnel.com/', 'http://8.8.8.8/'] as $base) {
            $html = '<img src="' . $base . self::LOGO . '">';
            $message = $this->message();

            $this->assertSame($html, EmailInlineImages::embed($message, $html));
            $this->assertCount(0, $message->getSymfonyMessage()->getAttachments());
        }
    }

    public function test_other_images_are_left_unchanged(): void
    {
        $html = '<img src="https://other.example.test/banner.png">'
            . '<img src="https://crm.example.test/uploads/email-signatures/missing.png">'
            . '<img src="data:image/png;base64,AAAA">'
            . '<img src="https://crm.example.test/storage/../../.env">';
        $message = $this->message();

        $this->assertSame($html, EmailInlineImages::embed($message, $html));
        $this->assertCount(0, $message->getSymfonyMessage()->getAttachments());
    }
}
