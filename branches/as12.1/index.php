<?php
/***********************************************
* File      :   index.php
* Project   :   Z-Push
* Descr     :   This is the entry point
*               through which all requests
*               are called.
*
* Created   :   01.10.2007
*
*  Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

ob_start(false, 1048576);

include_once('zpushdefs.php');
include_once("config.php");
include_once("proto.php");
include_once("request.php");
include_once("debug.php");
include_once("compat.php");
include_once("version.php");

// Attempt to set maximum execution time
ini_set('max_execution_time', SCRIPT_TIMEOUT);
set_time_limit(SCRIPT_TIMEOUT);

debugLog("Start ------ THIS IS AN UNOFFICIAL DEVELOPER VERSION!");
debugLog("Z-Push version: $zpush_version");
debugLog("Client IP: ". $_SERVER['REMOTE_ADDR']);
// debugLog(print_r($_SERVER,true));
// debugLog(print_r($_GET,true));
// debugLog(print_r($_POST,true));
// debugLog(print_r(apache_request_headers(),true));
 
$input = fopen("php://input", "r");
$output = fopen("php://output", "w+");

// The script must always be called with authorisation info

if(!isset($_SERVER['PHP_AUTH_PW'])) {
    header("HTTP/1.1 401 Unauthorized");
    header("WWW-Authenticate: Basic realm=\"ZPush\"");
    print("Access denied. Please send authorisation information");
    debugLog("Access denied: no password sent.");
    debugLog("end");
    debugLog("--------");
    return;
}


// split username & domain if received as one
$pos = strrpos($_SERVER['PHP_AUTH_USER'], '\\');
if($pos === false){
    $auth_user = $_SERVER['PHP_AUTH_USER'];
    $auth_domain = '';
}else{
    $auth_domain = substr($_SERVER['PHP_AUTH_USER'],0,$pos);
    $auth_user = substr($_SERVER['PHP_AUTH_USER'],$pos+1);
}
$auth_pw = $_SERVER['PHP_AUTH_PW'];

$cmd = $user = $devid = $devtype = "";

// Parse the standard GET parameters
if(isset($_GET["Cmd"]))
    $cmd = $_GET["Cmd"];
if(isset($_GET["User"]))
    $user = $_GET["User"];
if(isset($_GET["DeviceId"]))
    $devid = $_GET["DeviceId"];
if(isset($_GET["DeviceType"]))
    $devtype = $_GET["DeviceType"];
// Get the parameters from Query String in case they`re not in the get.
// AS >=12.1
if (!isset($_GET['Cmd']) &&
    !isset($_GET['DeviceId']) &&
    !isset($_GET['DeviceType']) &&
    isset($_SERVER['QUERY_STRING']) &&
    strlen($_SERVER['QUERY_STRING']) >= 10) {
    $user = $auth_user;
    $uri_decoded = base64uri_decode($_SERVER['QUERY_STRING']);
    $devid = $uri_decoded['DevID'];
    switch($uri_decoded['DevType']) {
	case 'PPC' 	: $devtype = 'PocketPC'; break;
	case 'SP' 	: $devtype = 'SmartPhone'; break;
    };
    switch($uri_decoded['Command']) {
	case '0' 	: $cmd = 'Sync'; break;
	case '1' 	: $cmd = 'SendMail'; break;
	case '2' 	: $cmd = 'SmartForward'; break;
	case '3' 	: $cmd = 'SmartReply'; break;
	case '4' 	: $cmd = 'GetAttachment'; break;
	case '9' 	: $cmd = 'FolderSync'; break;
	case '10' 	: $cmd = 'FolderCreate'; break;
	case '11' 	: $cmd = 'FolderDelete'; break;
	case '12' 	: $cmd = 'FolderUpdate'; break;
	case '13' 	: $cmd = 'MoveItems'; break;
	case '14' 	: $cmd = 'GetItemEstimate'; break;
	case '15' 	: $cmd = 'MeetingResponse'; break;
	case '16' 	: $cmd = 'Search'; break;
	case '17' 	: $cmd = 'Settings'; break;
	case '18' 	: $cmd = 'Ping'; break;
	case '19' 	: $cmd = 'ItemOperations'; break;
	case '20' 	: $cmd = 'Provision'; break;
	case '21' 	: $cmd = 'ResolveRecipients'; break;
	case '22' 	: $cmd = 'ValidateCert'; break;
    }
};

// The GET parameters are required
if($_SERVER["REQUEST_METHOD"] == "POST") {
    if(!isset($user) || !isset($devid) || !isset($devtype)) {
        print("Your device requested the Z-Push URL without the required GET parameters");
        return;
    }
}

// Get the request headers so we can see the versions
$requestheaders = apache_request_headers();
if (isset($requestheaders["Ms-Asprotocolversion"])) $requestheaders["MS-ASProtocolVersion"] = $requestheaders["Ms-Asprotocolversion"];
if(isset($requestheaders["MS-ASProtocolVersion"]) ||
   isset($uri_decoded['ProtVer'])) {
    global $protocolversion;
    if (isset($requestheaders["MS-ASProtocolVersion"])) 
	$protocolversion = $requestheaders["MS-ASProtocolVersion"];
    else
	$protocolversion = $uri_decoded['ProtVer']/10;
    debugLog("Client supports version " . $protocolversion);
} else {
    global $protocolversion;

    $protocolversion = "1.0";
}

// START ADDED dw2412 Support Multipart response
// From AS 12.1 there is no request header field for this. 
// Looks like that multipart is in general accepted, requested, expected and ...
// this is documented different in MS-ASCMD Page 52 (v20091030)
// || 
//    $protocolversion>=12.1
//
if ((isset($requestheaders["MS-ASAcceptMultiPart"]) &&
    $requestheaders["MS-ASAcceptMultiPart"] == "T")) {
    $multipart = true;
} else {
    $multipart = false;
}
// END ADDED dw2412 Support Multipart response

// START ADDED dw2412 Support gzip compression in result
if (1==2 && isset($requestheaders["Accept-Encoding"])) {
    $encodings = explode(", ",$requestheaders["Accept-Encoding"]);
    debugLog("Current zlib output compression setting: ".ini_get("zlib.output_compression"));

    if (array_search("gzip",$encodings) !== false &&
	function_exists('gzencode')) {
	ini_set("zlib.output_compression",'0');
	debugLog("Enabled zlib output compression");
	define ("GZIP_OUTPUT",true);
    } else {
	ini_set("zlib.output_compression",'0');
	debugLog("Disabled zlib output compression");
	define ("GZIP_OUTPUT",false);
    }
} else {
    define ("GZIP_OUTPUT",false);
}
// END ADDED dw2412 Support gzip compression in result

if (isset($requestheaders["X-Ms-Policykey"])) $requestheaders["X-MS-PolicyKey"] = $requestheaders["X-Ms-Policykey"];
if (isset($requestheaders["X-MS-PolicyKey"]) ||
    isset($uri_decoded['PolKey'])) {
    global $policykey;
    if (isset($requestheaders["X-MS-PolicyKey"])) 
	$policykey = $requestheaders["X-MS-PolicyKey"];
    else 
	$policykey = $uri_decoded['PolKey'];
} else {
    global $policykey;
    $policykey = 0;
}

//get user agent
if (isset($requestheaders["User-Agent"])) {
    global $useragent;
    $useragent = $requestheaders["User-Agent"];
} else {
    global $useragent;
    $useragent = "unknown";
}

// Load our backend driver
$backend_dir = opendir(BASE_PATH . "/backend");
while($entry = readdir($backend_dir)) {
    if(substr($entry,0,1) == "." || substr($entry,-3) != "php")
        continue;

    if (!function_exists("mapi_logon") && ($entry == "ics.php"))
        continue;

    include_once(BASE_PATH . "/backend/" . $entry);
}

// Initialize our backend
$backend = new $BACKEND_PROVIDER();

if($backend->Logon($auth_user, $auth_domain, $auth_pw) == false  && !$policykey) {
    header("HTTP/1.1 401 Unauthorized");
    header("WWW-Authenticate: Basic realm=\"ZPush\"");
    print("Access denied. Username or password incorrect.");
    debugLog("Access denied: backend logon failed.");
    debugLog("end");
    debugLog("--------");
    return;
}

// check policy header 
if (PROVISIONING === true && $_SERVER["REQUEST_METHOD"] != 'OPTIONS' && $cmd != 'Ping' && $cmd != 'Provision' && 
    $backend->CheckPolicy($policykey, $devid) != SYNC_PROVISION_STATUS_SUCCESS &&
    (LOOSE_PROVISIONING === false ||
    (LOOSE_PROVISIONING === true && (isset($requestheaders["X-MS-PolicyKey"]) || isset($uri_decoded['PolKey']) )))) {    	
    header("HTTP/1.1 449 Retry after sending a PROVISION command");
    // dw2412 changed to support AS14 Protocol
    header("MS-Server-ActiveSync: 14.00.048.018");
    header("MS-ASProtocolVersions: 1.0,2.0,2.1,2.5,12.0,12.1,14.0");
    header("MS-ASProtocolRevisions: 12.1r1");
    header("X-MS-MV: 14.0.255");
    // CHANGED dw2412 Support for Settings and ItemOperations command
    header("MS-ASProtocolCommands: Sync,SendMail,SmartForward,SmartReply,GetAttachment,GetHierarchy,CreateCollection,DeleteCollection,MoveCollection,FolderSync,FolderCreate,FolderDelete,FolderUpdate,MoveItems,GetItemEstimate,MeetingResponse,Provision,ResolveRecipients,ValidateCert,Settings,Search,Ping,ItemOperations");
    header("Cache-Control: private");
    debugLog("POST cmd $cmd denied: Retry after sending a PROVISION command");
    debugLog("end");
    debugLog("--------");
    return;
}

// $user is usually the same as the PHP_AUTH_USER. This allows you to sync the 'john' account if you
// have sufficient privileges as user 'joe'.
if($backend->Setup($user, $devid, $protocolversion) == false) {
    header("HTTP/1.1 401 Unauthorized");
    header("WWW-Authenticate: Basic realm=\"ZPush\"");
    print("Access denied or user '$user' unknown.");
    debugLog("Access denied: backend setup failed.");
    debugLog("end");
    debugLog("--------");
    return;
}

// Do the actual request
switch($_SERVER["REQUEST_METHOD"]) {
    case 'OPTIONS':
	// dw2412 changed to support AS14 Protocol
        header("MS-Server-ActiveSync: 14.00.048.018");
        header("MS-ASProtocolVersions: 1.0,2.0,2.1,2.5,12.0,12.1,14.0");
	header("MS-ASProtocolRevisions: 12.1r1");
	header("X-MS-MV: 14.0.255");
	// START ADDED dw2412 
	// Compare and send X-MS-RP depending on Protocol Version string
	// write the new Protocol Version string if update send
	include_once ('statemachine.php');
	$protstate = new StateMachine($devid);
	if ($protstate->getProtocolState() != "2.0,2.1,2.5,12.0,12.1,14.0") {
    	    header("X-MS-RP: 2.0,2.1,2.5,12.0,12.1,14.0");
	    debugLog("Sending X-MS-RP to update Protocol Version on Device");
    	    $protstate->setProtocolState("2.0,2.1,2.5,12.0,12.1,14.0");
    	}
    	unset($protstate);
	// END ADDED dw2412 
	// START CHANGED dw2412 Settings and ItemOperations Command Support
        header("MS-ASProtocolCommands: Sync,SendMail,SmartForward,SmartReply,GetAttachment,GetHierarchy,CreateCollection,DeleteCollection,MoveCollection,FolderSync,FolderCreate,FolderDelete,FolderUpdate,MoveItems,GetItemEstimate,MeetingResponse,ResolveRecipients,ValidateCert,Provision,Settings,Search,Ping,ItemOperations");
        debugLog("Options request");
        break;
    case 'POST':
	// dw2412 changed to support AS14 Protocol
	header("MS-Server-ActiveSync: 14.0");
        debugLog("POST cmd: $cmd");
        // Do the actual request
        if(!HandleRequest($backend, $cmd, $devid, $protocolversion, $multipart)) {
            // Request failed. Try to output some kind of error information. We can only do this if
            // output had not started yet. If it has started already, we can't show the user the error, and
            // the device will give its own (useless) error message.
            if(!headers_sent()) {
                header("Content-type: text/html");
                print("<BODY>\n");
                print("<h3>Error</h3><p>\n");
                print("There was a problem processing the <i>$cmd</i> command from your PDA.\n");
                print("<p>Here is the debug output:<p><pre>\n");
                print(getDebugInfo());
                print("</pre>\n");
                print("</BODY>\n");
            }
        }
        break;
    case 'GET':
        header("Content-type: text/html");
        print("<BODY>\n");
        print("<h3>GET not supported</h3><p>\n");
        print("This is the z-push location and can only be accessed by Microsoft ActiveSync-capable devices.");
        print("</BODY>\n");
        break;
}


$len = ob_get_length();
$data = ob_get_contents();
ob_end_clean();

// Unfortunately, even though zpush can stream the data to the client
// with a chunked encoding, using chunked encoding also breaks the progress bar
// on the PDA. So we de-chunk here and just output a content-length header and
// send it as a 'normal' packet. If the output packet exceeds 1MB (see ob_start)
// then it will be sent as a chunked packet anyway because PHP will have to flush
// the buffer.
if (!headers_sent()) { // dw2412 need to do this since i.E. getAttachmentData Request starts output in Backend...
    // START CHANGED dw2412 Support gzip compression in result
    // TODO: Find out what the hell is going on with compress DocumentLibrary body. If some needs the source
    //	 pakets from windump, please mail me.
    if (GZIP_OUTPUT == true &&
	!defined("OVERRIDE_GZIP") &&
        ($gz_data = gzencode($data,9,FORCE_GZIP)) !== false) {
	$gzlen=strlen(bin2hex($gz_data))/2;
        if ($len > $gzlen) {
    	    debugLog("GZip Results: Original Size ".$len." / Compress Size ".$gzlen." byte(s) --> Send compressed data");
	    header("Content-Encoding: gzip");
	    header("Content-Length: ".$gzlen);
    	    print $gz_data;
	} else {
	    debugLog("GZip Results: Original Size ".$len." / Compress Size ".$gzlen." byte(s) --> Send uncompressed data");
	    header("Content-Length: ".$len);
	    print $data;
	}
    } else {
	debugLog("Output Results: GZip not used send Original Size ".$len." byte(s) --> Send uncompressed data");
	header("Content-Length: ".$len);
	print $data;
    }
    // END CHANGED dw2412 Support gzip compression in result
    // destruct backend after all data is on the stream
} else { // just output what we maybe got from the content buffer
    debugLog("Output ".$len." Bytes of data found in content buffer since output already started earlier in backend");
    print $data;
}
$backend->Logoff();

debugLog("end");
debugLog("--------");
?>