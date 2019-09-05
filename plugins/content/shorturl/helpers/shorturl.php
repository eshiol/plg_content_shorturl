<?php
/**
 * @package		Joomla Plugins
 * @subpackage	plg_content_shorturl
 *
 * @author		Helios Ciancio <info@eshiol.it>
 * @link		http://www.eshiol.it
 * @copyright	Copyright (C) 2016, 2018 Helios Ciancio. All Rights Reserved
 * @license		http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * Shorturl for Joomla! is free software. This version may have been modified 
 * pursuant to the GNU General Public License, and as distributed it includes 
 * or is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */
 
// no direct access
defined('_JEXEC') or die('Restricted access.');

use Joomla\CMS\Language\LanguageHelper;


/**
 * @version		3.8.0
 * @since		3.5.0
 */
abstract class ShorturlHelper
{
    public static function getShortUrl($url, $language = null)
    {
        $plugin = JPluginHelper::getPlugin('content', 'shorturl');
        $params = new JRegistry($plugin->params);

        if ($params->get('debug') || defined('JDEBUG') && JDEBUG)
        {
            JLog::addLogger(array('text_file' => $params->get('log', 'eshiol.log.php'), 'extension' => 'plg_content_shorturl_file'), JLog::ALL, array('plg_content_shorturl'));
        }
        JLog::addLogger(array('logger' => (null !== $params->get('logger')) ? $params->get('logger') : 'messagequeue', 'extension' => 'plg_content_shorturl'), JLOG::ALL & ~JLOG::DEBUG, array('plg_content_shorturl'));
        if ($params->get('phpconsole') && class_exists('JLogLoggerPhpconsole'))
        {
            JLog::addLogger(array('logger' => 'phpconsole', 'extension' => 'plg_content_shorturl_phpconsole'),  JLOG::DEBUG, array('plg_content_shorturl'));
        }
        JLog::add(new JLogEntry(__METHOD__, JLog::DEBUG, 'plg_content_shorturl'));        
        JLog::add(new JLogEntry('url: ' . $url, JLog::DEBUG, 'plg_content_shorturl'));
        JLog::add(new JLogEntry('language: ' . $language, JLog::DEBUG, 'plg_content_shorturl'));

        $lang_sef = '';
        if (JPluginHelper::isEnabled('system', 'languagefilter'))
        {
            $default_language = JComponentHelper::getParams('com_languages')->get('site');
            JLog::add(new JLogEntry('default language: ' . $default_language, JLog::DEBUG, 'plg_content_shorturl'));

            $plugin = JPluginHelper::getPlugin('system', 'languagefilter');
            $lang_params = new JRegistry($plugin->params);
            $remove_default_prefix = $lang_params->get('remove_default_prefix', 0);

            if (($remove_default_prefix == 0) && ($language == '*'))
            {
                $language = $default_language;
            }

            // Get all content languages.
            $languages = LanguageHelper::getContentLanguages(array(0, 1));

            // Add language prefix
            foreach ($languages as $lang_code => $item)
            {
                // Don't do for the reference language.
                if ($lang_code == $language)
                {
                    if (($remove_default_prefix == 0) || ($lang_code != $default_language))
                    {
                        $lang_sef = $item->sef . '/';
                    }
                    break;
                }
            }
        }

        $x = md5($url);
        while (is_numeric(substr($x, 0, 1)))
        {
            $x = md5($x);
        }

        return '/' . $lang_sef . substr($x, 0, $params->get('length', 4));
    }
    
    public static function urlExists($url)
    {
        $plugin = JPluginHelper::getPlugin('content', 'shorturl');
        $params = new JRegistry($plugin->params);
        
        if ($params->get('debug') || defined('JDEBUG') && JDEBUG)
        {
            JLog::addLogger(array('text_file' => $params->get('log', 'eshiol.log.php'), 'extension' => 'plg_content_shorturl_file'), JLog::ALL, array('plg_content_shorturl'));
        }
        JLog::addLogger(array('logger' => (null !== $params->get('logger')) ? $params->get('logger') : 'messagequeue', 'extension' => 'plg_content_shorturl'), JLOG::ALL & ~JLOG::DEBUG, array('plg_content_shorturl'));
        if ($params->get('phpconsole') && class_exists('JLogLoggerPhpconsole'))
        {
            JLog::addLogger(array('logger' => 'phpconsole', 'extension' => 'plg_content_shorturl_phpconsole'),  JLOG::DEBUG, array('plg_content_shorturl'));
        }
        JLog::add(new JLogEntry(__METHOD__, JLog::DEBUG, 'plg_content_shorturl'));

        $db    = JFactory::getDbo();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__redirect_links'))
            ->where($db->quoteName('old_url') . ' = ' . $db->quote($url))
            ->where($db->quoteName('comment') . ' = ' . $db->quote('plg_content_shorturl'))
            ->where($db->quoteName('published') . ' = 1');
        JLog::add(new JLogEntry($query, JLog::DEBUG, 'plg_content_shorturl'));
        $db->setQuery($query, 0, 1);
        return $db->loadObject() ? true : false;
    }
}
