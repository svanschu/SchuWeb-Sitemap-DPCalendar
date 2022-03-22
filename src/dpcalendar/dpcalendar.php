<?php
/**
 * @package SchuWeb Sitemap
 *
 * @copyright (C) 2022 Sven Schultschik. All rights reserved
 * @license http://www.gnu.org/licenses/gpl-3.0.html GNU/GPLv3
 * @link https://extensions.schultschik.de
 **/

defined('_JEXEC') or die();

use Joomla\Utilities\ArrayHelper;

class schuweb_sitemap_dpcalendar
{

	public static function prepareMenuItem($node, &$params)
	{
		$link_query = parse_url($node->link);
		if (! isset($link_query['query'])) {
			return;
		}

		parse_str(html_entity_decode($link_query['query']), $link_vars);
		$view = ArrayHelper::getValue($link_vars, 'view', '');
		$id = ArrayHelper::getValue($link_vars, 'id', 0);
		JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_dpcalendar/models', 'DPCalendarModel');
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
				$node->uid = 'com_dpcalendar' . $id;
				$node->expandible = false;
				$model_cal = JModelLegacy::getInstance('Event', 'DPCalendarModel');

				$eid = intval($id);
				$row = $model_cal->getItem($eid);
				if ($row != null) {
					$node->modified = $row->modified;
					$text = @$item->description;
				}
				break;
		}
	}

	public static function getTree($xmap, $parent, &$params)
	{
		$db = JFactory::getDBO();
		$app = JFactory::getApplication();
		$user = JFactory::getUser();
		$result = null;

		$link_query = parse_url($parent->link);
		if (! isset($link_query['query'])) {
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
		$expand_calendars = ($expand_calendars == 1 || ($expand_calendars == 2 && $xmap->view == 'xml') ||
				 ($expand_calendars == 3 && $xmap->view == 'html') || $xmap->view == 'navigator');
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

		$params['nowDate'] = $db->quote(JFactory::getDate()->toSql());
		$params['groups'] = implode(',', $user->getAuthorisedViewLevels());

		// Define the language filter condition for the query
		$params['language_filter'] = $app->getLanguageFilter();

		switch ($view) {
			case 'calendar':
				if ($params['expand_calendars']) {
					$result = self::expandCalendar($xmap, $parent, ($id ? $id : 1), $params, $parent->id);
				}
				break;
		}
		return $result;
	}

	public static function expandCalendar($xmap, $parent, $caid, &$params, $itemid)
	{
		jimport('joomla.application.categories');
		$options = [];
		$options['countItems'] = 20;
		$categories = JCategories::getInstance('DPCalendar', $options);
		$cc = ($caid == 1) ? 'root' : $caid;
		$tparent = $categories->get($cc);
		if (is_object($tparent)) {
			$items = $tparent->getChildren(false);
		} else {
			$items = false;
		}

		if ($items && count($items) > 0) {
			$xmap->changeLevel(1);
			for ($i = 0; $i < count($items); $i ++) {
				$item = $items[$i];
				$node = new stdclass();
				$node->id = $parent->id;
				$node->uid = $parent->uid . 'c' . $item->id;
				$node->browserNav = $parent->browserNav;
				$node->priority = $params['calendar_priority'];
				$node->changefreq = $params['calendar_changefreq'];
				$node->name = $item->title;
				$node->expandible = true;
				$node->secure = $parent->secure;
				$node->newsItem = 0;

				if ($xmap->isNews || ! $item->modified_time) {
					$item->modified = $item->created_time;
				} else {
					$item->modified = $item->modified_time;
				}

				$node->slug = $item->id;
				$node->link = JRoute::_('index.php?option=com_dpcalendar&view=calendar&Itemid=' . $node->id);
				if (strpos($node->link, 'Itemid=') === false) {
					$node->itemid = $itemid;
					$node->link .= '&Itemid=' . $itemid;
				} else {
					$node->itemid = preg_replace('/.*Itemid=([0-9]+).*/', '$1', $node->link);
				}
				if ($xmap->printNode($node)) {
					self::expandCalendar($xmap, $parent, $item->id, $params, $node->itemid);
				}
			}

			$xmap->changeLevel(- 1);
		}

		// Include Calendar's content
		self::includeCalendarContent($xmap, $parent, $caid, $params, $itemid);
		return true;
	}

	public static function includeCalendarContent($xmap, $parent, $caid, &$params, $Itemid)
	{
		$db = JFactory::getDBO();

		// We do not do ordering for XML sitemap.
		if ($xmap->view != 'xml') {
			$orderby = self::buildEventOrderBy($parent->params, $parent->id, $Itemid);
		}
		JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_dpcalendar/models', 'DPCalendarModel');
		$model_cal = JModelLegacy::getInstance('Events', 'DPCalendarModel');
		$model_cal->setState('category.id', $caid);
		$items = $model_cal->getItems();
		if (count($items) > 0) {
			$xmap->changeLevel(1);
			foreach ($items as $item) {
				$node = new stdclass();
				$node->id = $parent->id;
				$node->uid = $parent->uid . 'a' . $item->id;
				$node->browserNav = $parent->browserNav;
				$node->priority = $params['event_priority'];
				$node->changefreq = $params['event_changefreq'];
				$node->name = $item->title;
				$node->modified = $item->modified;
				$node->expandible = false;
				$node->secure = $parent->secure;
				$node->newsItem = 1;
				$node->language = $item->language;

				if ($xmap->isNews || ! $node->modified) {
					$node->modified = $item->created;
				}

				$node->slug = $item->alias ? ($item->id . ':' . $item->alias) : $item->id;
				$node->catslug = $item->catid;
				$node->link = JRoute::_('index.php?option=com_dpcalendar&view=event&id=' . $item->id . '&Itemid=' . $node->id);

				// $text = @$item->description;

				if ($xmap->printNode($node) && $node->expandible) {
					self::printNodes($xmap, $parent, $params, $subnodes);
				}
			}
			$xmap->changeLevel(- 1);
		}
		return true;
	}

	private static function printNodes($xmap, $parent, &$params, &$subnodes)
	{
		$xmap->changeLevel(1);
		$i = 0;
		foreach ($subnodes as $subnode) {
			$i ++;
			$subnode->id = $parent->id;
			$subnode->uid = $parent->uid . 'p' . $i;
			$subnode->browserNav = $parent->browserNav;
			$subnode->priority = $params['event_priority'];
			$subnode->changefreq = $params['event_changefreq'];
			$subnode->secure = $parent->secure;
			$xmap->printNode($subnode);
		}
		$xmap->changeLevel(- 1);
	}

	public static function buildEventOrderBy(&$params, $parentId, $itemid)
	{
		$app = JFactory::getApplication('site');

		// Case when the child gets a different menu itemid than it's parent
		if ($parentId != $itemid) {
			$menu = $app->getMenu();
			$item = $menu->getItem($itemid);
			$menuParams = clone ($params);
			$itemParams = new JRegistry($item->params);
			$menuParams->merge($itemParams);
		} else {
			$menuParams = & $params;
		}

		$filter_order = $app->getUserStateFromRequest('com_dpcalendar.calendar.list.' . $itemid . '.filter_order', 'filter_order', '', 'string');
		$filter_order_Dir = $app->getUserStateFromRequest(
			'com_dpcalendar.calendar.list.' . $itemid . '.filter_order_Dir',
			'filter_order_Dir',
			'',
			'cmd'
		);
		$orderby = ' ';

		if ($filter_order && $filter_order_Dir) {
			$orderby .= $filter_order . ' ' . $filter_order_Dir . ', ';
		}

		$eventOrderby = $menuParams->get('orderby_sec', 'end_date');
		$eventOrderDate = $menuParams->get('order_date');
		$orderby .= ' a.created ';

		return $orderby;
	}
}
