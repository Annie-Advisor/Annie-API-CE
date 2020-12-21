# Annie API

Annie Advisor is a service for educational institutions for finding students in need of support. By finding their support needs early, interventions have the most impact and prevent dropouts from happening.

The Annie Advisor system consists of the API (this repository) and a [front end service](https://github.com/Annie-Advisor/Annie-UI-CE).

Annie API contains PHP scripts in a one-per-table basis. Index file is an exception in that it does not communicate with database at all. Also contact and supportneet scripts does fetch data from several tables for efficiency not just one table. Contact script does not have methods for PUT, POST or DELETE either, which is exceptional regarding the scripts.

NB! Most important part to change in scripts is to define the location of settings file. Now it is named `my_app_specific_ini` in file [settings.php](settings.php).

NB2! Second most important thing is to define authentication or what authentication is used. Now there's reference to a SimpleSAMLphp installation with SAML entity `my_app_specific_saml`.

For SMS usage we need access restrictions regarding used Servive Provider and due to security this will not be addressed here any further.

## Setup

Create and modify files on server manually
- [annie.ini] with location and name of your choosing
- [passthru/.htaccess] with suitable access restrictions for SMS Service Provider.

Edit files
- [auth.php](auth.php) and change authentication, at least replace `my_app_specific_saml`
- [settings.php](settings.php) and change location of settings file now named `my_app_specific_ini`


## Contact

To get more information or contact the development team, you can reach us at annie@annieadvisor.com. We'll be setting up an option to contribute in the future.
