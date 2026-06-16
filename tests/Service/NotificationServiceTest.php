<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Surcharge de la fonction native mail() dans le namespace App\Service.
 *
 * NotificationService appelle mail() de façon non qualifiée ; PHP résout d'abord la
 * fonction du namespace courant. En déclarant App\Service\mail() ici, le test intercepte
 * chaque envoi sans MTA réel, capture (destinataire, sujet, message, en-têtes) et contrôle
 * la valeur de retour via $GLOBALS. Technique de test standard, aucune modification du code
 * de production.
 */
if (!\function_exists('App\\Service\\mail')) {
    function mail(string $to, string $subject, string $message, string $headers = ''): bool
    {
        $GLOBALS['__notif_mail_calls'][] = [
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
        ];

        return $GLOBALS['__notif_mail_return'] ?? true;
    }
}

namespace Tests\Service;

use App\Service\LogService;
use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de NotificationService.
 *
 * Les envois e-mail sont interceptés par la surcharge App\Service\mail() définie en tête de
 * fichier : chaque appel est enregistré dans $GLOBALS['__notif_mail_calls'] et la valeur de
 * retour de mail() est pilotée par $GLOBALS['__notif_mail_return']. Le LogService est mocké
 * pour vérifier les branches de log succès/échec.
 *
 * Couverture :
 *  - configuration du destinataire et de l'expéditeur depuis $_ENV ;
 *  - chaque notification déclenche exactement UN envoi (sujet/destinataire attendus) ;
 *  - sendCustomAlert() applique nl2br() au corps du message ;
 *  - branche succès -> LogService::info, branche échec -> LogService::error ;
 *  - en-tête From positionné dans les headers.
 */
final class NotificationServiceTest extends TestCase
{
    /** @var array<string, string> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        $GLOBALS['__notif_mail_calls'] = [];
        $GLOBALS['__notif_mail_return'] = true;

        // Mémorise puis fixe les variables d'environnement utilisées par le constructeur.
        foreach (['NOTIF_EMAIL_RECIPIENT', 'MAIL_FROM'] as $key) {
            $this->previousEnv[$key] = $_ENV[$key] ?? '';
        }
        $_ENV['NOTIF_EMAIL_RECIPIENT'] = 'destinataire@test.local';
        $_ENV['MAIL_FROM'] = 'Système n3 <noreply@test.local>';

        putenv('LOG_FILE_PATH=php://memory');
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if ($value === '') {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        unset($GLOBALS['__notif_mail_calls'], $GLOBALS['__notif_mail_return']);
    }

    /**
     * @return array<int, array{to: string, subject: string, message: string, headers: string}>
     */
    private function mailCalls(): array
    {
        return $GLOBALS['__notif_mail_calls'] ?? [];
    }

    private function makeService(?LogService $logger = null): NotificationService
    {
        return new NotificationService($logger ?? $this->createMock(LogService::class));
    }

    public function testSendCustomAlertSendsSingleMailToConfiguredRecipient(): void
    {
        $service = $this->makeService();

        $result = $service->sendCustomAlert('Sujet de test', 'Corps simple');

        self::assertTrue($result);
        $calls = $this->mailCalls();
        self::assertCount(1, $calls);
        self::assertSame('destinataire@test.local', $calls[0]['to']);
        self::assertSame('Sujet de test', $calls[0]['subject']);
    }

    public function testSendCustomAlertAppliesNl2brToBody(): void
    {
        $service = $this->makeService();

        $service->sendCustomAlert('Sujet', "ligne1\nligne2");

        $calls = $this->mailCalls();
        self::assertCount(1, $calls);
        // nl2br insère un <br /> avant chaque saut de ligne.
        self::assertStringContainsString('<br />', $calls[0]['message']);
        self::assertStringContainsString('ligne1', $calls[0]['message']);
        self::assertStringContainsString('ligne2', $calls[0]['message']);
    }

    public function testSendCustomAlertSetsFromHeaderFromEnv(): void
    {
        $service = $this->makeService();

        $service->sendCustomAlert('Sujet', 'Corps');

        $calls = $this->mailCalls();
        self::assertStringContainsString('From: Système n3 <noreply@test.local>', $calls[0]['headers']);
        self::assertStringContainsString('Content-type: text/html; charset=utf-8', $calls[0]['headers']);
    }

    public function testSuccessfulSendLogsInfo(): void
    {
        $GLOBALS['__notif_mail_return'] = true;

        $logger = $this->createMock(LogService::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('destinataire@test.local'));
        $logger->expects($this->never())->method('error');

        $this->makeService($logger)->sendCustomAlert('Sujet', 'Corps');
    }

    public function testFailedSendLogsError(): void
    {
        $GLOBALS['__notif_mail_return'] = false;

        $logger = $this->createMock(LogService::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Échec'));
        $logger->expects($this->never())->method('info');

        $result = $this->makeService($logger)->sendCustomAlert('Sujet', 'Corps');

        self::assertFalse($result);
    }

    public function testNotifyFloodRiskSendsExpectedSubject(): void
    {
        $service = $this->makeService();

        $service->notifyFloodRisk();

        $calls = $this->mailCalls();
        self::assertCount(1, $calls);
        self::assertSame("Alerte système : risque d'inondation", $calls[0]['subject']);
        self::assertStringContainsString("niveau d'eau", $calls[0]['message']);
    }

    public function testNotifyMareesProblemSendsExpectedSubject(): void
    {
        $service = $this->makeService();

        $service->notifyMareesProblem();

        $calls = $this->mailCalls();
        self::assertCount(1, $calls);
        self::assertSame('Alerte système : problème de marées', $calls[0]['subject']);
    }

    public function testNotifyNoSensorDataSendsExpectedSubject(): void
    {
        $service = $this->makeService();

        $service->notifyNoSensorData();

        $calls = $this->mailCalls();
        self::assertCount(1, $calls);
        self::assertSame('Alerte système : aucune donnée capteur disponible', $calls[0]['subject']);
    }

    public function testNotifySystemOfflineSendsExpectedSubject(): void
    {
        $service = $this->makeService();

        $service->notifySystemOffline();

        $calls = $this->mailCalls();
        self::assertCount(1, $calls);
        self::assertSame('Alerte système : système hors ligne', $calls[0]['subject']);
    }

    public function testDefaultRecipientUsedWhenEnvMissing(): void
    {
        unset($_ENV['NOTIF_EMAIL_RECIPIENT']);

        $this->makeService()->notifyFloodRisk();

        $calls = $this->mailCalls();
        self::assertSame('user@example.com', $calls[0]['to']);
    }
}
