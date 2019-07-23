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

## The Inspiration Behind Writing Content Notifier
Back in the Joomla 1.5 days, newsletter extensions were few and far between. I used one called VEMOD News Mailer, which had a batch
scheduler that alleviated the 250/hour sendmail limit my hosting provider imposed. It worked great, and the idea was to keep all the news
on the site, just send it to subscribers when it was posted, similar to subscribing to a blog. The problem with any PHP-based newsletter
extension is that, unless it comes with a handler that a cronjob can invoke, sites with low traffic volume don't generate enough events
to make the scheduler do its job in a reasonable amount of time. They also put the processing burden on the site front end, slowing down
the site users' interaction with the site.

This is what made me seek-out an external email list system that I could integrate with Joomla. I found Dada Mail which, although it has
its own web interface, behind that is a Perl-powered system with batch scheduling and subscriptions that can be stored in a MySQL
database. The database became the medium of exchange between the Joomla site and Dada Mail, and I just had to write a plugin to provide
the "glue," a common thread with these two plugins. The Dada Mail Subscriptions Plugin was originally written for Community Builder (CB)
because there were no custom profile fields in Joomla yet, nor did Joomla have the extensive and refined event system CB had.

The Dada Mail Subscriptions Plugin allows users to subscribe to individual Dada Mail lists, the email addresses for which you can
subscribe to Content Notifier subscription groups. All this "glue" is transparent to the site users, but together the two plugins
create a pretty powerful newsletter system.
