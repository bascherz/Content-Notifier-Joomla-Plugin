<?php
class pkg_contentnotifierInstallerScript
{
    function postflight($type, $parent)
    {
        // Enable both plugins.
        $db = JFactory::getDBO();
        $db->setQuery("UPDATE #__extensions SET enabled=1 WHERE element IN ('notifier','notifiertask')");
        $db->execute();
    }
}
?>