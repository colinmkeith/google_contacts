<?php
/*
Google Contacts for RoundCube 0.6 and above.

@version 2.12 - 08/04/2013
@author Les Fenison /-- Original code by Roland 'rosali' Liebl
@licence GNU GPL

Questions, problems, or suggestions?  Email me rc@deltatechnicalservices.com

Will sync Google contacts both directions including all the new contacts
fields in RoundCube 0.6


Usage:

  Create the db table using the SQL in SQL directory.  The table is the same
exact format as the contacts table.   If you are upgrading from 0.5.2 you
will need to add the words column of type text.

copy ./config/config.inc.php.dist to ./config/config.inc.php and configure
as needed.  The only parameter worth messing with is the
$rcmail_config['google_contacts_max_results'] which defaults to 1000.


  Requirements: * Get Zend GData APIs

  /http://framework.zend.com/download/webservices
  Copy and paste "Zend" /folder into ./program/lib

  File structure must be: ./program/lib/Zend


TODO / bugs

If you get Google's over quota errors with this version, look at line 684  and increase the number.
The higher the number, the slower the load is.  If the number is too small,
google will complain because you are hitting their server too fast.
*/

class google_contacts extends rcube_plugin
{
	public $task = "mail|addressbook|settings";
	private $abook_id = 'google_contacts';  
	private $user = false;
	private $pass = false;
	private $contacts;
	private $error = false;
	private $results = null;

	function init()
  	{    
		$this->add_texts('localization/', false);
  
	  	if(file_exists("./plugins/google_contacts/config/config.inc.php"))
		  	$this->load_config('config/config.inc.php');
	  	else
		  	$this->load_config('config/config.inc.php.dist');
	  	$rcmail = rcmail::get_instance();
		
	  	$this->user = $rcmail->config->get('googleuser');
	  	$this->pass = $rcmail->config->get('googlepass');

	  	if($this->user && $this->pass){
			$this->pass = $rcmail->decrypt($this->pass);
			$this->add_hook('addressbooks_list', array($this, 'addressbooks_list'));
			$this->add_hook('addressbook_get', array($this, 'addressbook_get'));
			$this->add_hook('contact_create', array($this, 'contact_create'));    
			$this->add_hook('contact_update', array($this, 'contact_update'));
			$this->add_hook('contact_delete', array($this, 'contact_delete'));
			$this->add_hook('render_page', array($this, 'render_page'));
			// use this address book for autocompletion queries
			$config = $rcmail->config;
			$sources = $config->get('autocomplete_addressbooks', array('sql'));

			if (!in_array($this->abook_id, $sources)){
				$sources[] = $this->abook_id;
				$config->set('autocomplete_addressbooks', $sources);
			}       
    	}
//		$this->add_hook('preferences_sections_list', array($this, 'addressbooksLink'));    
		$this->add_hook('preferences_list', array($this, 'settings_table'));
		$this->add_hook('preferences_save', array($this, 'save_prefs'));                    

		$dir = INSTALL_PATH . 'program/lib/';
		if(!file_exists($dir . 'Zend/Loader.php')){
			write_log('errors', 'Plugin google_contacts: Zend GData API not installed (http://framework.zend.com/download/webservices)');
			$this->results = array();
			return;
		}
		require_once $dir . 'Zend/Loader.php';
		Zend_Loader::loadClass('Zend_Gdata');
		Zend_Loader::loadClass('Zend_Gdata_ClientLogin');
		Zend_Loader::loadClass('Zend_Http_Client');
		Zend_Loader::loadClass('Zend_Gdata_Query');
		Zend_Loader::loadClass('Zend_Gdata_Feed');

		if( !empty($this->user) && !empty($this->pass) ){		
			// perform login and set protocol version to 3.0
			try {
				$client = Zend_Gdata_ClientLogin::getHttpClient( $this->user, $this->pass, 'cp');
				$client->setHeaders('If-Match: *');
				$this->gdata = new Zend_Gdata($client);
				$this->gdata->setMajorProtocolVersion(3);		
			} catch (Exception $e) {
				$this->error = $e->getMessage();
				write_log('google_contacts', $this->error);
				if(method_exists($rcmail->output, 'show_message'))
					$rcmail->output->show_message($this->error,'error'); 
			}
		}
	}

