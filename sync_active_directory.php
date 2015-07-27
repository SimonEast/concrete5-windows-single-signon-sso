<?php
/*
* Responsible for syncing the user's information stored in AD with the
* information stored in concrete5
* 
* Note: For the time being, this is a self contained script (ie all settings [like LDAP settings]
* are currently contained solely within this script)
* 
* Please note that large chunks of this code are based on the ldap_auth package published by ljessup:
* http://www.concrete5.org/community/forums/customizing_c5/packaged-ldap-authentication-working-beta/
* 
* Version: 1.0.0
*/

defined('C5_EXECUTE') or die("Access Denied.");
class SyncActiveDirectory extends Job {
	private $targetLogs  = '/my_logs'; // relative directory in the concrete5 base directory to be used for logging - you need to create this
	private $logFile     = ''; // this will be set by the initLog() function
	
	// LDAP connection settings
	private $LDAP_server = 'mydc.mydomain.corp';  // set this to your AD domain controller hostname
	private $LDAP_user   = 'myuser';   // set this to the username of the account you wish to log in with/do an LDAP bind with
	private $LDAP_pass   = 'pass1234'; // set this to the password for previous account
	private $LDAP_base   = 'DC=mydomain,DC=corp'; // set this to your LDAP search base
	
	// LDAP filter - the filter below will get all enabled user accounts that belong to this group: Intranet Access
	private $LDAP_filter = '(&(objectCategory=Person)(sAMAccountName=*)(!(useraccountcontrol:1.2.840.113556.1.4.803:=2))(memberOf=CN=Intranet Access,OU=Groups,DC=mydomain,DC=com,DC=au))';
	
	// push the data in the following AD fields across to the corresponding concrete5 custom attribute; this is
	// required for our corporate phone directory ... set $LDAP_enable_fieldMap=0 to disable
	private $LDAP_enable_fieldMap = 1;
	private $LDAP_fieldMap = array(
		// AD field => user attribute handle
		givenName			=> 'givenName',
		sn				=> 'surname',
		displayName			=> 'displayName',
		telephoneNumber			=> 'phone',
		mobile				=> 'mobile',
		facsimileTelephoneNumber	=> 'fax',
		ipPhone				=> 'extension',
		title				=> 'jobTitle',
		physicalDeliveryOfficeName	=> 'office',
		department			=> 'department',
	);
	
	// although the IDs listed below are accurate, they're not required as concrete5 sets select attributes using the 
	// value of the attribute - not the ID (ie you need to set "Sydne" not the ID 8); in this respect, its more of a
	// validation rule than anything else
	private $LDAP_officeMap_default = 'Other';
	private $LDAP_officeMap = array(
		// AD entry => user select option ID
		'Brisbane'	=> 6,
		'Melbourne'	=> 7,
		'Sydney'	=> 8,
		// ... blah blah blah
	);
	
	// users will be created/updated with this password - note that this needs to match the single signon controller config
	private $USER_pass      = 'randomPass1234'; // change this!!!!
	private $GROUP_prefix   = 'Intranet - ';
	private $GROUP_admin    = 'Domain Admins'; // members of this group will be made concrete5 admins
	
	
	/** Returns the job name.
	* @return string
	*/
	public function getJobName() {
		return t('Sync AD User Accounts');
	}

	/** Returns the job description.
	* @return string
	*/
	public function getJobDescription() {
		return t('Sync the user accounts in concrete5 with the user accounts in Active Directory');
	}

