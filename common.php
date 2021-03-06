<?php

/**
 * Autonotify plugin by Andy Martin, Stanford University
 *
 * Substantially revised on 2016-03-01 to support saving to the log and additional features
 */

error_reporting(E_ALL);

class AutoNotify {

    const PluginName = "AutoNotify2 Spectrum";
    // Message Parameters
    public $to, $from, $subject, $message;

    // Other properties
    public $config, $triggers, $pre_det_url, $post_det_url;

    // DET Project Parameters
    public $project_id, $instrument, $record, $redcap_event_name, $event_id, $redcap_data_access_group, $instrument_complete;

    // Instantiate the object with the project_id
    public function __construct($project_id) {
        if ($project_id) {
            $this->project_id = intval($project_id);
        } else {
            logIt("Called outside of context of project", "ERROR");
            exit();
        }
    }

    // Adds information from the DET post into the current object
    public function loadDetPost() {
        $this->project_id = voefr('project_id');
        $this->instrument = voefr('instrument');
        $this->record = voefr('record');
        $this->redcap_event_name = voefr('redcap_event_name');
        if (REDCap::isLongitudinal()) {
            $events = REDCap::getEventNames(true,false);
            $this->event_id = array_search($this->redcap_event_name, $events);
        } else {
            global $Proj;
            $this->event_id = $Proj->firstEventId;
        }
        $this->redcap_data_access_group = voefr('redcap_data_access_group');
        $this->instrument_complete = voefr($this->instrument.'_complete');
        
    }

    // Converts old autonotify configs that used the url into new ones that use the log table
    public function checkForUpgrade() {
        // To be back-compatible, we need to be able to convert old autonotify calls into the newer format.

        global $data_entry_trigger_url;
        $det_qs = parse_url($data_entry_trigger_url, PHP_URL_QUERY);

        parse_str($det_qs,$params);
        if (!empty($params['an'])) {
            // Step 1:  We have identified an old DET-based config
            logIt("Updating older DET url: $data_entry_trigger_url", "DEBUG");
            $an = $params['an'];
            $old_config = self::decrypt($an);

            // Step 2:  Save the updated autonotify object
            $this->config = $old_config;
            $this->saveConfig();
            $log_data = array(
                'action' => 'AutoNotify2 config moved from querystring to log',
                'an' => $an,
                'config' => $old_config
            );
            REDCap::logEvent(AutoNotify::PluginName . " Update", "Moved querystring config to log table", json_encode($log_data));

            // Step 3:  Update the DET URL to be plain-jane
            self::isDetUrlNotAutoNotify(true);
        }
    }

    // Scans the log for the latest autonotify configuration
    public function loadConfig() {
//		logIt(__FUNCTION__, "DEBUG");

        // Convert old querystring-based autonotify configurations to the log-based storage method
        $this->checkForUpgrade();

        // Load from the log
		$log_event_table = REDCap::getLogEventTable($this->project_id);
        $sql = "SELECT l.sql_log, l.ts
			FROM $log_event_table l WHERE
		 		l.project_id = " . intval($this->project_id) . "
			
			AND l.description = '" . AutoNotify::PluginName . " Config'
			ORDER BY ts DESC LIMIT 1";
			
			//echo "project ID: $project_id";
			//echo "<br/>";
			//echo $sql;
        $q = db_query($sql);
//		logIt(__FUNCTION__ . ": sql: $sql","DEBUG");
        if (db_num_rows($q) == 1) {
            // Found config!
            $row = db_fetch_assoc($q);
            $this->config = json_decode($row['sql_log'], true);
            if (isset($this->config['triggers'])) {
                $this->triggers = json_decode(htmlspecialchars_decode($this->config['triggers'], ENT_QUOTES), true);
            }
            logIt(__FUNCTION__ . ": Found version with ts ". $row['ts'],"INFO");
            return true;
        } else {
            // No previous config was found in the logs
			//logIt(__FUNCTION__ . ": Sql: $sql", "INFO");
            logIt(__FUNCTION__ . ": No config saved in logs for this project", "INFO");
            return false;
        }
    }

    // Write the current config to the log
    public function saveConfig() {
        $sql_log = json_encode($this->config);
        REDCap::logEvent(AutoNotify::PluginName . " Config", "Configuration Updated", $sql_log);
        logIt(__FUNCTION__ . ": Saved configuration", "INFO");

        // Update the DET url if needed
        self::isDetUrlNotAutoNotify(true);
    }

