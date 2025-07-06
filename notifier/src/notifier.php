<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.Notifier
 * @version     5.7
 * @copyright   Copyright (C) Bruce Scherzinger. All rights reserved.
 * @license     GNU General Public License version 3
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * Notifier Content Plugin
 *
 * @since  3.9
 */

if(!class_exists('ContentHelperRoute')) require_once (JPATH_SITE.'/components/com_content/helpers/route.php');

class PlgContentNotifier extends CMSPlugin
{
    /**
     * Load the language file on instantiation. Note this is only available in Joomla 3.1 and higher.
     * If you want to support 3.0 series you must override the constructor
     *
     * @var    boolean
     * @since  3.9
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
     * @since   3.9
     */
    public function onContentAfterSave($context,&$article,$isNew)
    {
        // Only operate on com_content context articles.
        $contextlen = strpos($context,".") === FALSE ? strlen($context) : strpos($context,".");
        $context = substr($context,0,$contextlen);
        if ($context != "com_content") return false;
        
        // If there is a send-mode custom field, get it and some other info
        $emailmode = $this->emailMode($article);
        $emailed = 'Email was NOT sent';

        // Get the current date/time. Get the publish_up time also, but use only the date.
        $currdatetime = date('Y-m-d H-i-s');
        $publishdate = substr($article->publish_up,0,10);

        // This handler only sends a notification for a published article that was just saved.
        // Note that the article must not just be published but its publish date must have passed.
        if ($article->state == 1 && $currdatetime >= $publishdate)
        {
            $action = 'published';

            // If there is no send-mode field or the user did not specify 'Do NOT Send', we can proceed.            
            if (!$emailmode || $emailmode->Value != 'Never')
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
                if ($this->filterNotification($article,$action))
                {
                    // Reset the parameter to the default value if requested.
                    $this->resetMode($emailmode);
                    $emailed = 'Email was sent.';
                }
            }
        }
        else
        {
            $action = 'did NOT publish';
        }

