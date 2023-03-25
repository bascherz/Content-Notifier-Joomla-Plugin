<?php
/**
    Name:    CB Profile Notifier
    Version: 2.5
    Date:    December 2014
    Author:  Bruce Scherzinger
    Email:   bruce@joomlander.scherzinger.org
    URL:     http://joomlander.scherzinger.org
    Purpose: Community Builder tab to send admin nofitication emails when members change profiles.

    License: GNU/GPL
    This is free software. This version may have been modified pursuant
    to the GNU General Public License, and as distributed it includes or
    is derivative of works licensed under the GNU General Public License or
    other free or open source software licenses.
    (C) Bruce Scherzinger
*/
/** ensure this file is being included by a parent file */
if ( ! ( defined( '_VALID_CB' ) || defined( '_JEXEC' ) ) ) { die( 'Direct Access to this location is not allowed.' ); }

// These registrations handle administrator modifications to user settings.
$_PLUGINS->registerFunction('onBeforeUserUpdate', 'beforeUserUpdate', 'getProfileNotifyTab');
$_PLUGINS->registerFunction('onBeforeUpdateUser', 'beforeUpdateUser', 'getProfileNotifyTab');
$_PLUGINS->registerFunction('onAfterUserRegistration', 'afterUserRegistration', 'getProfileNotifyTab');

class getProfileNotifyTab extends cbTabHandler
{
    function __construct()
    {
        $this->cbTabHandler();
    }