	/** Executes the job.
	* @return string Returns a string describing the job result in case of success.
	* @throws Exception Throws an exception in case of errors.
	*/
	public function run() {
		try {
			// initialise logs
			$this->initLog();
			
			// test ldap module has been loaded
			if(!extension_loaded('ldap')) {
				$this->logEntry('The PHP LDAP extension has not been loaded - perhaps it has not been installed?');
				throw new Exception(t('The PHP LDAP extension has not been loaded - perhaps it has not been installed?'));
			}
			
			// load all of the concrete5 groups and put them in an associative array for easy reference
			$gList  = new GroupList(null,true,true);
			$existingGroups = $gList->getGroupList();
			$groupList = array();
			$groupObj  = array();
			foreach($existingGroups as $group) {
				$groupObj[$group->getGroupId()]    = $group;
				$groupList[$group->getGroupName()] = $group->getGroupId();
			}
			
			// create LDAP connection
			$ldap = NewADOConnection('ldap');
			global $LDAP_CONNECT_OPTIONS;
			$LDAP_CONNECT_OPTIONS = Array(
				Array ("OPTION_NAME"=>LDAP_OPT_DEREF, "OPTION_VALUE"=>2),
				Array ("OPTION_NAME"=>LDAP_OPT_SIZELIMIT,"OPTION_VALUE"=>1000),
				Array ("OPTION_NAME"=>LDAP_OPT_TIMELIMIT,"OPTION_VALUE"=>30),
				Array ("OPTION_NAME"=>LDAP_OPT_PROTOCOL_VERSION,"OPTION_VALUE"=>3),
				Array ("OPTION_NAME"=>LDAP_OPT_ERROR_NUMBER,"OPTION_VALUE"=>13),
				Array ("OPTION_NAME"=>LDAP_OPT_REFERRALS,"OPTION_VALUE"=>FALSE),
				Array ("OPTION_NAME"=>LDAP_OPT_RESTART,"OPTION_VALUE"=>FALSE)
			);
			
			$usersCreated  = 0;
			$usersUpdated  = 0;
			$usersDisabled = 0;
			
			// connect to active directory
			$this->logEntry('Connecting to LDAP');
			$ldap->Connect($this->LDAP_server, $this->LDAP_user, $this->LDAP_pass, $this->LDAP_base);
			
			// set and execute search filter
			$ldap->SetFetchMode(ADODB_FETCH_ASSOC);
			$rs = $ldap->Execute($this->LDAP_filter);
			
			// iterate through results
			$userTracker = array();
			if($rs) {
				$this->logEntry('Processing active LDAP users');
				while($row = $rs->FetchRow()) {
					// user sync flags
					$invalidEmail=0;
					$invalidOffice=0;
					$wasCreated=0;
					$groupsAdded=0;
					$groupsRemoved=0;
					
					// if user doesn't exist, register them
					$username = $row['sAMAccountName'];
					$ui = UserInfo::getByUserName($username);
					
					// prepare base user data
					$mail = $row['mail'];
					if(!preg_match("/\w/",$mail)) {
						$invalidEmail=1;
						$mail = 'helpdesk@mydomain.com.au';
					}
					
					$userData['uName']			= $username;
					$userData['uPassword']			= $this->USER_pass;
					$userData['uPasswordConfirm']		= $this->USER_pass;
					$userData['uEmail']			= $mail;
					
					if(!is_object($ui)) {
						// create a new user
						$ui = UserInfo::add($userData);
						$wasCreated=1;
						$usersCreated++;
					}
					else {
						// update the base user data for an existing user
						$ui->update($userData);
					}
					
					// set the isADUser attribute
					$ui->setAttribute('isADUser',1);
					
					// activate the account
					$ui->activate();
					
					// loop through and update the custom user attributes
					if($this->LDAP_enable_fieldMap) {
						foreach($this->LDAP_fieldMap as $adField => $uaField) {
							if($adField == 'physicalDeliveryOfficeName') {
								// make sure the office name is on our list; otherwise set it to the default
								$office = $row[$adField];
								if(!$this->LDAP_officeMap[$office]) {
									$invalidOffice=1;
									$office = $this->LDAP_officeMap_default;
								}
								$ui->setAttribute($uaField,$office);
							}
							else {
								$ui->setAttribute($uaField,$row[$adField]);
							}
						}
					}
					
					// get the user object (required for group processing)
					$u = $ui->getUserObject();
					
					// user groups
					$groups = array();
					if(is_array($row['memberOf'])) foreach($row['memberOf'] as $group) {
						// process the memberOf field so you end up with just the group name
						$group = explode(',', $group);
						$group = $group[0];
						$group = str_replace('CN=', '', $group);
						
						// add domain administrators to the concrete5 admin group
						if(!(strpos($group, $this->GROUP_admin)  === false)) $groups[] = 'Administrators';
						
						// if the group name matches the prefix, add it to the list
						if(!(strpos($group, $this->GROUP_prefix) === false)) $groups[] = str_replace($this->GROUP_prefix, '', $group);
					}
					
					// strip the user of their group memberships (apart from membership of admin group)
					$currentGroups = $u->getUserGroups();
					$foundGroups = array();
					
					// process the list of groups that matched the profix (and admin group)
					$groups = array_unique($groups);
					foreach($groups as $group) {
						if($groupList[$group]) {
							// group exists in concrete5
							$gID = $groupList[$group];
							$foundGroups[$gID] = 1;
							if(!$currentGroups[$gID]) {
								// user doesn't belong to group - add them (requires the group object to work)
								$u->enterGroup($groupObj[$gID]);
								$groupsAdded++;
							}
						}
					}
					
					// remove the user from any groups that they no longer should be a member of (note that
					// this doesn't apply to the admin group as you don't want to run the risk of locking yourself
					// out of concrete5 because you nuked all the AD accounts from being admins)
					foreach($currentGroups as $gID) {
						// note: admin group ID = 3
						if($gID>3 && !$foundGroups[$gID] && $groupObj[$gID]) {
							$u->exitGroup($groupObj[$gID]);
							$groupsRemoved++;
						}
					}
					
					$userTracker[$username] = 1;
					$usersUpdated++;
					
					$this->logEntry(sprintf('Processed user %-15s: invalid email=%d; invalid office=%d; created=%d; groups added=%d; groups removed=%d;', $username, $invalidEmail, $invalidOffice, $wasCreated, $groupsAdded, $groupsRemoved));
				}
			}
			else {
				$this->logEntry('ERROR: No users were found');
			}
			
			$this->logEntry('Closing LDAP connection');
			$ldap->Close();
			
			// loop through all of the users in concrete5 and disable any AD users which weren't found during the above process
			if($usersUpdated > 0) {
				$this->logEntry('Checking for concrete5 AD users that need to be disabled');
				$uList = new UserList();
				$users = $uList->get(1000,0);
				foreach($users as $userInfo) {
					if($userInfo->getAttribute('isADUser')) {
						// $processed will be set to 1 if the user was processed above and therefore represents
						// AD users that should be active in concrete5
						$processed = $userTracker[$userInfo->getUserName()];
						if(!$processed) {
							// disable the user
							$this->logEntry('Disabling user: '.$userInfo->getUserName());
							$userInfo->deactivate();
							$usersDisabled++;
						}
					}
				}
			}
			
			$this->logEntry('******************');
			$this->logEntry('Summary of Changes');
			$this->logEntry('******************');
			$this->logEntry('Created:  '.$usersCreated);
			$this->logEntry('Updated:  '.$usersUpdated);
			$this->logEntry('Disabled: '.$usersDisabled);
			$this->logEntry('******************');
			
			return t($usersCreated.' users created; '.$usersUpdated.' users updated; '.$usersDisabled.' users disabled');
		}
		catch(Exception $x) {
			throw $x;
		}
	}
	
	private function initLog() {
		$this->logFile = getcwd().$this->targetLogs.'/sync_ad_users.log';
		shell_exec('echo "------------------------------------------------" >> '.$this->logFile);
		shell_exec('date >> '.$this->logFile);
	}
	
	private function logEntry($message) {
		shell_exec('echo "'.$message.'" >> '.$this->logFile);
	}
}
