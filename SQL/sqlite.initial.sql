CREATE TABLE google_contacts (
  contact_id integer NOT NULL PRIMARY KEY,
  user_id integer NOT NULL default '0',
  changed datetime NOT NULL default '0000-00-00 00:00:00',
  del tinyint NOT NULL default '0',
  name varchar(128) NOT NULL default '',
  email varchar(128) NOT NULL default '',
  firstname varchar(128) NOT NULL default '',
  surname varchar(128) NOT NULL default '',
  vcard text NOT NULL default ''
);

CREATE INDEX ix_google_contacts_user_id ON google_contacts(user_id);
