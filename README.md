Google Contacts
===============

For RoundCube 0.6 and above
---------------------------

Roundcube mail plugin google_contacts, cloned from http://sourceforge.net/projects/roundcubegoogle/

@source http://sourceforge.net/projects/roundcubegoogle/
@version 2.12 - 08/04/2013
@author Les Fenison /-- Original code by Roland 'rosali' Liebl
@licence GNU GPL

Questions, problems, or suggestions? Email me, my email address is in google_contacts.php

Will sync Google contacts both directions including all the new contacts fields in RoundCube 0.9

Usage:
------

1. Create the db table using the SQL in SQL directory.

The table is the same exact format as the contacts table. If you are upgrading
from 0.5.2 you will need to add the words column of type text.

2. Configure ````./config/config.inc.php```` as needed.

The only parameter worth messing with is the
````$rcmail_config['google_contacts_max_results']```` which defaults to 1000.


Requirements: * Get Zend GData APIs

http://framework.zend.com/download/webservices
Copy and paste "Zend" /folder into ./program/lib

File structure must be: ./program/lib/Zend


