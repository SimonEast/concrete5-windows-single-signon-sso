<?php 
/*
* Responsible for signing a user into concrete5 if the $_SERVER['REMOTE_USER'] variable was set
* 
* Note: This is dependent on Apache's authentication module being correctly configured and processing
* the authentication prior to passing the request to the CMS
* 
* Version: 1.0.0
*/

defined('C5_EXECUTE') or die("Access Denied.");

class SingleSignonController extends Controller { 
	// redirect the user to this URL if they haven't been logged in by Apache (note that these URLs must be
	// outside the current Apache authentication container - ie must be a different URL/website)
	private $url_bad_auth     = 'http://error.mydomain.corp/bad_authentication.html';
	private $url_invalid_user = 'http://error.mydomain.corp/user_invalid.html';
	private $url_user_dne     = 'http://error.mydomain.corp/user_does_not_exist.html';
	private $url_user_dis     = 'http://error.mydomain.corp/user_disabled.html';
	private $ss_password      = 'randomPass1234'; // all users are set with this password - must match the password in the sync script
	
	// home page groups; groupId => url
	private $homepageGroup = array(
		4 => '/about/',			    // Test Group 1
		5 => '/about/guestbook/',	// Test Group 2
	);
	
	// if a user hasn't been logged into the CMS, do it; note that this logic assumes that only
	// authenticated users can access the CMS
	public function check_login() {
		$u = new User();
		if(!$u->IsLoggedIn()) {
			//error_log('Logging user into CMS: '.$_SERVER['REMOTE_USER']);
			$me = new SingleSignonController();
			$me->process_login();
		}
		
		if($u->IsLoggedIn()) {
			// redirect the user to their own home page (if they're requesting the site's home page)
			$me  = new SingleSignonController();
			$req = Request::get();
			if($u->IsLoggedIn() && $req->getRequestPath() == '') {
				// reinitialise the user object incase the user was just logged in (reloads group memberships)
				$u = new User();
				$me->redirect_to_home_page($u);
			}
		}
	}
	
	public function process_login() {
		$username = $_SERVER['REMOTE_USER'];
		if(!preg_match("/\w/",$username)) {
			// username has not been set - redirect the user to an exception page
			error_log('Username could not be detected; user could not be logged on');
			$this->externalRedirect($this->url_bad_auth);
		}
		
		// check the user exists in concrete5
		$ui = UserInfo::getByUserName($username);
		if(is_object($ui)) {
			// log the user in
			$u = new User($username, $this->ss_password);
			if($u->isError()) {
				switch($u->getError()) {
					case USER_INACTIVE:
						error_log('User '.$username.' has been disabled/deactivated');
						$this->externalRedirect($this->url_user_dis);
						break;
					default:
						error_log('User '.$username.' is invalid - reason: '.$u->getError());
						$this->externalRedirect($this->url_invalid_user);
				}
			}
			else {
				error_log('User '.$username.' has been logged in');
			}
		}
		else {
			error_log('User '.$username.' could not be found in CMS - check AD and re-run the sync job: '.$this->url_user_dne);
			$this->externalRedirect($this->url_user_dne);
		}
		
		$this->finishLogin();
	}

	protected function finishLogin() {
		$u = new User();
		
		$dash = Page::getByPath("/dashboard", "RECENT");
		$dbp = new Permissions($dash);
		
		Events::fire('on_user_login',$this);	
		
		//should administrator be redirected to dashboard?  defaults to yes if not set. 
		$adminToDash=intval(Config::get('LOGIN_ADMIN_TO_DASHBOARD'));  	
		
		if ($u->isRegistered()) { 
			if ( $dbp->canRead() && $adminToDash ) {
				$this->redirect('/dashboard');
			} else {
				//options set in dashboard/users/registration
				$login_redirect_cid=intval(Config::get('LOGIN_REDIRECT_CID'));
				$login_redirect_mode=Config::get('LOGIN_REDIRECT');		
				
				//redirect to user profile
				if( $login_redirect_mode=='PROFILE' && ENABLE_USER_PROFILES ){ 			
					$this->redirect( '/profile/', $u->uID ); 				
					
				//redirect to custom page	
				}elseif( $login_redirect_mode=='CUSTOM' && $login_redirect_cid > 0 ){ 
					$redirectTarget = Page::getByID( $login_redirect_cid ); 
					if(intval($redirectTarget->cID)>0) $this->redirect( $redirectTarget->getCollectionPath() );
					else $this->redirect('/');		
							
				//redirect home
				} //else $this->redirect('/');
			}
		}
	}
	
	public function redirect_to_home_page($u) {
		$userGroups = $u->getUserGroups();
		foreach($this->homepageGroup as $gid => $url) {
			//error_log('Checking for GID: '.$gid);
			if($userGroups[$gid]) {
				error_log('Redirecting user to home page: '.$url);
				$this->redirect($url);
				break;
			}
		}
	}
}

?>