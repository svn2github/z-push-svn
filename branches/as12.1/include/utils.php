<?php

/***********************************************
* File      :   utils.php
* Project   :   Z-Push
* Descr     :   
*
* Created   :   03.04.2008
*
*  Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

// saves information about folder data for a specific device    
function _saveFolderData($devid, $folders) {
    if (!is_array($folders) || empty ($folders))
        return false;

    $unique_folders = array ();

    foreach ($folders as $folder) {    

        // don't save folder-ids for emails
        if ($folder->type == SYNC_FOLDER_TYPE_INBOX)
            continue;

        // no folder from that type    or the default folder        
        if (!array_key_exists($folder->type, $unique_folders) || $folder->parentid == 0) {
            $unique_folders[$folder->type] = $folder->serverid;
        }
    }
    
    // Treo does initial sync for calendar and contacts too, so we need to fake 
    // these folders if they are not supported by the backend
    if (!array_key_exists(SYNC_FOLDER_TYPE_APPOINTMENT, $unique_folders))     
        $unique_folders[SYNC_FOLDER_TYPE_APPOINTMENT] = SYNC_FOLDER_TYPE_DUMMY;
    if (!array_key_exists(SYNC_FOLDER_TYPE_CONTACT, $unique_folders))         
        $unique_folders[SYNC_FOLDER_TYPE_CONTACT] = SYNC_FOLDER_TYPE_DUMMY;

    if (!file_put_contents(BASE_PATH.STATE_DIR."/compat-$devid", serialize($unique_folders))) {
        debugLog("_saveFolderData: Data could not be saved!");
    }
}

// returns information about folder data for a specific device    
function _getFolderID($devid, $class) {
    $filename = BASE_PATH.STATE_DIR."/compat-$devid";

    if (file_exists($filename)) {
        $arr = unserialize(file_get_contents($filename));

        if ($class == "Calendar")
            return $arr[SYNC_FOLDER_TYPE_APPOINTMENT];
        if ($class == "Contacts")
            return $arr[SYNC_FOLDER_TYPE_CONTACT];

    }

    return false;
}

/**
 * Function which converts a hex entryid to a binary entryid.
 * @param string @data the hexadecimal string
 */
function hex2bin($data)
{
    $len = strlen($data);
    $newdata = "";

    for($i = 0;$i < $len;$i += 2)
    {
        $newdata .= pack("C", hexdec(substr($data, $i, 2)));
    } 
    return $newdata;
}

function utf8_to_windows1252($string, $option = "")
{
    if (function_exists("iconv")){
        return @iconv("UTF-8", "Windows-1252" . $option, $string);
    }else{
        return utf8_decode($string); // no euro support here
    }
}

function windows1252_to_utf8($string, $option = "")
{
    if (function_exists("iconv")){
        return @iconv("Windows-1252", "UTF-8" . $option, $string);
    }else{
        return utf8_encode($string); // no euro support here
    }
}

function w2u($string) { return windows1252_to_utf8($string); }
function u2w($string) { return utf8_to_windows1252($string); }

function w2ui($string) { return windows1252_to_utf8($string, "//TRANSLIT"); }
function u2wi($string) { return utf8_to_windows1252($string, "//TRANSLIT"); }

/**
 * Truncate an UTF-8 encoded sting correctly
 * 
 * If it's not possible to truncate properly, an empty string is returned 
 *
 * @param string $string - the string
 * @param string $length - position where string should be cut
 * @return string truncated string
 */ 
function utf8_truncate($string, $length) {
    if (strlen($string) <= $length) 
        return $string;
    
    while($length >= 0) {
        if ((ord($string[$length]) < 0x80) || (ord($string[$length]) >= 0xC0))
            return substr($string, 0, $length);
        
        $length--;
    }
    return "";
}


/**
 * Build an address string from the components
 *
 * @param string $street - the street
 * @param string $zip - the zip code
 * @param string $city - the city
 * @param string $state - the state
 * @param string $country - the country
 * @return string the address string or null
 */
