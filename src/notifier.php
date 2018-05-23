<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.Notifier
 * @version     2.0
 * @copyright   Copyright (C) 2018 Bruce Scherzinger. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * Notifier Content Plugin
 *
 * @since  3.8
 */

if(!class_exists('ContentHelperRoute')) require_once (JPATH_SITE.'/components/com_content/helpers/route.php');

class plgContentNotifier extends JPlugin
{
    /**
     * Load the language file on instantiation. Note this is only available in Joomla 3.1 and higher.
     * If you want to support 3.0 series you must override the constructor
     *
     * @var    boolean
     * @since  3.8
     */
    protected $autoloadLanguage = true;

    /**
     * Email notification after save content method.
     * Content is passed by reference, but after the save, so no changes will be saved.
     * Method is called right after the content is saved.
     *
     * @param   string  $context  The context of the content passed to the plugin (added in 1.6)
     * @param   object  $article  A JTableContent object
     * @param   bool    $isNew    If the content has just been created
     *
     * @return  void
     *
     * @since   3.8
     */
    public function onContentAfterSave($context,&$article,$isNew)
    {
        // This handler only sends a notification for a published article that was just saved.
        if ($article->state == 1)
        {
            // Point to plug-in parameters
            $params = $this->params;

            // Determine the appropriate action
            if ($isNew)
                $action = $params->get('newaction');
            else
                $action = $params->get('saveaction');

            if ($action)
                $action = JText::_($action);
            else
                $action = JText::_('COM_CONTENT_SAVE_SUCCESS');

            // Send the notification email, if applicable
            $this->filterNotification($article,$action);
        }
        return true;
    }

    /**
     * Indicate whether event occurred via a front or back end article modification.
     *
     * @return  "Front" or "Back"
     */
    protected function areaSaved()
    {
        // Based on whether the following com_content constant is defined or not.
        return Jtext::_('PLG_CONTENT_NOTIFIER_AREA');
    }

    /**
     * Apply subscription group filtering for all groups in use.
     *
     * @param   object   $article  The content object passed to the plugin.
     * @param   string   $action   A string describing what just happened to the article.
     *
     * @return  void
     */
    protected function filterNotification($article,$action)
    {
        // Point to plug-in parameters
        $params = $this->params;

        // Get the parameters for all defined groups.
        $subgroups = $params->get('subgroups');

        // Process each group individually.
        foreach ($subgroups as $thisgroup)
        {
            // Get list of categories for notification
            $categories = $thisgroup->category;

            // First, determine if we even want to send this based on the save occurring in the front or back end of the site.
            if ($thisgroup->enabled == $this->areaSaved() || $thisgroup->enabled == "Both")
            {
                // If the category of this article is in the list for notification, then do it.
                if (in_array($article->catid,$categories))
                {
                    $this->sendNotification($thisgroup,$article,$action);
                }
            }
        }
    }

    /**
     * Send email notification to group address(es).
     *
     * @param   object   $group    Subscription group object.
     * @param   object   $article  The content object passed to the plugin.
     * @param   string   $action   A string describing what just happened to the article.
     *
     * @return  void
     */
    protected function sendNotification($group,$article,$action)
    {
        // Get the application framework
        $mainframe = JFactory::getApplication();

        // Setup a mailer
        $mailer = JFactory::getMailer();

        // Point to plug-in parameters
        $params = $this->params;

        // Get category name
        $db = JFactory::getDBO();
        $db->setQuery("SELECT * FROM #__categories WHERE id=$article->catid");
        $category = $db->loadObject();
        $cat_alias = $category->alias;
        $cat_title = $category->title;

        // Build article links
        $rel_link = str_replace('/administrator','',JRoute::_(ContentHelperRoute::getArticleRoute($article->id,$article->catid)));
        $abs_link = JURI::base().$rel_link;
        $abs_link = str_replace(':/','://',str_replace('//','/',$abs_link));

        // Get email addresses
        $from_name = $params->get('from_name',$mainframe->getCfg('fromname'));
        $from_addr = $params->get('from_addr',$mainframe->getCfg('mailfrom'));

        // Allow admin address field to contain multiple addresses
        $recipient = ($group->address == "") ? $mainframe->getCfg('mailfrom') : $group->address;
        $recipient = str_replace(" ","",$recipient);  // strip all spaces

        // Replace notice message placeholders
        $format = intval($group->format);
        if ($format == "HTML")
        {
            // HTML format
            $message = ($group->htmltemplate == "") ?
                '<p>[CATEGORY] article [TITLE] has been [ACTION].</p><p>'.$article_link.'</p>' :
                $group->htmltemplate;
            $message = html_entity_decode(str_replace(
                array('[SITE]','[CATEGORY]','[ACTION]','[TITLE]','[LINK]','[LINKREL]','[INTROTEXT]','[FULLTEXT]','[ALIAS]','[MODIFIED]','[CREATED]','[AREA]'),
                array($mainframe->getCfg('sitename'),$category->title,$action,'"'.$article->title.'"',$abs_link,$rel_link,$article->introtext,$article->fulltext,$article->alias,$article->modified,$article->created,$this->areaSaved()),$message),
                ENT_QUOTES);
            if ($group->prepare_content == "1")
            {
                $message  = JHtml::_('content.prepare', $message);
            }
        }
        else
        {
            // Text format
            $message = ($group->texttemplate == "") ?
                "[CATEGORY] article [TITLE] has been [ACTION].\n\nVisit the link below to read it.\n\n$article_link" :
                $group->texttemplate;
            $message = html_entity_decode(str_replace(
                array('[SITE]','[CATEGORY]','[ACTION]','[TITLE]','[LINK]','[ALIAS]','[MODIFIED]','[CREATED]'),
                array($mainframe->getCfg('sitename'),$category->title,$action,'"'.$article->title.'"',$article_link,$article->alias,$article->modified,$article->created),$message),
                ENT_QUOTES);
        }

        // Replace notice message SUBJECT placeholders
        $subject = ($group->email_subject != '' ? $group->email_subject : '[SITE] [CATEGORY] [TITLE] [ACTION]');
        $subject = html_entity_decode(str_replace(
                    array('[SITE]','[CATEGORY]','[TITLE]','[ACTION]'),
                    array($mainframe->getCfg('sitename'),$category->title,$article->title,$action),$subject),
                   ENT_QUOTES);

        // Build e-mail message
        $mailer->setSender(array($from_addr, $from_name));
        $mailer->addRecipient($recipient);
        $mailer->setSubject($subject);
        $mailer->setBody($message);
        $mailer->IsHTML($format == "HTML");

        // Send notification email to administrator
        $mailer->Send();
    }
}