<?php

/**
 * Script to notify via email using details of a on github project
 *    - commit hash id must be provided as parameter
 *    - branch name has to be provided as 2nd parameter (if not, it is master)
 *    - details of the commit are retrieved via github API
 *    - customized email notification (starting point was trying to keep same format
 *      or even better compared with old kamailio.org notifications)
 * Author: Daniel-Constantin Mierla <miconda@gmail.com>
 * License: GPLv2
 */

if(file_exists('gitpushub-config.php')) {
	include 'gitpushub-config.php';
}

function send_commit_email_notification($jdoc, $mbranch)
{
	global $notifyEmailAddress;
	global $notifyEmailRules;
	global $debugLevel;
	global $gitCommitsSplitLimit;
	global $attachPatchSizeLimit;
	global $projectName;

	/* discover the list of email addresses to send notifications */
	$emailsList = array ();
	$matchBranchFound = 0;
	if(empty($notifyEmailRules) || !is_array($notifyEmailRules)) {
		$emailsList[] = $notifyEmailAddress;
		$matchBranchFound = 1;
	} else {
		foreach($notifyEmailRules as $eRule) {
			if($eRule['match'] == 'str') {
				foreach($eRule['expr'] as $mExpr) {
					if($mExpr == $mbranch) {
						$emailsList = $eRule['sendto'];
						$matchBranchFound = 1;
						break;
					}
				}
			} else if($eRule['match'] == 'regex') {
				foreach($eRule['expr'] as $mExpr) {
					if(preg_match($mExpr, $mbranch)) {
						$emailsList = $eRule['sendto'];
						$matchBranchFound = 1;
						break;
					}
				}
			}
			if($matchBranchFound == 1) break;
		}
	}
	if(empty($emailsList)) {
		error_log('no email notification configured for branch: '. $mbranch);
		return;
	}

	$msbjid = substr($jdoc->sha, 0, 8);
	$mfline = strtok($jdoc->message, "\n");
	$msubject = "git:" . $mbranch . ":" . $msbjid . ": " . $mfline;
	$mbody  = "Module: " . $projectName . "\n";
	$mbody .= "Branch: " . $mbranch . "\n";
	$mbody .= "Commit: " . $jdoc->sha . "\n";
	$mbody .= "URL: " . $jdoc->html_url . "\n\n";
	$mbody .= "Author: " . $jdoc->author->name . " <" . $jdoc->author->email . ">\n";
	$mbody .= "Committer: " . $jdoc->committer->name . " <" . $jdoc->committer->email . ">\n";
	$mbody .= "Date: " . $jdoc->author->date . "\n\n";
	$mbody .= $jdoc->message;
	$mbody .= "\n\n---\n\n";
	$mbody .= "Diff:  " . $jdoc->html_url . ".diff\n";
	$mbody .= "Patch: " . $jdoc->html_url . ".patch\n";
	$mcdiff = file_get_contents($jdoc->html_url . ".diff");
	$mcdifflen = strlen($mcdiff);
	if($mcdifflen>0 && $mcdifflen<=$attachPatchSizeLimit) {
		$mbody .= "\n---\n\n";
		$mbody .= $mcdiff;
	}

	$mheaders = "X-Mailer: Git\r\nFrom: " . $jdoc->committer->name . " <" . $jdoc->committer->email . ">\r\n";
	foreach($emailsList as $emailTo) {
		mail($emailTo, $msubject, $mbody, $mheaders);
		if($debugLevel>0) error_log($projectName . ' sending notification to ' . $emailTo . ' for commit: [' . $jdoc->sha . ']');
	}
	return true;
}

/* *** main code part *** */

if($debugLevel>0) error_log($projectName . ' github commit notify');

if(empty($gitAPICommitURL)) {
	error_log($projectName . ' github commit notify - no API commit URL');
	exit;
}

if($argc<2 || empty($argv)) {
	error_log($projectName . ' github commit notify - no commit id parameter');
	exit;
}

$cBranch = 'master';
$cHashID = $argv[1];
if($argc>=3) {
	$cBranch = $argv[2];
}

$httpOptions = array(
  'http'=>array(
    'method'=>"GET",
    'header'=>"Accept-Language: en\r\n" .
              "User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:13.0) Gecko/20100101 Firefox/13.0.1\r\n"
  )
);

$httpContext = stream_context_create($httpOptions);

$payload = file_get_contents($gitAPICommitURL . '/' . $cHashID, false, $httpContext);
if(empty($payload)) {
	if($debugLevel>0) error_log($projectName . ' github commit notify - cloning activity: no payload');
	exit;
}

if($debugLevel>1) {
	// write the headers and payload to the file for troubleshooting 
	if(!empty($notifyLogFile)) {
		file_put_contents($notifyLogFile, '\n' . $payload . "\n\n-----\n\n", FILE_APPEND | LOCK_EX);
	}

	// write the payload to the syslog for troubleshooting 
	if($debugLevel>2) error_log($projectName . ' github commit notify - cloning activity: payload [[' . $payload . ']]');
}

if(!empty($notifyEmailAddress) || !empty($notifyEmailRules)) {
	// send notification
	$content = utf8_encode($payload); 
	$data = json_decode($content);

	if($data) {
		send_commit_email_notification($data, $cBranch);
	} else {
		if($debugLevel>0) error_log($projectName . ' github commit notify - no json payload');
	}
} else {
	if($debugLevel>0) error_log($projectName . ' github commit notify - no notification address or rule');
}

?>
