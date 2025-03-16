<?php
/**
 * @version     sw.build.version
 * @copyright   Copyright (C) 2019 - 2025 Sven Schultschik. All rights reserved
 * @license     GPL-3.0-or-later
 * @author      Sven Schultschik (extensions@schultschik.de)
 * @link        extensions.schultschik.de
 */

namespace SchuWeb\Plugin\SchuWeb_Sitemap\DPCalendar\Extension;

\defined('_JEXEC') or die();

use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\Utilities\ArrayHelper;
use Joomla\Registry\Registry;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use SchuWeb\Component\Sitemap\Site\Event\MenuItemPrepareEvent;
use SchuWeb\Component\Sitemap\Site\Event\TreePrepareEvent;
use DigitalPeak\Component\DPCalendar\Site\Model\EventsModel;
use DigitalPeak\Component\DPCalendar\Site\Model\EventModel;

class DPCalendar extends CMSPlugin implements SubscriberInterface
{
    /**
     * @since 5.2.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onGetMenus' => 'onGetMenus',
            'onGetTree'  => 'onGetTree',
        ];
    }

        /**
     * This function is called before a menu item is printed. We use it to set the
     * proper uniqueid for the item
     *
     * @param   MenuItemPrepareEvent  Event object
     *
     * @return void
     * @since  5.2.0
     */
    public function onGetMenus(MenuItemPrepareEvent $event)
    {
        $menu_item = $event->getMenuItem();

        $link_query = parse_url($menu_item->link);
        if (!isset($link_query['query'])) {
            return;
        }

        parse_str(html_entity_decode($link_query['query']), $link_vars);
        $view = ArrayHelper::getValue($link_vars, 'view', '');
        $id   = ArrayHelper::getValue($link_vars, 'id', 0);

        switch ($view) {
            case 'calendar':
                $menu_item->uid = $id ? "com_dpcalendar{$id}" : "com_dpcalendar";
                $menu_item->expandible = true;
                break;
            case 'event':
                $menu_item->uid = "com_dpcalendar{$id}";
                $menu_item->expandible = false;
                
                $component = Factory::getApplication()->bootComponent('dpcalendar');
                /** @var EventModel $model */
                $model = $component->getMVCFactory()->createModel('Event', 'Site');

                $eid = intval($id);
                $row = $model->getItem($eid);
                if ($row != null) {
                    $menu_item->modified = $row->modified;
                }
                break;
            case 'list':
                $menu_item->expandible = true;
        }
    }

        /**
     * Expands a com_content menu item
     *
     * @param   TreePrepareEvent  Event object
     *
     * @return void
     * @since  5.2.0
     */
    public function onGetTree(TreePrepareEvent $event)
    {
        $sitemap = $event->getSitemap();
        $parent  = $event->getNode();

        if ($parent->option != "com_dpcalendar")
            return null;

        // An image sitemap does not make sense, hence those are community postings
        // don't waste time/resources
        if ($sitemap->isImagesitemap())
            return null;

        /** @var DatabaseDriver $db */
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $result = null;

        if (is_null($user))
            $groups = [0 => 1];
        else
            $groups = $user->getAuthorisedViewLevels();

        $link_query = parse_url($parent->link);
        if (!isset($link_query['query'])) {
            return;
        }

        parse_str(html_entity_decode($link_query['query']), $link_vars);
        $view = ArrayHelper::getValue($link_vars, 'view', '');
        $id = intval(ArrayHelper::getValue($link_vars, 'id', ''));

        /*
         * * Parameters Initialitation
         */
        // ----- Set expand_calendars param
        $expand_calendars = $this->params->get('expand_calendars', 1);
        $expand_calendars = ($expand_calendars == 1 || ($expand_calendars == 2 && $sitemap->isXmlsitemap()) ||
            ($expand_calendars == 3 && !$sitemap->isXmlsitemap()));
        $params['expand_calendars'] = $expand_calendars;

        $priority = $this->params->get( 'calendar_priority', $parent->priority);
        $changefreq = $this->params->get( 'calendar_changefreq', $parent->changefreq);

        if ($priority == '-1') {
            $priority = $parent->priority;
        }
        if ($changefreq == '-1') {
            $changefreq = $parent->changefreq;
        }

        $params['calendar_priority'] = $priority;
        $params['calendar_changefreq'] = $changefreq;

        $event_priority = $this->params->get( 'event_priority', $parent->priority);
        $event_changefreq = $this->params->get( 'event_changefreq', $parent->changefreq);

        if ($event_priority == '-1') {
            $event_priority = $parent->priority;
        }
        if ($event_changefreq == '-1') {
            $event_changefreq = $parent->changefreq;
        }

        $params['event_priority'] = $event_priority;
        $params['event_changefreq'] = $event_changefreq;

        $params['nullDate'] = $db->quote($db->getNullDate());

        $params['nowDate'] = $db->quote(Factory::getDate()->toSql());
        $params['groups'] = implode(',', $groups);

        // Define the language filter condition for the query
        $params['language_filter'] = $sitemap->isLanguageFilter();

        switch ($view) {
            case 'calendar':
            case 'list':
                if ($params['expand_calendars']) {
                    $result = self::expandCalendar($sitemap, $parent, ($id ? $id : 1), $params, $parent->id);
                }
                break;
        }
        return $result;
    }

