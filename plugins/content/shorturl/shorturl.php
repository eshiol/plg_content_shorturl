<?php
/**
 * @package     Joomla.Plugins
 * @subpackage  Content.Shorturl
 * * @version     __DEPLOY_VERSION__
 * @since       3.5.0
 *
 * @author      Helios Ciancio <info (at) eshiol (dot) it>
 * @link        https://www.eshiol.it
 * @copyright   Copyright (C) 2016 - 2022 Helios Ciancio. All Rights Reserved
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * Shorturl for Joomla! is free software. This version may have been modified
 * pursuant to the GNU General Public License, and as distributed it includes
 * or is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */

// no direct access
defined('_JEXEC') or die('Restricted access.');

require_once JPATH_ROOT . '/components/com_content/helpers/route.php';
require_once JPATH_ROOT . '/plugins/content/shorturl/helpers/shorturl.php';

if (file_exists(JPATH_ROOT . '/components/com_k2/helpers/route.php'))
{
	require_once JPATH_ROOT . '/components/com_k2/helpers/route.php';
}

jimport('joomla.plugin.plugin');

use Joomla\CMS\Language\LanguageHelper;

class plgContentShorturl extends JPlugin
{
	/**
	 * Load the language file on instantiation.
	 *
	 * @var    boolean
	 */
	protected $autoloadLanguage = true;

	/**
	 * Constructor
	 *
	 * @param  object  $subject  The object to observe
	 * @param  array   $config   An array that holds the plugin configuration
	 */
	function __construct(&$subject, $config)
	{
	    parent::__construct($subject, $config);

	    if ($this->params->get('debug') || defined('JDEBUG') && JDEBUG)
	    {
	        JLog::addLogger(array('text_file' => $this->params->get('log', 'eshiol.log.php'), 'extension' => 'plg_content_shorturl_file'), JLog::ALL, array('plg_content_shorturl'));
	    }
	    if (PHP_SAPI == 'cli')
	    {
	        JLog::addLogger(array('logger' => 'echo', 'extension' => 'plg_content_shorturl'), JLog::ALL & ~ JLog::DEBUG, array('plg_content_shorturl'));
	    }
	    else
	    {
	        JLog::addLogger(array('logger' => ($this->params->get('logger') !== null) ? $this->params->get('logger') : 'messagequeue', 'extension' => 'plg_content_shorturl'), JLog::ALL & ~ JLog::DEBUG, array('plg_content_shorturl'));
	    }
	    JLog::add(new JLogEntry(__METHOD__, JLog::DEBUG, 'plg_content_shorturl'));
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
		JLog::add(new JLogEntry(__METHOD__, JLog::DEBUG, 'plg_content_shorturl'));
		JLog::add(new JLogEntry($context, JLog::DEBUG, 'plg_content_shorturl'));

		if (!JFactory::getConfig()->get('sef', 1))
		{
			return true;
		}

		if (JFactory::getApplication()->isClient('administrator'))
		{
			$allowedContexts = array('com_content.article', 'com_k2.item');
		}
		else
		{
			$allowedContexts = array('com_content.form', 'com_k2.item');
		}

		if (!in_array($context, $allowedContexts))
		{
			return true;
		}

		$article->slug = $article->alias ? ($article->id . ':' . $article->alias) : $article->id;
		if ($context === 'com_k2.item')
		{
			$url = K2HelperRoute::getItemRoute($article->slug, $article->catid);
		}
		else
		{
			$url = ContentHelperRoute::getArticleRoute($article->slug, $article->catid, $article->language);
		}
		JLog::add(new JLogEntry('url: ' . $url, JLog::DEBUG, 'plg_content_shorturl'));

		$shortUrl = rtrim(JURI::root(true), '/') . ShorturlHelper::getShortUrl($url, $article->language);
		JLog::add(new JLogEntry('shorturl: ' . $shortUrl, JLog::DEBUG, 'plg_content_shorturl'));

		// See if the current url exists in the database as a redirect.
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('old_url'))
			->select($db->quoteName('published'))
			->from($db->quoteName('#__redirect_links'))
			->where($db->quoteName('new_url') . ' = ' . $db->quote($url))
			->where($db->quoteName('comment') . ' = ' . $db->quote('plg_content_shorturl'));
		$db->setQuery($query, 0, 1);
		$link = $db->loadObject();
		if ($link)
		{
			JLog::add(new JLogEntry(JText::sprintf($link->published ? JText::_('PLG_CONTENT_SHORTURL_ENABLED') : JText::_('PLG_CONTENT_SHORTURL_ENABLED'), $link->old_url), JLog::INFO, 'plg_content_shorturl'));
			return true;
		}