	function render_page($p){
		if($p['template']== 'addressbook'){
			$rcmail = rcmail::get_instance();
		  	$rcmail->output->command('enable_command','import',false);
		  	$rcmail->output->command('enable_command','add',true);
			$script = "rcmail.add_onload(\"rcmail.command('list',rcmail.env.source,false);\");";
			$rcmail->output->add_script($script,'foot');
		}
		return $p;
	}
    
	function contact_create($a){
		if( is_null($a['record']) ){
			write_log('google_contacts','null record in create');			
			write_log('google_contacts',$a);			
			$a['abort'] = true;
			return $a;
		}
				
		if($a['source'] == $this->abook_id){
			$this->put_contact($a);
			$a['record']['edit']=$this->results->google_id;
			$a['abort'] = false;
		}
		
		return $a;
	}  
  
	function contact_update($a){
		if($a['source'] == $this->abook_id){
			$rcmail = rcmail::get_instance(); 
			rcmail_overwrite_action('show');  

			$this->put_contact($a, $this->get_google_id($a['id'])) ;			
			$a['record']['edit']=$this->results->google_id;
		
			$a['abort'] = false;
		}
		return $a;
	}
  
	function contact_delete($a){
		if($a['source'] == $this->abook_id){
			foreach( $a['id'] as $id ) {
				$this->delete_contact($id);
			}
			$a['abort'] = false;      
		}
		return $a;
	}
    
	function addressbooks_list($p)
	{
		$rcmail = rcmail::get_instance();
		if ($rcmail->config->get('use_google_abook'))
			$p['sources'][$this->abook_id] =  array('id' => $this->abook_id, 'name' => Q($this->gettext('googlecontacts')), 'readonly' => false, 'groups' => false);
	  	$rcmail->output->command('enable_command','import',false);
	  	$rcmail->output->command('enable_command','add',true);			
		return $p;  
	}
  
	function addressbook_get($p)
	{
		$rcmail = rcmail::get_instance();
		if (($p['id'] === $this->abook_id) && $rcmail->config->get('use_google_abook')) {
			require_once(dirname(__FILE__) . '/google_contacts_backend.php');
			$p['instance'] = new google_contacts_backend($rcmail->db, $rcmail->user->ID);
			$p['instance']->groups = false;
			$this->sync_contacts();
			$rcmail->output->command('enable_command','import',false);
			$rcmail->output->command('enable_command','add',true);
		}
		else{
			if ($p['id'] == $rcmail->config->get('default_addressbook')){
//				$rcmail->output->command('enable_command','import',false);
			}
		}
		return $p;
	}
  
/*	function addressbooksLink($args)
  	{
    	$temp = $args['list']['server'];
    	unset($args['list']['server']);
    	$args['list']['addressbooks']['id'] = 'addressbook';
    	$args['list']['addressbooks']['section'] = $this->gettext('addressbook');
    	$args['list']['server'] = $temp;

    	return $args;
  	}    
*/  
	function settings_table($args)
  	{
		if ($args['section'] == 'addressbook') {
      		$rcmail = rcmail::get_instance();    
			$use_google_abook = $rcmail->config->get('use_google_abook');
			$field_id = 'rcmfd_use_google_abook';
			$checkbox = new html_checkbox(array('name' => '_use_google_abook', 'id' => $field_id, 'value' => 1));
			$args['blocks']['googlecontacts']['name'] = $this->gettext('googlecontacts');
			$args['blocks']['googlecontacts']['options']['use_google_abook'] = array(
			  'title' => html::label($field_id, Q($this->gettext('usegoogleabook'))),
			  'content' => $checkbox->show($use_google_abook?1:0),
			);

			$field_id = 'rcmfd_google_user';
			$input_googleuser = new html_inputfield(array('name' => '_googleuser', 'id' => $field_id, 'size' => 35));
			$args['blocks']['googlecontacts']['options']['googleuser'] = array(
				'title' => html::label($field_id, Q($this->gettext('googleuser'))),
			  	'content' => $input_googleuser->show($rcmail->config->get('googleuser')),
			);
      
      		$field_id = 'rcmfd_google_pass';
      		if($rcmail->config->get('googlepass'))
        		$title = $this->gettext('googlepassisset');
      		else
        		$title = $this->gettext('googlepassisnotset');
      		$input_googlepass = new html_passwordfield(array('name' => '_googlepass', 'id' => $field_id, 'size' => 35, 'title' => $title));
      		$args['blocks']['googlecontacts']['options']['googlepass'] = array('title' => html::label($field_id, Q($this->gettext('googlepass'))),'content' => $input_googlepass->show(),);      
    	}
    	return $args;
	}

