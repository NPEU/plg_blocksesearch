<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Finder.BlocksSearch
 *
 * @copyright   Copyright (C) NPEU 2026.
 * @license     MIT License; see LICENSE.md
 */

namespace NPEU\Plugin\Finder\BlocksSearch\Extension;

\defined('_JEXEC') || die;

use Joomla\CMS\Component\ComponentHelper;

use Joomla\CMS\Event\Finder as FinderEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Finder\Administrator\Indexer\Adapter;
use Joomla\Component\Finder\Administrator\Indexer\Indexer;
use Joomla\Component\Finder\Administrator\Indexer\Result;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\QueryInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

use Joomla\CMS\Log\Log;

Log::addLogger(
    ['text_file' => 'debug-blocksearch.php'],
    Log::ALL,
    ['plg_blockssearch']
);

/**
 * Allows indexing of bespoke Blocks content.
 */
final class BlocksSearch extends Adapter implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $context = 'Blocks';

    protected $extension = 'com_blocks';

    protected $layout = 'page';

    protected $type_title = 'Block Page';

    protected $table = '#__menu';

    protected $autoloadLanguage = true;

    private const MENU_CONTEXT = 'com_menus.item';

    private const MODULE_CONTEXT = 'com_modules.module';

    private const ALLOWED_MODULE_TYPE = 'mod_blockstext';

    public static function getSubscribedEvents(): array
    {
        return array_merge(
            parent::getSubscribedEvents(),
            [
                'onFinderBeforeSave'  => 'onFinderBeforeSave',
                'onFinderAfterSave'   => 'onFinderAfterSave',
                'onFinderAfterDelete' => 'onFinderAfterDelete',
                'onFinderChangeState' => 'onFinderChangeState',
            ]
        );
    }

    protected function setup(): bool
    {
        return ComponentHelper::isEnabled($this->extension);
    }

    public function onFinderBeforeSave(FinderEvent\BeforeSaveEvent $event): void
    {
        $context = $event->getContext();
        $row = $event->getItem();
        $isNew = $event->getIsNew();

        // Keep access tracking for menu items so Finder can hide restricted pages.
        if ($context === self::MENU_CONTEXT && !$isNew) {
            $this->checkItemAccess($row);
        }
    }

    public function onFinderAfterSave(FinderEvent\AfterSaveEvent $event): void
    {
        $context = $event->getContext();
        $row = $event->getItem();
        $isNew = $event->getIsNew();

        if ($context === self::MENU_CONTEXT) {
            if (!$isNew && $this->old_access != $row->access) {
                $this->itemAccessChange($row);
            }

            $this->reindex((int) $row->id);

            return;
        }

        if ($context === self::MODULE_CONTEXT && $this->isEligibleModule($row)) {
            $this->reindexMenusForModule((int) $row->id);
        }
    }

    public function onFinderAfterDelete(FinderEvent\AfterDeleteEvent $event): void
    {
        $context = $event->getContext();
        $row = $event->getItem();

        if ($context === self::MENU_CONTEXT) {
            $this->remove((int) $row->id);

            return;
        }

        if ($context === self::MODULE_CONTEXT && isset($row->id)) {
            $this->reindexMenusForModule((int) $row->id);
        }
    }

    public function onFinderChangeState(FinderEvent\AfterChangeStateEvent $event): void
    {
        $context = $event->getContext();
        $pks = (array) $event->getPks();
        $value = (int) $event->getValue();

        if ($context === self::MENU_CONTEXT) {
            $this->itemStateChange($pks, $value);

            return;
        }

        if ($context === self::MODULE_CONTEXT) {
            foreach ($pks as $pk) {
                $module = $this->loadModuleRow((int) $pk);

                if ($module && $this->isEligibleModule($module)) {
                    $this->reindexMenusForModule((int) $pk);
                }
            }
        }
    }

    protected function index(Result $item): void
    {
        $menuRow = $this->loadMenuRow((int) $item->id);

        if ($menuRow === null) {
            return;
        }

        $params = $this->decodeJson((string) $menuRow->params);
        $moduleIds = $this->extractModuleIds($params);
        $modules = $this->loadEligibleModules($moduleIds);

        if ($modules === []) {
            // Nothing indexable for this page, so remove any stale record.
            $this->remove((int) $menuRow->id);

            return;
        }

        $item->context = 'com_blocks.page';
        $item->title = (string) $menuRow->title;
        $item->summary = (string) $menuRow->title;
        $item->body = $this->buildBodyFromModules($modules, $item, $params);
        $item->state = (int) $menuRow->published;
        #$item->state = (int) $menuRow->state;
        $item->access = (int) $menuRow->access;
        $item->language = (string) $menuRow->language;
        $item->params = new Registry($menuRow->params ?? '{}');
        $item->route = 'index.php?Itemid=' . (int) $menuRow->id;
        #$item->route = 'index.php?option=com_blocks&view=blocks&id=' . (int) $menuRow->id;
        $item->url = $item->route;
        $item->start_date = '2026-05-01 08:00:00';

        // Placeholder for extra Finder metadata if you later need it.
        // $item->addTaxonomy('Type', 'Block Page');
        // $item->addTaxonomy('Language', $item->language);

        $this->indexer->index($item);
    }

    protected function getListQuery($query = null): QueryInterface
    {
        $db = $this->getDatabase();
        $componentId = (int) ComponentHelper::getComponent($this->extension)->id;

        $query = $query instanceof QueryInterface ? $query : $db->getQuery(true);

        $query
            ->select(
                $db->quoteName(
                    [
                        'a.id',
                        'a.title',
                        'a.alias',
                        'a.link',
                        'a.params',
                        'a.published',
                        'a.access',
                        'a.language',
                        'a.menutype',
                        'a.parent_id',
                        'a.component_id',
                    ]
                )
            )
            ->from($db->quoteName('#__menu', 'a'))
            ->where($db->quoteName('a.type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('a.client_id') . ' = 0')
            ->where($db->quoteName('a.component_id') . ' = ' . $componentId);

        return $query;
    }

    private function loadMenuRow(int $id): ?object
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('id') . ' = ' . $id);

        $db->setQuery($query);

        $row = $db->loadObject();

        return $row ?: null;
    }

    private function loadModuleRow(int $id): ?object
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('id') . ' = ' . $id);

        $db->setQuery($query);

        $row = $db->loadObject();

        return $row ?: null;
    }

    private function isEligibleModule(object $module): bool
    {
        if (!isset($module->module) || $module->module !== self::ALLOWED_MODULE_TYPE) {
            return false;
        }

        $content = trim((string) ($module->content ?? ''));

        return $content !== '';
    }

    private function loadEligibleModules(array $moduleIds): array
    {
        $moduleIds = array_values(array_unique(array_filter(array_map('intval', $moduleIds))));

        if ($moduleIds === []) {
            return [];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('id') . ' IN (' . implode(',', $moduleIds) . ')')
            ->where($db->quoteName('module') . ' = ' . $db->quote(self::ALLOWED_MODULE_TYPE))
            ->where($db->quoteName('published') . ' = 1')
            ->where('TRIM(COALESCE(' . $db->quoteName('content') . ', \'\')) <> \'\'');

        $db->setQuery($query);

        return $db->loadObjectList() ?: [];
    }

    private function buildBodyFromModules(array $modules, Result $item, array $menuParams): string
    {
        $parts = [];

        foreach ($modules as $module) {
            $content = trim((string) ($module->content ?? ''));

            if ($content === '') {
                continue;
            }

            // TODO: run any extra content filtering or placeholder replacement here.
            // For example, you might strip module wrapper markup or normalise links.
            $parts[] = $content;
        }

        return implode("\n\n", $parts);
    }

    private function extractModuleIds(array $params): array
    {
        $moduleIds = [];

        $walker = function ($value) use (&$walker, &$moduleIds): void {
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    if (is_string($key) && preg_match('/^block_\d+_id$/', $key)) {
                        $id = (int) $child;

                        if ($id > 0) {
                            $moduleIds[] = $id;
                        }
                    }

                    $walker($child);
                }
            }
        };

        $walker($params);

        return array_values(array_unique(array_filter($moduleIds)));
    }

    private function decodeJson(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function reindexMenusForModule(int $moduleId): void
    {
        foreach ($this->findMenuIdsUsingModule($moduleId) as $menuId) {
            $this->reindex((int) $menuId);
        }
    }

    private function findMenuIdsUsingModule(int $moduleId): array
    {
        $db = $this->getDatabase();
        $componentId = (int) ComponentHelper::getComponent($this->extension)->id;
        $search = '"' . $moduleId . '"';

        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('component_id') . ' = ' . $componentId)
            ->where(
                '(' . implode(' OR ', [
                    $db->quoteName('params') . ' LIKE ' . $db->quote('%"block_1_id":' . $search . '%'),
                    $db->quoteName('params') . ' LIKE ' . $db->quote('%"block_2_id":' . $search . '%'),
                    $db->quoteName('params') . ' LIKE ' . $db->quote('%"block_3_id":' . $search . '%'),
                    $db->quoteName('params') . ' LIKE ' . $db->quote('%"block_4_id":' . $search . '%'),
                ]) . ')'
            );

        $db->setQuery($query);

        return array_map('intval', $db->loadColumn() ?: []);
    }
}
