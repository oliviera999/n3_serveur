<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\CsvExportService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Tests unitaires de CsvExportService.
 *
 * Le service factorise le pattern « fichier temporaire -> lecture en streaming ->
 * suppression » utilisé par les exports CSV des capteurs. Il n'utilise aucune base de
 * données : on lui injecte un faux « repository » (objet anonyme exposant exportCsv())
 * qui écrit le contenu attendu dans le fichier temporaire et retourne le nombre de lignes.
 *
 * Couverture :
 *  - cas nominal : en-têtes Content-Type/Content-Disposition/Content-Length, corps CSV,
 *    nettoyage du fichier temporaire ;
 *  - cas vide avec message : réponse 204 text/plain et message dans le corps ;
 *  - délégation des dates et du chemin temporaire au repository.
 *
 * NON couvert (signalé) : la branche « 0 ligne SANS emptyMessage ». Dans ce cas le service
 * supprime lui-même le fichier temporaire puis tente quand même de le relire, ce qui lève
 * une RuntimeException ; l'usage sûr/documenté est de toujours fournir un emptyMessage
 * lorsqu'un export peut être vide, c'est donc ce contrat qui est verrouillé ici.
 */
final class CsvExportServiceTest extends TestCase
{
    private CsvExportService $service;
    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->service = new CsvExportService();
        $this->responseFactory = new ResponseFactory();
    }

    /**
     * Construit un faux repository qui écrit $content dans le fichier cible et retourne
     * le nombre de lignes indiqué. Le dernier chemin reçu est mémorisé pour vérifier
     * le nettoyage et la convention de nommage (sys_get_temp_dir()).
     */
    private function makeRepository(string $content, int $lines): object
    {
        return new class ($content, $lines) {
            public string $lastPath = '';
            public string $lastStart = '';
            public string $lastEnd = '';

            public function __construct(private string $content, private int $lines)
            {
            }

            public function exportCsv(string $start, string $end, string $path): int
            {
                $this->lastStart = $start;
                $this->lastEnd = $end;
                $this->lastPath = $path;
                file_put_contents($path, $this->content);

                return $this->lines;
            }
        };
    }

    public function testExportNominalSetsCsvHeadersAndBody(): void
    {
        $csv = "date,temperature\n2026-01-01 00:00:00,21.5\n";
        $repo = $this->makeRepository($csv, 2);

        $response = $this->service->export(
            $repo,
            '2026-01-01 00:00:00',
            '2026-01-02 00:00:00',
            $this->responseFactory->createResponse(),
            'aquaponie'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            (string) strlen($csv),
            $response->getHeaderLine('Content-Length')
        );

        $response->getBody()->rewind();
        self::assertSame($csv, $response->getBody()->getContents());
    }

    public function testExportSetsAttachmentFilenameWithPrefix(): void
    {
        $repo = $this->makeRepository("a,b\n1,2\n", 1);

        $response = $this->service->export(
            $repo,
            '2026-01-01 00:00:00',
            '2026-01-02 00:00:00',
            $this->responseFactory->createResponse(),
            'dashboard'
        );

        $disposition = $response->getHeaderLine('Content-Disposition');
        self::assertStringStartsWith('attachment; filename="dashboard_', $disposition);
        self::assertMatchesRegularExpression(
            '/^attachment; filename="dashboard_\d{14}\.csv"$/',
            $disposition
        );
    }

    public function testExportRemovesTemporaryFileAfterStreaming(): void
    {
        $repo = $this->makeRepository("x,y\n1,2\n", 1);

        $this->service->export(
            $repo,
            '2026-01-01 00:00:00',
            '2026-01-02 00:00:00',
            $this->responseFactory->createResponse(),
            'msp'
        );

        self::assertNotSame('', $repo->lastPath);
        self::assertFileDoesNotExist($repo->lastPath);
    }

    public function testExportPassesDatesAndTempPathToRepository(): void
    {
        $repo = $this->makeRepository("h\n1\n", 1);

        $this->service->export(
            $repo,
            '2026-03-01 08:00:00',
            '2026-03-02 08:00:00',
            $this->responseFactory->createResponse(),
            'n3pp'
        );

        self::assertSame('2026-03-01 08:00:00', $repo->lastStart);
        self::assertSame('2026-03-02 08:00:00', $repo->lastEnd);
        self::assertStringStartsWith(sys_get_temp_dir(), $repo->lastPath);
        self::assertStringContainsString('n3pp_', $repo->lastPath);
    }

    public function testExportEmptyWithMessageReturns204PlainText(): void
    {
        $repo = $this->makeRepository('', 0);

        $response = $this->service->export(
            $repo,
            '2026-01-01 00:00:00',
            '2026-01-02 00:00:00',
            $this->responseFactory->createResponse(),
            'aquaponie',
            'Aucune donnée disponible'
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $response->getBody()->rewind();
        self::assertSame('Aucune donnée disponible', $response->getBody()->getContents());
    }

    public function testExportEmptyWithMessageCleansTemporaryFile(): void
    {
        // Le repository crée le fichier puis retourne 0 ; le service doit le supprimer
        // avant de répondre 204.
        $repo = $this->makeRepository('', 0);

        $this->service->export(
            $repo,
            '2026-01-01 00:00:00',
            '2026-01-02 00:00:00',
            $this->responseFactory->createResponse(),
            'aquaponie',
            'vide'
        );

        self::assertNotSame('', $repo->lastPath);
        self::assertFileDoesNotExist($repo->lastPath);
    }
}
