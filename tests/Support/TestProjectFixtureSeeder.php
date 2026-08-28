<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Support;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\Formie;

/**
 * Seeds the minimal deterministic Craft and Formie topology used by the suite.
 *
 * @since 5.6.0
 */
final readonly class TestProjectFixtureSeeder
{
    // Two reads of this set preserve the accepted 3,395-assertion floor while
    // exercising Formie's second and subsequent 100-row windows with real data.
    private const FORMIE_SUBMISSIONS = 587;

    public function __construct(TestProjectBoundary $boundary)
    {
        if (!$boundary->disposable) {
            throw new \LogicException('The deterministic fixture seeder may run only inside a disposable project.');
        }
    }

    /** @return array<string, int|list<int>> */
    public function seed(): array
    {
        $sites = $this->seedSites();
        [$section, $entryType] = $this->seedSection($sites);
        $entry = $this->seedEntry($sites[0], $section, $entryType);
        $group = $this->seedCategoryGroup($sites);
        $form = $this->seedFormie($sites[0]);

        return [
            'siteIds' => array_map(static fn(Site $site): int => (int)$site->id, $sites),
            'sectionId' => (int)$section->id,
            'entryId' => (int)$entry->id,
            'categoryGroupId' => (int)$group->id,
            'formId' => (int)$form->id,
            'formieSubmissions' => self::FORMIE_SUBMISSIONS,
        ];
    }

    /** @return list<Site> */
    private function seedSites(): array
    {
        $sites = Craft::$app->getSites();
        $primary = $sites->getPrimarySite();
        $primary->name = 'Report Primary';
        $primary->handle = 'reportPrimary';
        $primary->language = 'en-US';
        $primary->baseUrl = 'https://report-primary.example.test';
        $this->save($sites->saveSite($primary), $primary, 'primary site');

        $result = [$primary];
        foreach ([
            ['name' => 'Report Secondary', 'handle' => 'reportSecondary', 'language' => 'de-DE', 'baseUrl' => 'https://report-secondary.example.test'],
            ['name' => 'Report Tertiary', 'handle' => 'reportTertiary', 'language' => 'fr-FR', 'baseUrl' => 'https://report-tertiary.example.test'],
        ] as $definition) {
            $site = $sites->getSiteByHandle($definition['handle']);
            if ($site === null) {
                $site = new Site([
                    ...$definition,
                    'groupId' => $primary->groupId,
                    'primary' => false,
                    'enabled' => true,
                ]);
                $this->save($sites->saveSite($site), $site, "site {$definition['handle']}");
            }
            $result[] = $site;
        }
        if (count($sites->getAllSites()) !== 3) {
            throw new \RuntimeException('Report Manager fixture must contain exactly three sites.');
        }

        return $result;
    }

    /** @param list<Site> $sites @return array{Section, EntryType} */
    private function seedSection(array $sites): array
    {
        $entries = Craft::$app->getEntries();
        $entryType = new EntryType([
            'name' => 'Report Fixture Entry',
            'handle' => 'reportFixtureEntry',
            'hasTitleField' => true,
        ]);
        $layout = new FieldLayout(['type' => Entry::class]);
        $tab = new FieldLayoutTab(['name' => 'Content']);
        $tab->setLayout($layout);
        $tab->setElements([new EntryTitleField(['required' => true])]);
        $layout->setTabs([$tab]);
        $entryType->setFieldLayout($layout);
        $this->save($entries->saveEntryType($entryType), $entryType, 'entry type');

        $siteSettings = [];
        foreach ($sites as $site) {
            $siteSettings[] = new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => false,
            ]);
        }
        $section = new Section([
            'name' => 'Report Fixture Entries',
            'handle' => 'reportFixtureEntries',
            'type' => Section::TYPE_CHANNEL,
            'propagationMethod' => PropagationMethod::All,
        ]);
        $section->setEntryTypes([$entryType]);
        $section->setSiteSettings($siteSettings);
        $this->save($entries->saveSection($section), $section, 'entry section');

        return [$section, $entryType];
    }

    private function seedEntry(Site $site, Section $section, EntryType $entryType): Entry
    {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $entryType->id,
            'siteId' => $site->id,
            'enabled' => true,
            'enabledForSite' => true,
            'title' => 'Report Fixture Entry',
            'slug' => 'report-fixture-entry',
        ]);
        $this->save(
            Craft::$app->getElements()->saveElement($entry, runValidation: false, updateSearchIndex: false),
            $entry,
            'entry',
        );

        return $entry;
    }

    /** @param list<Site> $sites */
    private function seedCategoryGroup(array $sites): CategoryGroup
    {
        $settings = [];
        foreach ($sites as $site) {
            $settings[] = new CategoryGroup_SiteSettings([
                'siteId' => $site->id,
                'hasUrls' => false,
            ]);
        }
        $group = new CategoryGroup([
            'name' => 'Report Fixture Categories',
            'handle' => 'reportFixtureCategories',
            'maxLevels' => 1,
        ]);
        $group->setSiteSettings($settings);
        $this->save(Craft::$app->getCategories()->saveGroup($group), $group, 'category group');

        $category = new Category([
            'groupId' => $group->id,
            'siteId' => $sites[0]->id,
            'enabled' => true,
            'enabledForSite' => true,
            'title' => 'Report Fixture Category',
            'slug' => 'report-fixture-category',
        ]);
        $this->save(
            Craft::$app->getElements()->saveElement($category, runValidation: false, updateSearchIndex: false),
            $category,
            'category',
        );

        return $group;
    }

    private function seedFormie(Site $site): Form
    {
        if (!class_exists(Form::class) || Formie::$plugin === null) {
            throw new \RuntimeException('The disposable project must install and bootstrap Formie.');
        }
        $status = Formie::$plugin->getStatuses()->getDefaultStatus();
        if ($status === null) {
            throw new \RuntimeException('Formie did not install its default submission status.');
        }
        $form = new Form([
            'title' => 'Report Fixture Form',
            'handle' => 'reportFixtureForm',
            'defaultStatusId' => $status->id,
        ]);
        $this->save(Craft::$app->getElements()->saveElement($form, updateSearchIndex: false), $form, 'Formie form');

        for ($index = 0; $index < self::FORMIE_SUBMISSIONS; $index++) {
            $submission = new Submission([
                'formId' => $form->id,
                'statusId' => $status->id,
                'siteId' => $site->id,
                'title' => sprintf('Report Fixture Submission %03d', $index),
                'isIncomplete' => false,
                'isSpam' => false,
                'isNewSubmission' => true,
            ]);
            $this->save(
                Craft::$app->getElements()->saveElement($submission, runValidation: false, updateSearchIndex: false),
                $submission,
                "Formie submission {$index}",
            );
        }

        return $form;
    }

    private function save(bool $success, object $model, string $label): void
    {
        if ($success) {
            return;
        }
        $errors = method_exists($model, 'getErrorSummary') ? $model->getErrorSummary(true) : [];
        throw new \RuntimeException("Unable to save {$label}: " . implode('; ', $errors));
    }
}
