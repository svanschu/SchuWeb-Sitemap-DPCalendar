<?php
/**
 * @package     SchuWeb Sitemap
 *
 * @version     sw.build.version
 * @author      Sven Schultschik
 * @copyright   (C) 2022 - 2023 Sven Schultschik. All rights reserved
 * @license     https://www.gnu.org/licenses/gpl-3.0.html GNU/GPLv3
 * @link        https://extensions.schultschik.de
 **/

defined('_JEXEC') or die();

use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Router\Route;
use Joomla\Utilities\ArrayHelper;

class schuweb_sitemap_dpcalendar
{

    public static function prepareMenuItem(&$node, &$params)
    {
        $link_query = parse_url($node->link);
        if (!isset($link_query['query'])) {
            return;
        }

        parse_str(html_entity_decode($link_query['query']), $link_vars);
        $view = ArrayHelper::getValue($link_vars, 'view', '');
        $id = ArrayHelper::getValue($link_vars, 'id', 0);
        BaseDatabaseModel::addIncludePath(JPATH_SITE . '/components/com_dpcalendar/models', 'DPCalendarModel');

        switch ($view) {
            case 'calendar':
                if ($id) {
                    $node->uid = 'com_dpcalendar' . $id;
                } else {
                    $node->uid = 'com_dpcalendar';
                }
                $node->expandible = true;
                break;
            case 'event':
                if (!JLoader::import('components.com_dpcalendar.helpers.dpcalendar', JPATH_ADMINISTRATOR)) {
                    return;
                }
                $node->uid = 'com_dpcalendar' . $id;
                $node->expandible = false;
                $model_cal = Factory::getApplication()->bootComponent('com_dpcalendar')
                    ->getMVCFactory()->createModel('Event', 'DPCalendarModel');

                $eid = intval($id);
                $row = $model_cal->getItem($eid);
                if ($row != null) {
                    $node->modified = $row->modified;
                }
                break;
            case 'list':
                $node->expandible = true;
        }
    }

    public static function getTree(&$sitemap, &$parent, &$params)
    {
        // An image sitemap does not make sense, hence those are community postings
        // don't waste time/resources
        if ($sitemap->isImagesitemap())
            return false;

        $db = Factory::getDBO();
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $result = null;

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
        $expand_calendars = ArrayHelper::getValue($params, 'expand_calendars', 1);
        $expand_calendars = ($expand_calendars == 1 || ($expand_calendars == 2 && $sitemap->isXmlsitemap()) ||
            ($expand_calendars == 3 && !$sitemap->isXmlsitemap()));
        $params['expand_calendars'] = $expand_calendars;

        // ----- Set calendar_priority and calendar_changefreq params
        $priority = ArrayHelper::getValue($params, 'calendar_priority', $parent->priority);
        $changefreq = ArrayHelper::getValue($params, 'calendar_changefreq', $parent->changefreq);
        if ($priority == '-1') {
            $priority = $parent->priority;
        }
        if ($changefreq == '-1') {
            $changefreq = $parent->changefreq;
        }

        $params['calendar_priority'] = $priority;
        $params['calendar_changefreq'] = $changefreq;

        $params['nullDate'] = $db->quote($db->getNullDate());

        $params['nowDate'] = $db->quote(Factory::getDate()->toSql());
        $params['groups'] = implode(',', $user->getAuthorisedViewLevels());

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
     * @param   SchuWeb\Component\Sitemap\Administrator\Model\SitemapModel  $sitemap
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

        $menuitem = $app->getMenu('site')->getItem($itemid);
        $calendar_ids = $menuitem->getParams()->get('ids');

        if (!in_array("-1", $calendar_ids))
            foreach ($items as $k => $item) {
                if (!in_array($item->id, $calendar_ids))
                    unset($items[$k]);
            }

        if ($items && count($items) > 0) {
            foreach ($items as $item) {
                $node = new stdclass();
                $node->id = $parent->id;
                $id = $node->uid = $parent->uid . 'c' . $item->id;
                $node->browserNav = $parent->browserNav;
                $node->priority = $params['calendar_priority'];
                $node->changefreq = $params['calendar_changefreq'];

                $node->xmlInsertChangeFreq = $parent->xmlInsertChangeFreq;
                $node->xmlInsertPriority = $parent->xmlInsertPriority;

                $node->name = $item->title;
                $node->expandible = true;
                $node->secure = $parent->secure;
                $node->lastmod = $parent->lastmod;
                $node->newsItem = 0;

                $item->modified = $item->modified_time;

                if ($sitemap->isNewssitemap()) {
                    $item->modified = $item->created_time;
                } 
                
                $node->slug = $item->id;
                $node->link = Route::_(
                    'index.php?option=com_dpcalendar&view=calendar&Itemid=' . $node->id,
                    true,
                    Route::TLS_IGNORE,
                    $sitemap->isXmlsitemap()
                );
                if (strpos($node->link, 'Itemid=') === false) {
                    $node->itemid = $itemid;
                    $node->link .= '&Itemid=' . $itemid;
                } else {
                    $node->itemid = preg_replace('/.*Itemid=([0-9]+).*/', '$1', $node->link);
                }

                if (!isset($parent->subnodes))
                    $parent->subnodes = new \stdClass();

                $node->params = &$parent->params;

                $parent->subnodes->$id = $node;

                // Include Calendar's content
                self::includeCalendarContent($sitemap, $parent->subnodes->$id, $item->id, $params, $itemid);

                self::expandCalendar($sitemap, $parent->subnodes->$id, $item->id, $params, $node->itemid);
            }

        }

        return true;
    }

    public static function includeCalendarContent(&$sitemap, &$parent, $caid, &$params, $Itemid)
    {
        BaseDatabaseModel::addIncludePath(JPATH_SITE . '/components/com_dpcalendar/models', 'DPCalendarModel');
        if (!JLoader::import('components.com_dpcalendar.helpers.dpcalendar', JPATH_ADMINISTRATOR)) {
            return;
        }

        $app = Factory::getApplication();
        $model_cal = $app->bootComponent('com_dpcalendar')
            ->getMVCFactory()->createModel('Events', 'DPCalendarModel');

        $app->input->set('ids', $caid);
        $items = $model_cal->getItems();
        if (count($items) > 0) {
            foreach ($items as $item) {
                $node = new stdclass();
                $node->id = $parent->id;
                $id = $node->uid = $parent->uid . 'a' . $item->id;
                $node->browserNav = $parent->browserNav;
                $node->priority = $params['event_priority'];
                $node->changefreq = $params['event_changefreq'];

                $node->xmlInsertChangeFreq = $parent->xmlInsertChangeFreq;
                $node->xmlInsertPriority = $parent->xmlInsertPriority;

                $node->name = $item->title;
                $node->modified = $item->modified;
                $node->expandible = false;
                $node->secure = $parent->secure;
                $node->newsItem = 1;
                $node->language = $item->language;
                $node->lastmod = $parent->lastmod;

                if ($sitemap->isNewssitemap()) {
                    $node->modified = $item->created;
                }

                $node->slug = $item->alias ? ($item->id . ':' . $item->alias) : $item->id;
                $node->catslug = $item->catid;
                $node->link = Route::_(
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

            $subnode->xmlInsertChangeFreq = $parent->xmlInsertChangeFreq;
            $subnode->xmlInsertPriority = $parent->xmlInsertPriority;

            $subnode->secure = $parent->secure;
            $subnode->lastmod = $parent->lastmod;

            if (!isset($parent->subnodes))
                $parent->subnodes = new \stdClass();

            $parent->subnodes->$id = $subnode;
        }
    }
}
