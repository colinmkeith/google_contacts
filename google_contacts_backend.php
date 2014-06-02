<?php

/**
 * Google contacts backend
 *
 * Minimal backend for Google contacts
 *
 * @author Roland 'rosali' Liebl
 * @version 1.0
 */

class google_contacts_backend extends rcube_contacts
{
	
	public $name;
	
    function __construct($dbconn, $user)
    {
		$this->name = 'Google Contacts';
        $rcmail = rcmail::get_instance();
        parent::__construct($dbconn, $user);
        $this->db_name = get_table_name('google_contacts');
		$this->ready = true;
	}	
	
  public function get_name()
  {   
    return $this->name;
  }
	
	
}
?>