		$query->clear()
			->select($db->quoteName('id'))
			->select($db->quoteName('new_url'))
			->from($db->quoteName('#__redirect_links'))
			->where($db->quoteName('old_url') . ' = ' . $db->quote($shortUrl));
		$db->setQuery($query, 0, 1);
		$link = $db->loadObject();

		if (!$link)
		{
			$columns = array(
				$db->quoteName('old_url'),
				$db->quoteName('new_url'),
				$db->quoteName('referer'),
				$db->quoteName('comment'),
				$db->quoteName('hits'),
				$db->quoteName('published'),
				$db->quoteName('created_date'));

			$values = array(
				$db->quote($shortUrl),
				$db->quote($url),
				$db->quote(''),
				$db->quote('plg_content_shorturl'),
				0,
				1,
				$db->quote(JFactory::getDate()->toSql()));

			$query->clear()
				->insert($db->quoteName('#__redirect_links'), false)
				->columns($columns)
				->values(implode(', ', $values));

			$db->setQuery($query);
			$db->execute();
			JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_SHORTURL_ADDED', $shortUrl), JLog::INFO, 'plg_content_shorturl'));
		}
		elseif (empty($link->new_url))
		{
			$query->clear()
				->update($db->quoteName('#__redirect_links'))
				->set($db->quoteName('new_url') . ' = ' . $db->quote($url))
				->set($db->quoteName('published') . ' = true')
				->set($db->quoteName('comment') . ' = ' . $db->quote('plg_content_shorturl'))
				->where($db->quoteName('id') . ' = ' . (int) $link->id);
			$db->setQuery($query);
			$db->execute();
			JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_SHORTURL_UPDATED', $shortUrl), JLog::INFO, 'plg_content_shorturl'));
		}
		else
		{
			JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_SHORTURL_SAVE_FAILED', $shortUrl), JLog::WARNING, 'plg_content_shorturl'));
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

		JLog::add(new JLogEntry(__METHOD__, JLog::DEBUG, 'plg_content_shorturl'));

		if (JFactory::getApplication()->isClient('administrator'))
		{
			$allowedContexts = array('com_content.article');
		}
		else
		{
			$allowedContexts = array('com_content.form');
		}

		if (!in_array($context, $allowedContexts))
		{
			return true;
		}

		$article->slug = $article->alias ? ($article->id . ':' . $article->alias) : $article->id;
		$url  = ContentHelperRoute::getArticleRoute($article->slug, $article->catid, $article->language);
		JLog::add(new JLogEntry('url: ' . $url, JLog::DEBUG, 'plg_content_shorturl'));

		$shortUrl = rtrim(JURI::root(true), '/') . ShorturlHelper::getShortUrl($url, $article->language);
		JLog::add(new JLogEntry('shorturl: ' . $shortUrl, JLog::DEBUG, 'plg_content_shorturl'));

		$db    = JFactory::getDbo();
		$query = $db->getQuery(true)
			->delete($db->quoteName('#__redirect_links'))
			->where($db->quoteName('new_url') . ' = ' . $db->quote($url))
			->where($db->quoteName('comment') . ' = ' . $db->quote('plg_content_shorturl'));
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

		JLog::add(new JLogEntry(__METHOD__, JLog::DEBUG, 'plg_content_shorturl'));

		if (JFactory::getApplication()->isClient('administrator'))
		{
			$allowedContexts = array('com_content.article');
		}
		else
		{
			$allowedContexts = array('com_content.form');
		}

		if (!in_array($form->getName(), $allowedContexts))
		{
			return true;
		}

		if (is_object($data))
		{
    		$data->slug = $data->alias ? ($data->id . ':' . $data->alias) : $data->id;
    		$url  = ContentHelperRoute::getArticleRoute($data->slug, $data->catid, $data->language);
    		JLog::add(new JLogEntry('url: ' . $url, JLog::DEBUG, 'plg_content_shorturl'));

    		$shortUrl = rtrim(JURI::root(true), '/') . ShorturlHelper::getShortUrl($url, $data->language);
    		JLog::add(new JLogEntry('shorturl: ' . $shortUrl, JLog::DEBUG, 'plg_content_shorturl'));

    		// See if the current url exists in the database as a redirect.
    		$db    = JFactory::getDbo();
    		$query = $db->getQuery(true)
    			->select($db->quoteName('old_url'))
    			->select($db->quoteName('published'))
    			->from($db->quoteName('#__redirect_links'))
    			->where($db->quoteName('new_url') . ' = ' . $db->quote($url))
    			->where($db->quoteName('comment') . ' = ' . $db->quote('plg_content_shorturl'));
    		JLog::add(new JLogEntry($query, JLog::DEBUG, 'plg_content_shorturl'));
    		$db->setQuery($query, 0, 1);
    		$link = $db->loadObject();
    		if ($link)
    		{
    			if ($link->published == 1)
    			{
    				JLog::add(new JLogEntry(JText::sprintf(JText::_('PLG_CONTENT_SHORTURL_ENABLED'), $link->old_url), JLog::INFO, 'plg_content_shorturl'));
    			}
    			elseif ($link->published == 0)
    			{
    			    JLog::add(new JLogEntry(JText::sprintf(JText::_('PLG_CONTENT_SHORTURL_DISABLED'), $link->old_url), JLog::INFO, 'plg_content_shorturl'));
    			}
    			elseif ($link->published == 2)
    			{
    			    JLog::add(new JLogEntry(JText::sprintf(JText::_('PLG_CONTENT_SHORTURL_ARCHIVED'), $link->old_url), JLog::INFO, 'plg_content_shorturl'));
    			}
    			elseif ($link->published == -2)
    			{
    			    JLog::add(new JLogEntry(JText::sprintf(JText::_('PLG_CONTENT_SHORTURL_TRASHED'), $link->old_url), JLog::INFO, 'plg_content_shorturl'));
    			}
    		}
		}
	}

