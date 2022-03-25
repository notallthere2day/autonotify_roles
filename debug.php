<?php
// Include required files
require_once "../../redcap_connect.php";
require_once "common.php";

$rights = REDCap::getUserRights();
print_array($rights);
?>