<?php
/**
 * Template for config/config.php, created from this file on the server and
 * excluded from Git. Every value below is a placeholder.
 *
 * cPanel prefixes database and user names with the account, so they usually
 * look like account_dbname and account_dbuser.
 *
 * The site only reads, except for one INSERT into field_reports. The user
 * needs SELECT on every table and INSERT on field_reports, and nothing else.
 */

return [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'account_dbname',
    'user' => 'account_dbuser',
    'pass' => 'put-the-real-password-here',
];
