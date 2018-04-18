<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.Notifier
 *
 * @copyright   Copyright (C) 2018 Bruce Scherzinger. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * Notifier Content Plugin
 *
 * @since  3.8
 */
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
            // Send the notification email, if applicable
            $this->filterNotification($article,"updated");
        }
        return true;
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

            // If the category of this article is in the list for notification, then do it.
            if ($thisgroup->enabled && in_array($article->catid,$categories))
            {
                $this->sendNotification($thisgroup,$article,$action);
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

        // Build non-SEF article link
        $article_link = JUri::base().$cat_alias."?view=article&id=".$article->id.":".$article->alias."&catid=$article->catid";

        // Get email addresses
        $from_name = $params->get('from_name',$mainframe->getCfg('fromname'));
        $from_addr = $params->get('from_addr',$mainframe->getCfg('mailfrom'));

        // Allow admin address field to contain multiple addresses
        $recipient = ($group->address == "") ? $mainframe->getCfg('mailfrom') : $group->address;
        $recipient = str_replace(" ","",$recipient);  // strip all spaces

        // Replace notice message placeholders
        $format = intval($group->format);
        if ($format)
        {
            // HTML format
            $message = ($group->htmltemplate == "") ?
                '<p>[CATEGORY] article [TITLE] has been [ACTION].</p><p>'.$article_link.'</p>' :
                $group->htmltemplate;
            $message = html_entity_decode(str_replace(
                array('[SITE]','[CATEGORY]','[ACTION]','[TITLE]','[LINK]','[INTROTEXT]','[FULLTEXT]','[ALIAS]','[MODIFIED]','[CREATED]'),
                array($mainframe->getCfg('sitename'),$category->title,$action,'"'.$article->title.'"',$article_link,$article->introtext,$article->fulltext,$article->alias,$article->modified,$article->created),$message),
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
        $subject = ($group->email_subject != '' ? $group->email_subject : '[SITE] [CATEGORY] Article [ACTION]');
        $subject = html_entity_decode(str_replace(
                    array('[SITE]','[CATEGORY]','[ACTION]'),
                    array($mainframe->getCfg('sitename'),$category->title,$action),$subject),
                   ENT_QUOTES);

        // Build e-mail message
        $mailer->setSender(array($from_addr, $from_name));
        $mailer->addRecipient($recipient);
        $mailer->setSubject($subject);
        $mailer->setBody($message);
        $mailer->IsHTML($format);

        // Send notification email to administrator
        $mailer->Send();
    }
}