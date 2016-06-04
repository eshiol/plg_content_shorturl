<?php
/**
 * @version		3.5.0 plugins/content/shorturl/shorturl.php
 * 
 * @package		J2XML
 * @subpackage	plg_content_shorturl
 * @since		3.5.0
 *
 * @author		Helios Ciancio <info@eshiol.it>
 * @link		http://www.eshiol.it
 * @copyright	Copyright (C) 2016 Helios Ciancio. All Rights Reserved
 * @license		http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * shorturll for Joomla! is free software. This version may have been modified 
 * pursuant to the GNU General Public License, and as distributed it includes 
 * or is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */
 
// no direct access
defined('_JEXEC') or die('Restricted access.');

require_once JPATH_ROOT . '/components/com_content/helpers/route.php';

jimport('joomla.plugin.plugin');

class plgContentShorturl extends JPlugin
{
	/**
	 * Load the language file on instantiation.
	 *
	 * @var    boolean
	 */
	protected $autoloadLanguage = true;
	
	/**
	 * CONSTRUCTOR
	 * 
	 * @param object $subject The object to observe
	 * @param object $config  The object that holds the plugin parameters
	 */
	function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);		

		if ($this->params->get('debug') || (defined('JDEBUG') && JDEBUG))
		{
			JLog::addLogger(array('text_file' => 'shorturl.php', 'extension' => 'plg_content_shorturl'), JLog::ALL, array('plg_content_shorturl'));
		}
		JLog::addLogger(array('logger' => 'messagequeue', 'extension' => 'plg_content_shorturl'), JLOG::ALL & ~JLOG::DEBUG, array('plg_content_shorturl'));
		JLog::add(__METHOD__, JLog::DEBUG, 'plg_content_shorturl');
	}
	
	/**
	 * Article is passed by reference, but after the save, so no changes will be saved.
	 * Method is called right after the content is saved
	 *
	 * @param   string   $context  The context of the content passed to the plugin (added in 1.6)
	 * @param   object   $article  A JTableContent object
	 * @param   boolean  $isNew    If the content is just about to be created
	 *
	 * @return  boolean   true if function not enabled, is in front-end or is new. Else true or
	 *                    false depending on success of save function.
	 */
	public function onContentAfterSave($context, $article, $isNew)
	{
		if (!JFactory::getConfig()->get('sef', 1))
		{
			return true;
		}		

		JLog::add(__METHOD__, JLog::DEBUG, 'plg_content_shorturl');
		
		if (JFactory::getApplication()->isAdmin())
		{
			$allowed_contexts = array('com_content.article');
		}
		else
		{
			$allowed_contexts = array('com_content.form');
		}

		if (!in_array($context, $allowed_contexts))
		{
			return true;
		}
		
		$article->slug = $article->alias ? ($article->id . ':' . $article->alias) : $article->id;
		$current  = ContentHelperRoute::getArticleRoute($article->slug, $article->catid, $article->language);
		JLog::add(new JLogEntry('current: '.$current, JLog::DEBUG, 'plg_content_shorturl'));
		
		// Build the short url.
		$x = md5($current);
		while (is_numeric(substr($x, 0, 1)))
		{
			$x = md5($x);
		}
		$shorturl = rtrim(JURI::root(true), '/').'/'.substr($x, 0, $this->params->get('length', 4));
		JLog::add(new JLogEntry('shorturl: '.$shorturl, JLog::DEBUG, 'plg_content_shorturl'));
		
		// See if the current url exists in the database as a redirect.
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select($db->qn('old_url'))
			->select($db->qn('published'))
			->from($db->qn('#__redirect_links'))
			->where($db->qn('new_url') . ' = ' . $db->q($current))
			->where($db->qn('comment') . ' = ' . $db->q('plg_content_shorturl'))
			;
		$db->setQuery($query, 0, 1);
		$link = $db->loadObject();
		if ($link)
		{
			JLog::add(JText::sprintf($link->published ? JText::_('PLG_CONTENT_SHORTURL_ENABLED') : JText::_('PLG_CONTENT_SHORTURL_ENABLED'), $link->old_url), JLog::INFO, 'plg_content_shorturl');
			return true;
		}
		
		$query->clear()
			->select($db->qn('id'))
			->select($db->qn('new_url'))
			->from($db->qn('#__redirect_links'))
			->where($db->qn('old_url').' = '.$db->q($shorturl))
			;
		$db->setQuery($query, 0, 1);
		$link = $db->loadObject();

		if (!$link)
		{
			$columns = array(
				$db->qn('old_url'),
				$db->qn('new_url'),
				$db->qn('referer'),
				$db->qn('comment'),
				$db->qn('hits'),
				$db->qn('published'),
				$db->qn('created_date')
			);
			
			$values = array(
				$db->q($shorturl),
				$db->q($current),
				$db->q(''),
				$db->q('plg_content_shorturl'),
				0,
				1,
				$db->q(JFactory::getDate()->toSql())
			);
			
			$query->clear()
				->insert($db->qn('#__redirect_links'), false)
				->columns($columns)
				->values(implode(', ', $values));
			
			$db->setQuery($query);
			$db->execute();
			JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_SHORTURL_ADDED', $shorturl), JLog::INFO, 'plg_content_shorturl'));
		}
		elseif (empty($link->new_url))
		{
			$query->clear()
				->update($db->qn('#__redirect_links'))
				->set($db->qn('new_url').' = '.$db->q($current))
				->set($db->qn('published').' = true')
				->set($db->qn('comment') . ' = ' . $db->q('plg_content_shorturl'))
				->where($db->qn('id').' = '.(int)$link->id)
				;
			$db->setQuery($query);
			$db->execute();
			JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_SHORTURL_UPDATED', $shorturl), JLog::INFO, 'plg_content_shorturl'));
		}
		else
		{
			JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_SHORTURL_SAVE_FAILED', $shorturl), JLog::WARNING, 'plg_content_shorturl'));
		}

		return true;
	}
	
	/**
	 * Content is passed by reference, but after the deletion.
	 *
	 * @param   string  $context  The context of the content passed to the plugin (added in 1.6).
	 * @param   object  $article  A JTableContent object.
	 *
	 * @return  void
	 */
	public function onContentAfterDelete($context, $article)
	{
		if (!JFactory::getConfig()->get('sef', 1))
		{
			return true;
		}		

		JLog::add(__METHOD__, JLog::DEBUG, 'plg_content_shorturl');
		
		if (JFactory::getApplication()->isAdmin())
		{
			$allowed_contexts = array('com_content.article');
		}
		else
		{
			$allowed_contexts = array('com_content.form');
		}

		if (!in_array($context, $allowed_contexts))
		{
			return true;
		}
		
		$article->slug = $article->alias ? ($article->id . ':' . $article->alias) : $article->id;
		$current  = ContentHelperRoute::getArticleRoute($article->slug, $article->catid, $article->language);
		JLog::add(new JLogEntry('current: '.$current, JLog::DEBUG, 'plg_content_shorturl'));
		
		// Build the short url.
		$x = md5($current);
		while (is_numeric(substr($x, 0, 1)))
		{
			$x = md5($x);
		}
		$shorturl = rtrim(JURI::root(true), '/').'/'.substr($x, 0, $this->params->get('length', 4));
		JLog::add(new JLogEntry('shorturl: '.$shorturl, JLog::DEBUG, 'plg_content_shorturl'));
		
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true)
			->delete($db->qn('#__redirect_links'))
			->where($db->qn('new_url') . ' = ' . $db->q($current))
			->where($db->qn('comment') . ' = ' . $db->q('plg_content_shorturl'))
			;
		$db->setQuery($query);
		$db->execute();

		JLog::add(new JLogEntry(JText::_('PLG_CONTENT_SHORTURL_DELETED'), JLog::INFO, 'plg_content_shorturl'));
		
		return $true;
	}

	/**
	 * Prepare form.
	 *
	 * @param   JForm  $form  The form to be altered.
	 * @param   mixed  $data  The associated data for the form.
	 *
	 * @return  boolean
	 */
	public function onContentPrepareForm($form, $data)
	{
		if (!JFactory::getConfig()->get('sef', 1))
		{
			return true;
		}		

		JLog::add(__METHOD__, JLog::DEBUG, 'plg_content_shorturl');
		
		if (JFactory::getApplication()->isAdmin())
		{
			$allowed_contexts = array('com_content.article');
		}
		else
		{
			$allowed_contexts = array('com_content.form');
		}

		if (!in_array($form->getName(), $allowed_contexts))
		{
			return true;
		}
		
		$data->slug = $data->alias ? ($data->id . ':' . $data->alias) : $data->id;
		$current  = ContentHelperRoute::getArticleRoute($data->slug, $data->catid, $data->language);
		JLog::add(new JLogEntry('current: '.$current, JLog::DEBUG, 'plg_content_shorturl'));
		
		// Build the short url.
		$x = md5($current);
		while (is_numeric(substr($x, 0, 1)))
		{
			$x = md5($x);
		}
		$shorturl = rtrim(JURI::root(true), '/').'/'.substr($x, 0, $this->params->get('length', 4));
		JLog::add(new JLogEntry('shorturl: '.$shorturl, JLog::DEBUG, 'plg_content_shorturl'));

		// See if the current url exists in the database as a redirect.
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select($db->qn('old_url'))
			->select($db->qn('published'))
			->from($db->qn('#__redirect_links'))
			->where($db->qn('new_url') . ' = ' . $db->q($current))
			->where($db->qn('comment') . ' = ' . $db->q('plg_content_shorturl'))
			;
		JLog::add($query, JLog::DEBUG, 'plg_content_shorturl');
		$db->setQuery($query, 0, 1);
		$link = $db->loadObject();
		if ($link)
		{
			if ($link->published == 1)
				JLog::add(JText::sprintf(JText::_('PLG_CONTENT_SHORTURL_ENABLED'), $link->old_url), JLog::INFO, 'plg_content_shorturl');
			elseif ($link->published == 0)
				JLog::add(JText::sprintf(JText::_('PLG_CONTENT_SHORTURL_DISABLED'), $link->old_url), JLog::INFO, 'plg_content_shorturl');
			elseif ($link->published == 2)
				JLog::add(JText::sprintf(JText::_('PLG_CONTENT_SHORTURL_ARCHIVED'), $link->old_url), JLog::INFO, 'plg_content_shorturl');
			elseif ($link->published == -2)
				JLog::add(JText::sprintf(JText::_('PLG_CONTENT_SHORTURL_TRASHED'), $link->old_url), JLog::INFO, 'plg_content_shorturl');
		}
	}
}