        if ($debug)
        {
            $db->setQuery("INSERT INTO a_debug_log (message) VALUES ('PlgContentNotifier $action \"".addslashes($article->title)."\"". $emailed. $summary."\")");
            $db->execute();
        }
        return true;
    }

    /**
     * Return the Send Mode custom field value if it exists.
     *
     * @return  value of the send-mode custom field
     *
     * @since   3.9
     */
    protected function emailMode($article)
    {
        // Get the custom field value (because $article->jcFields doesn't do it)
        $db = Factory::getDBO();

        // Get the value of the send-mode field.
        $query = "SELECT v.value as Value, f.id as Field, f.default_value as Reset, c.id as Item
                 FROM #__fields f LEFT JOIN #__fields_values v ON f.id=v.field_id
                 LEFT JOIN #__content c ON c.id=v.item_id 
                 WHERE c.id=$article->id AND f.name LIKE '%send-mode'";
        $db->setQuery($query);

        // Return the necessary info
        return $db->loadObject();
    }

    /**
     * Reset the Send Mode custom field value to the default value, if it exists.
     *
     * @return  nothing
     *
     * @since   3.9
     */
    protected function resetMode($fieldinfo)
    {
        // If 'Send Once' was specified, reset the parameter to the default value.
        if ($fieldinfo->Value == 'Once')
        {
            // Get a database object
            $db = Factory::getDBO();

            // Fields to update.
            $fields = $db->quoteName('value') . " = 'Never'";
            
            // Conditions for which records should be updated.
            $conditions = array($db->quoteName('field_id') . ' = ' . $fieldinfo->Field,
                                $db->quoteName('item_id')  . ' = ' . $db->quote($fieldinfo->Item));
    
            // Set the field back to its default value
            $query = $db->getQuery(true);
            $query->update($db->quoteName('#__fields_values'))->set($fields)->where($conditions);
            $db->setQuery($query);
            $db->execute();
        }
    }

    /**
     * Apply subscription group filtering for all groups in use.
     *
     * @param   object   $article  The content object passed to the plugin.
     * @param   string   $action   A string describing what just happened to the article.
     *
     * @return  void
     *
     * @since   3.9
     */
    protected function filterNotification($article,$action)
    {
        $sentnotification = false;

        // Point to plug-in parameters
        $params = $this->params;

        // Get the parameters for all defined groups.
        $subgroups = $params->get('subgroups');

        // Process each group individually.
        foreach ($subgroups as $thisgroup)
        {
            // Get list of categories for notification
            $categories = $thisgroup->category;

            // First, determine if we even want to send this based on the article being saved.
            if ($thisgroup->enabled == "Save")
            {
                // If the category of this article is in the list for notification, then do it.
                if (in_array($article->catid,$categories))
                {
                    $this->sendNotification($thisgroup,$article,$action);
                    $sentnotification = true;
                }
            }
        }
        return $sentnotification;
    }

    /**
     * Send email notification to group address(es).
     *
     * @param   object   $group    Subscription group object.
     * @param   object   $article  The content object passed to the plugin.
     * @param   string   $action   A string describing what just happened to the article.
     *
     * @return  void
     *
     * @since   3.9
     */
    protected function sendNotification($group,$article,$action)
    {
        // Get the application framework
        $mainframe = Factory::getApplication();

        // Setup a mailer
        $mailer = Factory::getMailer();

        // Point to plug-in parameters
        $params = $this->params;

        // Get a database object
        $db = Factory::getDBO();

        // See if we need to send this to the article author, the group address, or both.
        // If both, make the author the primary.
        $address = "";
        $author  = "";
        $copied  = "";

        // Get article author info
        $db->setQuery("SELECT * FROM #__users u JOIN #__comprofiler c WHERE u.id=c.id AND u.id=$article->created_by");
        $created_by = $db->loadObject();

        if ($group->recipients == "Address" || $group->recipients == "Both")
        {
            // Add the admin address as a recipient. Allow admin address field to contain multiple addresses.
            $address = ($group->address == "") ? $mainframe->get('mailfrom') : $group->address;
            $address = str_replace(" ","",$address);  // strip all spaces
        }
        if ($group->recipients == "Author" || $group->recipients == "Both")
        {
            // Add the article author as a recipient
            $author = $created_by->email;
        }
        if ($author != "")
        {
            // Set the to: address to the author
            $recipient = $author;

            if ($address != "")
            {
                // Set the cc: address to the specified address.
                $copied = $address;
            }
        }
        else
        {
            $recipient = $address;
        }

        // Only send the email if we have an address.
        if ($recipient != "")
        {
            // Get system email address
            $from_name = $params->get('from_name',$mainframe->get('fromname'));
            $from_addr = $params->get('from_addr',$mainframe->get('mailfrom'));
    
            // Get article category name
            $db->setQuery("SELECT * FROM #__categories WHERE id=$article->catid");
            $category = $db->loadObject();
            $cat_alias = $category->alias;
            $cat_title = $category->title;

            // Build article links (Note: only works with Joomla 3.9 or later)
            $rel_link = JRoute::link('site','index.php?option=com_content&view=article&id='.$article->id.'&catid='.$category->id);
            $site_url = rtrim(JURI::root(),'/');
            $abs_link = $site_url.$rel_link;
    
            // Replace notice message placeholders
            if ($group->format == "HTML")
            {
                // HTML format
                $message = ($group->htmltemplate == "") ?
                    '<p>[CATEGORY] article [TITLE] has been [ACTION].</p><p>'.$abs_link.'</p>' :
                    $group->htmltemplate;
            }
            else
            {
                // Text format
                $message = ($group->texttemplate == "") ?
                    "[CATEGORY] article [TITLE] has been [ACTION].\n\nVisit the link below to read it.\n\n$article_link" :
                    $group->texttemplate;
            }
            $message = html_entity_decode(str_replace(
                array('[SITE]',
                      '[CATEGORY]',
                      '[ACTION]',
                      '[TITLE]',
                      '[AUTHOR]',
                      '[FIRST]',
                      '[LINK]',
                      '[LINKREL]',
                      '[INTROTEXT]',
                      '[FULLTEXT]',
                      '[ALIAS]',
                      '[MODIFIED]',
                      '[CREATED]'),
                array($mainframe->get('sitename'),
                      $category->title,
                      $action,
                      $article->title,
                      $created_by->name,
                      $created_by->firstname,
                      $abs_link,
                      $rel_link,
                      $article->introtext,
                      $article->fulltext,
                      $article->alias,
                      $article->modified,
                      $article->created
                      ),
                $message),
                ENT_QUOTES);
    
            // Replace notice message SUBJECT placeholders
            $subject = ($group->email_subject != '' ? $group->email_subject : '[SITE] [CATEGORY] [TITLE] [ACTION]');
            $subject = html_entity_decode(str_replace(
                        array('[SITE]','[CATEGORY]','[TITLE]','[ACTION]'),
                        array($mainframe->get('sitename'),$category->title,$article->title,$action),$subject),
                       ENT_QUOTES);
    
            // Build e-mail message
            $mailer->setSender(array($from_addr, $from_name));
            $mailer->addRecipient($recipient);
            if ($copied != '') $mailer->addCC($copied);
            $mailer->setSubject($subject);
            $mailer->setBody($message);
            $mailer->IsHTML($group->format == "HTML");
    
            // Send notification email to administrator
            $mailer->Send();
        }
    }
}
