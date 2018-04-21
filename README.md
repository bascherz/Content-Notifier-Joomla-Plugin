# Content-Notifier-Joomla-Plugin
Allows the site admin to "subscribe" any number of email addresses to receive emails when articles in selected categories are
published or modified.

## Admininstrator Configuration
You can create up to 1000 subscription groups. Each subscription group associates one or more article categories with one or
more email addresses. Once the subscription group is enabled, any time an article is published or a published article is saved
to one of those categories, an email is sent to the list of subscribed addresses. The email subject and body can contain placeholders
for things such as the article title, category, article intro and full text, link to the article on the site, and modification date.
Some of these tags only work in the email subject.

## Concept of Operation
The idea behind this plugin is not to give your site users the ability to subscribe to individual article categories but, rather,
to use an external email list system to give the administrator the ability to configure which email list(s) receive what content.
User subscription, then, lies within the email list system and this plugin just provides the "glue" between Joomla and that email
system. Of course, you can also just subscribe specific individual email addresses to a group. It's all up to you where to apply the glue.
