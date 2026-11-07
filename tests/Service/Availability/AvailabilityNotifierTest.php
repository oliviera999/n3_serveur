<?php

declare(strict_types=1);

namespace Tests\Service\Availability;

use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Service\Availability\AvailabilityIncident;
use App\Service\Availability\AvailabilityNotifier;
use App\Service\LogService;
use App\Service\NotificationService;
use App\Util\JsonFileStore;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la machine à états « disponibilité » : DEUX e-mails par panne (perte + retour),
 * pas un de plus, quelle que soit la durée de l'incident ni le nombre de passages CRON.
 */
final class AvailabilityNotifierTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        putenv('LOG_FILE_PATH=php://memory');
        $this->stateFile = sys_get_temp_dir() . '/availability_state_' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    private function notifier(NotificationService $mails): AvailabilityNotifier
    {
        return new AvailabilityNotifier(
            $mails,
            $this->createMock(LogService::class),
            new JsonFileStore($this->stateFile)
        );
    }

    public function testOfflineIsAnnouncedOnceThenStaysSilent(): void
    {
        $mails = $this->createMock(NotificationService::class);
        $mails->expects($this->once())
            ->method('sendImmediateAlert')
            ->with(
                Severity::Critical,
                NotificationCategory::Availability,
                'N3PP',
                'Appareil silencieux (heartbeat)',
                $this->anything(),
                'heartbeat:offline:n3pp'
            )
            ->willReturn(true);

        $notifier = $this->notifier($mails);
        $incident = AvailabilityIncident::heartbeat('N3PP');

        self::assertTrue($notifier->reportOffline($incident, 'plus de heartbeat'));
        // Panne toujours en cours : les passages suivants ne doivent RIEN envoyer.
        for ($i = 0; $i < 10; $i++) {
            self::assertFalse($notifier->reportOffline($incident, 'plus de heartbeat'));
        }
    }

    public function testRecoveryIsAnnouncedOnceThenStaysSilent(): void
    {
        $subjects = [];
        $mails = $this->createMock(NotificationService::class);
        $mails->method('sendImmediateAlert')->willReturnCallback(
            static function (
                Severity $severity,
                NotificationCategory $category,
                string $family,
                string $subject,
                string $message
            ) use (&$subjects): bool {
                $subjects[] = $subject;

                return true;
            }
        );

        $notifier = $this->notifier($mails);
        $incident = AvailabilityIncident::heartbeat('N3PP');

        $notifier->reportOffline($incident, 'plus de heartbeat');
        $notifier->reportOffline($incident, 'plus de heartbeat');

        self::assertTrue($notifier->reportOnline($incident));
        // Appareil de nouveau en ligne : plus aucun e-mail « tout va bien » ensuite.
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($notifier->reportOnline($incident));
        }

        self::assertSame(
            ['Appareil silencieux (heartbeat)', 'Appareil de nouveau en ligne'],
            $subjects
        );
    }

    public function testHealthyDeviceNeverSendsAnything(): void
    {
        $mails = $this->createMock(NotificationService::class);
        $mails->expects($this->never())->method('sendImmediateAlert');

        $notifier = $this->notifier($mails);
        $incident = AvailabilityIncident::heartbeat('MSP1');

        for ($i = 0; $i < 3; $i++) {
            self::assertFalse($notifier->reportOnline($incident));
        }
    }

    public function testNewOutageAfterRecoveryIsAnnouncedAgain(): void
    {
        $mails = $this->createMock(NotificationService::class);
        $mails->expects($this->exactly(4))->method('sendImmediateAlert')->willReturn(true);

        $notifier = $this->notifier($mails);
        $incident = AvailabilityIncident::heartbeat('FFP3');

        $notifier->reportOffline($incident, 'panne 1');   // 1 : perte
        $notifier->reportOnline($incident);               // 2 : retour
        $notifier->reportOffline($incident, 'panne 2');   // 3 : nouvelle perte
        $notifier->reportOnline($incident);               // 4 : nouveau retour
    }

    public function testFailedSendIsRetriedThenAbandoned(): void
    {
        // Transport KO / politique muette : la bascule n'est pas consommée, on réessaie —
        // mais jamais indéfiniment (3 tentatives), sinon le CRON réémettrait à chaque passage.
        $mails = $this->createMock(NotificationService::class);
        $mails->expects($this->exactly(3))->method('sendImmediateAlert')->willReturn(false);

        $notifier = $this->notifier($mails);
        $incident = AvailabilityIncident::heartbeat('N3PP');

        for ($i = 0; $i < 6; $i++) {
            self::assertFalse($notifier->reportOffline($incident, 'plus de heartbeat'));
        }
    }

    public function testRecoveryIsNotAnnouncedWhenOutageNeverWas(): void
    {
        // L'ouverture d'incident n'est jamais partie (politique muette) : on n'annonce pas
        // la fin d'une panne que personne n'a vue commencer.
        $mails = $this->createMock(NotificationService::class);
        $mails->method('sendImmediateAlert')->willReturn(false);

        $notifier = $this->notifier($mails);
        $incident = AvailabilityIncident::dataFlow('FFP3');

        $notifier->reportOffline($incident, 'plus de données');

        $silent = $this->createMock(NotificationService::class);
        $silent->expects($this->never())->method('sendImmediateAlert');
        self::assertFalse($this->notifier($silent)->reportOnline($incident));
    }

    public function testStateSurvivesBetweenRuns(): void
    {
        // Chaque passage CRON reconstruit le service : l'état doit venir du fichier.
        $mails = $this->createMock(NotificationService::class);
        $mails->expects($this->once())->method('sendImmediateAlert')->willReturn(true);

        $incident = AvailabilityIncident::heartbeat('N3PP');
        $this->notifier($mails)->reportOffline($incident, 'plus de heartbeat');
        $this->notifier($mails)->reportOffline($incident, 'plus de heartbeat');
        $this->notifier($mails)->reportOffline($incident, 'plus de heartbeat');
    }

    public function testIsOfflineReflectsCurrentIncidentState(): void
    {
        $mails = $this->createMock(NotificationService::class);
        $mails->method('sendImmediateAlert')->willReturn(true);

        $notifier = $this->notifier($mails);
        $incident = AvailabilityIncident::heartbeat('FFP3');

        self::assertFalse($notifier->isOffline($incident));
        $notifier->reportOffline($incident, 'plus de heartbeat');
        self::assertTrue($notifier->isOffline($incident));
        $notifier->reportOnline($incident);
        self::assertFalse($notifier->isOffline($incident));
    }

    public function testRecoveryMailReportsOutageDuration(): void
    {
        $message = '';
        $mails = $this->createMock(NotificationService::class);
        $mails->method('sendImmediateAlert')->willReturnCallback(
            static function (
                Severity $severity,
                NotificationCategory $category,
                string $family,
                string $subject,
                string $body
            ) use (&$message): bool {
                if ($subject === 'Appareil de nouveau en ligne') {
                    $message = $body;
                }

                return true;
            }
        );

        $incident = AvailabilityIncident::heartbeat('N3PP');
        $notifier = $this->notifier($mails);
        $notifier->reportOffline($incident, 'plus de heartbeat');

        // Antidate l'ouverture de l'incident de 3 h pour vérifier la durée annoncée.
        $store = new JsonFileStore($this->stateFile);
        $state = $store->load();
        $record = $state[$incident->key];
        self::assertIsArray($record);
        $record['since'] = time() - 10800;
        $state[$incident->key] = $record;
        $store->save($state);

        $notifier->reportOnline($incident);

        self::assertStringContainsString('3 h', $message);
        self::assertStringContainsString('N3PP', $message);
    }

    public function testIncidentKeysMatchLegacyThrottleKeys(): void
    {
        // Continuité de l'historique `notification_log` : mêmes clés qu'avant la machine à états.
        self::assertSame('heartbeat:offline:n3pp', AvailabilityIncident::heartbeat('N3PP')->key);
        self::assertSame('ffp3:offline', AvailabilityIncident::dataFlow('FFP3')->key);
        self::assertSame('FFP3', AvailabilityIncident::dataFlow('ffp3')->family);
    }
    /**
     * Incident du 06/11/2026 : à 18:35 « Système hors ligne » (dernier POST 16:39:18) ; à 19:40 le
     * seuil nuit s'allonge et l'âge du dernier POST repasse SOUS le seuil → l'ancien code annonçait
     * « Réception des données rétablie » sans la moindre donnée reçue, puis « hors ligne » à 21:45.
     * Désormais la clôture exige un contact strictement postérieur au dernier contact connu.
     */
    public function testRecoveryRequiresNewContactAfterOutageOpened(): void
    {
        $lastPost = (int) strtotime('2026-11-06 16:39:18');
        $subjects = [];
        $mails = $this->createMock(NotificationService::class);
        $mails->method('sendImmediateAlert')->willReturnCallback(
            static function (Severity $s, NotificationCategory $c, string $f, string $subject) use (&$subjects): bool {
                $subjects[] = $subject;

                return true;
            }
        );
        $notifier = $this->notifier($mails);
        $incident = AvailabilityIncident::dataFlow('FFP3');

        // 18:35 : ouverture, dernier contact connu = 16:39:18.
        self::assertTrue($notifier->reportOffline($incident, 'plus de données', Severity::Critical, $lastPost));
        self::assertSame($lastPost, $notifier->lastKnownContact($incident));

        // 19:40 : la supervision juge « en ligne » (seuil nuit) mais le dernier contact est inchangé.
        self::assertFalse($notifier->reportOnline($incident, $lastPost), 'aucun nouveau contact : pas de rétablissement');
        self::assertTrue($notifier->isOffline($incident));
        // Un appelant qui ne fournit aucun contact ne peut pas non plus clore l'incident.
        self::assertFalse($notifier->reportOnline($incident, null));
        self::assertTrue($notifier->isOffline($incident));

        // 21:45 : toujours hors ligne, aucun second e-mail d'ouverture.
        self::assertFalse($notifier->reportOffline($incident, 'plus de données', Severity::Critical, $lastPost));

        // Un POST réellement reçu plus tard → UN e-mail de rétablissement.
        self::assertTrue($notifier->reportOnline($incident, $lastPost + 3 * 3600));
        self::assertFalse($notifier->isOffline($incident));

        self::assertSame(['Système hors ligne', 'Réception des données rétablie'], $subjects);
    }

    public function testLegacyStateWithoutKnownContactStillCloses(): void
    {
        // État persisté par une version antérieure (sans last_contact) : comportement historique.
        $mails = $this->createMock(NotificationService::class);
        $mails->method('sendImmediateAlert')->willReturn(true);
        $notifier = $this->notifier($mails);
        $incident = AvailabilityIncident::heartbeat('N3PP');

        $notifier->reportOffline($incident, 'plus de heartbeat'); // sans horodatage
        self::assertTrue($notifier->reportOnline($incident));
        self::assertFalse($notifier->isOffline($incident));
    }
}