  	function save_prefs($args)
  	{
    	if ($args['section'] == 'addressbook') {    
      		$rcmail = rcmail::get_instance();
      		$args['prefs']['use_google_abook'] = isset($_POST['_use_google_abook']) ? true : false;
      		$args['prefs']['googleuser'] = get_input_value('_googleuser', RCUBE_INPUT_POST);
      		$pass = get_input_value('_googlepass', RCUBE_INPUT_POST);
      		if($pass){
        		$args['prefs']['googlepass'] = $rcmail->encrypt($pass);
      		}
			if( $args['prefs']['use_google_abook'] && !empty($args['prefs']['googleuser']) && !empty($args['prefs']['googlepass']) ) {
				if( $this->user != $args['prefs']['googleuser'] || $this->pass != $args['prefs']['googlepass'] ) {
					// sync
					$_SESSION['google_contacts_sync'] = false;
				}
			} else {
				// delete
				$db_table = $rcmail->config->get('db_table_google_contacts');
				$query = "DELETE FROM $db_table WHERE user_id=?";
				$res = $rcmail->db->query($query, $rcmail->user->ID);
				$obj = (array) $this->results;				
			}
    	}
    	return $args;
  	}
    


	function get_google_id( $recid ) {
		$rcmail = rcmail::get_instance();   
		require_once(dirname(__FILE__) . '/google_contacts_backend.php');
		$CONTACTS = new google_contacts_backend($rcmail->db, $rcmail->user->ID);	
		$a_record = $CONTACTS->get_record($recid, true);
	
		//@ ToDo: roundcube MUST have a function to extract a value from a vcard..  Someday we will find it after they document the code.
		// For now, this works fine.
		$vcardlines = explode("\n",$a_record['vcard']);
		foreach ( $vcardlines as $k=>$v) {
			if( strstr($v,'X-AB-EDIT:') ){
				$id=trim(substr($v,strpos($v,':')+1));
				return $id;
			}
		}
		return false;
	}