	/**
	 * Displays the toolbar at the top of the article
	 *
	 * @param   string   $context  The context of the content being passed to the plugin.
	 * @param   mixed    &$row     An object with a "text" property
	 * @param   mixed    $params   Additional parameters. See {@see PlgContentContent()}.
	 * @param   integer  $page     Optional page number. Unused. Defaults to zero.
	 *
	 * @return  mixed  html string
	 *
	 * @since   3.8.0
	 */
	public function onContentBeforeDisplay($context, &$row, &$params, $page=0)
	{
	    JLog::add(new JLogEntry(__METHOD__, JLog::DEBUG, 'plg_content_shorturl'));

	    if ($this->params->get('shortlink', 1) == 0)
	    {
	        JLog::add(new JLogEntry('shortlink disabled', JLog::DEBUG, 'plg_content_shorturl'));
	        return;
	    }

	    $app    = JFactory::getApplication();
	    $doc    = $app->getDocument();
	    $server = JUri::getInstance()->toString(array('scheme', 'host', 'port'));

	    if (!$app->isClient('site'))
	    {
	        JLog::add(new JLogEntry('client not allowed', JLog::DEBUG, 'plg_content_shorturl'));
	        return;
	    }
	    elseif (!JPluginHelper::isEnabled('system', 'redirect'))
	    {
	        JLog::add(new JLogEntry('plugin system redirect not enabled', JLog::DEBUG, 'plg_content_shorturl'));
	        return;
	    }
	    elseif ($doc->getType() !== 'html')
	    {
	        JLog::add(new JLogEntry('document type not allowed: ' . $doc->getType(), JLog::DEBUG, 'plg_content_shorturl'));
	        return;
	    }
	    elseif ($context != 'com_content.article')
	    {
	        JLog::add(new JLogEntry('context not allowed: ' . $context, JLog::DEBUG, 'plg_content_shorturl'));
	        return;
	    }

	    $row->slug = $row->alias ? ($row->id . ':' . $row->alias) : $row->id;
	    $url  = ContentHelperRoute::getArticleRoute($row->slug, $row->catid, $row->language);
	    JLog::add(new JLogEntry('url: ' . $url, JLog::DEBUG, 'plg_content_shorturl'));

	    $shortUrl = rtrim(JURI::root(true), '/') . ShorturlHelper::getShortUrl($url, $row->language);
	    JLog::add(new JLogEntry('shorturl: ' . $shortUrl, JLog::DEBUG, 'plg_content_shorturl'));

	    $db    = JFactory::getDbo();
	    $query = $db->getQuery(true)
    	    ->select($db->quoteName('published'))
    	    ->from($db->quoteName('#__redirect_links'))
    	    ->where($db->quoteName('old_url') . ' = ' . $db->quote($shortUrl));
	    $db->setQuery($query, 0, 1);
	    $link = $db->loadObject();
	    if (!$link)
	    {
	        JLog::add(new JLogEntry('shorturl doesn\'t exist', JLog::DEBUG, 'plg_content_shorturl'));
	        return;
	    }

	    $doc->addHeadLink($server . htmlspecialchars($shortUrl), 'shortlink');
	}
}
