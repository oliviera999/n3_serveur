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
 *  - cas vide SANS message (bug B1 corrigé) : CSV vide valide (200, en-têtes seuls) au lieu
 *    d'une RuntimeException / HTTP 500 ;
 *  - délégation des dates et du chemin temporaire au repository.
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

    public function testExportEmptyWithoutMessageReturnsValidEmptyCsv(): void
    {
        // Bug B1 : 0 ligne + emptyMessage null ne doit plus lever de RuntimeException (HTTP 500).
        // Le repository écrit tout de même la ligne d'en-tête (colonnes) : on renvoie un CSV
        // vide mais valide (200) avec Content-Type/Content-Disposition.
        $header = "id,TempAir,reading_time\n";
        $repo = $this->makeRepository($header, 0);

        $response = $this->service->export(
            $repo,
            '2026-01-01 00:00:00',
            '2026-01-02 00:00:00',
            $this->responseFactory->createResponse(),
            'aquaponie'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment; filename="aquaponie_', $response->getHeaderLine('Content-Disposition'));

        $response->getBody()->rewind();
        self::assertSame($header, $response->getBody()->getContents());
        self::assertFileDoesNotExist($repo->lastPath);
    }

    public function testExportEmptyWithoutMessageAndNoFileReturnsEmptyBody(): void
    {
        // Repository qui ne crée aucun fichier et retourne 0 : garde-fou anti-500 -> corps vide.
        $repo = new class {
            public string $lastPath = '';

            public function exportCsv(string $start, string $end, string $path): int
            {
                $this->lastPath = $path;

                return 0;
            }
        };

        $response = $this->service->export(
            $repo,
            '2026-01-01 00:00:00',
            '2026-01-02 00:00:00',
            $this->responseFactory->createResponse(),
            'aquaponie'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('0', $response->getHeaderLine('Content-Length'));

        $response->getBody()->rewind();
        self::assertSame('', $response->getBody()->getContents());
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