	/*
	NOTE: There is a bug in /Zend/Gdata/App.php. This code will NOT work until the following is applied
	for details see http://www.google.com/support/forum/p/apps-apis/thread?tid=11ddcc0df1a25c0a&hl=en
	
	This code needs to be added to /Zend/Gdata/App.php on line 500
	
			if ($data == null && $method == 'DELETE')
			  { $headers['If-Match'] = '*'; }
	*/
	function delete_contact( $recid ) 
	{
		$id=$this->get_google_id($recid);
		spl_autoload_unregister('rcube_autoload');        
		try {
			// version 1 method, does not require the zend mod but may change in the future because it is old
			$this->gdata->setMajorProtocolVersion(1);
			$query = new Zend_Gdata_Query($id);
			$entry = $this->gdata->getEntry($query);
			$entry->delete($id);	

			// version 3 method, newer but requires mod to zend
//			$this->gdata->delete($id);
			$this->gdata->setMajorProtocolVersion(3);
			
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			write_log('google_contacts', $this->error);
			if(method_exists($rcmail->output, 'show_message'))
				$rcmail->output->show_message($this->error,'error'); 
		}
		spl_autoload_register('rcube_autoload');      
		$this->results = $results;
	}

    
	// if edit id is supplied, we are updateing an existing contact, if empty, create new contact
  	function put_contact( $a , $edit_id='') 
  	{	
  		$phonetypes=array('home'=>'home',
					'home2'=>'home',
					'work'=>'work',
					'work2'=> 'work',
					'mobile'=>'mobile',
					'main'=>'main',
					'homefax'=>'home_fax',
					'workfax'=>'work_fax',
					'pager'=>'pager',
					'assistant'=>'assistant',
					'other'=>'other');
					  
	// google does not have OTHER and rc does not have GOOGLE_TALK.  and there is a good probability that if someone specifies other, that it is google_talk.
		$imtypes=array('aim'=>'AIM',
					'msn'=>'MSN',
					'icq'=>'ICQ',
					'yahoo'=>'YAHOO',
					'skype'=>'SKYPE',
					'jabber'=>'JABBER',
					'other'=>'GOOGLE_TALK');	

	// google has types that we don't such as profile, home, and ftp				   
		$urltypes=array('homepage'=>'home-page',
	 				'work'=>'work',
					'other'=>'other',
					'blog'=>'blog');				   
					  
		$rcmail = rcmail::get_instance();   
		// set credentials for ClientLogin authentication
		$user = $this->user;
		$pass = $this->pass;
		spl_autoload_unregister('rcube_autoload');        
	    try {
			$doc  = new DOMDocument();
			$doc->formatOutput = true;
			$entry = $doc->createElement('atom:entry');
			$entry->setAttributeNS('http://www.w3.org/2000/xmlns/' , 'xmlns:atom', 'http://www.w3.org/2005/Atom');
			$entry->setAttributeNS('http://www.w3.org/2000/xmlns/' , 'xmlns:gd', 'http://schemas.google.com/g/2005');
        	$entry->setAttributeNS('http://www.w3.org/2000/xmlns/' , 'xmlns:gContact', 'http://schemas.google.com/contact/2008');
			$doc->appendChild($entry);

			// Add to the My Contacts Group for now, my contacts are /base/6  This may change some day!
			$grp = $doc->createElement('gContact:groupMembershipInfo');
			$grp->setAttribute('href' , "http://www.google.com/m8/feeds/groups/". $user ."/base/6");
			$grp->setAttribute('deleted','false');
			$entry->appendChild($grp);
	  
			// add name element
			$name = $doc->createElement('gd:name');
			$entry->appendChild($name);
			$fullName = $doc->createElement('gd:fullName', $a['record']['name']);
			$name->appendChild($fullName);

			// add org name element
			if( isset($a['record']['organization']) ){
				$org = $doc->createElement('gd:organization');
				$org->setAttribute('rel' ,'http://schemas.google.com/g/2005#work');
				$entry->appendChild($org);
				$orgName = $doc->createElement('gd:orgName', flatten_array($a['record']['organization']));
				$org->appendChild($orgName);
				if( isset($a['record']['jobtitle']) ){
					$orgTitle = $doc->createElement('gd:orgTitle', flatten_array($a['record']['jobtitle']));
					$org->appendChild($orgTitle);
				}
			}

			// add notes
			if( !empty( $a['record']['notes'] ) ){
				// sometimes rc returns this as an array and sometimes not
				if( is_array($a['record']['notes']) ){
					$note = implode("\n",$a['record']['notes']);
				} else {
					$note = $a['record']['notes'];
				}
				$notes = $doc->createElement('atom:content');
				$notes->setAttribute('type' , "text");
				$notes->appendChild($doc->createTextNode($note));
				$entry->appendChild($notes);
			}

			if( !empty($a['record']['nickname']) ){
				$nickname = $doc->createElement('gContact:nickname');
				$nickname->appendChild($doc->createTextNode(flatten_array($a['record']['nickname'])));
				$entry->appendChild($nickname);
			}
			if( !empty($a['record']['gender']) ){
				$gender = $doc->createElement('gContact:gender');
				$gender->setAttribute('value', flatten_array($a['record']['gender']));
				$entry->appendChild($gender);
			}
			if( !empty($a['record']['maidenname']) ){
				$mname = $doc->createElement('gContact:maidenName');
				$mname->appendChild($doc->createTextNode(flatten_array($a['record']['maidenname'])));
				$entry->appendChild($mname);
			}
			if( !empty($a['record']['birthday']) ){
				$birthday = $doc->createElement('gContact:birthday');
				$birthday->setAttribute('when', date('Y-m-d',strtotime(flatten_array($a['record']['birthday']))));
				$entry->appendChild($birthday);
			}
			if( !empty($a['record']['anniversary']) ){
				$av = $doc->createElement('gContact:event');
				$av->setAttribute('rel', 'anniversary');				
				$wh = $doc->createElement('gd:when');
				$wh->setAttribute('startTime',date('Y-m-d',strtotime(flatten_array($a['record']['anniversary']))));
				$av->appendChild($wh);
				$entry->appendChild($av);        
			}

			// loop thru the rest of the data that could have multiples
			foreach( $a['record'] as $key=>$val ) {
				if( strstr($key,'phone:') ){
					list($junk,$ptype) = explode(':',$key);
					foreach ($val as $phnum ) {
						if( !empty($phnum) ){
							// add phone number element 
							$phoneNumber = $doc->createElement('gd:phoneNumber');
							$phoneNumber->setAttribute('rel', 'http://schemas.google.com/g/2005#'. $phonetypes[$ptype]);
		//					$phoneNumber->setAttribute('primary', 'true');
							$phoneNumber->appendChild($doc->createTextNode($phnum));
							$entry->appendChild($phoneNumber);
						}
					}
				}
				if( strstr($key,'email:') ){
					list($junk,$etype) = explode(':',$key);
					foreach( $val as $addr ) {
						if( !empty($addr) ) {
							// add email element 
							$email = $doc->createElement('gd:email');
							$email->setAttribute('address' ,$addr);
							$email->setAttribute('rel' ,'http://schemas.google.com/g/2005#'.$etype);
							$entry->appendChild($email);
						}
					}
				}
				if( strstr($key,'im:') ){
					list($junk,$imtype) = explode(':',$key);
					foreach( $val as $addr ) {
						if( !empty($addr) ){
							// add IM element 
							$im = $doc->createElement('gd:im');
							$im->setAttribute('address' ,$addr);
							$im->setAttribute('protocol' ,'http://schemas.google.com/g/2005#'.$imtypes[$imtype]);
							$im->setAttribute('rel' ,'http://schemas.google.com/g/2005#home');
		//					$im->setAttribute('primary', 'true');
							$entry->appendChild($im);
						}
					}
				}
				if( strstr($key,'website:') ){
					list($junk,$urltype) = explode(':',$key);
					foreach( $val as $addr ) {
						if( !empty($addr) ){
							// add website element 
							
							$website = $doc->createElement('gContact:website');
							$website->setAttribute('href', $addr);
							$website->setAttribute('rel', $urltypes[$urltype]);
							$website->setAttribute('xmlns' , "http://schemas.google.com/contact/2008");
							$entry->appendChild($website);	
						}
					}
				}
	
				if( strstr($key,'address:') ){
					list($junk,$addrtype) = explode(':',$key);
					foreach( $val as $addr ) {
						if( !empty($addr['street']) || !empty($addr['locality']) || !empty($addr['region']) || !empty($addr['zipcode']) || !empty($addr['country']) ){
							$postal = $doc->createElement('gd:structuredPostalAddress');
							$postal->setAttribute('mailClass', 'http://schemas.google.com/g/2005#letters');
							$postal->setAttribute('rel', 'http://schemas.google.com/g/2005#'. $addrtype);
							if( !empty($addr['street']) ){						
								$street=$doc->createElement('gd:street',$addr['street']);
								$postal->appendChild($street);
							}
							if( !empty($addr['locality']) ){						
								$city=$doc->createElement('gd:city',$addr['locality']);
								$postal->appendChild($city);
							}
							if( !empty($addr['region']) ){						
								$region=$doc->createElement('gd:region',$addr['region']);
								$postal->appendChild($region);
							}
							if( !empty($addr['zipcode']) ){						
								$zip=$doc->createElement('gd:postcode',$addr['zipcode']);
								$postal->appendChild($zip);
							}
							if( !empty($addr['country']) ){						
								$country=$doc->createElement('gd:country',$addr['country']);
								$postal->appendChild($country);
							}
							$entry->appendChild($postal);
						}
					}
				}
			}
			if( empty($edit_id) ) {
				// insert entry
		  		$entryResult = $this->gdata->insertEntry($doc->saveXML(), 'http://www.google.com/m8/feeds/contacts/default/full');
		  		$results->google_id = $entryResult->getEditLink()->href;
			} else {
				// update entry
				$extra_header = array('If-Match'=>'*'); 
				$entryResult  = $this->gdata->updateEntry($doc->saveXML(),$edit_id,null,$extra_header);	
				$results->google_id = $edit_id;	
			}
		}
		catch (Exception $e) {
			$this->error = $e->getMessage();
			write_log('google_contacts', $this->error);
			if(method_exists($rcmail->output, 'show_message'))
				$rcmail->output->show_message($this->error,'error'); 
		}
    	spl_autoload_register('rcube_autoload');      
    	$this->results = $results;
	}
  
 
	function sync_contacts()
  	{
    	if($_SESSION['google_contacts_sync'] &&  $_SESSION['google_contacts']->lastuser == $this->user && $_SESSION['google_contacts']->lastpass == $this->pass )
      		return;
    
    	$rcmail = rcmail::get_instance();    
    	require_once(dirname(__FILE__) . '/google_contacts_backend.php');
    	$CONTACTS = new google_contacts_backend($rcmail->db, $rcmail->user->ID);

 
 		$urltypes=array('home' => 'homepage',
					'home-page' => 'homepage',
					'homepage' => 'homepage',
	 				'work'=>'work',
					'other'=>'other',
					'blog'=>'blog',
					);
 
   		$ptypes=array('home'=>'home',
					'work'=>'work',
					'mobile'=>'mobile',
					'main'=>'main',
					'home_fax'=>'homefax',
					'work_fax'=>'workfax',
					'pager'=>'pager',
					'assistant'=>'assistant',
					'other'=>'other',
					);
		
		// set credentials for ClientLogin authentication
		$user = $this->user;
		$pass = $this->pass;

		$_SESSION['google_contacts']->lastuser = $this->user;
		$_SESSION['google_contacts']->lastpass = $this->pass;

		try {
			// perform login and set protocol version to 3.0
			$client = Zend_Gdata_ClientLogin::getHttpClient( $user, $pass, 'cp');
			$gdata = new Zend_Gdata($client);
			$gdata->setMajorProtocolVersion(3);
      
			$max = $rcmail->config->get('google_contacts_max_results');
			if(empty($max))
				$max = 1000;
			// perform query and get result feed. NOTE: using $user here instead of default gives a lot more data, including notes!
			$query = new Zend_Gdata_Query('http://www.google.com/m8/feeds/contacts/'. $user .'/full?max-results=' . $max );
			  
			$feed = $gdata->getFeed($query);
			$title = $feed->title;
			$totals = $feed->totalResults;

			// delete the cached contents
			$db_table = $rcmail->config->get('db_table_google_contacts');
			$query = "DELETE FROM $db_table WHERE user_id=?";
			$res = $rcmail->db->query($query, $rcmail->user->ID);
			$obj = (array) $this->results;

			// parse feed and extract contact information
			// into simpler objects
			foreach($feed as $entry){
		        $xml = simplexml_load_string($entry->getXML());
				
				$a_record=array();	
				$badrec=false;	
				
				$name = (array) $xml->name;
				$orgName = (string) $xml->organization->orgName; 
				if( !empty($name)){
					$a_record['name']= $name['fullName'];
					$a_record['firstname'] = $name['givenName'];
					$a_record['surname'] = $name['familyName'];
					$a_record['middlename'] = $name['additionalName'];
					$a_record['prefix'] = $name['namePrefix'];
					$a_record['suffix'] = $name['nameSuffix'];
				} elseif( !empty($orgName) ) {
					$a_record['name'] = $orgName;
				} else {
					$badrec=true;	
				}	
				
				$a_record['jobtitle'] = (string) $xml->organization->orgTitle;
				$a_record['organization'] = $orgName;
				$a_record['birthday'] = (string) @$xml->birthday->attributes()->when;	
				$a_record['nickname'] = (string) $xml->nickname;
				$a_record['gender'] = $xml->gender['value'];
				foreach ($xml->im as $e) {
					$prot = str_replace('http://schemas.google.com/g/2005#','',$e['protocol']);
					$a_record['im:'.strtolower($prot) ] = (string) $e['address'];
				}

				// Zend doesn't specify phone number types in $xml, but it is specified in the $entry object..  Dig it out...
				$ext=$entry->getExtensionElements();
				foreach( $ext as $key=>$val ){
					if( $val->rootElement == 'phoneNumber' ){
						$ptype=str_replace('http://schemas.google.com/g/2005#','',$val->extensionAttributes['rel']['value']);
						$a_record['phone:'. $ptypes[$ptype]] = $val->text;
					}
				}

				foreach ($xml->email as $e) {
					$emtype = str_replace('http://schemas.google.com/g/2005#','',$e['rel']);
					$a_record['email:'. $emtype][] = (string) $e['address'];
				}

				foreach ($xml->structuredPostalAddress as $a) {
					$atype= str_replace('http://schemas.google.com/g/2005#','', (string) $a['rel']);
					$address['formatted'] = (string) $a->formattedAddress;
					$address['street'] = (string) $a->street;
					$address['postcode'] = (string) $a->postcode;
					$address['city'] = (string) $a->city;
					$address['region'] = (string) $a->region;
					$address['country'] = (string) $a->country;

					// if we have all address components, use them, otherwise let vcard figure it out from the formatted address
					if( !empty($address['city']) && !empty($address['postcode']) && !empty($address['region']) && !empty($address['street']) ){
						$adr['street'] = $address['street'];
						$adr['locality'] = $address['city'];
						$adr['zipcode'] = $address['postcode'];
						$adr['region'] = $address['region'];
						$adr['country'] = $address['country'];
					} else {
						// Its not a structured address so try to split it up
						$addressblk = explode("\n",$address['formatted']);
						$adr['street'] = $addressblk[0];
						$adr['locality'] = $address[1];
						$adr['region'] = $address[2];
						$adr['zipcode'] = $address[3];
						$adr['country'] = $address[4];
					}
					$a_record['address:'. $atype][]=$adr;
				}

				foreach ($xml->website as $w) {
					$w = (array) $w;
					if( isset( $urltypes[  $w['rel'] ] ))
						$stype = $urltypes[  $w['rel'] ];
					else
						$stype='other';
					$a_record['website:'. $stype][] = (string) $w['href'];	
				}
				$a_record['edit'] = $entry->getEditLink()->href;
				$a_record['notes'] = (string) $entry->content;
				
usleep(5000);				
				
				if( !$badrec )
// start photo
					$photoLink = (object) $entry->getLink('http://schemas.google.com/contacts/2008/rel#photo');
					if ( !empty($photoLink) and !empty($photoLink->extensionAttributes) ) {
//write_log('google_contacts',$a_record);
//write_log('google_contacts',$photolink);
						$photo = $gdata->get($photoLink->getHref()); // fetch image 
						$a_record['photoetag'] = $photo->getHeader('ETag');
						$a_record['photo'] = $photo->getBody(); // we have a jpg image
					}

// end photo
					$CONTACTS->insert($a_record,false); 
      		}
			$_SESSION['google_contacts_sync'] = true;
    	}
		catch (Exception $e) {
			$this->error = $e->getMessage();
			write_log('google_contacts', $this->error);
			if(method_exists($rcmail->output, 'show_message'))
				$rcmail->output->show_message($this->error,'error'); 
		}
		$this->results = $results;
  	}
} 

//  Sometimes rc passes values as arrays that should be flat values.  rc is NOT consistant even on the 
//  same variables.   sometimes they are an array, sometimes they are flat.   This takes the vars
//  that SHOULD be flat and flattens them if they aren't alraady.
function flatten_array( $v ) {
	if( is_array($v) ){
		return implode(' ',$v);
	} else {
		return $v;
	}
}
?>