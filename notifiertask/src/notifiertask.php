<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content Notifier Task
 * @version     5.4
 * @copyright   Copyright (C) 2018-2022 Bruce Scherzinger. All rights reserved.
 * @license     GNU General Public License version 3
 */

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Task\Task;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;

defined( '_JEXEC' ) or die;

class PlgTaskNotifierTask extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;
    
    protected const TASKS_MAP = array(
        'plg_task_notifiertask' => array(
            'langConstPrefix' => 'PLG_TASK_NOTIFIERTASK',
            'method' => 'doContentNotifier',
            'form' => 'notifiertaskparams'
            )
        );

    protected $autoloadLanguage = true;
    protected $db;
    
    public static function getSubscribedEvents(): array
    {
        return array(
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        );
    }
    
    private function doContentNotifier(ExecuteTaskEvent $event): int
    {
        /**
         * Email notification of selected article(s) using the "task" method.
         *  Selection is made based on the query coded at the end of this file.
         *  Method is called by the Joomla Task Scheduler triggering a CB Auto Action.
         *  This is a companion script to Content Notifier meant to be included in an
         *  unconstrained CB Auto Action Task that uses no other trigger. This code
         *  will only send the article if "Task" is selected as the trigger in the
         *  Content Notifier plugin for a given subscription group.
         *
         * @author  Bruce Scherzinger
         *
         * @license GNU v2 Public License
         *
         * @param   object  $article  A JTableContent object
         *
         * @return  void
         *
         * @since   3.9
         */
        function emailArticle($article)
        {
            // If there is a send-mode custom field, get it and some other info
            $emailmode = emailMode($article);

            // Get the current date/time
            $currdatetime = date('Y-m-d');

            // This handler sends a notification for a published article that is preconditioned to email.
            // Note that the article must not just be published but its publish date must have passed.
            if ($article->state == 1 && $currdatetime >= $article->publish_up)
            {
                // If there is no send-mode field or the user did not specify 'Do NOT Send', we can proceed.            
                if (!$emailmode || $emailmode->Value != 'Do NOT Send')
                {
                    // Reset the parameter to the default value if requested.
                    if ($emailmode) resetMode($emailmode);

                    // Send the notification email, if applicable
                    filterNotification($article);
                }
            }
        }

        /**
         * Return the Send Mode custom field value if it exists.
         *
         * @return  value of the send-mode custom field
         *
         * @since   3.9
         */
        function emailMode($article)
        {
            // Get the custom field value (because $article->jcFields does not do it)
            $db = JFactory::getDBO();

            // Get the value of the send-mode field.
            $query = "SELECT v.value as Value, f.id as Field, f.default_value as Reset, c.id as Item
                      FROM #__fields f 
                      LEFT JOIN #__fields_values v
                      ON f.id=v.field_id
                      LEFT JOIN #__content c
                      ON c.id=v.item_id 
                      WHERE c.id=".$article->id." AND f.name='send-mode'";
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
        function resetMode($fieldinfo)
        {
            // If 'Send Once' was specified, reset the parameter to the default value.
            if ($fieldinfo->Value == 'Send Once')
            {
                // Get a database object
                $db = JFactory::getDBO();

                // Fields to update.
                $fields = $db->quoteName('value') . ' = ' . $db->quote($fieldinfo->Reset);
                
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
         * Apply Content Notifier plugin subscription group filtering for all groups in use.
         *
         * @param   object   $article  The content object passed to the plugin.
         *
         * @return  void
         *
         * @since   3.9
         */
        function filterNotification($article)
        {
            $sentnotification = false;

            // Get the parameters for the Content Notifier plugin
            $db = JFactory::getDBO();
            $db->setQuery("SELECT params FROM #__extensions WHERE folder='content' AND element='notifier'");
            
            
            // Get the plug-in parameters
            $params = json_decode($db->loadResult(),false,20);

            // Get the parameters for all defined groups.
            $subgroups = $params->subgroups;

            // Process each group individually.
            foreach ($subgroups as $thisgroup)
            {
                // Get list of categories for notification
                $categories = $thisgroup->category;

                // First, determine if we even want to send this based on whether it is task activated.
                if ($thisgroup->enabled == "Task")
                {
                    // If the category of this article is in the list for notification, then do it.
                    if (in_array($article->catid,$categories))
                    {
                        sendNotification($thisgroup,$article);
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
         *
         * @return  void
         *
         * @since   3.9
         */
        function sendNotification($group,$article)
        {
            // Get the application framework
            $mainframe = JFactory::getApplication();

            // Setup a mailer
            $mailer = JFactory::getMailer();

            // Get a database object
            $db = JFactory::getDBO();

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
                $address = ($group->address == "") ? $mainframe->getCfg('mailfrom') : $group->address;
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
                $from_name = $params->from_name;
                if (!$from_name) $from_name = $mainframe->getCfg('fromname');
                $from_addr = $params->from_addr;
                if (!$from_addr) $from_addr = $mainframe->getCfg('mailfrom');
        
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
                          '[CREATED]',
                          '[AREA]'),
                    array($mainframe->getCfg('sitename'),
                          addslashes($category->title),
                          'Article Sent',
                          addslashes($article->title),
                          $created_by->name,
                          $created_by->firstname,
                          $abs_link,
                          $rel_link,
                          addslashes($article->introtext),
                          addslashes($article->fulltext),
                          $article->alias,
                          $article->modified,
                          $article->created,
                          'Task'),
                    $message),
                    ENT_QUOTES);
        
                // Replace notice message SUBJECT placeholders
                $subject = ($group->email_subject != '' ? $group->email_subject : '[SITE] [CATEGORY] [TITLE] [ACTION]');
                $subject = html_entity_decode(str_replace(
                    array('[SITE]',
                          '[CATEGORY]',
                          '[TITLE]',
                          '[ACTION]'),
                    array($mainframe->getCfg('sitename'),
                          $category->title,
                          $article->title,
                          "Article Sent"),
                    $subject),
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

        ///////////////////////////////////////////////////////////////////////////////////////////
        // PROGRAM EXECUTION STARTS HERE
        ///////////////////////////////////////////////////////////////////////////////////////////
        // Get the WHERE clause provided by the user.
        $params = $event->getArgument('params');

        // Get the newest published article whose title includes the word "Issue" and whose publish date is in the past.
        $db = JFactory::getDBO();
        $db->setQuery("SELECT * FROM `#__content` " . $params->query . " LIMIT 1");
        $article = $db->loadObject();

        if ($article) // if there isn't one (yet), don't do anything.
        {
            // Send the email
            emailArticle($article);
        }
        return Status::OK;
    }
}
?>
