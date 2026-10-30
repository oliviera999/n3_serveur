<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Display\LayoutResolver;
use App\Display\PageCatalogRegistry;
use App\Repository\DisplayPresetRepository;
use App\Service\DisplayPresetService;
use App\Service\LogService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Presets intégrés vs enregistrés (SQLite en mémoire) : fusion, défaut unique,
 * sélection par ?preset=, validation stricte et repli si la BDD est absente.
 */
final class DisplayPresetServiceTest extends TestCase
{
    private DisplayPresetService $service;
    private PageCatalogRegistry $registry;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->registry = new PageCatalogRegistry();
        $this->service = new DisplayPresetService(
            new DisplayPresetRepository($pdo),
            $this->registry,
            new LayoutResolver(),
            $this->createMock(LogService::class)
        );
    }

    public function testBuiltInPresetsWithoutStoredRows(): void
    {
        $catalog = $this->registry->get('msp1');
        $this->assertNotNull($catalog);
        $presets = $this->service->presets($catalog);

        $this->assertSame(
            ['simple', 'visite', 'eleves', 'etendu', 'technique'],
            array_column($presets, 'slug')
        );
        // Lecteur / anonyme : défaut « simple » tant que le défaut BDD n'est pas personnalisé.
        $this->assertSame('simple', $this->service->select($presets, null, 'reader')['slug']);
        $this->assertSame('etendu', $this->service->select($presets, null, 'operator')['slug']);
        $this->assertSame('simple', $this->service->select($presets, 'simple')['slug']);
        $this->assertSame('etendu', $this->service->select($presets, 'inconnu', 'operator')['slug']);
    }

    public function testStoredPresetBecomesUniqueDefaultAndAppearsInMenu(): void
    {
        $catalog = $this->registry->get('n3pp');
        $this->assertNotNull($catalog);
        $this->service->save($catalog, [
            'slug' => 'eleves',
            'label' => 'Élèves',
            'description' => 'Vue collège',
            'is_default' => true,
            'show_on_page' => true,
            'sort_order' => 5,
            'layout' => ['blocks' => [['id' => 'physique'], ['id' => 'sol', 'visible' => true], ['id' => 'systeme', 'visible' => false]]],
        ], 'prof');

        $presets = $this->service->presets($catalog);
        $this->assertSame('eleves', $presets[0]['slug']);
        $this->assertTrue($presets[0]['is_default']);
        $this->assertContains('simple', array_column($presets, 'slug'));
        $this->assertContains('etendu', array_column($presets, 'slug'));
        $this->assertSame('prof', $presets[0]['updated_by']);

        $ctx = $this->service->pageContext($catalog, null, 'operator');
        $this->assertSame('eleves', $ctx['preset']['slug']);
        $this->assertSame('eleves', $ctx['default_preset_slug']);
        $this->assertSame('physique', $ctx['layout']['blocks'][0]['id']);
        $this->assertNotContains('systeme', array_column($ctx['layout']['blocks'], 'id'));
        $this->assertNotContains('chart-cycles', json_decode($ctx['chart_ids_json'], true));
        $this->assertContains('eleves', array_column($ctx['presets_menu'], 'slug'));
        $this->assertContains('simple', array_column($ctx['presets_menu'], 'slug'));
    }

    public function testCustomizingABuiltInPresetAndResettingIt(): void
    {
        $catalog = $this->registry->get('energie');
        $this->assertNotNull($catalog);
        $this->service->save($catalog, [
            'slug' => 'simple',
            'label' => 'Simple (atelier)',
            'show_on_page' => false,
            'layout' => ['blocks' => [['id' => 'batterie'], ['id' => 'puissances']]],
        ], null);

        $simple = $this->service->presets($catalog)[0];
        $this->assertSame('Simple (atelier)', $simple['label']);
        $this->assertTrue($simple['builtin']);
        $this->assertTrue($simple['customized']);

        // Masqué du menu, mais toujours accessible en prévisualisation (?preset=simple).
        $ctx = $this->service->pageContext($catalog, 'simple');
        $this->assertSame('simple', $ctx['preset']['slug']);
        $this->assertContains('simple', array_column($ctx['presets_menu'], 'slug'));
        $this->assertContains('etendu', array_column($ctx['presets_menu'], 'slug'));
        $this->assertNotContains('simple', array_column($this->service->pageContext($catalog, null, 'operator')['presets_menu'], 'slug'));

        $this->assertTrue($this->service->delete($catalog, 'simple'));
        $this->assertSame('Simple', $this->service->presets($catalog)[0]['label']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidInputs(): iterable
    {
        $layout = ['blocks' => []];
        yield 'slug vide' => [['slug' => '', 'label' => 'X', 'layout' => $layout]];
        yield 'slug invalide' => [['slug' => 'Mon Preset', 'label' => 'X', 'layout' => $layout]];
        yield 'label vide' => [['slug' => 'ok', 'label' => '  ', 'layout' => $layout]];
        yield 'label trop long' => [['slug' => 'ok', 'label' => str_repeat('x', 65), 'layout' => $layout]];
        yield 'layout invalide' => [['slug' => 'ok', 'label' => 'X', 'layout' => ['blocks' => [['id' => 'inconnu']]]]];
    }

    /**
     * @dataProvider invalidInputs
     * @param array<string, mixed> $input
     */
    public function testInvalidInputIsRejected(array $input): void
    {
        $catalog = $this->registry->get('msp1');
        $this->assertNotNull($catalog);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->save($catalog, $input, null);
    }

    public function testFallsBackToBuiltInPresetsWhenStorageFails(): void
    {
        $repo = $this->createMock(DisplayPresetRepository::class);
        $repo->method('findByPage')->willThrowException(new \PDOException('SQLSTATE[HY000] [2002] Connection refused'));
        $service = new DisplayPresetService($repo, $this->registry, new LayoutResolver(), $this->createMock(LogService::class));

        $catalog = $this->registry->get('msp1');
        $this->assertNotNull($catalog);
        $ctx = $service->pageContext($catalog, null, 'operator');
        $this->assertSame('etendu', $ctx['preset']['slug']);
        $this->assertNotEmpty($ctx['layout']['blocks']);
        $this->assertSame('simple', $service->pageContext($catalog, null, 'reader')['preset']['slug']);
    }

    public function testEditorDataExposesCatalogAndExpandedPresets(): void
    {
        $catalog = $this->registry->get('msp1');
        $this->assertNotNull($catalog);
        $data = $this->service->editorData($catalog, 'msp_test');

        $this->assertSame('msp1', $data['page']);
        $this->assertSame('/msp1-test/msp1datas/msp1-data.php', $data['monitoring_url']);
        $this->assertSame(array_column($catalog->blocks(), 'id'), array_column($data['catalog'], 'id'));
        $extended = $data['presets'][1];
        $this->assertSame('etendu', $extended['slug']);
        $this->assertCount(count($catalog->blocks()), $extended['blocks']);
        $this->assertSame('shown', $extended['blocks'][0]['series']['LuminositeA']);
    }
}
