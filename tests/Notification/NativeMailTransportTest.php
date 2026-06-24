<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Surcharge de mail() dans le namespace App\Notification : NativeMailTransport appelle
 * mail() de façon non qualifiée, PHP résout d'abord la fonction du namespace courant.
 * On capture (to, subject, message, headers) sans MTA réel et on pilote la valeur de retour.
 */
if (!\function_exists('App\\Notification\\mail')) {
    function mail(string $to, string $subject, string $message, string $headers = ''): bool
    {
        $GLOBALS['__native_mail_calls'][] = [
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
        ];

        return $GLOBALS['__native_mail_return'] ?? true;
    }
}

namespace Tests\Notification;

use App\Notification\NativeMailTransport;
use PHPUnit\Framework\TestCase;

final class NativeMailTransportTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__native_mail_calls'] = [];
        $GLOBALS['__native_mail_return'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__native_mail_calls'], $GLOBALS['__native_mail_return']);
    }

    public function testSendBuildsMultipartMessage(): void
    {
        $transport = new NativeMailTransport();

        $ok = $transport->send(
            'dest@test.local',
            'Exp <noreply@test.local>',
            '[FFP3][P1] Sujet',
            '<html><body>HTML body</body></html>',
            'Texte brut'
        );

        self::assertTrue($ok);
        $calls = $GLOBALS['__native_mail_calls'];
        self::assertCount(1, $calls);
        self::assertSame('dest@test.local', $calls[0]['to']);
        self::assertSame('[FFP3][P1] Sujet', $calls[0]['subject']);
        self::assertStringContainsString('From: Exp <noreply@test.local>', $calls[0]['headers']);
        self::assertStringContainsString('multipart/alternative', $calls[0]['headers']);
        // Le corps contient à la fois la partie texte et la partie HTML.
        self::assertStringContainsString('text/plain', $calls[0]['message']);
        self::assertStringContainsString('Texte brut', $calls[0]['message']);
        self::assertStringContainsString('text/html', $calls[0]['message']);
        self::assertStringContainsString('HTML body', $calls[0]['message']);
    }

    public function testSendReturnsFalseWhenMailFails(): void
    {
        $GLOBALS['__native_mail_return'] = false;
        $transport = new NativeMailTransport();

        $ok = $transport->send('a@b.c', 'x@y.z', 'S', '<p>h</p>', 't');

        self::assertFalse($ok);
    }
}