    /**
     * Get all content items within a calendar.
     *
     * @param   \SchuWeb\Component\Sitemap\Site\Model\SitemapModel  $sitemap
     * @param   \stdClass   $parent  the menu item
     * @param   int         $catid   the id of the category to be expanded
     * @param   mixed[]     $params  an assoc array with the params for this plugin on Xmap
     * @param   int         $itemid  the itemid to use for this category's children
     */
    public static function expandCalendar(&$sitemap, &$parent, $caid, &$params, $itemid)
    {
        $options = [];
        $options['countItems'] = 20;
        $app = Factory::getApplication();
        $component = $app->bootComponent('DPCalendar');
        if ($component instanceof CategoryServiceInterface) {
            $categories = $component->getCategory($options);
        }
        $cc = ($caid == 1) ? 'root' : $caid;
        if (isset($categories))
            $tparent = $categories->get($cc);
        if (is_object($tparent)) {
            $items = $tparent->getChildren(false);
        } else {
            $items = false;
        }

        $menuitemparams = null;
        if ($app instanceof Joomla\CMS\Application\ConsoleApplication) {
            
            /** @var DatabaseDriver $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            
            $query = $db->getQuery(true);
            $query->select($db->qn('params'))
                ->from($db->qn('#__menu'))
                ->where($db->qn('id') . '=' . $db->q($itemid));
            $db->setQuery($query);
            $menuparams = $db->loadResult();
            if (is_null($menuparams))
                throw new \RuntimeException("Menuprams of DPCalendar menu item not found in database");
            $menuitemparams = new Registry($menuparams);
        } else {
            $menuitem       = $app->getMenu('site')->getItem($itemid);
            $menuitemparams = $menuitem->getParams();
        }

        if (is_null($menuitemparams))
            throw new \RuntimeException("Menuprams of DPCalendar menu item not found");

        $calendar_ids = $menuitemparams->get('ids');

        if (!in_array("-1", $calendar_ids))
            foreach ($items as $k => $item) {
                if (!in_array($item->id, $calendar_ids))
                    unset($items[$k]);
            }

        if ($items && count($items) > 0) {
            foreach ($items as $item) {
                $node = new \stdClass;
                $node->id = $parent->id;
                $id = $node->uid = $parent->uid . 'c' . $item->id;
                $node->browserNav = $parent->browserNav;
                $node->priority = $params['calendar_priority'];
                $node->changefreq = $params['calendar_changefreq'];

                $node->name = $item->title;
                $node->expandible = true;
                $node->secure = $parent->secure;
                $node->newsItem = 0;

                $item->modified = $item->modified_time;

                if ($sitemap->isNewssitemap()) {
                    $item->modified = $item->created_time;
                } 
                
                $node->slug = $item->id;
                $node->link = Route::link('site',
                    'index.php?option=com_dpcalendar&view=calendar&Itemid=' . $node->id,
                    true,
                    Route::TLS_IGNORE,
                    $sitemap->isXmlsitemap()
                );

                if (!isset($parent->subnodes))
                    $parent->subnodes = new \stdClass();

                $node->params = &$parent->params;

                $parent->subnodes->$id = $node;

                // Include Calendar's content
                self::includeCalendarContent($sitemap, $parent->subnodes->$id, $item->id, $params, $itemid);

                self::expandCalendar($sitemap, $parent->subnodes->$id, $item->id, $params, $node->id);
            }

        }

        return true;
    }

    public static function includeCalendarContent(&$sitemap, &$parent, $caid, &$params, $Itemid)
    {
        $app = Factory::getApplication();

        $component = $app->bootComponent('dpcalendar');
        /** @var EventsModel $model */
        $model = $component->getMVCFactory()->createModel('Events', 'Site', ['ignore_request' => true]);

        $model->setState('category.id', $caid);
        $items = $model->getItems();
        
        if (count($items) > 0) {
            foreach ($items as $item) {
                $node = new \stdClass;
                $node->id = $parent->id;
                $id = $node->uid = $parent->uid . 'a' . $item->id;
                $node->browserNav = $parent->browserNav;
                $node->priority = $params['event_priority'];
                $node->changefreq = $params['event_changefreq'];

                $node->name = $item->title;
                $node->modified = $item->modified;
                $node->expandible = false;
                $node->secure = $parent->secure;
                $node->newsItem = 1;
                $node->language = $item->language;

                if ($sitemap->isNewssitemap()) {
                    $node->modified = $item->created;
                }

                $node->slug = $item->alias ? ($item->id . ':' . $item->alias) : $item->id;
                $node->catslug = $item->catid;
                $node->link = Route::link('site',
                    'index.php?option=com_dpcalendar&view=event&id=' . $item->id . '&Itemid=' . $node->id,
                    true,
                    Route::TLS_IGNORE,
                    $sitemap->isXmlsitemap()
                );

                if (!isset($parent->subnodes))
                    $parent->subnodes = new \stdClass();

                $parent->subnodes->$id = $node;

                if ($node->expandible) {
                    self::printNodes($sitemap, $parent->subnodes->$id, $params, $subnodes);
                }
            }
        }
        return true;
    }

    private static function printNodes(&$sitemap, &$parent, &$params, &$subnodes)
    {
        $i = 0;
        foreach ($subnodes as $subnode) {
            $i++;
            $subnode->id = $parent->id;
            $id = $subnode->uid = $parent->uid . 'p' . $i;
            $subnode->browserNav = $parent->browserNav;
            $subnode->priority = $params['event_priority'];
            $subnode->changefreq = $params['event_changefreq'];

            $subnode->secure = $parent->secure;

            if (!isset($parent->subnodes))
                $parent->subnodes = new \stdClass();

            $parent->subnodes->$id = $subnode;
        }
    }
}
