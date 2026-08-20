<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use Craft;
use craft\base\FsInterface;
use craft\base\LocalFsInterface;
use craft\fs\MissingFs;
use craft\models\Volume;
use craft\services\Config;
use craft\services\Volumes;
use craft\web\View;
use Error;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\reportmanager\models\Settings;
use lindemannrock\reportmanager\presenters\StorageWarningPresentation;
use lindemannrock\reportmanager\services\ExportService;
use lindemannrock\reportmanager\tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

/**
 * @since 5.6.0
 */
final class StorageWarningPresentationTest extends TestCase
{
    private const WARNING = 'This host has an ephemeral filesystem. Files in the effective local storage path may be lost during deployments, restarts, or environment replacement. Select a Craft volume backed by durable remote storage. On Craft Cloud, use a Cloud filesystem.';
    private const UNAVAILABLE = 'The configured export volume is unavailable. Check its volume and filesystem configuration, then try again.';

    private bool $hadEphemeralSetting;
    private mixed $originalEphemeralSetting;
    private Volumes $originalVolumes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadEphemeralSetting = array_key_exists('CRAFT_EPHEMERAL', $_SERVER);
        $this->originalEphemeralSetting = $_SERVER['CRAFT_EPHEMERAL'] ?? null;
        $this->originalVolumes = Craft::$app->getVolumes();
        $_SERVER['CRAFT_EPHEMERAL'] = true;
    }

    protected function tearDown(): void
    {
        Craft::$app->set('volumes', $this->originalVolumes);
        if ($this->hadEphemeralSetting) {
            $_SERVER['CRAFT_EPHEMERAL'] = $this->originalEphemeralSetting;
        } else {
            unset($_SERVER['CRAFT_EPHEMERAL']);
        }
        parent::tearDown();
    }

    public function testDurableCustomPathDoesNotShowWarning(): void
    {
        $_SERVER['CRAFT_EPHEMERAL'] = false;
        $volumes = $this->createMock(Volumes::class);
        $volumes->expects(self::never())->method('getVolumeByUid');
        Craft::$app->set('volumes', $volumes);

        $presentation = StorageWarningPresentation::forSettings($this->localSettings());

        self::assertSame(StorageWarningPresentation::STATE_DURABLE_HOST, $presentation->state);
        self::assertFalse($presentation->shouldShowWarning());
    }

    public function testEphemeralCustomPathShowsWarning(): void
    {
        $presentation = StorageWarningPresentation::forSettings($this->localSettings());

        self::assertSame(StorageWarningPresentation::STATE_LOCAL, $presentation->state);
        self::assertTrue($presentation->shouldShowWarning());
    }

    public function testEphemeralLocalVolumeShowsWarning(): void
    {
        $this->installVolumes($this->volume($this->localFilesystem()));

        $presentation = StorageWarningPresentation::forSettings($this->volumeSettings());

        self::assertSame(StorageWarningPresentation::STATE_LOCAL, $presentation->state);
        self::assertTrue($presentation->shouldShowWarning());
    }

    public function testEphemeralNonLocalVolumeSuppressesWarning(): void
    {
        $this->installVolumes($this->volume($this->nonLocalFilesystem()));

        $presentation = StorageWarningPresentation::forSettings($this->volumeSettings());

        self::assertSame(StorageWarningPresentation::STATE_NON_LOCAL, $presentation->state);
        self::assertFalse($presentation->shouldShowWarning());
    }

    public function testConfigNonLocalVolumeOverrideSuppressesWarningDespiteStoredLocalPath(): void
    {
        $this->installVolumes($this->volume($this->nonLocalFilesystem()));
        $effective = $this->applyConfigOverrides(
            $this->localSettings(),
            ['exportVolumeUid' => 'warning-volume'],
        );

        $presentation = StorageWarningPresentation::forSettings($effective);

        self::assertSame('warning-volume', $effective->exportVolumeUid);
        self::assertFalse($presentation->shouldShowWarning());
    }

    public function testConfigLocalPathOverrideShowsWarningDespiteStoredNonLocalVolume(): void
    {
        $effective = $this->applyConfigOverrides(
            $this->volumeSettings(),
            [
                'exportVolumeUid' => '',
                'exportPath' => '@storage/report-manager/configured-exports',
            ],
        );

        $presentation = StorageWarningPresentation::forSettings($effective);

        self::assertSame('', $effective->exportVolumeUid);
        self::assertSame('@storage/report-manager/configured-exports', $effective->exportPath);
        self::assertTrue($presentation->shouldShowWarning());
    }

    public function testEphemeralMissingVolumeIsUnavailableWithoutLocalWarning(): void
    {
        $this->installVolumes(null);

        $presentation = StorageWarningPresentation::forSettings($this->volumeSettings());

        self::assertSame(StorageWarningPresentation::STATE_UNAVAILABLE, $presentation->state);
        self::assertTrue($presentation->isUnavailable());
        self::assertFalse($presentation->shouldShowWarning());
    }

    public function testEphemeralInvalidLocalVolumeIsUnavailableWithoutLocalWarning(): void
    {
        $webroot = Craft::getAlias('@webroot');
        self::assertIsString($webroot);
        /** @var FsInterface&LocalFsInterface&MockObject $fs */
        $fs = $this->createMockForIntersectionOfInterfaces([FsInterface::class, LocalFsInterface::class]);
        $fs->method('getRootPath')->willReturn($webroot . '/warning-test');
        $this->installVolumes($this->volume($fs));

        $presentation = StorageWarningPresentation::forSettings($this->volumeSettings());

        self::assertSame(StorageWarningPresentation::STATE_UNAVAILABLE, $presentation->state);
        self::assertTrue($presentation->isUnavailable());
        self::assertFalse($presentation->shouldShowWarning());
    }

    public function testEphemeralThrowingFilesystemExceptionIsUnavailableWithoutLocalWarning(): void
    {
        $validationVolume = $this->volume($this->nonLocalFilesystem());
        $throwingVolume = $this->createMock(Volume::class);
        $throwingVolume->method('getFs')->willThrowException(new RuntimeException('warning test exception'));
        $this->installVolumes($validationVolume, $throwingVolume);

        $presentation = StorageWarningPresentation::forSettings($this->volumeSettings());

        self::assertSame(StorageWarningPresentation::STATE_UNAVAILABLE, $presentation->state);
        self::assertTrue($presentation->isUnavailable());
        self::assertFalse($presentation->shouldShowWarning());
    }

    public function testEphemeralThrowingFilesystemErrorIsUnavailableAndNotClassifiedAsDurable(): void
    {
        $validationVolume = $this->volume($this->nonLocalFilesystem());
        $throwingVolume = $this->createMock(Volume::class);
        $throwingVolume->method('getFs')->willReturnCallback(
            static fn(): never => throw new Error('warning test error'),
        );
        $this->installVolumes($validationVolume, $throwingVolume);

        $presentation = StorageWarningPresentation::forSettings($this->volumeSettings());

        self::assertSame(StorageWarningPresentation::STATE_UNAVAILABLE, $presentation->state);
        self::assertTrue($presentation->isUnavailable());
        self::assertFalse($presentation->shouldShowWarning());
    }

    public function testEphemeralMissingFilesystemIsUnavailableAndNotClassifiedAsDurable(): void
    {
        $this->installVolumes($this->volume(new MissingFs(['handle' => 'warning-missing'])));

        $presentation = StorageWarningPresentation::forSettings($this->volumeSettings());

        self::assertSame(StorageWarningPresentation::STATE_UNAVAILABLE, $presentation->state);
        self::assertTrue($presentation->isUnavailable());
        self::assertFalse($presentation->shouldShowWarning());
    }

    public function testDurableHostStillPresentsUnavailableConfiguredVolume(): void
    {
        $_SERVER['CRAFT_EPHEMERAL'] = false;
        $this->installVolumes(null);

        $presentation = StorageWarningPresentation::forSettings($this->volumeSettings());

        self::assertSame(StorageWarningPresentation::STATE_UNAVAILABLE, $presentation->state);
        self::assertTrue($presentation->isUnavailable());
        self::assertFalse($presentation->shouldShowWarning());
    }

    public function testClassificationDoesNotInvokeExportGenerationStreamingOrTemporaryFileStaging(): void
    {
        $exports = $this->createMock(ExportService::class);
        foreach (['createExport', 'createQueuedExport', 'generateExport', 'generateQueuedExport', 'createCombinedExport', 'generateCombinedExport'] as $method) {
            $exports->expects(self::never())->method($method);
        }
        $this->swapPluginComponent('report-manager', 'exports', $exports);

        $parent = $this->createTrackedTempDirectory('report-storage-warning-');
        $prospectiveDirectory = $parent . '/must-not-exist';
        $fs = $this->nonLocalFilesystem();
        $this->expectNoStorageIo($fs);
        $this->installVolumes($this->volume($fs));
        $settings = $this->volumeSettings();
        $settings->exportPath = $prospectiveDirectory;

        $presentation = StorageWarningPresentation::forSettings($settings);

        self::assertSame(StorageWarningPresentation::STATE_NON_LOCAL, $presentation->state);
        self::assertDirectoryDoesNotExist($prospectiveDirectory);
    }

    public function testClassificationDoesNotMutateEffectiveSettings(): void
    {
        $settings = $this->volumeSettings();
        $before = $settings->getAttributes();
        $this->installVolumes($this->volume($this->nonLocalFilesystem()));

        StorageWarningPresentation::forSettings($settings);

        self::assertSame($before, $settings->getAttributes());
    }

    public function testWarningRendersExactPluginOwnedMessageAfterLocationAndBeforeCsvSettings(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/export.twig');
        $location = strpos($template, 'Export Location:');
        $warning = strpos($template, self::WARNING);
        $csv = strpos($template, 'CSV Settings');

        self::assertIsInt($location);
        self::assertIsInt($warning);
        self::assertIsInt($csv);
        self::assertTrue($location < $warning && $warning < $csv);
        self::assertStringContainsString("|t('report-manager')", substr($template, $warning, strlen(self::WARNING) + 40));

        $html = Craft::$app->getView()->renderTemplate(
            'lindemannrock-base/_components/info-box',
            [
                'message' => Craft::t('report-manager', self::WARNING),
                'type' => 'warning',
                'variant' => 'colored',
                'allowHtml' => false,
            ],
            View::TEMPLATE_MODE_CP,
        );
        self::assertStringContainsString(self::WARNING, $html);
        self::assertStringContainsString('lr-info-box--colored', $html);

        foreach (['en', 'de', 'fr', 'nl', 'es', 'ar', 'it', 'pt', 'ja', 'sv', 'da', 'no'] as $locale) {
            $catalogue = require dirname(__DIR__, 2) . "/src/translations/{$locale}/report-manager.php";
            self::assertArrayHasKey(self::WARNING, $catalogue);
            self::assertArrayHasKey(self::UNAVAILABLE, $catalogue);
        }
    }

    public function testUnavailableErrorIsSeparateFromTheCloudWarning(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/export.twig');
        $warning = strpos($template, self::WARNING);
        $unavailable = strpos($template, self::UNAVAILABLE);

        self::assertIsInt($warning);
        self::assertIsInt($unavailable);
        self::assertNotSame($warning, $unavailable);
        self::assertStringContainsString('storageWarning.shouldShowWarning', $template);
        self::assertStringContainsString('storageWarning.isUnavailable', $template);
    }

    public function testExportTemplatesSurfaceUnavailableStorageWithoutCallingItAMissingFile(): void
    {
        $indexTemplate = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/exports/index.twig');
        $viewTemplate = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/exports/view.twig');

        self::assertStringContainsString('{% block beforeTable %}', $indexTemplate);
        self::assertStringContainsString('not storageError', $indexTemplate);
        self::assertStringContainsString('{% if storageError %}', $viewTemplate);
    }

    private function localSettings(): Settings
    {
        return new Settings([
            'exportVolumeUid' => '',
            'exportPath' => '@storage/report-manager/exports',
        ]);
    }

    private function volumeSettings(): Settings
    {
        return new Settings([
            'exportVolumeUid' => 'warning-volume',
            'exportPath' => '@storage/report-manager/stored-local-exports',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function applyConfigOverrides(Settings $settings, array $overrides): Settings
    {
        $config = $this->createMock(Config::class);
        $config->method('getConfigFromFile')->willReturnCallback(
            static fn(string $handle): array => $handle === 'report-manager' ? $overrides : [],
        );
        Craft::$app->set('config', $config);
        PluginHelper::applyConfigOverridesToSettings($settings, 'report-manager');

        return $settings;
    }

    private function localFilesystem(): FsInterface & LocalFsInterface
    {
        /** @var FsInterface&LocalFsInterface&MockObject $fs */
        $fs = $this->createMockForIntersectionOfInterfaces([FsInterface::class, LocalFsInterface::class]);
        $fs->method('getRootPath')->willReturn($this->createTrackedTempDirectory('report-local-volume-'));
        return $fs;
    }

    private function nonLocalFilesystem(): FsInterface & MockObject
    {
        return $this->createMock(FsInterface::class);
    }

    private function volume(FsInterface $fs): Volume
    {
        $volume = $this->createMock(Volume::class);
        $volume->method('getFs')->willReturn($fs);
        return $volume;
    }

    private function installVolumes(?Volume ...$sequence): void
    {
        $volumes = $this->createMock(Volumes::class);
        $index = 0;
        $volumes->method('getVolumeByUid')->willReturnCallback(
            static function(string $uid) use (&$index, $sequence): ?Volume {
                $position = min($index, count($sequence) - 1);
                $index++;
                return $sequence[$position];
            },
        );
        Craft::$app->set('volumes', $volumes);
    }

    private function expectNoStorageIo(FsInterface & MockObject $fs): void
    {
        foreach ([
            'getFileList',
            'getFileSize',
            'getDateModified',
            'write',
            'read',
            'writeFileFromStream',
            'fileExists',
            'deleteFile',
            'renameFile',
            'copyFile',
            'getFileStream',
            'directoryExists',
            'createDirectory',
            'deleteDirectory',
            'renameDirectory',
        ] as $method) {
            $fs->expects(self::never())->method($method);
        }
    }
}