function buildAddressString($street, $zip, $city, $state, $country) {
    $out = "";
    
    if (isset($country) && $street != "") $out = $country;
    
    $zcs = "";
    if (isset($zip) && $zip != "") $zcs = $zip;
    if (isset($city) && $city != "") $zcs .= (($zcs)?" ":"") . $city;
    if (isset($state) && $state != "") $zcs .= (($zcs)?" ":"") . $state;
    if ($zcs) $out = $zcs . "\r\n" . $out;
    
    if (isset($street) && $street != "") $out = $street . (($out)?"\r\n\r\n". $out: "") ;
    
    return ($out)?$out:null;
}

function base64uri_decode($uri) {
    $uri = base64_decode($uri);
    $lenDevID = ord($uri{4});
    $lenPolKey = ord($uri{4+(1+$lenDevID)});
    $lenDevType = ord($uri{4+(1+$lenDevID)+(1+$lenPolKey)});
    return unpack("CProtVer/CCommand/vLocale/CDevIDLen/H".($lenDevID*2)."DevID/CPolKeyLen".($lenPolKey == 4 ? "/VPolKey" : "")."/CDevTypeLen/A".($lenDevType)."DevType",$uri);
}

/**
 * Read the correct message body 
 *
 * @param ressource $msg - the message
**/
function eml_ReadMessage($msg) {
    global $protocolversion;
    $rtf = mapi_message_openproperty($msg, PR_RTF_COMPRESSED);
    if (!$rtf) {
	$body = mapi_message_openproperty($msg, PR_BODY);
	$content = "text/plain";
    } else {
        $rtf = preg_replace("/(\n.*)/m","",mapi_decompressrtf($rtf));
        if (strpos($rtf,"\\fromtext") != false || !($protocolversion >= 2.5)) {
	    $body = mapi_message_openproperty($msg, PR_BODY);
	    $content = "text/plain";
	} else {
	    $body = mapi_message_openproperty($msg, PR_HTML);
	    $content = "text/html";
	}
    }
    if (mb_detect_encoding($body) != "UTF-8") 
	$body = iconv("Windows-1252", "UTF-8//TRANSLIT", $body );
    return array('body' => $body,'content' => $content);
}

