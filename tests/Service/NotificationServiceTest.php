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

use App\Notification\AlertThrottler;
use App\Notification\NotificationCategory;
use App\Notification\NotificationMode;
use App\Notification\NotificationPolicy;
use App\Notification\Severity;
use App\Service\LogService;
use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de NotificationService.
 *
 * Les envois e-mail sont interceptés par la surcharge App\Service\mail() définie en tête de
 * fichier : chaque appel est enregistré dans $GLOBALS['__notif_mail_calls'] et la valeur de
 * retour de mail() est pilotée par $GLOBALS['__notif_mail_return']. Le LogService est mocké
 * pour vérifier les branches de log succès/échec. Le AlertThrottler est mocké (allow=true par
 * défaut) afin d'isoler les tests de la base de données.
 *
 * Couverture :
 *  - configuration du destinataire et de l'expéditeur depuis $_ENV ;
 *  - chaque notification déclenche exactement UN envoi (sujet préfixé [FAMILLE][Pn]) ;
 *  - sendCustomAlert() applique nl2br() au corps du message ;
 *  - branche succès -> LogService::info, branche échec -> LogService::error ;
 *  - filtrage par la politique (mode + catégorie coupée) ;
 *  - suppression par l'anti-spam (cooldown).
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

    /** Throttler permissif (autorise tout envoi) pour isoler les tests de la base. */
    private function permissiveThrottler(): AlertThrottler
    {
        $throttler = $this->createMock(AlertThrottler::class);
        $throttler->method('allow')->willReturn(true);

        return $throttler;
    }

    private function makeService(
        ?LogService $logger = null,
        ?NotificationPolicy $policy = null,
        ?AlertThrottler $throttler = null
    ): NotificationService {
        return new NotificationService(
            $logger ?? $this->createMock(LogService::class),
            $policy ?? new NotificationPolicy(NotificationMode::Full),
            $throttler ?? $this->permissiveThrottler()
        );
    }

    public function testSendCustomAlertSendsSingleMailToConfiguredRecipient(): void
    {
        $service = $this->makeService();

        $result = $service->sendCustomAlert('Sujet de test', 'Corps simple');

        self::assertTrue($result);
        $calls = $this->mailCalls();
        self::assertCount(1, $calls);
        self::assertSame('destinataire@test.local', $calls[0]['to']);
        // sendCustomAlert : sévérité P2 (Alerte), sans famille -> préfixe [P2].
        self::assertSame('[P2] Sujet de test', $calls[0]['subject']);
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
        self::assertSame("[FFP3][P1] Risque d'inondation", $calls[0]['subject']);
        self::assertStringContainsString("niveau d'eau", $calls[0]['message']);
    }

    public function testNotifyMareesProblemSendsExpectedSubject(): void
    {
        $service = $this->makeService();

        $service->notifyMareesProblem();

        $calls = $this->mailCalls();
        self::assertCount(1, $calls);
        self::assertSame('[FFP3][P1] Problème de marées', $calls[0]['subject']);
    }

    public function testNotifyNoSensorDataSendsExpectedSubject(): void
    {
        $service = $this->makeService();

        $service->notifyNoSensorData();

        $calls = $this->mailCalls();
        self::assertCount(1, $calls);
        self::assertSame('[FFP3][P2] Aucune donnée capteur disponible', $calls[0]['subject']);
    }

    public function testNotifySystemOfflineSendsExpectedSubject(): void
    {
        $service = $this->makeService();

        $service->notifySystemOffline();

        $calls = $this->mailCalls();
        self::assertCount(1, $calls);
        self::assertSame('[FFP3][P1] Système hors ligne', $calls[0]['subject']);
    }

    public function testSendAlertBuildsFamilyAndSeverityPrefix(): void
    {
        $service = $this->makeService();

        $service->sendAlert(
            Severity::Critical,
            NotificationCategory::Energy,
            'N3PP',
            'Batterie critique (1650 mV)',
            'Niveau batterie sous le seuil.'
        );

        $calls = $this->mailCalls();
        self::assertCount(1, $calls);
        self::assertSame('[N3PP][P1] Batterie critique (1650 mV)', $calls[0]['subject']);
    }

    public function testDefaultRecipientUsedWhenEnvMissing(): void
    {
        unset($_ENV['NOTIF_EMAIL_RECIPIENT']);

        $this->makeService()->notifyFloodRisk();

        $calls = $this->mailCalls();
        self::assertSame('user@example.com', $calls[0]['to']);
    }

    // ------------------------------------------------------------------
    // Filtrage par la politique (mode de verbosité + catégorie coupée)
    // ------------------------------------------------------------------

    public function testModeNoneBlocksEveryMail(): void
    {
        $service = $this->makeService(null, new NotificationPolicy(NotificationMode::None));

        $service->notifyMareesProblem();
        $service->notifySystemOffline();

        self::assertCount(0, $this->mailCalls());
    }

    public function testImportantModeBlocksInfoButAllowsAlert(): void
    {
        $service = $this->makeService(null, new NotificationPolicy(NotificationMode::Important));

        $service->sendAlert(Severity::Info, NotificationCategory::Lifecycle, 'FFP3', 'Réveil système', 'corps');
        self::assertCount(0, $this->mailCalls(), 'Une P3 doit être filtrée en mode important');

        $service->sendAlert(Severity::Alert, NotificationCategory::System, 'FFP3', 'Anomalie', 'corps');
        self::assertCount(1, $this->mailCalls(), 'Une P2 doit passer en mode important');
    }

    public function testDisabledCategoryBlocksMatchingMailOnly(): void
    {
        $policy = new NotificationPolicy(NotificationMode::Full, [NotificationCategory::Hydraulic]);
        $service = $this->makeService(null, $policy);

        // Catégorie Hydraulic coupée -> marées filtrées.
        $service->notifyMareesProblem();
        self::assertCount(0, $this->mailCalls());

        // Catégorie Availability toujours active -> passe.
        $service->notifySystemOffline();
        self::assertCount(1, $this->mailCalls());
    }

    public function testThrottledAlertIsNotSent(): void
    {
        $throttler = $this->createMock(AlertThrottler::class);
        $throttler->method('allow')->willReturn(false);

        $service = $this->makeService(null, new NotificationPolicy(NotificationMode::Full), $throttler);

        $service->notifyMareesProblem();

        self::assertCount(0, $this->mailCalls(), 'Le cooldown anti-spam doit supprimer l\'envoi');
    }
}
