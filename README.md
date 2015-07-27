Concrete5 Windows Single Sign On (SSO)
========================

Some intial proof-of-concept code for implementing transparent pass-through authentication (SSO) from Windows machines into concrete5 via LDAP/ActiveDirectory/Kerberos

Thanks to ntisteve who published this code via the concrete5 forums at:  
https://www.concrete5.org/community/forums/installation/single-sign-on-automatic-login/

Implementation Notes
-------------------- 

Obviously use these scripts at your own risk. Its not designed to be an addon to concrete5. Rather, it outlines a *POTENTIAL* way
to implement Active Directory based single sign-on in Concrete5.

Note that I'm not a PHP developer and I'm quite new to Concrete5. As such, there are probably many things that can be enhanced in these scripts. If you're interested, please go for it :-)


Overview
--------

Before diving into the code, you probably need a basic overview of how this system works. Essentially, this system works on the principle that all of the authentication logic is handled by Apache prior to passing a request through to Concrete5. All the
single signon script does is look for the REMOTE_USER environment variable (set by Apache's authentication modules) and uses it to automatically log a user into the CMS.

In our implementation, we're using Kerberos/Active Directory authentication (via mod_auth_kerb) at the Apache level however I suppose you could use something else. We've then got a job that runs in Concrete5 to extract our uses from Active Directory and create their accounts in Concrete5.


Implementation Flow
-------------------

1. Implement and configure Kerberos/Active Directory authentication in Apache.
2. Test and be 110% sure that the above works before proceeding!
3. Implement the background job that keeps the Concrete5 accounts in sync with Active Directory
4. Implement single signon in Concrete5


Implementation
--------------

### 1. Kerberos/Active Directory in Apache

There are many, many guides on this already so I'm going to defer to documentation stored elsewhere. Here's a page which I found
detailed the process quite thoroughly:

- http://blogs.law.emory.edu/benchapman/2010/06/16/kerberized-sso-from-windows-to-apache-on-centos/

From a high level, you have to:

- Make sure mod_auth_kerb is installed (I'm using version 5.4.9 - shipped with CentOS/RHEL)
- Make sure your Apache server's clock is within 5 seconds of your domain controllers
- Configure /etc/krb5.conf
- Generate a key tab
- Configure Apache to secure a location on your server and make it use kerberos authentication

Here's a copy of the Apache settings we used for our setup:

	<Location />
			AuthName "Example Website"
			AuthType Kerberos
			Krb5Keytab /etc/httpd/conf/kerberos.keytab
			KrbAuthRealm MYDOMAIN.CORP
			KrbServiceName HTTP/servername.mydomain.corp@MYDOMAIN.corp
			KrbMethodNegotiate On
			KrbMethodK5Passwd On
			KrbVerifyKDC Off
			KrbLocalUserMapping On
			require valid-user
	</Location>
	
Substitute:

  - `MYDOMAIN.CORP` for your AD domain name
  - `servername.mydomain.corp` for the FQDN of your server

### 2. Test and retest the Kerberos/Active Directory integration and be 100% confident that its working. 

Seriously, if you don't do this you'll end up chasing your tail later!
   
 I used a simple perl script for my own testing (supplied below) if you'd like to use that. If you do use it, note that you'll need to adjust the Apache settings I supplied above so that they apply to the cgi-bin directory (instead of to just the document root).
   
    #!/usr/bin/perl
   
    use strict;
    use CGI;
    my $q = CGI->new();
    print $q->header;

    my $user = $ENV{REMOTE_USER};
    if($user =~ /\w/m) { print "Authenticated user detected: $user\n"; }
    else { print "No authenticated user detected\n"; }
   
### 3. Implement the sync background job by copying the sync_active_directory.php script to the jobs directory at the base of the Concrete5 installation directory. 

You'll then need to edit the PHP script and update the settings at the top of the PHP class. Note that the
   user password (not the LDAP password) you use in this script needs to match the password set in the single signon script below.
   
   Go into Concrete5 and create a user attribute of type "number" named "isADUser". Configure this attribute so that it can't be edited
   by users. The system sets this value to 1 for any user accounts created by this background job.
   
   We also use this script to bring across some other Active Directory information about our users as we use/consume this data via a 
   custom phonebook we've implemented in Concrete5. If you wish to do the same thing, you'll need to create user attributes for each
   of the Active Directory fields that you wish to store in Concrete5.
   
   Once you've created your attributes and configured your script, install and run the job via the concrete5 dashboard. Only proceed to the
   next step if it works properly.
   
### 4. Implement single signon in Concrete5 by copying the single_sigon.php script to the controllers directory at the base of the Concrete5 installation directory. 

You'll then need to edit the PHP script and update the settings at the top of the PHP class. In addition to
   single signon, we also use this script to direct users to team "home pages" based on their group memberships. Delete/disable this 
   functionality if you don't need it.
   
   To finally enable single signon, add the following line to config/site.php (this enables application events):

    define('ENABLE_APPLICATION_EVENTS', true);
   
   Then create the config/site_events.php configuration script with the following entries:
   
    <?php
    Events::extend('on_before_render','SingleSignonController','check_login','controllers/single_signon.php');
    ?>
   

Testing
-------

If everything is working properly, you should see an entry like this in your Apache error logs (where xxxxx is a username):

    [Mon Mar 04 08:47:01 2013] [error] [client 192.168.100.103] User xxxxx has been logged in

The user should obviously also be logged into the CMS as well :-)

Note that you should only see the above entry when the user first connects to the Concrete5 website; if you see an entry like this appear everytime
the user requests a new page, then something is wrong (see the known bugs section below).
   

Known Bugs/Issues
-----------------

- For whatever reason, Google Chrome doesn't properly log into the CMS if the favicon is missing; no idea why this is ... just make sure
  a favicon is in the concrete5 directory. If you don't do this, you'll see that the CMS has to re-authenticate/re-login the user on every request.
  
- When we run the `sync_active_directory.php` job in Concrete5, it currently hangs the CMS for a few minutes while the sync job is running. I
  haven't looked into why this is happening or if it can be fixed, so for the time being we're only running this sync job after hours.

