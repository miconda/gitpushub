<?php

/**
 * Script to syncronize local repository mirror and notify upon a commit on github project
 *    - keep an up to date clone in real time
 *    - customized email notification (trying to keep same format or better compared with old kamailio.org notifications)
 * Author: Daniel-Constantin Mierla <miconda@gmail.com>
 * License: GPLv2
 */

if(file_exists('gitpushub-config.php')) {
	include 'gitpushub-config.php';
}

function send_email_notification($jdoc)
{
	global $notifyEmailAddress;
	global $notifyEmailRules;
	global $debugLevel;
	global $gitCommitsSplitLimit;
	global $attachPatchSizeLimit;

	$nrcommits = 0;

	foreach ($jdoc->commits as $gcommit) {
		$nrcommits++;
	}
	if($debugLevel>0) error_log('kamailio github notify - number of commits: ' . $nrcommits);
	if($nrcommits<=0) {
		return;
	}

	$mbranch = $jdoc->ref;
	if (0 === strpos($mbranch, 'refs/heads/')) {
		$mbranch = substr($mbranch, 11);
	}

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
					if(preg_match($eRule['expr'], $mbranch)) {
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

	if($nrcommits<=$gitCommitsSplitLimit) {
		// one email per commit
		foreach ($jdoc->commits as $gcommit) {
			$msbjid = substr($gcommit->id, 0, 8);
			$mfline = strtok($gcommit->message, "\n");
			$msubject = "git:" . $mbranch . ":" . $msbjid . ": " . $mfline;
			$mbody  = "Module: kamailio\n";
			$mbody .= "Branch: " . $mbranch . "\n";
			$mbody .= "Commit: " . $gcommit->id . "\n";
			$mbody .= "URL: " . $gcommit->url . "\n\n";
			$mbody .= "Author: " . $gcommit->author->name . " <" . $gcommit->author->email . ">\n";
			$mbody .= "Committer: " . $gcommit->committer->name . " <" . $gcommit->committer->email . ">\n";
			$mbody .= "Date: " . $gcommit->timestamp . "\n\n";
			$mbody .= $gcommit->message;
			$mbody .= "\n\n---\n\n";
			foreach($gcommit->added as $fcpath) {
				$mbody .= "Added: " . $fcpath . "\n";
			}
			foreach($gcommit->modified as $fcpath) {
				$mbody .= "Modified: " . $fcpath . "\n";
			}
			foreach($gcommit->removed as $fcpath) {
				$mbody .= "Removed: " . $fcpath . "\n";
			}
			$mbody .= "\n---\n\n";
			$mbody .= "Diff:  " . $gcommit->url . ".diff\n";
			$mbody .= "Patch: " . $gcommit->url . ".patch\n";
			$mcdiff = file_get_contents($gcommit->url . ".diff");
			$mcdifflen = strlen($mcdiff);
			if($mcdifflen>0 && $mcdifflen<=$attachPatchSizeLimit) {
				$mbody .= "\n---\n\n";
				$mbody .= $mcdiff;
			}

			$mheaders = "X-Mailer: Git\r\nFrom: " . $gcommit->committer->name . " <" . $gcommit->committer->email . ">\r\n";
			foreach($emailsList as $emailTo) {
				mail($emailTo, $msubject, $mbody, $mheaders);
			}
		}
		return true;
	}

	// nrcommits >= $gitCommitsSplitLimit - one email for all commits
	$msubject = "git: new commits in branch " . $mbranch;
	$mheaders = "X-Mailer: Git\r\nFrom: " . $jdoc->head_commit->committer->name . " <" . $jdoc->head_commit->committer->email . ">\r\n";
	foreach ($jdoc->commits as $gcommit) {
		$mbody .= "- URL:  " . $gcommit->url . "\n";
		$mbody .= "Author: " . $gcommit->author->name . " <" . $gcommit->author->email . ">\n";
		$mbody .= "Date:   " . $gcommit->timestamp . "\n\n";
		$mbody .= $gcommit->message;
		$mbody .= "\n\n";
	}
	foreach($emailsList as $emailTo) {
		mail($emailTo, $msubject, $mbody, $mheaders);
	}
	return true;
}

/* get all headers function for ngnix */
if (!function_exists('getallheaders')) 
{ 
	function getallheaders() 
	{
		$hdrs = '';
		foreach ($_SERVER as $name => $value)  { 
			if (substr($name, 0, 5) == 'HTTP_') { 
				$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5))))); 
				$hdrs[$name] = $value; 
			} else if ($name == "CONTENT_TYPE") { 
				$hdrs["Content-Type"] = $value; 
			} else if ($name == "CONTENT_LENGTH") { 
				$hdrs["Content-Length"] = $value; 
			} /* else {
				$hdrs[$name] = $value; 
			} */ 
		} 
		return $hdrs; 
	}
}

/* *** main code part *** */

// github webhooks push notifications - get the headers and the payload
$headers = getallheaders();
if(empty($headers)) {
	if($debugLevel>0) error_log('kamailio github notify - cloning activity: no headers');
	exit;
}

$payload = file_get_contents('php://input');
if(empty($payload)) {
	if($debugLevel>0) error_log('kamailio github notify - cloning activity: no payload');
	exit;
}

if($debugLevel>1) {
	// write the headers and payload to the file for troubleshooting 
	if(!empty($notifyLogFile)) {
		$allheaders = '';
		foreach ($headers as $name => $value) {
			$allheaders .= $name . ':' . $value . '\n';
		}
		file_put_contents($notifyLogFile, $allheaders . '\n' . $payload . "\n\n-----\n\n", FILE_APPEND | LOCK_EX);
	}

	// write the payload to the syslog for troubleshooting 
	if($debugLevel>2) error_log('kamailio github notify - cloning activity: payload [[' . $payload . ']]');
}

/* checking the secret signature */
if(!empty($githubSecret)) {
	// get the header with security signature
	$hubSignature = $headers['X-Hub-Signature'];
	if(empty($hubSignature)) {
		$hubSignature = $headers['x-hub-signature'];
	}

	if(empty($hubSignature)) {
		if($debugLevel>0) error_log('kamailio github notify - cloning activity: no signature header');
		exit;
	}

	// check if the signature is valid
	list($algo, $hash) = explode('=', $hubSignature, 2);
	$payloadHash = hash_hmac($algo, $payload, $githubSecret);
	if ($hash !== $payloadHash) {
		if($debugLevel>0) error_log('kamailio github notify - bad notification secret token');
		exit;
	}
}

if(!empty($gitCloneDirectory)) {
	// fetch remote github to syncronize local clone repository
	$output = shell_exec( 'cd ' . $gitCloneDirectory . ' && git fetch 2>&1' );

	// write the output of sync command to web server log
	error_log('kamailio github notify - cloning activity: output [[' . $output . ']]');
}

if(!empty($notifyEmailAddress) || !empty($notifyEmailRules)) {
	// send notification
	$content = utf8_encode($payload); 
	$data = json_decode($content);

	if($data) {
		send_email_notification($data);
	} else {
		if($debugLevel>0) error_log('kamailio github notify - no json payload');
	}
}

?>
