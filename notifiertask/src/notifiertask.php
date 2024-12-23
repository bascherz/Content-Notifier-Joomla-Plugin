<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content Notifier Task
 * @version     5.6
 * @copyright   Copyright (C) 2018-2024 Bruce Scherzinger. All rights reserved.
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
            $currdate = date('Y-m-d H:i:s');
            $publishdate = substr($article->publish_up,0,10);

            // This handler sends a notification for a published article that is preconditioned to email.
            // Note that the article must not just be published but its publish date must have passed.
            if ($article->state == 1 && $currdate >= $publishdate)
            {
                // If there is no send-mode field or the user did not specify 'Do NOT Send', we can proceed.
                if (!$emailmode || $emailmode->Value != 'Never')
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
            $query = "SELECT v.value as `Value`, f.id as `Field`, f.default_value as `Default`, c.id as `Item`
                      FROM #__fields f 
                      LEFT JOIN #__fields_values v
                      ON f.id=v.field_id
                      LEFT JOIN #__content c
                      ON c.id=v.item_id 
                      WHERE c.id=".$article->id." AND f.name LIKE '%send-mode'";
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
            // If 'Send Just Once' was specified, reset the parameter to the default value.
            if ($fieldinfo->Value == 'Once')
            {
                // Get a database object
                $db = JFactory::getDBO();

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

            // Get the parameters for the Content Notifier plugin
            $db->setQuery("SELECT params FROM #__extensions WHERE folder='content' AND element='notifier'");
            
            // Get the plug-in parameters
            $params = json_decode($db->loadResult(),false,20);

            // See if we need to send this to the article author, the group address, or both.
            // If both, make the author the primary.
            $address = "";
            $author  = "";
            $copied  = "";

            // Get article author info
            $db->setQuery("SELECT * FROM #__users u JOIN #__comprofiler c WHERE u.id=c.id AND u.id=".$article->created_by);
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
                $from_name = $params->from_name;
                if (!$from_name) $from_name = $mainframe->get('fromname');
                $from_addr = $params->from_addr;
                if (!$from_addr) $from_addr = $mainframe->get('mailfrom');

                // Get article category name
                $db->setQuery("SELECT * FROM #__categories WHERE id=$article->catid");
                $category = $db->loadObject();
                $cat_alias = $category->alias;
                $cat_title = $category->title;

                // Build article links (Note: only works with Joomla 3.9 or later).
                $site_url = $params->get('siteurl');
                if ($site_url == "")
                    $site_url = rtrim(JURI::root(),'/');

                // Build the relative link. Remove any reference to the backend.
                $rel_link = JRoute::_(ContentHelperRoute::getArticleRoute($article->id,$article->catid));
                $rel_link = preg_replace('#^/administrator#', '', $rel_link);

                // See if we're using SEF URLs and convert if so.
                $routerOptions = [];
                $sef = $mainframe->get('sef');
                if ($sef)
                {
                    $routerOptions['mode'] = 1;
                    $router   = JRouter::getInstance('site', $routerOptions);
                    $rel_link = $router->build($rel_link)->toString();
                }

                // Remove any non-URL reference to the CLI.
                if (strpos($rel_link,"/component") !== false)
                    $rel_link = substr($rel_link,0,strpos($rel_link,"/component"));

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
                          '[CREATED]',
                          '[AREA]'),
                    array($mainframe->get('sitename'),
                          $category->title,
                          'Article Sent',
                          $article->title,
                          $created_by->name,
                          $created_by->firstname,
                          $abs_link,
                          $rel_link,
                          $article->introtext,
                          $article->fulltext,
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
                    array($mainframe->get('sitename'),
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