    // Execute the loaded DET.  Returns false if any errors
    public function execute() {
//		logIt(__FUNCTION__, "DEBUG");

        // Check for Pre-DET url
        self::checkPreDet();

        // Decode the triggers from the config
        $triggers = json_decode(htmlspecialchars_decode($this->config['triggers'], ENT_QUOTES), true);

        // Loop through each notification
        foreach ($triggers as $i => $trigger) {
            $logic = $trigger['logic'];
            $title = $trigger['title'];
            $enabled = $trigger['enabled'];
			$role = $trigger['role'];
			$dagonly = $trigger['dagonly'];
			$recordlink = $trigger['recordlink'];

            $scope = isset($trigger['scope']) ? $trigger['scope'] : 0;  // Get the scope or set to 0 (default)

            if (!$enabled) {
//				logIt(__FUNCTION__ . ": The current trigger ($title) is not set as enabled - skipping", "DEBUG");
                continue;
            }

            if (empty($title)) {
                logIt("Cannot process alert $i because it has an empty title: " . json_encode($trigger),"ERROR");
                continue;
            }

            // Append current event prefix to lonely fields if longitidunal
            if (REDCap::isLongitudinal() && $this->redcap_event_name) $logic = LogicTester::logicPrependEventName($logic, $this->redcap_event_name, $Proj);

            if (!empty($logic) && !empty($this->record)) {
                if (LogicTester::evaluateLogicSingleRecord($logic, $this->record)) {
                    // Condition is true, check to see if already notified
                    if (!self::checkForPriorNotification($title, $scope)) {
                        $result = self::notify($title, $trigger);
                        logIt("{$this->record}: Notified ($title) / " . ($result ? 'Success' : 'Failure') );
                    } else {
                        // Already notified
                        logIt("{$this->record}: [$title] - Already notified");
                    }
                } else {
                    // Logic did not pass
                    //logIt("Logic: $logic / Record: " . $this->record . " / Project: " . $this->project_id, "DEBUG");
                    logIt("{$this->record}: [$title] - Logic false");
                }
            } else {
                // object missing logic or record
                logIt("{$this->record}: [$title] - Unable to execute: missing logic or record");
            }
        }

        // Check for Post-DET url
        self::checkPostDet();
    }


    // Used to test the logic and return an appropriate image
    public function testLogic($logic) {

//		logIt('Testing record '. $this->record . ' with ' . $logic, "DEBUG");
        if (LogicTester::isValid($logic)) {

            // Append current event details
            if (REDCap::isLongitudinal() && $this->redcap_event_name) {
                $logic = LogicTester::logicPrependEventName($logic, $this->redcap_event_name, $Proj);
                logIt(__FUNCTION__ . ": logic updated with selected event as " . $logic, "INFO");
            }

            if (LogicTester::evaluateLogicSingleRecord($logic, $this->record)) {
                $result = RCView::img(array('class'=>'imgfix', 'src'=>'accept.png'))." True";
            } else {
                $result = RCView::img(array('class'=>'imgfix', 'src'=>'cross.png'))." False";
            }
        } else {
            $result = RCView::img(array('class'=>'imgfix', 'src'=>'error.png'))." Invalid Syntax";
        }
        return $result;
    }

    // Check if there is a pre-trigger DET configured - if so, post to it.
    public function checkPreDet() {
        if ($this->config['pre_script_det_url']) {
            self::callDets($this->config['pre_script_det_url']);
        }
    }

    // Check if there is a post-trigger DET configured - if so, post to it.
    public function checkPostDet() {
        if ($this->config['post_script_det_url']) {
            self::callDets($this->config['post_script_det_url']);
        }
    }

    // Takes a pipe-separated list of urls and calls them as DETs
    private function callDets($urls) {
        $dets = explode('|',$urls);
        foreach ($dets as $det_url) {
            $det_url = trim($det_url);
            http_post($det_url, $_POST, 10);
        }
    }