    /**
    * This function checks to see which fields the user changed before the
    * changes actually get saved and notifies the admin via email.
    */
    function sendNotification(&$user, &$cbUser, $notify, $friendstoo=0)
    {
        global $ueConfig;
        
        // Get the application framework
        $mainframe = JFactory::getApplication();

        // Get plug-in parameters
        $params = $this->params;

        // Let's get the database
        $database = JFactory::getDBO();

        // Setup a mailer
	    $mailer = JFactory::getMailer();

        // See which fields we should be excluded
        $exclude = "_.avatar.password."; // this particular handler doesn't get called when the avatar changes
        if (!$params->get('name',0)) $exclude .= "name.";
        if (!$params->get('username',0)) $exclude .= "username.";
        if (!$params->get('email',0)) $exclude .= "email.";
        if (!$params->get('firstname',0)) $exclude .= "firstname.";
        if (!$params->get('middlename',0)) $exclude .= "middlename.";
        if (!$params->get('lastname',0)) $exclude .= "lastname.";
        $excludes = $params->get('excludes','');
        $excludes = str_replace(array(" ",","),array("","."),$excludes);
        $exclude .= $excludes.".";

        // Get message prefix and suffix
        $prefix = $params->get('email_prefix',"The user '[NAME]' just made the following profile changes:");
        $suffix = $params->get('email_suffix','');

        // Default message
        $message = $prefix;
        $change = $params->get('email_msg'," - [OPTION] changed from [OLD] to [NEW]");

        // Total settings changed (there may be none)
        $changes = 0;

        // Loop through all jos_users fields in the profile
        $query = "SELECT * FROM #__comprofiler_fields".
                 " WHERE `published`".
                 " AND (NOT `readonly`)".
                 " AND (`table` LIKE '%_users')".
                 " AND (`name` != 'lastVisitDate')".
                 " AND (`name` != 'registerDate')";
        $database->setQuery($query);
        $settings = $database->loadObjectList();
        foreach ($settings as $setting)
        {
            $name = $setting->name;
            if (!stripos($exclude,".$name."))
            {
                $new_setting = trim($user->{$name});
                $database->setQuery("SELECT $name FROM #__users WHERE id=$user->id");
                $old_setting = trim($database->loadResult());
                if ($new_setting != $old_setting)
                {
                    $title = getLangDefinition($setting->title);
                    if (!$new_setting) $new_setting = "{nothing}";
                    if (!$old_setting) $old_setting = "{nothing}";
                    if (substr($title,0,1) != '_')
                        $message .= str_replace(array('[OPTION]','[FIELD]','[OLD]','[NEW]','[SITE]','[USER]','[USERNAME]'),
                                                array($title,$setting->name,$old_setting,$new_setting,$mainframe->getCfg('sitename'),$user->name,$user->username),$change);
                    $changes++;
                }
            }
        }

        // Loop through all user-defined fields in the profile
        $query = "SELECT * FROM #__comprofiler_fields".
                 " WHERE `published`".
                 " AND (NOT `readonly`)".
                 " AND (`tablecolumns`!= '' )".
                 " AND (`tablecolumns`IS NOT NULL )".
                 " AND (`table` LIKE '%_comprofiler')".
                 " AND (`name` != 'NA')".
                 " AND (`name` != 'lastupdatedate')";
        $database->setQuery($query);
        $settings = $database->loadObjectList();
        foreach ($settings as $setting)
        {
            $name = $setting->name;
            if (!stripos($exclude,".$name."))
            {
                $new_setting = trim($cbUser->{$name});
                $database->setQuery("SELECT $name FROM #__comprofiler WHERE id=$user->id");
                $old_setting = trim($database->loadResult());
                $database->setQuery("SELECT type FROM #__comprofiler_fields WHERE name='$name'");
                $setting_type = $database->loadResult();
                if ($setting_type == 'date')
                {
                    $old_setting = substr($old_setting,0,10);
                    $new_setting = substr($new_setting,0,10);
                }
                if ($new_setting != $old_setting)
                {
                    // Don't list date changes from no date to nothing.
              	    if ($new_setting || $old_setting!="0000-00-00")
                    {
                        $changes++;
    	                $title = getLangDefinition($setting->title);
    	                if (!$new_setting) $new_setting = "{nothing}";
    	                if (!$old_setting) $old_setting = "{nothing}";
    	                if (substr($title,0,1) != '_')
    	                    $message .= str_replace(array('[OPTION]','[FIELD]','[OLD]','[NEW]','[SITE]','[USER]','[USERNAME]'),
    	                                            array($title,$setting->name,$old_setting,$new_setting,$mainframe->getCfg('sitename'),$user->name,$user->username),$change);
                    }
                }
            }
        }

        // If the user changed any settings, notify the admin.
        if ($changes)
        {
            // Get email addresses
            $from_name  = $params->get('email_from_name',$mainframe->getCfg('fromname'));
            $from_addr  = $params->get('email_from_addr',$mainframe->getCfg('mailfrom'));
            $admin_addr = $params->get('admin_addr'     ,$mainframe->getCfg('mailfrom'));

            // Allow admin address field to contain multiple addresses
            $admin_addr = str_replace(" ","",$admin_addr);  // strip all spaces
            

            // Who gets this notification?
            $recipient = array();
            $copyto = array();
            $bcc = array();
            switch ($notify)
            {
                case 1: // admin only
                    $recipient = explode(",",$admin_addr);
                    break;
                case 2: // user only
                    $recipient = $user->email;
                    break;
                case 3: // user and admin
                default:
                    $recipient = $user->email;
                    $bcc = explode(",",$admin_addr);
                break;
            }

            // See if we should copy user's connections also
            if ($friendstoo)
            {
                $query = "SELECT * FROM #__comprofiler_members AS conn ".
                         "JOIN #__users AS users ".
                         "ON users.id=conn.referenceid ".
                         "WHERE conn.memberid=$user->id";
                $database->setQuery($query);
                $connections = $database->loadObjectList();
                foreach ($connections as $connection)
                {
                    if ($friendstoo == "2")
                        $bcc[] = "$connection->email";
                    else
                        $copyto[] = "$connection->email";
                }
            }

            // Replace notice message placeholders
            $subject = $params->get('email_subject','[SITE] Member Profile Update');
            $subject = html_entity_decode(str_replace(array('[SITE]','[USER]','[USERNAME]'),array($mainframe->getCfg('sitename'),$user->name,$user->username),$subject),ENT_QUOTES);
            $message = html_entity_decode(str_replace(array('[SITE]','[USER]','[USERNAME]'),array($mainframe->getCfg('sitename'),$user->name,$user->username),$message.$suffix),ENT_QUOTES);

            // See if there are any user-specified placeholders and then substitute them in the message.
            $placeholders = $params->get('placeholders','');
            if ($placeholders)
            {
                // change all delimiters to spaces then create an array of placeholders
                $delimited = str_replace(array(" ","\r\n"),",",$placeholders);
                $fieldnames = explode(",", $delimited);

                // one by one, replace them in the message
                foreach ($fieldnames as $fieldname)
                {
                    // remove any cb_ prefix on the field name and create the keyword
                    $placeholder = "[".strtoupper(str_replace("cb_","",$fieldname))."]";
                    $message = html_entity_decode(str_replace($placeholder,$user->$fieldname,$message),ENT_QUOTES);
                }
            }
            
            // Get message format
            $format = (int) $params->get('email_format',0);

	        // Build e-mail message format
	        $mailer->setSender(array($from_addr, $from_name));
	        $mailer->addRecipient($recipient);
	        $mailer->setSubject($subject);
	        $mailer->setBody($message);
	        $mailer->addCC($copyto);
	        $mailer->addBCC($bcc);
	        $mailer->IsHTML($format);

            // Send notification email to administrator
            $mailer->Send();
        }
    }
    /**
    * This function handles backend events
    */
    function beforeUpdateUser(&$user, &$cbUser)
    {
        // Get plug-in parameters
        $params = $this->params;

        // See if notification of backend changes is requested
        $notify = (int) $params->get('backend_notify',0);
        if ($notify)
        {
            $this->sendNotification($user, $cbUser, $notify);
        }
    }

    /**
    * This function handles frontend events
    */
    function beforeUserUpdate(&$user, &$cbUser)
    {
        // Get plug-in parameters
        $params = $this->params;

        // See if notification of backend changes is requested
        $notify = (int) $params->get('frontend_notify',0);
        if ($notify)
        {
            $friendstoo = (int) $params->get('friends_notify',0);
            $this->sendNotification($user, $cbUser, $notify, $friendstoo);
        }
    }

    /**
    * This function handles user registration
    */
    function afterUserRegistration (&$user, &$cbUser)
    {
        // Get plug-in parameters
        $params = $this->params;
        $database = JFactory::getDBO();

        // Check for default field values and set them, if any.
        $defaults = explode("\n",$params->get('regdefaults',''));
        foreach ($defaults as $default)
        {
            $database->setQuery("UPDATE #__comprofiler SET $default WHERE user_id=$user->id");
            $result = $database->query();
        }

        // See if notification of backend changes is requested
        $notify = (int) $params->get('register_notify',0);
        if ($notify) {
            $this->sendNotification($user, $cbUser, $notify, 0);
        }
    }
}

?>