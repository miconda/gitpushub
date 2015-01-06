# GITPusHub

PHP Hub Script for Github Push Notifications

## Overview

The script is able to process Github webhook notification for GIT push events.

Features:
  * verify the secret key to authenticate the notification
  * do fetch in a local directory that has a mirror of Github repository
  * send customized email notification

## Installation

Deploy the script and its configuration file to your PHP-enabled web server.
It was tested with PHP v5.3 and Apache (it includes code for supporting Ngnix).

If the script is available at:

  * https://www.example.com/gitpushub/gitpushub.php

go to the webpage of your project on github.com, then Setting, Webhooks & Services.
Add a webhook, paste the URL to 'Payload URL' filed and set your secret key. The
Content-Type has to be 'application/json'. The event for which the hook has to be
triggered is 'Just the push event.'

Note: if the URL is https and you have an untrusted certificate, be sure to
disable the check of certificate in the page.

## Configuration:

Sample configuration file: gitpushub-config.php.sample

### Secret Key Verification

Set the same value you added on github.com inside gitpushub-config.php to the
variable $githubSecret.

### Email Notification

Set the address where to send the notification inside gitpushub-config.php to
the variable $notifyEmailAddress. More advanced filtering rules (by matching
on branch name) can be added via $notifyEmailRules.

The email format was guided based on Kamailio project custom git commit
notification. If the number of commits in a push is <=15, then one email is
sent for each commit. If there are more, then one single email for all commits
is generated - it includes less details for each commit. The limit to switch
between one email per commit and combined commits email can be set in config
via variable $gitCommitsSplitLimit.

For the individual email per commit, if the patch is less than 4096 bytes, then
it is included in the content of the notification message (easier to review
directly from email). The size limit can be changed via config variable
$attachPatchSizeLimit.


### GIT Repository Mirroring

First clone your repository with '--mirror' parameter, like:

```
mkdir -p /tmp/github/mirrors
cd /tmp/github/mirrors
git clone --mirror https://github.com/userid/projectid.git projectid
```

Set the path to the folder with the local GIT repository mirror inside
gitpushub-config.php to the variable $gitCloneDirectory:

```
$gitCloneDirectory='/tmp/github/mirrors/projectid';
```

## Author

Daniel-Constantin Mierla (@miconda)

Contact: via github

## License

GPLv2

## Contributions

Contributions are welcome, feel free to clone & do pull requests or submit
improvements & fixes via github tracker.

The initial author activity does not focus on PHP programming, therefore do not
expect high activity on this project. However, more like a whish list (for the
spare time or contributors):

  * make the content of email notification based on a templates (one for
  individual commit notification and one for combined commits notification)
  * become a hub for all github webhooks notifications (first version was coded
  and tested only with push notification)