    // Now that we're not storing the DET in the query string - this simply needs to be the url to this plugin
    public function getDetUrl() {
        // Build the url of the page that called us
        $isHttps = isset($_SERVER['HTTPS']) AND !empty($_SERVER['HTTPS']) AND $_SERVER['HTTPS'] != 'off';

        // Force http for certain domains
        global $http_only;
        foreach ($http_only as $site) {
            if (strpos($_SERVER['HTTP_HOST'], $site) !== false) $isHttps = false;
        }

        // Build URL
        $url = 'http' . ($isHttps ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

        // Remove query string from DET url
        $url = preg_replace('/\?.*/', '', $url);

        return $url;
    }

    // If there is a different DET url configured in the project, it will return it, otherwise returns false
    public function isDetUrlNotAutoNotify($update = false) {
        global $data_entry_trigger_url;
        $det_url = self::getDetUrl();
        if ($data_entry_trigger_url !== $det_url) {
            logIt("DETS ARE DIFFERENT - DET: [$data_entry_trigger_url] / SELF DET: [$det_url]", "DEBUG");
            if ($update) {
                // Force the update
                $sql = "update redcap_projects set data_entry_trigger_url = '".prep($det_url)."' where project_id = " . intval($this->project_id) . " LIMIT 1;";
                db_query($sql);
                REDCap::logEvent(AutoNotify::PluginName . " Update", "Converted DET Url to $det_url (see log table for old value)", $data_entry_trigger_url);
                $data_entry_trigger_url = $det_url;
            }
            return $det_url;
        } else {
            return false;
        }
    }

    // Notify and log
    public function notify($title, $trigger) {
        global $redcap_version;
        $dark = "#800000";	//#1a74ba  1a74ba
        $light = "#FFE1E1";		//#ebf6f3
        $border = "#800000";	//FF0000";	//#a6d1ed	#3182b9
        // Run notification
        $url = APP_PATH_WEBROOT_FULL . "redcap_v{$redcap_version}/" . "DataEntry/index.php?pid={$this->project_id}&page={$this->instrument}&id={$this->record}&event_id={$this->event_id}";

        // Prepare message
        $email = new Message();
		
		// ARC 09/11/17 Extend the plugin to send to all users in a role
		// Pipe values from 'to' field into a string
		$toListStr = self::pipeThis($trigger['to']);
		
		// Array from the "to" field
		if ($toListStr){
			$toListArr = array_map('trim', explode(',', $toListStr));
		}
		
		// Get role drop down value
		$selectedRole = self::pipeThis($trigger['role']);

		// Check if an option has been selected from the list of roles
		if ($selectedRole > 0) {
			
			// AMC 08/12/17 Extend the plugin to allow user to select whether to send email to all users in role, or only users in same DAG as record
			// Get DAGOnly value
			$DAGOnly = self::pipeThis($trigger['dagonly']);
			
			// Get the DAG for the current record
			$currentDAG="";
			$currentDAG = Records::getRecordGroupId($this->project_id, $this->record);
			
			// Cycle through users with rights
			$rights = REDCap::getUserRights();
			$usernames ="";//debug
			$usersChecked=0;
			$usersInRole=0;
			$usersInDAG=0;
			$usersAndRecordNotInDAG=0;
			
			//get list of suspended users in this project
			$suspendedUsers=User::getSuspendedUsers();
						
			foreach ($rights as $username=>$userRights) {
				
				$userEmail = "";
				$userDAG = "";
				$userDAG = $userRights['group_id'];

				// User is in selected role (also user rights haven't expired) (23/04/18 suspended users?)
				if (!in_array($username, $suspendedUsers) && $userRights['role_id'] == $selectedRole && ($userRights['expiration'] == "" || $userRights['expiration'] > TODAY)) {
					$usersInRole++;
					//If DAG-only option is selected (only send email to users in same DAG as record)
					if ($DAGOnly == "0"){	

							// User is in same DAG as record or record isn't in DAG and user isn't
						if (($userDAG == $currentDAG) or ($userDAG == "" and $currentDAG == "")){
						$usersInDAG++;
							// Get email address from username
							$userEmail = $this->getEmailByUsername($username);
						
							// Add if email isn't already in the list (and there's a value in $userEmail)
							if ($userEmail and !in_array($userEmail, $toListArr)) {
								$toListArr[] = $userEmail;
							}
						}
						
					} else { //Send to users in this role in all dags
						$usersAndRecordNotInDAG++;
						// Get email address from username
						$usernames.=$username;
						$userEmail = $this->getEmailByUsername($username);
						
						// Add if email isn't already in the list (and there's a value in $userEmail)
						if ($userEmail and !in_array($userEmail, $toListArr)) {
							$toListArr[] = $userEmail;
						}
					}
				}
				$usersChecked++;
			}
		}

		// ARC 19/04/2018 Get the body text, and append a link to the text if checkbox is ticked
		$bodyText = self::pipeThis($trigger['body']);
		
		$linkChecked = self::pipeThis($trigger['recordlink']);
		if ($linkChecked == 1){
			
			// Build the link to the record
			$linkURL = "https://". SERVER_NAME . "/" . APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . $this->project_id . "&id=" . $this->record . "&page=" . $this->instrument;
			if (REDCap::isLongitudinal()) {
				$linkURL .= "&event_id=" . $this->event_id;
			}
			$bodyText .= "<br /><br /><a href='" . $linkURL . "'>Click here</a> to view this record.";
		}
		
		// Create a final string from items in the array
		$finalList = "";
		foreach($toListArr as $arrayID => $arrayEmail){
		   
			if ($finalList) {
				// Add it
				$finalList = $finalList . "," . $arrayEmail;
			} else {
				// Start it
				$finalList = $arrayEmail;
			}   
		}

		// Send to the new list
		$email->setTo($finalList);

        //$email->setTo(self::pipeThis($trigger['to']));
		
        $email->setBcc(self::pipeThis($trigger['bcc']));
        $email->setFrom(self::pipeThis($trigger['from']));
		//CW change 23/02/2017 to try to fix html tags showing in subject line - doen't work
		//AC change 24/02/2017 to try to fix html tags showing in subject line - DOES work- mwahahaha!
		//uses strip_tags to strip out the HTML span element introduced by the Redcap piping::replacevariable method.
		$subject_text = strip_tags(self::pipeThis($trigger['subject']));
		
		$email->setSubject(self::pipeThis($subject_text));
        //$email->setSubject(self::pipeThis($trigger['subject']));
		
		$email->setBody($bodyText);
		
        // Send Email
        if (!$email->send()) {
            error_log('Error sending mail: '.$email->getSendError().' with '.json_encode($email));
            REDCap::logEvent(
                AutoNotify::PluginName . " Error", "Error sending AutoNotify2 Email: " . $title . "to role $selectedRole with dagonly: $DAGOnly to users $usernames with addresses: $finalList. Users checked: $usersChecked, users in role: $usersInRole, users in DAG: $usersInDAG, users not in DAG, but record not in DAG either: $usersAndRecordNotInDAG ",
                $email->getSendError() . " with " . json_encode($email),
                $this->record,
                $this->event_id
            );
            return false;
        }

        // Add Log Entry
        $data_values = "==> AutoNotify2 Rule Fired\n".
            "title,$title\n".
            "record,{$this->record}\n" .
						
            (REDcap::isLongitudinal() ? "event,{$this->redcap_event_name}" : "");
        REDCap::logEvent('AutoNotify2 Alert',$data_values,"",$this->record, $this->event_id);
        return true;
    }

    // A wrapper for piping values...
    public function pipeThis($input) {
        $result = Piping::replaceVariablesInLabel($input, $this->record, $this->event_id, null, false, $this->project_id, false);
        return $result;
    }

    // Go through logs to see if there is a prior alert for this record/event/title
    // Scope 0 is record/event match, scope 1 is record only
    public function checkForPriorNotification($title, $scope=0) {
        if (!REDCap::isLongitudinal()) $scope = 1; // Record match only is sufficient
		$log_event_table = REDCap::getLogEventTable($this->project_id);
        $sql = "SELECT l.data_values, l.ts
			FROM $log_event_table l WHERE 
		 		l.project_id = {$this->project_id}
			
			AND l.description = 'AutoNotify2 Alert';";
        $q = db_query($sql);

        while ($row = db_fetch_assoc($q)) {
            $pairs = parseEnum($row['data_values']);
            if (
                $pairs['title'] == $title &&
                $pairs['record'] == $this->record &&
                ( $scope == 1 OR $pairs['event'] == $this->redcap_event_name)
            )
            {
                $date = substr($row['ts'], 4, 2) . "/" . substr($row['ts'], 6, 2) . "/" . substr($row['ts'], 0, 4);
                $time = substr($row['ts'], 8, 2) . ":" . substr($row['ts'], 10, 2);

                // Already triggered
                logIt("Trigger previously matched on $date $time / Row: ". json_encode($row) . " / Pairs: " . json_encode($pairs), "DEBUG");
                return true;
            }
        }
        return false;
    }

    // Takes an encoded string and returns the array representation of the object
    public function decrypt($code) {
        $template_enc = rawurldecode($code);
        $json = decrypt_me($template_enc);	//json string representation of parameters
        $params = json_decode($json, true);	//array representation
        return $params;
    }

    // Takes an array and returns the encoded string
    public function encode($params) {
        $json = json_encode($params);
        $encoded = encrypt($json);
        return rawurlencode($encoded);
    }

    // Renders the triggers portion of the page, or an empty trigger if new
    public function renderTriggers() {
        $html = "<div id='triggers_config'>";
        if (isset($this->triggers)) {
            foreach ($this->triggers as $i => $trigger) {
                $html .= self::renderTrigger($i, $trigger); //['title'], $trigger['logic'], $trigger['test_record'], $trigger['test_event'], $trigger['enabled'], $trigger['scope']);
            }
        } else {
            $html .= self::renderTrigger(1,'');
        }
        $html .= "</div>";
        return $html;
    }
	
	public function getEmailByUsername($username){
		
		$sql = "SELECT user_email FROM redcap_user_information where username = ('".$username."')";
		
		$q = db_query($sql, null, MYSQLI_USE_RESULT);
		$row = db_fetch_assoc($q);

		return $row['user_email'];
		
	}

    // Render an individual trigger (also called by Ajax to add a new trigger to the page)
    public function renderTrigger($id, $trigger) {
        //, $title = '', $logic = '', $test_record = null, $test_event = null, $enabled = 1, $scope=0, $to='', $bcc='', $from='',$subject='',$body='') {
        $title = isset($trigger['title']) ? $trigger['title'] : '';
        $logic = isset($trigger['logic']) ? $trigger['logic'] : '';
        $test_record = isset($trigger['test_record']) ? $trigger['test_record'] : null;
        $test_event = isset($trigger['test_event']) ? $trigger['test_event'] : null;
        $enabled = isset($trigger['enabled']) ? $trigger['enabled'] : 1;
        $scope = isset($trigger['scope']) ? $trigger['scope'] : 0;
        $to = isset($trigger['to']) ? $trigger['to'] : '';
		$role = isset($trigger['role']) ? $trigger['role'] : null;
		$dagonly = isset($trigger['dagonly']) ? $trigger['dagonly'] : 1;
        $bcc = isset($trigger['bcc']) ? $trigger['bcc'] : '';
        $from = isset($trigger['from']) ? $trigger['from'] : 'dm.norwichctu@uea.ac.uk';
        $subject = isset($trigger['subject']) ? $trigger['subject'] : 'SECURE:';
        $body = isset($trigger['body']) ? $trigger['body'] : '';
		$recordlink = isset($trigger['recordlink']) ? $trigger['recordlink'] : '';

        $html = RCView::div(array('class'=>'round chklist trigger','idx'=>"$id"),
            RCView::div(array('class'=>'chklisthdr', 'style'=>'color:rgb(128,0,0); margin-bottom:5px; padding-bottom:5px; border-bottom:1px solid #AAA;'), "Trigger $id: $title".
                RCView::a(array('href'=>'javascript:','onclick'=>"removeTrigger('$id')"), RCView::img(array('style'=>'float:right;padding-top:0px;', 'src'=>'cross.png')))
            ).
            RCView::table(array('cellspacing'=>'5', 'class'=>'tbi'),
                self::renderRow('title-'.$id,'Title',$title, 'title').
                self::renderLogicRow($id,'Logic',$logic, 'logic').
                (REDCap::isLongitudinal() ? self::renderScopeRow($id, 'Evaluate', $scope) : "") .
                self::renderTestRow($id,'Test Logic', $test_record, $test_event, 'test').
                self::renderEnabledRow($id,'Status', $enabled) .
            //	self::renderStatusRow();	// Enable or Disable the current trigger
                self::renderRow('to-'.$id,'To', $to, 'to') .
				self::renderRoleRow($id,'Role', $role) .
				self::renderDAGOnlyRow($id,'DAG', $dagonly) .
                self::renderRow('bcc-'.$id,'Bcc', $bcc, 'bcc') .
                self::renderRow('from-'.$id,'From', $from, 'from') .
                self::renderRow('subject-'.$id,'Subject', $subject, 'subject') .
                self::renderRow('body-'.$id,'Body', $body, 'body', 'textarea') .
				self::renderLinkRow($id,'Link', $recordlink)
            )
        );

        return $html;
    }


    // Adds a single row with an input
    public function renderRow($id, $label, $value, $help_id = null, $format='input') {
        $help_id = ( $help_id ? $help_id : $id);
        $input_element = '';
        if ($format == 'input') {
            $input_element = RCView::input(array('class'=>'tbi x-form-text x-form-field','id'=>$id, 'value'=>$value));
        } elseif ($format == 'textarea') {
            $input_element = RCView::textarea(array('class'=>'tbi x-form-text x-form-field','id'=>$id), $value) .
                RCView::div(array('style'=>'text-align:right'),
                    RCView::a(array('onclick'=>'growTextarea("' . $id. '")', 'style'=>'font-weight:normal;text-decoration:none;color:#999;font-family:tahoma;font-size:10px;', 'href'=>'javascript:;'),'Expand')
                );
        } else {
            $input_element = "Invalid input format!!!";
        }

        $row = RCView::tr(array(),
            RCView::td(array('class'=>'td1'), self::insertHelp($help_id)).
            RCView::td(array('class'=>'td2'), "<label for='$id'><b>$label:</b></label>").
            RCView::td(array('class'=>'td3'), $input_element)
        );
        return $row;
    }

    // Renders the logic row with the text area
    public function renderLogicRow($id, $label, $value) {
        $row = RCView::tr(array(),
            RCView::td(array('class'=>'td1'), self::insertHelp('logic')).
            RCView::td(array('class'=>'td2'), "<label for='logic-$id'><b>$label:</b></label>").
            RCView::td(array('class'=>'td3'),
                RCView::textarea(array('class'=>'tbi x-form-text x-form-field','id'=>"logic-$id",'name'=>"logic-$id",'onblur'=>"testLogic('$id');"), $value).
                RCView::div(array('style'=>'text-align:right'),
                    RCView::a(array('onclick'=>'growTextarea("logic-'.$id.'")', 'style'=>'font-weight:normal;text-decoration:none;color:#999;font-family:tahoma;font-size:10px;', 'href'=>'javascript:;'),'Expand')
                )
            )
        );
        return $row;
    }

    // Renders the enabled row
    public function renderEnabledRow($id, $label, $value) {
        //error_log('ID:'.$id.' and VALUE:'.$value);
        $enabledChecked = ($value == 1 ? 'checked' : '');
        $disabledChecked = ($value == 1 ? '' : 'checked');
        $row = RCView::tr(array(),
            RCView::td(array('class'=>'td1'), self::insertHelp('enabled')).
            RCView::td(array('class'=>'td2'), "<label for='logic-$id'><b>$label:</b></label>").
            RCView::td(array('class'=>'td3'),
                RCView::span(array(),
                    RCView::radio(array('name'=>"enabled-$id",'value'=>'1',$enabledChecked=>$enabledChecked)).
                    RCView::span(array('class'=>'radio-option'),'Enabled') . RCView::SP . RCView::SP .
                    RCView::radio(array('name'=>"enabled-$id",'value'=>'0',$disabledChecked=>$disabledChecked)).
                    RCView::span(array('class'=>'radio-option'),'Disabled')
                )
            )
        );
        return $row;
    }
	
		// ARC 08/11/17 New row to select a role to send email to
	    public function renderRoleRow($id, $label, $value) {
						
			// Create an array with "none" as the default option
			$role_names = array("0"=>"- None -");
			
			// Add project roles to the array
			foreach (UserRights::getRoles() as $role_id => $attr) {
				$role_names[$role_id] = $attr['role_name'];
			}

			// Build the row
			$row = RCView::tr(array(),
				RCView::td(array('class'=>'td1'), self::insertHelp('role')).
				RCView::td(array('class'=>'td2'), "<label for='logic-$id'><b>$label:</b></label>").
				RCView::td(array('class'=>'td3'),
					RCView::select(array('id'=>"role-$id", 'name'=>"role-$id", 'class'=>"tbi x-form-text x-form-field role_ddl", 'style'=>'height:20px;'), $role_names, $value)
				)
			);
			
			return $row;
			
		}

    // Renders a radio that allows selection of eval once per record(1) or record/event (default/0) - only displayed for longitudinal projects
    public function renderScopeRow($id, $label, $value) {
        //error_log('ID:'.$id.' and VALUE:'.$value);
        $perRecordChecked = ($value == 1 ? 'checked' : '');
        $perRecordEventChecked = ($value == 1 ? '' : 'checked');
        $row = RCView::tr(array(),
            RCView::td(array('class'=>'td1'), self::insertHelp('scope')).
            RCView::td(array('class'=>'td2'), "<label for='scope-$id'><b>$label:</b></label>").
            RCView::td(array('class'=>'td3'),
                RCView::span(array(),
                    RCView::radio(array('name'=>"scope-$id",'value'=>'1',$perRecordChecked=>$perRecordChecked)).
                    RCView::span(array('class'=>'radio-option'),'Once per record') . RCView::SP . RCView::SP .
                    RCView::radio(array('name'=>"scope-$id",'value'=>'0',$perRecordEventChecked=>$perRecordEventChecked)).
                    RCView::span(array('class'=>'radio-option'),'Once per record/event')
                )
            )
        );
        return $row;
    }
	
	  // Renders a radio that allows selection of sending emails only to users in same DAG as Record, or to all users in the selected role
    public function renderDAGOnlyRow($id, $label, $value) {
        //error_log('ID:'.$id.' and VALUE:'.$value);
		$allDAGsChecked = ($value == 1 ? 'checked' : '');
        $sameDAGOnlyChecked = ($value == 1 ? '' : 'checked');       
        $row = RCView::tr(array(),
            RCView::td(array('class'=>'td1'), self::insertHelp('dagonly')).
            RCView::td(array('class'=>'td2'), "<label for='dagonly-$id'><b>$label:</b></label>").
            RCView::td(array('class'=>'td3'),
                RCView::span(array(),
				    RCView::radio(array('name'=>"dagonly-$id",'value'=>'1',$allDAGsChecked=>$allDAGsChecked)).
                    RCView::span(array('class'=>'radio-option'),'Users in all DAGS') . RCView::SP . RCView::SP .
                    RCView::radio(array('name'=>"dagonly-$id",'value'=>'0',$sameDAGOnlyChecked=>$sameDAGOnlyChecked)).
                    RCView::span(array('class'=>'radio-option'),'Only users in same DAG as record')
                )
            )
        );
        return $row;
    }
	
	// ARC 19/04/18 Renders a checkbox to append optional link to the relevant record
    public function renderLinkRow($id, $label, $value) {
		
		$checkedVal = 'unchecked';   

		if ($value == 1){
			$checkedVal = "checked";		
		}

        $row = RCView::tr(array(),
            RCView::td(array('class'=>'td1'), self::insertHelp('recordlink')).
            RCView::td(array('class'=>'td2'), "<label for='recordlink-$id'><b>$label:</b></label>").
            RCView::td(array('class'=>'td3'),
                RCView::checkbox(array('style'=>'width:14px;','id'=>"recordlink-$id",'name'=>"recordlink-$id", $checkedVal => 'checked', 'onclick'=>"linkCheckBtn(this);"))
            )
        );
        return $row;
    }

    // Renders a test row with dropdowns for the various events/records in the project
    public function renderTestRow($id, $label, $selectedRecord, $selectedEvent) {
        // Make a dropdown that contains all record_ids.
        $data = REDCap::getData('array', NULL, REDCap::getRecordIdField());
//error_log("data: ".print_r($data,true));
        foreach ($data as $record_id => $arr) $record_id_options[$record_id] = $record_id;

        // Get all Events
        $events = REDCap::getEventNames(TRUE,FALSE);
        $row = RCView::tr(array(),
            RCView::td(array('class'=>'td1'), self::insertHelp('test')).
            RCView::td(array('class'=>'td2'), "<label for='test-$id'><b>$label:</b></label>").
            RCView::td(array('class'=>'td3'),
                RCView::span(array(), "Test logic using ".REDCap::getRecordIdField().":".
                    RCView::select(array('id'=>"test_record-$id", 'name'=>"test_record-$id", 'class'=>"tbi x-form-text x-form-field", 'style'=>'height:20px;border:0px;', 'onchange'=>"testLogic('$id');"), $record_id_options, $selectedRecord)
                ).
                RCView::span(array('style'=>'display:'. (REDCap::isLongitudinal() ? 'inline;':'none;')), " of event ".
                    RCView::select(array('id'=>"test_event-$id", 'name'=>"test_event-$id", 'class'=>"tbi x-form-text x-form-field", 'style'=>'height:20px;border:0px;', 'onchange'=>"testLogic('$id');"), $events, $selectedEvent)
                ).
                RCView::span(array(),
                    RCView::button(array('class'=>'jqbuttonmed ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only','onclick'=>'testLogic("'.$id.'");', 'style'=>'margin:0px 10px;'), 'Test').
                    RCView::span(array('id'=>'result-'.$id),' ')
                )
            )
        );
        return $row;
    }

    public function insertHelp($e) {
        return "<span><a href='javascript:;' id='".$e."_info_trigger' info='".$e."_info' class='info' title='Click for help'><img class='imgfix' style='height: 16px; width: 16px;' src='".APP_PATH_IMAGES."help.png'></a></span>";
    }

    public function renderHelpDivs() {
        $help = RCView::div(array('id'=>'to_info','style'=>'display:none;'),
                RCView::p(array(),'The following are valid email formats.  Piping is also supported.'.
                    RCView::ul(array('style'=>'margin-left:15px;'),
                        RCView::li(array(),'&raquo; user@example.com').
                        RCView::li(array(),'&raquo; user@example.com, anotheruser@example.com')
                    )
                )  
            ).RCView::div(array('id'=>'role_info','style'=>'display:none;'),
                RCView::p(array(),'Select an option from the drop down list to send the email to all users within that role in addition to any addresses in the "to" field.'
                )
			).RCView::div(array('id'=>'dagonly_info','style'=>'display:none;'),
                RCView::p(array(),'Select whether to send the email to users in the chosen role who are in the same DAG as the record, or to all users in that role.'
                )
			).RCView::div(array('id'=>'from_info','style'=>'display:none;'),
                RCView::p(array(),'Please note that some spam filters my classify this email as spam - you should test prior to going into production.'.
                    RCView::ul(array('style'=>'margin-left:15px;'),
                        RCView::li(array(),'A valid format is: user@example.com')
                    )
                )
            ).RCView::div(array('id'=>'subject_info','style'=>'display:none;'),
                RCView::p(array(),'To send a secure message, prefix the subject with <B>SECURE:</b>'.
                    RCView::ul(array('style'=>'margin-left:15px;'),
                        RCView::li(array(),'&raquo; Secure messages open normally for Stanford SOM users but require additional authentication for non-Stanford SOM email accounts.').
                    	RCView::li(array(),'Piping is also supported.')
                    )
                )
            ).RCView::div(array('id'=>'body_info','style'=>'display:none;'),
                RCView::p(array(),'This message will be included in the alert.  Piping is supported.')
            ).
            RCView::div(array('id'=>'title_info','style'=>'display:none;'),
                RCView::p(array(),'An alert will only be fired once per title per record or record/event depending on the setting of the scope.  This means that if you rename a trigger it may re-fire next time you save a previously true record.  The title of the alert will also be included in the notification email.')
            ).
            RCView::div(array('id'=>'logic_info','style'=>'display:none;'),
                RCView::p(array(),'This is an expression that will be evaluated to determine if the saved record should trigger an alert.  You should use the same format you use for branching logic.')
            ).
            RCView::div(array('id'=>'scope_info','style'=>'display:none;'),
                RCView::p(array(),'By default, each trigger will fire once per title/record/event.  So, if you had a repeating survey with a sensitive question in many events, it would re-fire for each event.  However, if you have an alert which should only fire once per record (say, in demographics) - then select once per record.')
            ).
            RCView::div(array('id'=>'test_info','style'=>'display:none;'),
                RCView::p(array(),'You can test your logical expression by selecting a record (and event) to evaluate the expression against.  This is useful if you have an existing record that would be a match for your condition.')
            ).RCView::div(array('id'=>'post_script_det_url_info','style'=>'display:none;'),
                RCView::p(array(),'By inserting a pipe-separated (e.g. | char) list of valid URLs into this field you can trigger additional DETs <b>AFTER</b> this one is complete.  This is useful for chaining DETs together.')
            ).RCView::div(array('id'=>'pre_script_det_url_info','style'=>'display:none;'),
                RCView::p(array(),'By inserting a pipe-separated (e.g. | char) list of valid URLs into this field you can trigger additional DETs to run <b>BEFORE</b> this notification trigger.  This might be useful for running an auto-scoring algorithm, for example.')
            ).
			RCView::div(array('id'=>'recordlink_info','style'=>'display:none;'),
                RCView::p(array(),'Ticking the checkbox adds a link to the relevant record at the bottom of the email.')
            );



        echo $help;
    }

} // End of Class



function renderTemporaryMessage($msg, $title='') {
    $id = uniqid();
    $html = RCView::div(array('id'=>$id,'class'=>'green','style'=>'margin-top:20px;padding:10px 10px 15px;'),
        RCView::div(array('style'=>'text-align:center;font-size:20px;font-weight:bold;padding-bottom:5px;'), $title).
        RCView::div(array(), $msg)
    );
    $js = "<script type='text/javascript'>
	$(function(){
		t".$id." = setTimeout(function(){
			$('#".$id."').hide('blind',1500);
		},10000);
		$('#".$id."').bind( 'click', function() { 
			$(this).hide('blind',1000);
			window.clearTimeout(t".$id.");
		});
	});
	</script>";
    echo $html . $js;
}


// Get variable or empty string from _REQUEST
function voefr($var) {
    $result = isset($_REQUEST[$var]) ? $_REQUEST[$var] : "";
    return $result;
}

function insertImage($i) {
    return "<img class='imgfix' style='height: 16px; width: 16px; vertical-align: middle;' src='".APP_PATH_IMAGES.$i.".png'>";
}

#display an error from scratch
function showError($msg) {
    $HtmlPage = new HtmlPage();
    $HtmlPage->PrintHeaderExt();
    echo "<div class='red'>$msg</div>";
}

function injectPluginTabs($pid, $plugin_path, $plugin_name) {
    $msg = '<script>
		jQuery("#sub-nav ul li:last-child").before(\'<li class="active"><a style="font-size:13px;color:#393733;padding:4px 9px 7px 10px;" href="'.$plugin_path.'"><img src="' . APP_PATH_IMAGES . 'email.png" class="imgfix" style="height:16px;width:16px;"> ' . $plugin_name . '</a></li>\');
		</script>';
    echo $msg;
}

function logIt($msg, $level = "INFO") {
    global $log_file, $project_id;
    if ( !empty($log_file) ) file_put_contents( $log_file,
        date( 'Y-m-d H:i:s' ) . "\t" . $project_id . "\t" . $level . "\t" . $msg . "\n",
        FILE_APPEND );
}

// Function for decrypting (from version 643)
function decrypt_643($encrypted_data, $custom_salt=null)
{
    if (!mcrypt_loaded()) return false;
    // $salt from db connection file
    global $salt;
    // If $custom_salt is not provided, then use the installation-specific $salt value
    $this_salt = ($custom_salt === null) ? $salt : $custom_salt;
    // If salt is longer than 32 characters, then truncate it to prevent issues
    if (strlen($this_salt) > 32) $this_salt = substr($this_salt, 0, 32);
    // Define an encryption/decryption variable beforehand
    defined("MCRYPT_IV") or define("MCRYPT_IV", mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB), MCRYPT_RAND));
    // Decrypt and return
    return rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $this_salt, base64_decode($encrypted_data), MCRYPT_MODE_ECB, MCRYPT_IV),"\0");
}