// START ADDED dw2412 EML Attachment
function buildEMLAttachment($attach) {
    $msgembedded = mapi_attach_openobj($attach);
    $msgprops = mapi_getprops($msgembedded,array(PR_MESSAGE_CLASS,PR_CLIENT_SUBMIT_TIME,PR_DISPLAY_TO,PR_SUBJECT,PR_SENT_REPRESENTING_NAME,PR_SENT_REPRESENTING_EMAIL_ADDRESS));
    $msgembeddedrcpttable = mapi_message_getrecipienttable($msgembedded);
    $msgto = $msgprops[PR_DISPLAY_TO];
    if($msgembeddedrcpttable) {
	$msgembeddedrecipients = mapi_table_queryrows($msgembeddedrcpttable, array(PR_ADDRTYPE, PR_ENTRYID, PR_DISPLAY_NAME, PR_EMAIL_ADDRESS, PR_SMTP_ADDRESS, PR_RECIPIENT_TYPE, PR_RECIPIENT_FLAGS, PR_PROPOSEDNEWTIME, PR_PROPOSENEWTIME_START, PR_PROPOSENEWTIME_END, PR_RECIPIENT_TRACKSTATUS), 0, 99999999);
	foreach($msgembeddedrecipients as $rcpt) {
	    if ($rcpt[PR_DISPLAY_NAME] == $msgprops[PR_DISPLAY_TO]) {
	    $msgto = $rcpt[PR_DISPLAY_NAME];
	    if (isset($rcpt[PR_EMAIL_ADDRESS]) &&
	        $rcpt[PR_EMAIL_ADDRESS] != $msgprops[PR_DISPLAY_TO]) $msgto .= " <".$rcpt[PR_EMAIL_ADDRESS].">";
	        break;
	    }
	}
    }
    $msgsubject = $msgprops[PR_SUBJECT];
    $msgfrom = $msgprops[PR_SENT_REPRESENTING_NAME];
    if (isset($msgprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS]) &&
        $msgprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS] != $msgprops[PR_SENT_REPRESENTING_NAME]) $msgfrom .= " <".$msgprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS].">";
    $msgtime = $msgprops[PR_CLIENT_SUBMIT_TIME];
    $msgembeddedbody = eml_ReadMessage($msgembedded);
    $msgembeddedattachtable = mapi_message_getattachmenttable($msgembedded);
    $msgembeddedattachtablerows = mapi_table_queryallrows($msgembeddedattachtable, array(PR_ATTACH_NUM, PR_ATTACH_METHOD));
    if ($msgembeddedattachtablerows) {
	$boundary = '=_zpush_static';
	$headercontenttype = "multipart/mixed";
	$msgembeddedbody['body'] = 	"Unfortunately your mobile is not able to handle MIME Messages\n".
					"--".$boundary."\n".
					"Content-Type: ".$msgembeddedbody['content']."; charset=utf-8\n".
					"Content-Transfer-Encoding: quoted-printable\n\n".
					$msgembeddedbody['body']."\n";
	foreach ($msgembeddedattachtablerows as $msgembeddedattachtablerow) {
    	    $msgembeddedattach = mapi_message_openattach($msgembedded, $msgembeddedattachtablerow[PR_ATTACH_NUM]);
	    if(!$msgembeddedattach) {
	        debugLog("Unable to open attachment number $attachnum");
	    } else {
	    	$msgembeddedattachprops = mapi_getprops($msgembeddedattach, array(PR_ATTACH_MIME_TAG, PR_ATTACH_LONG_FILENAME,PR_ATTACH_FILENAME,PR_DISPLAY_NAME));
            	if (isset($msgembeddedattachprops[PR_ATTACH_LONG_FILENAME])) 
        	    $attachfilename = w2u($msgembeddedattachprops[PR_ATTACH_LONG_FILENAME]);
        	else if (isset($msgembeddedattachprops[PR_ATTACH_FILENAME]))
		    $attachfilename = w2u($msgembeddedattachprops[PR_ATTACH_FILENAME]);
		else if (isset($msgembeddedattachprops[PR_DISPLAY_NAME]))
		    $attachfilename = w2u($msgembeddedattachprops[PR_DISPLAY_NAME]);
		else
		    $attachfilename = w2u("untitled");
        	if ($msgembeddedattachtablerow[PR_ATTACH_METHOD] == ATTACH_EMBEDDED_MSG) 
        	    $attachfilename .= w2u(".eml");
		$msgembeddedbody['body'] .= "--".$boundary."\n".
			    		    "Content-Type: ".$msgembeddedattachprops[PR_ATTACH_MIME_TAG].";\n".
					    " name=\"".$attachfilename."\"\n".
					    "Content-Transfer-Encoding: base64\n".
					    "Content-Disposition: attachment;\n".
					    " filename=\"".$attachfilename."\"\n\n";
		$msgembeddedattachstream = mapi_openpropertytostream($msgembeddedattach, PR_ATTACH_DATA_BIN);
    		$msgembeddedattachment = "";
    		while(1) {
        	    $msgembeddedattachdata = mapi_stream_read($msgembeddedattachstream, 4096);
        	    if(strlen($msgembeddedattachdata) == 0)
		        break;
		    $msgembeddedattachment .= $msgembeddedattachdata;
		}
		$msgembeddedbody['body'] .= chunk_split(base64_encode($msgembeddedattachment))."\n";
		unset($msgembeddedattachment);
	    }
	}
	$msgembeddedbody['body'] .= "--".$boundary."--\n";
    } else {
	$headercontenttype = $msgembeddedbody['content']."; charset=utf-8";
	$boundary = '';
    }
    $msgembeddedheader = "Subject: ".$msgsubject."\n".
    		         "From: ".$msgfrom."\n".
			 "To: ".$msgto."\n".
			 "Date: ".gmstrftime("%a, %d %b %Y %T +0000",$msgprops[PR_CLIENT_SUBMIT_TIME])."\n".
			 "MIME-Version: 1.0\n".
			 "Content-Type: ".$headercontenttype.";\n".
			 ($boundary ? " boundary=\"".$boundary."\"\n" : "").
			 "\n";
    $stream = mapi_stream_create();
    mapi_stream_setsize($stream,strlen($msgembeddedheader.$msgembeddedbody['body']));
    mapi_stream_write($stream,$msgembeddedheader.$msgembeddedbody['body']);
    mapi_stream_seek($stream,0,STREAM_SEEK_SET);
    return $stream;
}
// END ADDED dw2412 EML Attachment



?>
