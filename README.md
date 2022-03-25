# redcap-autonotify2
A modified version of auto-notify supporting more separate emails for each trigger


 v1_1 updated to allow emails to be sent to members of specific roles, either in all DAGs or only in the same DAG as the record. Also checks for uesr rights expiration - Antony Colles, Alex Coombes- Norwich Clinical Trials Unit, 2017
 
 v1_2 checks for suspended users and will not send emails to them. Updated to work with multiple REDCap_log_event tables. Antony Colles, Norwich Clinical Trials Unit, 2021

*Installation
 1. Save these files in a folder in your REDCap plugins folder
 2. Add a bookmark to the index.html, appending the project ID. This will set up the Plugin and DET for your project, and will also allow yo to add an modify email triggers.

*Note
This plugin saves configuration details and a log of sent emails in the redcap_log_eventx table for your project. As it has to call these config details on every page save, this can lead to slower page saves. 