function decrypt_me($encrypted_data) {
    // Try decrypting using the current format:
    $t1 = decrypt($encrypted_data);
    $t1_json = json_decode($t1,true);
    if (json_last_error() == JSON_ERROR_NONE) return $t1;
    $t2 = decrypt_643($encrypted_data);
    $t2_json = json_decode($t2,true);
    if (json_last_error() == JSON_ERROR_NONE) return $t2;
    print "ERROR DECODING";
    return false;
}

function viewLog($file) {
    // Render the page
    $page = new HtmlPage();
    $page->addExternalJS("https://cdnjs.cloudflare.com/ajax/libs/ace/1.2.2/ace.js");
    //$page->addExternalJS("https://cdnjs.cloudflare.com/ajax/libs/ace/1.2.2/ext-searchbox.js");
    //$page->addExternalJS("https://rawgithub.com/ajaxorg/ace-builds/master/src/ext-language_tools.js");
    //$page->addExternalJs("https://code.jquery.com/jquery-2.0.3.min.js");
    $page->addExternalJS(APP_PATH_JS . "base.js");
    $page->addStylesheet("jquery-ui.min.css", 'screen,print');
    //$page->addStylesheet("style.css", 'screen,print');
    //$page->addStylesheet("home.css", 'screen,print');

    $page->setPageTitle("Log View");
    $page->PrintHeader();

//	require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
    print RCView::div(
        array('class'=>'chklisthdr', 'style'=>'color:rgb(128,0,0);margin-top:10px;'),
        "Custom Log File: " . $file
    );

    ?>
    <div id="editor" style="height: 500px; width: 100%; display:none;"><?php
        // Easy method
        //readfile_chunked($file);

        // harder method
        $lines = file($file);
        $re = "/^.+\\t(\\d+)\\t/";
        global $project_id;
        foreach ($lines as $k => $line) {
            if (preg_match($re,$line, $matches)) {
                if ($matches[1] != $project_id) {
                    unset($lines[$k]);
                }
            }
        }
        echo implode("",$lines);
        ?></div>
    <div id="commandline" style="margin-top:10px;">This is the global <?php echo AutoNotify::PluginName ?> log.  Refresh for an update.</div>
    <script>
        $(document).ready(function(){
            //var langTools = ace.require("ace/ext/language_tools");
            var editor = ace.edit("editor");
            editor.$blockScrolling = Infinity;
            editor.resize(true);
            editor.setReadOnly(true);
            editor.setOptions({
                autoScrollEditorIntoView: true,
                showPrintMargin: false,
                fontSize: "8pt"
            });
            $('#editor').css({'border':'1px solid'}).fadeIn('slow');
            var row = editor.session.getLength() - 1
            editor.gotoLine(row, 0);
            editor.resize(true); // There is a bug in current version requiring this...
            editor.scrollToLine(row);
        });
    </script>
    <?php
}




?>

