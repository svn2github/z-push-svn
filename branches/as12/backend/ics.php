<?php
/***********************************************
* File      :   ics.php
* Project   :   Z-Push
* Descr     :   This is a generic class that is
*               used by both the proxy importer
*               (for outgoing messages) and our
*               local importer (for incoming
*               messages). Basically all shared
*               conversion data for converting
*               to and from MAPI objects is in here.
*
* Created   :   01.10.2007
*
*  Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

include_once('mapi/mapi.util.php');
include_once('mapi/mapidefs.php');
include_once('mapi/mapitags.php');
include_once('mapi/mapicode.php');
include_once('mapi/mapiguid.php');
include_once('mapi/class.recurrence.php');
include_once('mapi/class.meetingrequest.php');
include_once('mapi/class.freebusypublish.php');

// START ADDED dw2412 to support linked appointments
if (include_file_exists('mapi/class.linkedappointment.php') == true) {
    include_once 'mapi/class.linkedappointment.php';
    define("LINKED_APPOINTMENTS",true);
} else {
    define("LINKED_APPOINTMENTS",false);
    debugLog("Working without linked appointments - necessary class cannot be found in include path");
}
// END ADDED dw2412

// START ADDED dw2412 to support DocumentLibrary functionality
if (include_file_exists('include/smb.php') == true) {
    include_once 'include/smb.php';
    define("DOCUMENTLIBRARY",true);
} else {
    define("DOCUMENTLIBRARY",false);
    debugLog("SMB4PHP not found - DocumentLibrary Functions disabled");
}
// END ADDED dw2412


// We need this in order to parse the rfc822 messages
//that are passed in SendMail
include_once('mimeDecode.php');
require_once('z_RFC822.php');

include_once('proto.php');
include_once('backend.php');
include_once('z_tnef.php');
include_once('z_ical.php');
include_once('z_RTF.php');


function GetPropIDFromString($store, $mapiprop) {
    if(is_string($mapiprop)) {
        $split = explode(":", $mapiprop);

        if(count($split) != 3)
            continue;

        if(substr($split[2], 0, 2) == "0x") {
            $id = hexdec(substr($split[2], 2));
        } else
            $id = $split[2];

        $named = mapi_getidsfromnames($store, array($id), array(makeguid($split[1])));

        $mapiprop = mapi_prop_tag(constant($split[0]), mapi_prop_id($named[0]));
    } else {
        return $mapiprop;
    }

    return $mapiprop;
}

function readPropStream($message, $prop)
{
    $stream = mapi_openproperty($message, $prop, IID_IStream, 0, 0);
    $data = "";
    $string = "";
    while(1) {
        $data = mapi_stream_read($stream, 1024);
        if(strlen($data) == 0)
            break;
        $string .= $data;
}

    return $string;
}

function getContactPicRestriction() {
    return array ( RES_PROPERTY,
                    array (
                        RELOP => RELOP_EQ,
                        ULPROPTAG => mapi_prop_tag(PT_BOOLEAN, 0x7FFF),
                        VALUE => true
                    )
    );
}

class MAPIMapping {
    var $_contactmapping = array (
                            "anniversary" => PR_WEDDING_ANNIVERSARY,
                            "assistantname" => PR_ASSISTANT,
                            "assistnamephonenumber" => PR_ASSISTANT_TELEPHONE_NUMBER,
                            "birthday" => PR_BIRTHDAY,
                            "body" => PR_BODY,
                            "business2phonenumber" => PR_BUSINESS2_TELEPHONE_NUMBER,
                            "businesscity" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8046",
                            "businesscountry" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8049",
                            "businesspostalcode" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8048",
                            "businessstate" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8047",
                            "businessstreet" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8045",
                            "businessfaxnumber" => PR_BUSINESS_FAX_NUMBER,
                            "businessphonenumber" => PR_OFFICE_TELEPHONE_NUMBER,
                            "carphonenumber" => PR_CAR_TELEPHONE_NUMBER,
                            "categories" => "PT_MV_STRING8:{00020329-0000-0000-C000-000000000046}:Keywords",
                            "children" => PR_CHILDRENS_NAMES,
                            "companyname" => PR_COMPANY_NAME,
                            "department" => PR_DEPARTMENT_NAME,
                            "email1address" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8083",
                            "email2address" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8093",
                            "email3address" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x80A3",
                            "fileas" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8005",
                            "firstname" => PR_GIVEN_NAME,
                            "home2phonenumber" => PR_HOME2_TELEPHONE_NUMBER,
                            "homecity" => PR_HOME_ADDRESS_CITY,
                            "homecountry" => PR_HOME_ADDRESS_COUNTRY,
                            "homepostalcode" => PR_HOME_ADDRESS_POSTAL_CODE,
                            "homestate" => PR_HOME_ADDRESS_STATE_OR_PROVINCE,
                            "homestreet" => PR_HOME_ADDRESS_STREET,
                            "homefaxnumber" => PR_HOME_FAX_NUMBER,
                            "homephonenumber" => PR_HOME_TELEPHONE_NUMBER,
                            "jobtitle" => PR_TITLE,
                            "lastname" => PR_SURNAME,
                            "middlename" => PR_MIDDLE_NAME,
                            "mobilephonenumber" => PR_CELLULAR_TELEPHONE_NUMBER,
                            "officelocation" => PR_OFFICE_LOCATION,
                            "othercity" => PR_OTHER_ADDRESS_CITY,
                            "othercountry" => PR_OTHER_ADDRESS_COUNTRY,
                            "otherpostalcode" => PR_OTHER_ADDRESS_POSTAL_CODE,
                            "otherstate" => PR_OTHER_ADDRESS_STATE_OR_PROVINCE,
                            "otherstreet" => PR_OTHER_ADDRESS_STREET,
                            "pagernumber" => PR_PAGER_TELEPHONE_NUMBER,
                            "radiophonenumber" => PR_RADIO_TELEPHONE_NUMBER,
                            "spouse" => PR_SPOUSE_NAME,
                            "suffix" => PR_GENERATION,
                            "title" => PR_DISPLAY_NAME_PREFIX,
                            "webpage" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x802b",
                            "yomicompanyname" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x802e",
                            "yomifirstname" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x802c",
                            "yomilastname" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x802d",
                            "rtf" => PR_RTF_COMPRESSED,
                            // picture
                            "customerid" => PR_CUSTOMER_ID,
                            "governmentid" => PR_GOVERNMENT_ID_NUMBER,
                            "imaddress" => "PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8062",
                            // imaddress2
                            // imaddress3
                            "managername" => PR_MANAGER_NAME,
                            "companymainphone" => PR_COMPANY_MAIN_PHONE_NUMBER,
                            "accountname" => PR_ACCOUNT,
                            "nickname" => PR_NICKNAME,
                            // mms
                            );

    var $_emailmapping = array (
                            // from
                            "datereceived" => PR_MESSAGE_DELIVERY_TIME,
                            "displayname" => PR_SUBJECT,
                            "displayto" => PR_DISPLAY_TO,
                            "importance" => PR_IMPORTANCE,
                            "messageclass" => PR_MESSAGE_CLASS,
                            "subject" => PR_SUBJECT,
                            "read" => PR_MESSAGE_FLAGS,
                            // "to" // need to be generated with SMTP addresses
                            // "cc"
                            // "threadtopic" => PR_CONVERSATION_TOPIC,
                            "internetcpid" => PR_INTERNET_CPID,
                            );

    var $_emailflagmapping = array (
		    	    "flagstatus" => PR_FLAG_STATUS,
		    	    "flagicon" => PR_FLAG_ICON,
			    "completetime" => PR_FLAG_COMPLETE_TIME,
			    "flagtype" => "PT_STRING8:{00062008-0000-0000-C000-000000000046}:0x85A4",
			    "ordinaldate" => "PT_SYSTIME:{00062008-0000-0000-C000-000000000046}:0x85A0",
			    "subordinaldate" => "PT_STRING8:{00062008-0000-0000-C000-000000000046}:0x85A1",
			    "reminderset" => "PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8503",
			    "remindertime" => "PT_SYSTIME:{00062008-0000-0000-C000-000000000046}:0x8502",
			    "startdate" => "PT_SYSTIME:{00062003-0000-0000-C000-000000000046}:0x8104",
			    "duedate" => "PT_SYSTIME:{00062003-0000-0000-C000-000000000046}:0x8105",
			    "datecompleted" => "PT_SYSTIME:{00062003-0000-0000-C000-000000000046}:0x810F",
                            );

    var $_meetingrequestmapping = array (
                            "responserequested" => PR_RESPONSE_REQUESTED,
                            // timezone
                            "alldayevent" => "PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8215",
                            "busystatus" => "PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8205",
                            "rtf" => PR_RTF_COMPRESSED,
                            "dtstamp" => PR_LAST_MODIFICATION_TIME,
                            "endtime" => "PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x820e",
                            "location" => "PT_STRING8:{00062002-0000-0000-C000-000000000046}:0x8208",
                            // recurrences
                            "reminder" => "PT_LONG:{00062008-0000-0000-C000-000000000046}:0x8501",
                            "starttime" => "PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x820d",
                            "sensitivity" => PR_SENSITIVITY,
                            );

    var $_appointmentmapping = array (
                            "alldayevent" => "PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8215",
                            "body" => PR_BODY,
                            "busystatus" => "PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8205",
                            "categories" => "PT_MV_STRING8:{00020329-0000-0000-C000-000000000046}:Keywords",
                            "rtf" => PR_RTF_COMPRESSED,
                            "dtstamp" => PR_LAST_MODIFICATION_TIME,
                            "endtime" => "PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x820e",
                            "location" => "PT_STRING8:{00062002-0000-0000-C000-000000000046}:0x8208",
                            "meetingstatus" => "PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8217",
                            // "organizeremail" => PR_SENT_REPRESENTING_EMAIL,
                            // "organizername" => PR_SENT_REPRESENTING_NAME,
                            "reminder" => "PT_LONG:{00062008-0000-0000-C000-000000000046}:0x8501",
                            "sensitivity" => PR_SENSITIVITY,
                            "subject" => PR_SUBJECT,
                            "starttime" => "PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x820d",
                            "uid" => "PT_BINARY:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x3",
                            );

    var $_taskmapping = array (
                            "body" => PR_BODY,
                            "categories" => "PT_MV_STRING8:{00020329-0000-0000-C000-000000000046}:Keywords",
                            "complete" => "PT_BOOLEAN:{00062003-0000-0000-C000-000000000046}:0x811C",
                            "datecompleted" => "PT_SYSTIME:{00062003-0000-0000-C000-000000000046}:0x810F",
                            "duedate" => "PT_SYSTIME:{00062003-0000-0000-C000-000000000046}:0x8105",
                            "importance" => PR_IMPORTANCE,
                            // recurrence
                            // regenerate
                            // deadoccur
                            "reminderset" => "PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8503",
                            "remindertime" => "PT_SYSTIME:{00062008-0000-0000-C000-000000000046}:0x8502",
                            "sensitivity" => PR_SENSITIVITY,
                            "startdate" => "PT_SYSTIME:{00062003-0000-0000-C000-000000000046}:0x8104",
                            "subject" => PR_SUBJECT,
                            "rtf" => PR_RTF_COMPRESSED,
                            );

    // Sets the properties in a MAPI object according to an Sync object and a property mapping
    function _setPropsInMAPI($mapimessage, $message, $mapping) {
        foreach ($mapping as $asprop => $mapiprop) {
            if(isset($message->$asprop)) {
                $mapiprop = $this->_getPropIDFromString($mapiprop);

                // UTF8->windows1252.. this is ok for all numerical values
                if(mapi_prop_type($mapiprop) != PT_BINARY && mapi_prop_type($mapiprop) != PT_MV_BINARY) {
                    if(is_array($message->$asprop))
                        $value = array_map("u2wi", $message->$asprop);
                    else
                        $value = u2wi($message->$asprop);
                } else {
                    $value = $message->$asprop;
                }

                // Make sure the php values are the correct type
                switch(mapi_prop_type($mapiprop)) {
                    case PT_BINARY:
                    case PT_STRING8:
                        settype($value, "string");
                        break;
                    case PT_BOOLEAN:
                        settype($value, "boolean");
                        break;
                    case PT_SYSTIME:
                    case PT_LONG:
                        settype($value, "integer");
                        break;
                }

                // decode base64 value
                if($mapiprop == PR_RTF_COMPRESSED) {
                    $value = base64_decode($value);
                    if(strlen($value) == 0)
                        continue; // PDA will sometimes give us an empty RTF, which we'll ignore.

                    // Note that you can still remove notes because when you remove notes it gives
                    // a valid compressed RTF with nothing in it.

                }

                mapi_setprops($mapimessage, array($mapiprop => $value));
            }
        }

    }

    // Gets the properties from a MAPI object and sets them in the Sync object according to mapping
    function _getPropsFromMAPI(&$message, $mapimessage, $mapping) {
        foreach ($mapping as $asprop => $mapipropstring) {
            // Get the MAPI property we need to be reading
            $mapiprop = $this->_getPropIDFromString($mapipropstring);

            $prop = mapi_getprops($mapimessage, array($mapiprop));

            // Get long strings via openproperty
            if(isset($prop[mapi_prop_tag(PT_ERROR, mapi_prop_id($mapiprop))])) {
                if($prop[mapi_prop_tag(PT_ERROR, mapi_prop_id($mapiprop))] == -2147024882 || // 32 bit
                   $prop[mapi_prop_tag(PT_ERROR, mapi_prop_id($mapiprop))] == 2147942414) {  // 64 bit
                    $prop = array($mapiprop => readPropStream($mapimessage, $mapiprop));
                }
            }

            if(isset($prop[$mapiprop])) {
                if(mapi_prop_type($mapiprop) == PT_BOOLEAN) {
                    // Force to actual '0' or '1'
                    if($prop[$mapiprop])
                        $message->$asprop = 1;
                    else
                        $message->$asprop = 0;
                } else {
                    // Special handling for PR_MESSAGE_FLAGS
                    if($mapiprop == PR_MESSAGE_FLAGS)
                        $message->$asprop = $prop[$mapiprop] & 1; // only look at 'read' flag
                    else if($mapiprop == PR_RTF_COMPRESSED)
                        $message->$asprop = base64_encode($prop[$mapiprop]); // send value base64 encoded
                    else if(is_array($prop[$mapiprop]))
                        $message->$asprop = array_map("w2u", $prop[$mapiprop]);
                    else {
                        if(mapi_prop_type($mapiprop) != PT_BINARY && mapi_prop_type($mapiprop) != PT_MV_BINARY)
                            $message->$asprop = w2u($prop[$mapiprop]);
                        else
                            $message->$asprop = $prop[$mapiprop];
                    }
                }
            }
        }
    }

    // Parses a property from a string. May be either an ULONG, which is a direct property ID,
    // or a string with format "PT_TYPE:{GUID}:StringId" or "PT_TYPE:{GUID}:0xXXXX" for named
    // properties. Returns the property tag.
    function _getPropIDFromString($mapiprop) {
        return GetPropIDFromString($this->_store, $mapiprop);
    }

    // PHP localtime function replacement that includes timezone    
    function _localtimeByTZ($gmtts,$tz, $assoc = false) {
	if ($tz == false) return localtime($gmtts);
	$local = $this->_getLocaltimeByTZ($gmtts,$tz);
	if ($assoc == true) 
	    list($arr['tm_isdst'], $arr['tm_yday'], $arr['tm_wday'], $arr['tm_year'], $arr['tm_mon'], $arr['tm_mday'], $arr['tm_hour'], $arr['tm_min'], $arr['tm_sec']) = 
		    split('\|',gmstrftime(($this->_isDST($local,$tz) === false ? "0" : "1")."|".(gmstrftime("%j",$local)-1)."|%w|".(gmstrftime("%Y",$local)-1900)."|".(gmstrftime("%m",$local)-1)."|%d|%H|%M|%S",$local));
	else    
	    $arr = split('\|',gmstrftime("%S|%M|%H|%d|".(gmstrftime("%m",$local)-1)."|".(gmstrftime("%Y",$local)-1900)."|%w|".(gmstrftime("%j",$local)-1)."|".($this->_isDST($local,$tz) === false ? "0" : "1"),$local));
	foreach($arr as $key=>$value) {
	    $arr[$key] = (int)$value;
	}
	return $arr;
    }

    function _getGMTTZ() {
        $tz = array("bias" => 0, "stdbias" => 0, "dstbias" => 0, "dstendyear" => 0, "dstendmonth" =>0, "dstendday" =>0, "dstendweek" => 0, "dstendhour" => 0, "dstendminute" => 0, "dstendsecond" => 0, "dstendmillis" => 0,
                                      "dststartyear" => 0, "dststartmonth" =>0, "dststartday" =>0, "dststartweek" => 0, "dststarthour" => 0, "dststartminute" => 0, "dststartsecond" => 0, "dststartmillis" => 0);

        return $tz;
    }

    // Unpack timezone info from MAPI
    function _getTZFromMAPIBlob($data) {
        $unpacked = unpack("lbias/lstdbias/ldstbias/" .
                           "vconst1/vdstendyear/vdstendmonth/vdstendday/vdstendweek/vdstendhour/vdstendminute/vdstendsecond/vdstendmillis/" .
                           "vconst2/vdststartyear/vdststartmonth/vdststartday/vdststartweek/vdststarthour/vdststartminute/vdststartsecond/vdststartmillis", $data);

        return $unpacked;
    }

    // Unpack timezone info from Sync
    function _getTZFromSyncBlob($data) {
        $tz = unpack(    "lbias/a64name/vdstendyear/vdstendmonth/vdstendday/vdstendweek/vdstendhour/vdstendminute/vdstendsecond/vdstendmillis/" .
                        "lstdbias/a64name/vdststartyear/vdststartmonth/vdststartday/vdststartweek/vdststarthour/vdststartminute/vdststartsecond/vdststartmillis/" .
                        "ldstbias", $data);

        // Make the structure compatible with class.recurrence.php
        $tz["timezone"] = $tz["bias"];
        $tz["timezonedst"] = $tz["dstbias"];

        return $tz;
    }

    // Pack timezone info for Sync
    function _getSyncBlobFromTZ($tz) {
        $packed = pack("la64vvvvvvvv" . "la64vvvvvvvv" . "l",
                $tz["bias"], "", 0, $tz["dstendmonth"], $tz["dstendday"], $tz["dstendweek"], $tz["dstendhour"], $tz["dstendminute"], $tz["dstendsecond"], $tz["dstendmillis"],
                $tz["stdbias"], "", 0, $tz["dststartmonth"], $tz["dststartday"], $tz["dststartweek"], $tz["dststarthour"], $tz["dststartminute"], $tz["dststartsecond"], $tz["dststartmillis"],
                $tz["dstbias"]);

        return $packed;
    }

    // Pack timezone info for MAPI
    function _getMAPIBlobFromTZ($tz) {
        $packed = pack("lll" . "vvvvvvvvv" . "vvvvvvvvv",
                      $tz["bias"], $tz["stdbias"], $tz["dstbias"],
                      0, 0, $tz["dstendmonth"], $tz["dstendday"], $tz["dstendweek"], $tz["dstendhour"], $tz["dstendminute"], $tz["dstendsecond"], $tz["dstendmillis"],
                      0, 0, $tz["dststartmonth"], $tz["dststartday"], $tz["dststartweek"], $tz["dststarthour"], $tz["dststartminute"], $tz["dststartsecond"], $tz["dststartmillis"]);

        return $packed;
    }

    // Checks the date to see if it is in DST, and returns correct GMT date accordingly
    function _getGMTTimeByTZ($localtime, $tz) {
        if(!isset($tz) || !is_array($tz))
            return $localtime;

        if($this->_isDST($localtime, $tz))
            return $localtime + $tz["bias"]*60 + $tz["dstbias"]*60;
        else
            return $localtime + $tz["bias"]*60;
    }

    // Returns the local time for the given GMT time, taking account of the given timezone
    function _getLocaltimeByTZ($gmttime, $tz) {
        if(!isset($tz) || !is_array($tz))
            return $gmttime;

        if($this->_isDST($gmttime - $tz["bias"]*60, $tz)) // may bug around the switch time because it may have to be 'gmttime - bias - dstbias'
            return $gmttime - $tz["bias"]*60 - $tz["dstbias"]*60;
        else
            return $gmttime - $tz["bias"]*60;
    }

    // Returns TRUE if it is the summer and therefore DST is in effect
    function _isDST($localtime, $tz) {
	// dw2412 in case the dststartmonth or dstendmonth is 0 we need to abort. irregualar tz definition (which could mean none)
        if(!isset($tz) || !is_array($tz) || $tz["dststartmonth"] == 0 || $tz["dstendmonth"] == 0)
            return false;

        $year = gmdate("Y", $localtime);
        $start = $this->_getTimestampOfWeek($year, $tz["dststartmonth"], $tz["dststartweek"], $tz["dststartday"], $tz["dststarthour"], $tz["dststartminute"], $tz["dststartsecond"]);
        $end = $this->_getTimestampOfWeek($year, $tz["dstendmonth"], $tz["dstendweek"], $tz["dstendday"], $tz["dstendhour"], $tz["dstendminute"], $tz["dstendsecond"]);

        if($start < $end) {
            // northern hemisphere (july = dst)
          if($localtime >= $start && $localtime < $end)
              $dst = true;
          else
              $dst = false;
        } else {
            // southern hemisphere (january = dst)
          if($localtime >= $end && $localtime < $start)
              $dst = false;
          else
              $dst = true;
        }

        return $dst;
    }

    // Returns the local timestamp for the $week'th $wday of $month in $year at $hour:$minute:$second
    function _getTimestampOfWeek($year, $month, $week, $wday, $hour, $minute, $second)
    {
        $date = gmmktime($hour, $minute, $second, $month, 1, $year);

        // Find first day in month which matches day of the week
        while(1) {
            $wdaynow = gmdate("w", $date);
            if($wdaynow == $wday)
                break;
            $date += 24 * 60 * 60;
        }

        // Forward $week weeks (may 'overflow' into the next month)
        $date = $date + $week * (24 * 60 * 60 * 7);

        // Reverse 'overflow'. Eg week '10' will always be the last week of the month in which the
        // specified weekday exists
        while(1) {
            $monthnow = gmdate("n", $date); // gmdate returns 1-12, dw2412: removed -1 since months in tz starts with 1
            if($monthnow > $month)
                $date = $date - (24 * 7 * 60 * 60);
            else
                break;
        }

        return $date;
    }

    // Normalize the given timestamp to the start of the day
    function _getDayStartOfTimestamp($timestamp) {
        return $timestamp - ($timestamp % (60 * 60 * 24));
    }

    function _getSMTPAddressFromEntryID($entryid) {
        $ab = mapi_openaddressbook($this->_session);

        $mailuser = mapi_ab_openentry($ab, $entryid);
        if(!$mailuser)
            return "";

        $props = mapi_getprops($mailuser, array(PR_ADDRTYPE, PR_SMTP_ADDRESS, PR_EMAIL_ADDRESS));

        $addrtype = isset($props[PR_ADDRTYPE]) ? $props[PR_ADDRTYPE] : "";

        if(isset($props[PR_SMTP_ADDRESS]))
            return $props[PR_SMTP_ADDRESS];

        if($addrtype == "SMTP" && isset($props[PR_EMAIL_ADDRESS]))
            return $props[PR_EMAIL_ADDRESS];

        return "";
    }

    function _readReplyRecipientEntry($flatEntryList) {
	// Unpack number of entries, the byte count and the entries
	$unpacked = unpack("V1cEntries/V1cbEntries/a*abEntries", $flatEntryList);
			
	$abEntries = Array();
	$stream = $unpacked['abEntries'];
	$pos = 8;
			
	for ($i=0; $i<$unpacked['cEntries']; $i++) {
	    $findEntry = unpack("a".$pos."before/V1cb/a*after", $flatEntryList);
	    // Go to after the unsigned int
	    $pos += 4;
	    $entry = unpack("a".$pos."before/a".$findEntry['cb']."abEntry/a*after", $flatEntryList);
	    // Move to after the entry
	    $pos += $findEntry['cb'];
	    // Move to next 4-byte boundary
	    $pos += $pos%4;
	    // One one-off entry id
	    $abEntries[] = $entry['abEntry'];
	}
			
	$recipients = Array();
	foreach ($abEntries as $abEntry){
	    // Unpack the one-off entry identifier
	    $findID = unpack("V1version/a16mapiuid/v1flags/v1abFlags/a*abEntry", $abEntry);
	    $tempArray = Array();
	    // Split the entry in its three fields
				
	    // Workaround (if Unicode then strip \0's)
	    if (($findID['abFlags'] & 0x8000)) {
		$idParts = explode("\0\0", $findID['abEntry']);
		foreach ($idParts as $idPart) {
		// Remove null characters from the field contents
		    $tempArray[] = str_replace("\x00", "", $idPart);
		}
	    } else {
		// Not Unicode. Just split by \0.
		$tempArray = explode("\0", $findID['abEntry']);
	    }
				
	    // Put data in recipient array
	    $recipients[] = Array("display_name" => windows1252_to_utf8($tempArray[0]),
				  "email_address" => windows1252_to_utf8($tempArray[2]));
	}
			
	return $recipients;
    }

}

// This is our local importer. IE it receives data from the PDA. It must therefore receive Sync
// objects and convert them into MAPI objects, and then send them to the ICS importer to do the actual
// writing of the object.
class ImportContentsChangesICS extends MAPIMapping {
    function ImportContentsChangesICS($session, $store, $folderid) {
        $this->_session = $session;
        $this->_store = $store;
        $this->_folderid = $folderid;

        $entryid = mapi_msgstore_entryidfromsourcekey($store, $folderid);
        if(!$entryid) {
            // Folder not found
            debugLog("Folder not found: " . bin2hex($folderid));
            $this->importer = false;
            return;
        }

        $folder = mapi_msgstore_openentry($store, $entryid);
        if(!$folder) {
            debugLog("Unable to open folder: " . sprintf("%x", mapi_last_hresult()));
            $this->importer = false;
            return;
        }

        $this->importer = mapi_openproperty($folder, PR_COLLECTOR, IID_IExchangeImportContentsChanges, 0 , 0);
    }

    function Config($state, $flags = 0, $mclass = false, $restrict = false, $bodypreference = false) {
        $stream = mapi_stream_create();
        if(strlen($state) == 0) {
            $state = hex2bin("0000000000000000");
        }

        mapi_stream_write($stream, $state);
        $this->statestream = $stream;

        mapi_importcontentschanges_config($this->importer, $stream, $flags);
        $this->_flags = $flags;

//	debugLog("ImportContentsChangesICS->Config: ".($this->_folderid ? "Have Folder" : "No Folder"). " mclass: ". $mclass. " state: ". bin2hex($state) . " restriction " . $restrict);
        // configure an exporter so we can detect conflicts
        $exporter = new ExportChangesICS($this->_session, $this->_store, $this->_folderid);
        $memImporter = new ImportContentsChangesMem();
	if (substr(phpversion("mapi"),0,4) >= "6.40") {
    	    $exporter->Config(&$memImporter, $mclass, $restrict, $state, 0, 0, $bodypreference);
	} else {
    	    $exporter->Config(&$memImporter, false, false, $state, 0, 0, $bodypreference);
	}
        while(is_array($exporter->Synchronize()));
        $this->_memChanges = $memImporter;
        
    }

    function ImportMessageChange($id, $message) {
        $parentsourcekey = $this->_folderid;
        if($id)
            $sourcekey = hex2bin($id);

        $flags = 0;
        $props = array();
        $props[PR_PARENT_SOURCE_KEY] = $parentsourcekey;

        // set the PR_SOURCE_KEY if available or mark it as new message
        if($id) {
            $props[PR_SOURCE_KEY] = $sourcekey;

            // check for conflicts
            if($this->_memChanges->isChanged($id)) {
                if ($this->_flags == SYNC_CONFLICT_OVERWRITE_PIM) {
                    debugLog("Conflict detected. Data from PIM will be dropped! Server overwrites PIM.");
                    return false;
                }
                else
                   debugLog("Conflict detected. Data from Server will be dropped! PIM overwrites server.");
            }
            if($this->_memChanges->isDeleted($id)) {
                debugLog("Conflict detected. Data from PIM will be dropped! Object was deleted on server.");
                return false;
            }            
        }
        else
            $flags = SYNC_NEW_MESSAGE;

        if(mapi_importcontentschanges_importmessagechange($this->importer, $props, $flags, $mapimessage)) {
            $this->_setMessage($mapimessage, $message);
            mapi_message_savechanges($mapimessage);

            $sourcekeyprops = mapi_getprops($mapimessage, array (PR_SOURCE_KEY));
        } else {
            debugLog("Unable to update object $id:" . sprintf("%x", mapi_last_hresult()));
            return false;
        }

        return bin2hex($sourcekeyprops[PR_SOURCE_KEY]);
    }

    // Import a deletion. This may conflict if the local object has been modified.
    function ImportMessageDeletion($objid) {
        // check for conflicts
        if($this->_memChanges->isChanged($objid)) {
           debugLog("Conflict detected. Data from Server will be dropped! PIM deleted object.");
        }
        // do a 'soft' delete so people can un-delete if necessary
        mapi_importcontentschanges_importmessagedeletion($this->importer, 1, array(hex2bin($objid)));
    }

    // Import a change in 'read' flags .. This can never conflict
    function ImportMessageReadFlag($id, $flags) {
        $readstate = array ( "sourcekey" => hex2bin($id), "flags" => $flags);
        $ret = mapi_importcontentschanges_importperuserreadstatechange($this->importer, array ($readstate) );
        if($ret == false)
            debugLog("Unable to set read state: " . sprintf("%x", mapi_last_hresult()));
    }

    // START ADDED dw2412 AS 12.0 Support for flags
    // Import a change in 'flag' ... 
    // TODO: find some way that this does not result in message being synced from server to mobile device
    //       php-mapi needs to be changed so that this is being replaced with a mapi_importcontentschanges
    //       function
    function ImportMessageFlag($id, $flag) {
	$emailflag = $this->_emailflagmapping;
        $entryid = mapi_msgstore_entryidfromsourcekey($this->_store, hex2bin($id));
        $mapimessage = mapi_msgstore_openentry($this->_store, $entryid);
        if($mapimessage == false)
            debugLog("Unable to openentry in ImportMessageFlag: " . sprintf("%x", mapi_last_hresult()));
	else {
	    // we need this for importing changes in the end...
	    $flags = 0;
            $props = array();
	    $props = mapi_getprops($mapimessage,array(PR_PARENT_SOURCE_KEY,PR_SOURCE_KEY));

	    // so now do the job with the flags. Delete flags not being sent, set flags that are in sync packet
	    // flagicon is just necessary for Zarafa WebAccess. Outlook does not need it.
	    $setflags = array();
	    $delflags = array();
	    if (isset($flag->flagstatus) && $flag->flagstatus!="") {
		$setflags += array($this->_getPropIDFromString($emailflag["flagstatus"]) => $flag->flagstatus);
		switch ($flag->flagstatus) {
		    case '2'	: $setflags += array($this->_getPropIDFromString($emailflag["flagicon"]) => 6); break;
		    default 	: $setflags += array($this->_getPropIDFromString($emailflag["flagicon"]) => 0); break;
		};
	    } else {
		$delflags[] = $this->_getPropIDFromString($emailflag["flagstatus"]);
		$delflags[] = $this->_getPropIDFromString($emailflag["flagicon"]);
	    }

	    // dw2412 in case the flag should be removed... just do it compatible with o2k7	    	    
	    if ((isset($flag->flagstatus) && $flag->flagstatus == 0) || !isset($flag->flagstatus)) {
		$delflags[] = $this->_getPropIDFromString($emailflag["flagstatus"]);
		$delflags[] = $this->_getPropIDFromString($emailflag["flagicon"]);
		$delflags[] = $this->_getPropIDFromString("PT_SYSTIME:{00062003-0000-0000-C000-000000000046}:0x8104");
		$delflags[] = $this->_getPropIDFromString("PT_SYSTIME:{00062003-0000-0000-C000-000000000046}:0x8105");
		$delflags[] = $this->_getPropIDFromString("PT_SYSTIME:{00062008-0000-0000-C000-000000000046}:0x8516");
		$delflags[] = $this->_getPropIDFromString("PT_SYSTIME:{00062008-0000-0000-C000-000000000046}:0x8517");
		$delflags[] = $this->_getPropIDFromString("PT_SYSTIME:{00062006-0000-0000-C000-000000000046}:0x85A0");
		$delflags[] = $this->_getPropIDFromString("PT_STRING8:{00062006-0000-0000-C000-000000000046}:0x85A1");
		$delflags[] = $this->_getPropIDFromString("PT_STRING8:{00062006-0000-0000-C000-000000000046}:0x85A4");
		$setflags += array($this->_getPropIDFromString("PT_STRING8:{00062008-0000-0000-C000-000000000046}:0x8530") => "");
		$setflags += array($this->_getPropIDFromString("PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8503") => "0");
		$setflags += array($this->_getPropIDFromString("PT_LONG:{00062003-0000-0000-C000-000000000046}:0x8101") => "0");
		$setflags += array($this->_getPropIDFromString("PT_DOUBLE:{00062003-0000-0000-C000-000000000046}:0x8102") => "0");
		$setflags += array($this->_getPropIDFromString("PT_BOOLEAN:{00062003-0000-0000-C000-000000000046}:0x811C") => "0");
		$setflags += array(mapi_prop_tag(PT_LONG,0x0E2B) => "0"); //dw2412 responsible for displaying flag in O2K7, added as 'PR_TODO_ITEM_FLAGS' to the mapitags.php
		$setflags += array($this->_getPropIDFromString($emailflag["reminderset"]) => "0");
	    } else {
		if (isset($flag->flagtype) && $flag->flagtype!="") $setflags += array($this->_getPropIDFromString($emailflag["flagtype"]) => $flag->flagtype);
		    else $delflags[] = $this->_getPropIDFromString($emailflag["flagtype"]);
		if (isset($flag->startdate) && $flag->startdate!="") $setflags += array($this->_getPropIDFromString($emailflag["startdate"]) => $flag->startdate);
		    else $delflags[] = $this->_getPropIDFromString($emailflag["startdate"]);
	        if (isset($flag->duedate) && $flag->duedate!="") $setflags += array($this->_getPropIDFromString($emailflag["duedate"]) => $flag->duedate);
	    	    else $delflags[] = $this->_getPropIDFromString($emailflag["duedate"]); 
		if (isset($flag->datecompleted) && $flag->datecompleted!="") $setflags += array($this->_getPropIDFromString($emailflag["datecompleted"]) => $flag->datecompleted);
		    else $delflags[] = $this->_getPropIDFromString($emailflag["datecompleted"]);
		if (isset($flag->reminderset) && $flag->reminderset!="")  
		    $setflags += array($this->_getPropIDFromString($emailflag["reminderset"]) => $flag->reminderset);
		    else if ($flag->flagstatus > 0) $setflags += array($this->_getPropIDFromString($emailflag["reminderset"]) => "0");
		        else $delflags[] = $this->_getPropIDFromString($emailflag["reminderset"]);
		if (isset($flag->remindertime) && $flag->remindertime!="") $setflags += array($this->_getPropIDFromString($emailflag["remindertime"]) => $flag->remindertime);
		    else $delflags[] = $this->_getPropIDFromString($emailflag["remindertime"]);
		if (isset($flag->ordinaldate) && $flag->ordinaldate!="") $setflags += array($this->_getPropIDFromString($emailflag["ordinaldate"]) => $flag->ordinaldate);
		    else $delflags[] = $this->_getPropIDFromString($emailflag["ordinaldate"]);
		if (isset($flag->subordinaldate) && $flag->subordinaldate!="") $setflags += array($this->_getPropIDFromString($emailflag["subordinaldate"]) => $flag->subordinaldate);
		    else $delflags[] = $this->_getPropIDFromString($emailflag["subordinaldate"]);
		if (isset($flag->completetime) && $flag->completetime!="") $setflags += array($this->_getPropIDFromString($emailflag["completetime"]) => $flag->completetime);
		    else $delflags[] = $this->_getPropIDFromString($emailflag["completetime"]);
	    }
	    // hopefully I'm doing this right. It should prevent the back sync of whole message
	    mapi_importcontentschanges_importmessagechange($this->importer, $props, $flags, $mapimessage);
	    mapi_setprops($mapimessage,$setflags);
	    mapi_deleteprops($mapimessage,$delflags);
	    mapi_savechanges($mapimessage);
	    return true;
	}
    }
    // END ADDED dw2412 AS 12.0 Support for flags

    // Import a move of a message. This occurs when a user moves an item to another folder. Normally,
    // we would implement this via the 'offical' importmessagemove() function on the ICS importer, but the
    // Zarafa importer does not support this. Therefore we currently implement it via a standard mapi
    // call. This causes a mirror 'add/delete' to be sent to the PDA at the next sync.
    function ImportMessageMove($id, $newfolder) {
        $sourcekey = hex2bin($id);
        $parentsourcekey = $this->_folderid;

        // Get the entryid of the message we're moving
        $entryid = mapi_msgstore_entryidfromsourcekey($this->_store, $parentsourcekey, $sourcekey);

        if(!$entryid) {
            debugLog("Unable to resolve source message id");
            return false;
        }

        $dstentryid = mapi_msgstore_entryidfromsourcekey($this->_store, hex2bin($newfolder));

        if(!$dstentryid) {
            debugLog("Unable to resolve destination folder");
            return false;
        }

        $dstfolder = mapi_msgstore_openentry($this->_store, $dstentryid);
        if(!$dstfolder) {
            debugLog("Unable to open destination folder");
            return false;
        }

        // Open the source folder (we just open the root because it doesn't matter which folder you open as a source
        // folder)
        $root = mapi_msgstore_openentry($this->_store);

        // Do the actual move
        return mapi_folder_copymessages($root, array($entryid), $dstfolder, MESSAGE_MOVE);
    }

    function GetState() {
    	if(!isset($this->statestream))
            return false;

        if (function_exists("mapi_importcontentschanges_updatestate")) {
        	debugLog("using mapi_importcontentschanges_updatestate");
	        if(mapi_importcontentschanges_updatestate($this->importer, $this->statestream) != true) {
	            debugLog("Unable to update state: " . sprintf("%X", mapi_last_hresult()));
	            return false;
	        }
        }

        mapi_stream_seek($this->statestream, 0, STREAM_SEEK_SET);
        $state = "";
        while(true) {
            $data = mapi_stream_read($this->statestream, 4096);
            if(strlen($data))
                $state .= $data;
            else
                break;
        }

        return $state;
    }

    // ----------------------------------------------------------------------------------------------------------

    function GetTZOffset($ts)
    {
        $Offset = date("O", $ts);

        $Parity = $Offset < 0 ? -1 : 1;
        $Offset = $Parity * $Offset;
        $Offset = ($Offset - ($Offset % 100)) / 100 * 60 + $Offset % 100;

        return $Parity * $Offset;
    }

    function gmtime($time)
    {
        $TZOffset = $this->GetTZOffset($time);

        $t_time = $time - $TZOffset * 60; #Counter adjust for localtime()
        $t_arr = localtime($t_time, 1);

        return $t_arr;
    }

    function _setMessage($mapimessage, $message) {
        switch(strtolower(get_class($message))) {
            case "synccontact":
                return $this->_setContact($mapimessage, $message);
            case "syncappointment":
                return $this->_setAppointment($mapimessage, $message);
            case "synctask":
                return $this->_setTask($mapimessage, $message);
            default:
                return $this->_setEmail($mapimessage, $message); // In fact, this is unimplemented. It never happens. You can't save or modify an email from the PDA (except readflags)
        }
    }

    function _setAppointment($mapimessage, $appointment) {
        // MAPI stores months as the amount of minutes until the beginning of the month in a
        // non-leapyear. Why this is, is totally unclear.
	
        $monthminutes = array(0,44640,84960,129600,172800,217440,260640,305280,348480,393120,437760,480960);

        // Get timezone info
        if(isset($appointment->timezone))
            $tz = $this->_getTZFromSyncBlob(base64_decode($appointment->timezone));
	else
            $tz = false;

        //calculate duration because without it some webaccess views are broken. duration is in min
        $localstart = $this->_getLocaltimeByTZ($appointment->starttime, $tz);
        $localend = $this->_getLocaltimeByTZ($appointment->endtime, $tz);
        $duration = ($localend - $localstart)/60;

        //nokia sends an yearly event with 0 mins duration but as all day event,
        //so make it end next day
        if ($appointment->starttime == $appointment->endtime && isset($appointment->alldayevent) && $appointment->alldayevent) {
            $duration = 1440;
            $appointment->endtime = $appointment->starttime + 24 * 60 * 60;
            $localend = $localstart + 24 * 60 * 60;
        }

        // is the transmitted UID OL compatible?
        // if not, encapsulate the transmitted uid
        $appointment->uid = getOLUidFromICalUid($appointment->uid);
        
        mapi_setprops($mapimessage, array(PR_MESSAGE_CLASS => "IPM.Appointment"));

	// START ADDED dw2412 Take care about notes
	if (isset($appointment->airsyncbasebody)) {
	    switch($appointment->airsyncbasebody->type) {
		case '3' 	: $appointment->rtf = $appointment->airsyncbasebody->data; break;
		case '1' 	: $appointment->body = $appointment->airsyncbasebody->data; break;
	    }
	}
	if(isset($appointment->rtf)) {
	    // start dw2412
	    // Nokia MfE 2.9.158 sends contact notes with RTF and Body element. 
	    // The RTF is empty, the body contains the note therefore we need to unpack the rtf 
	    // to see if it is realy empty and in case not, take the appointment body.
	    $rtf_body = new rtf ();
	    $rtf_body->loadrtf(base64_decode($appointment->rtf));
	    $rtf_body->output("ascii");
	    $rtf_body->parse();
	    if (isset($appointment->body) &&
		isset($rtf_body->out) &&
		$rtf_body->out == "" && $appointment->body != "") {
		unset($appointment->rtf);
	    }
	    // end dw2412
	}
	// END ADDED dw2412 Take care about notes
        $this->_setPropsInMAPI($mapimessage, $appointment, $this->_appointmentmapping);

        //we also have to set the responsestatus and not only meetingstatus, so we use another mapi tag
        if (isset($appointment->meetingstatus)) 
            mapi_setprops($mapimessage, array(
                $this->_getPropIDFromString("PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8218") =>  $appointment->meetingstatus));

        //sensitivity is not enough to mark an appointment as private, so we use another mapi tag
        if (isset($appointment->sensitivity) && $appointment->sensitivity == 0) $private = false;
        else  $private = true;

        // Set commonstart/commonend to start/end and remindertime to start, duration, private and cleanGlobalObjectId
        mapi_setprops($mapimessage, array(
            $this->_getPropIDFromString("PT_SYSTIME:{00062008-0000-0000-C000-000000000046}:0x8516") =>  $appointment->starttime,
            $this->_getPropIDFromString("PT_SYSTIME:{00062008-0000-0000-C000-000000000046}:0x8517") =>  $appointment->endtime,
            $this->_getPropIDFromString("PT_SYSTIME:{00062008-0000-0000-C000-000000000046}:0x8502") =>  $appointment->starttime,
            $this->_getPropIDFromString("PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8213") =>     $duration,
            $this->_getPropIDFromString("PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8506") =>  $private,
            $this->_getPropIDFromString("PT_BINARY:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x23") =>     $appointment->uid,
            ));

        // Set named prop 8510, unknown property, but enables deleting a single occurrence of a recurring
        // type in OLK2003.
        mapi_setprops($mapimessage, array(
            $this->_getPropIDFromString("PT_LONG:{00062008-0000-0000-C000-000000000046}:0x8510") => 369));

        // Set reminder boolean to 'true' if reminderminutes > 30
        mapi_setprops($mapimessage, array(
            $this->_getPropIDFromString("PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8503") => isset($appointment->reminder) && $appointment->reminder > 0 ? true : false));

        if(isset($appointment->reminder) && $appointment->reminder > 0) {
            // Set 'flagdueby' to correct value (start - reminderminutes)
            mapi_setprops($mapimessage, array(
                $this->_getPropIDFromString("PT_SYSTIME:{00062008-0000-0000-C000-000000000046}:0x8560") => $appointment->starttime - $appointment->reminder));
        }

        if(isset($appointment->recurrence)) {
            // Set PR_ICON_INDEX to 1025 to show correct icon in category view
            mapi_setprops($mapimessage, array(PR_ICON_INDEX => 1025));

            $recurrence = new Recurrence($this->_store, $mapimessage);

            if(!isset($appointment->recurrence->interval))
                $appointment->recurrence->interval = 1;

            switch($appointment->recurrence->type) {
                case 0:
                    $recur["type"] = 10;
                    if(isset($appointment->recurrence->dayofweek))
                        $recur["subtype"] = 1;
                    else
                        $recur["subtype"] = 0;

                    $recur["everyn"] = $appointment->recurrence->interval * (60 * 24);
                    break;
                case 1:
                    $recur["type"] = 11;
                    $recur["subtype"] = 1;
                    $recur["everyn"] = $appointment->recurrence->interval;
                    break;
                case 2:
                    $recur["type"] = 12;
                    $recur["subtype"] = 2;
                    $recur["everyn"] = $appointment->recurrence->interval;
                    break;
                case 3:
                    $recur["type"] = 12;
                    $recur["subtype"] = 3;
                    $recur["everyn"] = $appointment->recurrence->interval;
                    break;
                case 4:
                    $recur["type"] = 13;
                    $recur["subtype"] = 1;
                    $recur["everyn"] = $appointment->recurrence->interval * 12;
                    break;
                case 5:
                    $recur["type"] = 13;
                    $recur["subtype"] = 2;
                    $recur["everyn"] = $appointment->recurrence->interval * 12;
                    break;
                case 6:
                    $recur["type"] = 13;
                    $recur["subtype"] = 3;
                    $recur["everyn"] = $appointment->recurrence->interval * 12;
                    break;
            }

	    // dw2412 Zarafa expects start & endtime in Localtime for the recurrence! We need localtime depending on tz provided.
            $starttime = $this->_localtimeByTZ($appointment->starttime,$tz,true);
            $endtime = $this->_localtimeByTZ($appointment->endtime,$tz,true);

            $recur["startocc"] = $starttime["tm_hour"] * 60 + $starttime["tm_min"];
            $recur["endocc"] = $recur["startocc"] + $duration; // Note that this may be > 24*60 if multi-day

            // dw2412 "start" and "end" are in Local Time when passing to class.recurrence
            $recur["start"] = $this->_getLocaltimeByTz($appointment->starttime, $tz);
	    if (isset($appointment->alldayevent) && $appointment->alldayevent) 
		$recur["start"] = $this->_getLocaltimeByTz($appointment->starttime,$tz);
	    else
		$recur["start"] = $this->_getDayStartOfTimestamp($appointment->starttime);
            $recur["end"] = $this->_getDayStartOfTimestamp(0x7fffffff); // Maximum value for end by default

            if(isset($appointment->recurrence->until)) {
                $recur["term"] = 0x21;
        	// dw2412 "end" is in Local Time when passing to class.recurrence
                $recur["end"] = $this->_getLocaltimeByTz($appointment->recurrence->until,$tz);
            } else if(isset($appointment->recurrence->occurrences)) {
                $recur["term"] = 0x22;
                $recur["numoccur"] = $appointment->recurrence->occurrences;
            } else {
                $recur["term"] = 0x23;
            }

            if(isset($appointment->recurrence->dayofweek))
                $recur["weekdays"] = $appointment->recurrence->dayofweek;
            if(isset($appointment->recurrence->weekofmonth))
                $recur["nday"] = $appointment->recurrence->weekofmonth;
            if(isset($appointment->recurrence->monthofyear))
                $recur["month"] = $monthminutes[$appointment->recurrence->monthofyear-1];
            if(isset($appointment->recurrence->dayofmonth))
                $recur["monthday"] = $appointment->recurrence->dayofmonth;

            // Process exceptions. The PDA will send all exceptions for this recurring item.
            if(isset($appointment->exceptions)) {
                foreach($appointment->exceptions as $exception) {
                    // we always need the base date
                    if(!isset($exception->exceptionstarttime))
                        continue;

                    if(isset($exception->deleted) && $exception->deleted) {
                        // Delete exception
                        if(!isset($recur["deleted_occurences"]))
                            $recur["deleted_occurences"] = array();

			if (isset($appointment->alldayevent) && $appointment->alldayevent) 
		    	    array_push($recur["deleted_occurences"], $this->_getLocaltimeByTZ($exception->exceptionstarttime,$tz)); // dw2412 we need localtime here...
			else
                    	    array_push($recur["deleted_occurences"], $this->_getLocaltimeByTZ($this->_getDayStartOfTimestamp($exception->exceptionstarttime)));
                    } else {
                        // Change exception
                        $mapiexception = array("basedate" => $this->_getDayStartOfTimestamp($exception->exceptionstarttime));

                        if(isset($exception->starttime))
                            $mapiexception["start"] = $this->_getLocaltimeByTZ($exception->starttime, $tz);
                        if(isset($exception->endtime))
                            $mapiexception["end"] = $this->_getLocaltimeByTZ($exception->endtime, $tz);
                        if(isset($exception->subject))
                            $mapiexception["subject"] = u2w($exception->subject);
                        if(isset($exception->location))
                            $mapiexception["location"] = u2w($exception->location);
                        if(isset($exception->busystatus))
                            $mapiexception["busystatus"] = $exception->busystatus;
                        if(isset($exception->reminder)) {
                            $mapiexception["reminder_set"] = 1;
                            $mapiexception["remind_before"] = $exception->reminder;
                        }
                        if(isset($exception->alldayevent))
                            $mapiexception["alldayevent"] = $exception->alldayevent;

                        if(!isset($recur["changed_occurences"]))
                            $recur["changed_occurences"] = array();

                        array_push($recur["changed_occurences"], $mapiexception);

                    }
                }
            }

            $recurrence->setRecurrence($tz, $recur);

        }
        else {
            $isrecurringtag = $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8223");
            mapi_setprops($mapimessage, array($isrecurringtag => false));
        }

        // Do attendees
        if(isset($appointment->attendees) && is_array($appointment->attendees)) {
            $recips = array();

            foreach($appointment->attendees as $attendee) {
                $recip = array();
                $recip[PR_DISPLAY_NAME] = u2w($attendee->name);
                $recip[PR_EMAIL_ADDRESS] = u2w($attendee->email);
                $recip[PR_ADDRTYPE] = "SMTP";
		// START CHANGED dw2412 to support AS 12.0 attendee type
                if (isset($attendee->type)) {
		    $recip[PR_RECIPIENT_TYPE] = $attendee->type;
                } else {
            	    $recip[PR_RECIPIENT_TYPE] = MAPI_TO;
		}
		// END CHANGED dw2412 to support AS 12.0 attendee type
                $recip[PR_ENTRYID] = mapi_createoneoff($recip[PR_DISPLAY_NAME], $recip[PR_ADDRTYPE], $recip[PR_EMAIL_ADDRESS]);

                array_push($recips, $recip);
            }

            mapi_message_modifyrecipients($mapimessage, 0, $recips);
            mapi_setprops($mapimessage, array(
                PR_ICON_INDEX => 1026,
                $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8229") => true
                ));
        }
    
	//START ADDED dw2412 Birthday & Anniversary create / update
	// update linked contacts (birthday & anniversary)
	$this->_setLinkedContact($mapimessage);
        //END ADDED dw2412 Birthday & Anniversary create / update
    }

    //START ADDED dw2412 Birthday & Anniversary create / update
    function _setLinkedContact($mapimessage) {
	if (LINKED_APPOINTMENTS==false) return;
	$linkApp = new linkedAppointment($this->_store);
	$linkApp->setLinkedContact($mapimessage);
    }
    //END ADDED dw2412 Birthday & Anniversary create / update

    function _setContact($mapimessage, $contact) {
        mapi_setprops($mapimessage, array(PR_MESSAGE_CLASS => "IPM.Contact"));

	// START ADDED dw2412 Take care about notes
	if (isset($contact->airsyncbasebody)) {
	    switch($contact->airsyncbasebody->type) {
		case '3' 	: $contact->rtf = $contact->airsyncbasebody->data; break;
		case '1' 	: $contact->body = $contact->airsyncbasebody->data; break;
	    }
	}
	if(isset($contact->rtf)) {
	    // start dw2412
	    // Nokia MfE 2.9.158 sends contact notes with RTF and Body element. 
	    // The RTF is empty, the body contains the note therefore we need to unpack the rtf 
	    // to see if it is realy empty and in case not, take the contact body.
	    $rtf_body = new rtf ();
	    $rtf_body->loadrtf(base64_decode($contact->rtf));
	    $rtf_body->output("ascii");
	    $rtf_body->parse();
	    if (isset($contact->body) &&
		isset($rtf_body->out) &&
		$rtf_body->out == "" && $contact->body != "") {
		unset($contact->rtf);
	    }
	    // end dw2412
	}
	// END ADDED dw2412 Take care about notes

        $this->_setPropsInMAPI($mapimessage, $contact, $this->_contactmapping);

        // Set display name and subject to a combined value of firstname and lastname
        $cname = (isset($contact->prefix))?u2w($contact->prefix)." ":"";
        $cname .= u2w($contact->firstname);
        $cname .= (isset($contact->middlename))?" ". u2w($contact->middlename):"";
        $cname .= " ". u2w($contact->lastname);
        $cname .= (isset($contact->suffix))?" ". u2w($contact->suffix):"";
        $cname = trim($cname);
         
        //set contact specific mapi properties
        $props = array();
        $nremails = array();
        $abprovidertype = 0;
        if (isset($contact->email1address)) {
            $nremails[] = 0;
            $abprovidertype |= 1;
            $props[$this->_getPropIDFromString("PT_BINARY:{00062004-0000-0000-C000-000000000046}:0x8085")] = mapi_createoneoff($cname, "SMTP", $contact->email1address); //emailentryid
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8080")] = "$cname ({$contact->email1address})"; //displayname
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8082")] = "SMTP"; //emailadresstype
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8084")] = $contact->email1address; //original emailaddress
        }

        if (isset($contact->email2address)) {
            $nremails[] = 1;
            $abprovidertype |= 2;
            $props[$this->_getPropIDFromString("PT_BINARY:{00062004-0000-0000-C000-000000000046}:0x8095")] = mapi_createoneoff($cname, "SMTP", $contact->email2address); //emailentryid
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8090")] = "$cname ({$contact->email2address})"; //displayname
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8092")] = "SMTP"; //emailadresstype
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8094")] = $contact->email2address; //original emailaddress
        }

        if (isset($contact->email3address)) {
            $nremails[] = 2;
            $abprovidertype |= 4;
            $props[$this->_getPropIDFromString("PT_BINARY:{00062004-0000-0000-C000-000000000046}:0x80A5")] = mapi_createoneoff($cname, "SMTP", $contact->email3address); //emailentryid
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x80A0")] = "$cname ({$contact->email3address})"; //displayname
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x80A2")] = "SMTP"; //emailadresstype
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x80A4")] = $contact->email3address; //original emailaddress
        }


        $props[$this->_getPropIDFromString("PT_LONG:{00062004-0000-0000-C000-000000000046}:0x8029")] = $abprovidertype;
        $props[PR_DISPLAY_NAME] = $cname;
        $props[PR_SUBJECT] = $cname;

        //pda multiple e-mail addresses bug fix for the contact
        if (!empty($nremails))
            $props[$this->_getPropIDFromString("PT_MV_LONG:{00062004-0000-0000-C000-000000000046}:0x8028")] = $nremails;

        //addresses' fix
        $homecity = $homecountry = $homepostalcode = $homestate = $homestreet = $homeaddress = "";
        if (isset($contact->homecity))
            $props[PR_HOME_ADDRESS_CITY] = $homecity = u2w($contact->homecity);
        if (isset($contact->homecountry))
            $props[PR_HOME_ADDRESS_COUNTRY] = $homecountry = u2w($contact->homecountry);
        if (isset($contact->homepostalcode))
            $props[PR_HOME_ADDRESS_POSTAL_CODE] = $homepostalcode = u2w($contact->homepostalcode);
        if (isset($contact->homestate))
            $props[PR_HOME_ADDRESS_STATE_OR_PROVINCE] = $homestate = u2w($contact->homestate);
        if (isset($contact->homestreet))
            $props[PR_HOME_ADDRESS_STREET] = $homestreet = u2w($contact->homestreet);
        $homeaddress = buildAddressString($homestreet, $homepostalcode, $homecity, $homestate, $homecountry);
        if ($homeaddress)
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x801A")] = $homeaddress;

        $businesscity = $businesscountry = $businesspostalcode = $businessstate = $businessstreet = $businessaddress = "";
        if (isset($contact->businesscity))
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8046")] = $businesscity = u2w($contact->businesscity);
        if (isset($contact->businesscountry))
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8049")] = $businesscountry = u2w($contact->businesscountry);
        if (isset($contact->businesspostalcode))
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8048")] = $businesspostalcode = u2w($contact->businesspostalcode);
        if (isset($contact->businessstate))
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8047")] = $businessstate = u2w($contact->businessstate);
        if (isset($contact->businessstreet))
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8045")] = $businessstreet = u2w($contact->businessstreet);
        $businessaddress = buildAddressString($businessstreet, $businesspostalcode, $businesscity, $businessstate, $businesscountry);
        if ($businessaddress) $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x801B")] = $businessaddress;

        $othercity = $othercountry = $otherpostalcode = $otherstate = $otherstreet = $otheraddress = "";
        if (isset($contact->othercity))
            $props[PR_OTHER_ADDRESS_CITY] = $othercity = u2w($contact->othercity);
        if (isset($contact->othercountry))
            $props[PR_OTHER_ADDRESS_COUNTRY] = $othercountry = u2w($contact->othercountry);
        if (isset($contact->otherpostalcode))
            $props[PR_OTHER_ADDRESS_POSTAL_CODE] = $otherpostalcode = u2w($contact->otherpostalcode);
        if (isset($contact->otherstate))
            $props[PR_OTHER_ADDRESS_STATE_OR_PROVINCE] = $otherstate = u2w($contact->otherstate);
        if (isset($contact->otherstreet))
            $props[PR_OTHER_ADDRESS_STREET] = $otherstreet = u2w($contact->otherstreet);
        $otheraddress = buildAddressString($otherstreet, $otherpostalcode, $othercity, $otherstate, $othercountry);
        if ($otheraddress)
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x801C")] = $otheraddress;

           $mailingadresstype = 0;

        if ($businessaddress) $mailingadresstype = 2;
        elseif ($homeaddress) $mailingadresstype = 1;
        elseif ($othercity) $mailingadresstype = 3;

        if ($mailingadresstype) {
            $props[$this->_getPropIDFromString("PT_LONG:{00062004-0000-0000-C000-000000000046}:0x8022")] = $mailingadresstype;

            switch ($mailingadresstype) {
                case 1:
                    $this->_setMailingAdress($homestreet, $homepostalcode, $homecity, $homestate, $homecountry, $homeaddress, $props);
                    break;
                case 2:
                    $this->_setMailingAdress($businessstreet, $businesspostalcode, $businesscity, $businessstate, $businesscountry, $businessaddress, $props);
                    break;
                case 3:
                    $this->_setMailingAdress($otherstreet, $otherpostalcode, $othercity, $otherstate, $othercountry, $otheraddress, $props);
                    break;
                }
            }

        if (isset($contact->picture)) {
            $picbinary = base64_decode($contact->picture);
            $picsize = strlen($picbinary);
            if ($picsize < MAX_EMBEDDED_SIZE) {
                //set the has picture property to true
                $haspic = $this->_getPropIDFromString("PT_BOOLEAN:{00062004-0000-0000-C000-000000000046}:0x8015");

                $props[$haspic] = false;

                //check if contact has already got a picture. delete it first in that case
                //delete it also if it was removed on a mobile
                $picprops = mapi_getprops($mapimessage, array($haspic));
                if (isset($picprops[$haspic]) && $picprops[$haspic]) {
                    debugLog("Contact already has a picture. Delete it");

                    $attachtable = mapi_message_getattachmenttable($mapimessage);
                    mapi_table_restrict($attachtable, getContactPicRestriction());
                    $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM));
                    if (isset($rows) && is_array($rows)) {
                        foreach ($rows as $row) {
                            mapi_message_deleteattach($mapimessage, $row[PR_ATTACH_NUM]);
                        }
                    }
                }

                //only set picture if there's data in the request
                if ($picbinary !== false && $picsize > 0) {
                    $props[$haspic] = true;
                    $pic = mapi_message_createattach($mapimessage);
                    // Set properties of the attachment
                    $picprops = array(
                        PR_ATTACH_LONG_FILENAME_A => "ContactPicture.jpg",
                        PR_DISPLAY_NAME => "ContactPicture.jpg",
                        0x7FFF000B => true,
                        PR_ATTACHMENT_HIDDEN => false,
                        PR_ATTACHMENT_FLAGS => 1,
                        PR_ATTACH_METHOD => ATTACH_BY_VALUE,
                        PR_ATTACH_EXTENSION_A => ".jpg",
                        PR_ATTACH_NUM => 1,
                        PR_ATTACH_SIZE => $picsize,
                        PR_ATTACH_DATA_BIN => $picbinary,
                    );

                    mapi_setprops($pic, $picprops);
                    mapi_savechanges($pic);
                }
            }
	}

        mapi_setprops($mapimessage, $props);

	//START ADDED dw2412 Birthday & Anniversary create / update
	if ($contact->birthday) $this->_setLinkedAppointment($mapimessage, 0x01);
	if ($contact->anniversary) $this->_setLinkedAppointment($mapimessage, 0x02);
	//END ADDED dw2412 Birthday & Anniversary create / update

    }

    //START ADDED dw2412 Birthday & Anniversary create / update
    // $what 0x01 = birthday 0x02 = anniversary
    function _setLinkedAppointment($mapimessage,$what) {
	if (LINKED_APPOINTMENTS==false) return; 
	$linkApp = new linkedAppointment($this->_store);
	$linkApp->setLinkedAppointment($mapimessage,$what);
    }
    //END ADDED dw2412 Birthday & Anniversary create / update

    function _setTask($mapimessage, $task) {
        mapi_setprops($mapimessage, array(PR_MESSAGE_CLASS => "IPM.Task"));

	// START ADDED dw2412 Take care about notes
	if (isset($task->airsyncbasebody)) {
	    switch($task->airsyncbasebody->type) {
		case '3' 	: $task->rtf = $task->airsyncbasebody->data; break;
		case '1' 	: $task->body = $task->airsyncbasebody->data; break;
	    }
	}
	if(isset($task->rtf)) {
	    // start dw2412
	    // Nokia MfE 2.9.158 sends task notes with RTF and Body element. 
	    // The RTF is empty, the body contains the note therefore we need to unpack the rtf 
	    // to see if it is realy empty and in case not, take the task body.
	    $rtf_body = new rtf ();
	    $rtf_body->loadrtf(base64_decode($task->rtf));
	    $rtf_body->output("ascii");
	    $rtf_body->parse();
	    if (isset($task->body) &&
		isset($rtf_body->out) &&
		$rtf_body->out == "" && $task->body != "") {
		unset($task->rtf);
	    }
	    // end dw2412
	}
	// END ADDED dw2412 Take care about notes

	// END ADDED dw2412 Take care about notes
        $this->_setPropsInMAPI($mapimessage, $task, $this->_taskmapping);

        if(isset($task->complete)) {
            if($task->complete) {
                // Set completion to 100%
                // Set status to 'complete'
                mapi_setprops($mapimessage, array (
                    $this->_getPropIDFromString("PT_DOUBLE:{00062003-0000-0000-C000-000000000046}:0x8102") => 1.0,
                    $this->_getPropIDFromString("PT_LONG:{00062003-0000-0000-C000-000000000046}:0x8101") => 2 )
                );
            } else {
                // Set completion to 0%
                // Set status to 'not started'
                mapi_setprops($mapimessage, array (
                    $this->_getPropIDFromString("PT_DOUBLE:{00062003-0000-0000-C000-000000000046}:0x8102") => 0.0,
                    $this->_getPropIDFromString("PT_LONG:{00062003-0000-0000-C000-000000000046}:0x8101") => 0 )
                );
            }
        }
    }

    function _setMailingAdress($street, $zip, $city, $state, $country, $address, &$props) {
        $props[PR_STREET_ADDRESS] = $street;
        $props[PR_LOCALITY] = $city;
        $props[PR_COUNTRY] = $country;
        $props[PR_POSTAL_CODE] = $zip;
        $props[PR_STATE_OR_PROVINCE] = $state;
        $props[PR_POSTAL_ADDRESS] = $address;
    }
};

// This is our local hierarchy changes importer. It receives folder change
// data from the PDA and must therefore convert to calls into MAPI ICS
// import calls. It is fairly trivial because folders that are created on
// the PDA are always e-mail folders.

class ImportHierarchyChangesICS  {
    var $_user;

    function ImportHierarchyChangesICS($store) {
        $storeprops = mapi_getprops($store, array(PR_IPM_SUBTREE_ENTRYID));

        $folder = mapi_msgstore_openentry($store, $storeprops[PR_IPM_SUBTREE_ENTRYID]);
        if(!$folder) {
            $this->importer = false;
            return;
        }

        $this->importer = mapi_openproperty($folder, PR_COLLECTOR, IID_IExchangeImportHierarchyChanges, 0 , 0);
        $this->store = $store;
    }

    function Config($state, $flags = 0) {
        // Put the state information in a stream that can be used by ICS

        $stream = mapi_stream_create();
        if(strlen($state) == 0) {
            $state = hex2bin("0000000000000000");
        }

        mapi_stream_write($stream, $state);
        $this->statestream = $stream;

        return mapi_importhierarchychanges_config($this->importer, $stream, $flags);
    }

    function ImportFolderChange($id, $parent, $displayname, $type) {
        //create a new folder if $id is not set
        if (!$id) {
            $parentfentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($parent));
            $parentfolder = mapi_msgstore_openentry($this->store, $parentfentryid);
            $parentpros = mapi_getprops($parentfolder, array(PR_DISPLAY_NAME));
            $newfolder = mapi_folder_createfolder($parentfolder, $displayname, "");
            $props =  mapi_getprops($newfolder, array(PR_SOURCE_KEY));
            $id = bin2hex($props[PR_SOURCE_KEY]);
        }

        // 'type' is ignored because you can only create email (standard) folders
        mapi_importhierarchychanges_importfolderchange($this->importer, array ( PR_SOURCE_KEY => hex2bin($id), PR_PARENT_SOURCE_KEY => hex2bin($parent), PR_DISPLAY_NAME => $displayname) );
        debugLog("Imported changes for folder:$id");
        return $id;
    }

    function ImportFolderDeletion($id, $parent) {
        return mapi_importhierarchychanges_importfolderdeletion ($this->importer, 0, array (PR_SOURCE_KEY => hex2bin($id)) );
    }

    function GetState() {
        mapi_stream_seek($this->statestream, 0, STREAM_SEEK_SET);
        $data = mapi_stream_read($this->statestream, 4096);

        return $data;
    }
};

// We proxy the contents importer because an ICS importer is MAPI specific.
// Because we want all MAPI code to be separate from the rest of z-push, we need
// to remove the MAPI dependency in this class. All the other importers are based on
// Sync objects, not MAPI.

// This is our outgoing importer; ie it receives message changes from ICS and
// must send them on to the wrapped importer (which in turn will turn it into
// XML and send it to the PDA)

class PHPContentsImportProxy extends MAPIMapping {
    var $_session;
    var $store;
    var $importer;

    // CHANGED dw2412 Support Protocol Version 12 (added bodypreference)
    function PHPContentsImportProxy($session, $store, $folder, &$importer, $truncation, $bodypreference) {
        $this->_session = $session;
        $this->_store = $store;
        $this->_folderid = $folder;
        $this->importer = &$importer;
        $this->_truncation = $truncation;
        $this->_bodypreference = $bodypreference;
    }

    function Config($stream, $flags = 0) {
    }

    function GetLastError($hresult, $ulflags, &$lpmapierror) {}

    function UpdateState($stream) {
    }

    function ImportMessageChange ($props, $flags, &$retmapimessage) {
        $sourcekey = $props[PR_SOURCE_KEY];
        $parentsourcekey = $props[PR_PARENT_SOURCE_KEY];
        $entryid = mapi_msgstore_entryidfromsourcekey($this->_store, $parentsourcekey, $sourcekey);

        if(!$entryid)
            return SYNC_E_IGNORE;

        $mapimessage = mapi_msgstore_openentry($this->_store, $entryid);

	// CHANGED dw2412 Support Protocol Version 12 (added $this->_bodypreference)
        $message = $this->_getMessage($mapimessage, $this->getTruncSize($this->_truncation), $this->_bodypreference);

        // substitute the MAPI SYNC_NEW_MESSAGE flag by a z-push proprietary flag
        if ($flags == SYNC_NEW_MESSAGE) $message->flags = SYNC_NEWMESSAGE;
        else $message->flags = $flags;

        $this->importer->ImportMessageChange(bin2hex($sourcekey), $message);

        // Tell MAPI it doesn't need to do anything itself, as we've done all the work already.
        return SYNC_E_IGNORE;
    }

    function ImportMessageDeletion ($flags, $sourcekeys) {
        foreach($sourcekeys as $sourcekey) {
            $this->importer->ImportMessageDeletion(bin2hex($sourcekey));
        }
    }

    function ImportPerUserReadStateChange($readstates) {
        foreach($readstates as $readstate) {
            $this->importer->ImportMessageReadFlag(bin2hex($readstate["sourcekey"]), $readstate["flags"] & MSGFLAG_READ);
        }
    }

    function ImportMessageMove ($sourcekeysrcfolder, $sourcekeysrcmessage, $message, $sourcekeydestmessage, $changenumdestmessage) {
        // Never called
    }

    // ------------------------------------------------------------------------------------------------------------

    // CHANGED dw2412 Support Protocol Version 12 (added bodypreference)
    function _getMessage($mapimessage, $truncsize, $bodypreference, $mimesupport = 0) {
        // Gets the Sync object from a MAPI object according to its message class

        $props = mapi_getprops($mapimessage, array(PR_MESSAGE_CLASS));
        if(isset($props[PR_MESSAGE_CLASS]))
            $messageclass = $props[PR_MESSAGE_CLASS];
        else
            $messageclass = "IPM";

        // CHANGED dw2412 Support Protocol Version 12 (added bodypreference to the _get functions)
        if(strpos($messageclass,"IPM.Contact") === 0)
            return $this->_getContact($mapimessage, $truncsize, $bodypreference, $mimesupport);
        else if(strpos($messageclass,"IPM.Appointment") === 0)
            return $this->_getAppointment($mapimessage, $truncsize, $bodypreference, $mimesupport);
        else if(strpos($messageclass,"IPM.Task") === 0)
            return $this->_getTask($mapimessage, $truncsize, $bodypreference, $mimesupport);
        else
            return $this->_getEmail($mapimessage, $truncsize, $bodypreference, $mimesupport);
    }

    // Get the right body for sync object
    
    function _getBody($mapimessage,$message,$truncsize,$bodypreference) {
	// START ADDED dw2412 Support Protocol Version 12 (added bodypreference compare)
	if ($bodypreference == false) {
	    $rtf = mapi_message_openproperty($mapimessage, PR_RTF_COMPRESSED);
	    if (isset($rtf) && $rtf) {
	        $rtf = mapi_decompressrtf($rtf);
		if ($rtf &&
		    strlen($rtf) > 0 &&
		    strlen($rtf) < $truncsize) {
		    unset($message->body);
		} else {
		    unset($message->rtf);
		}
	    } else {
		unset($message->rtf);
	    }
	    if (isset($message->body) &&
		$message->body) {
		$body = mapi_openproperty($mapimessage, PR_BODY);
        	$bodysize = strlen($body);
		if($bodysize > $truncsize) {
        	    $body = substr($body, 0, $truncsize);
        	    $message->bodysize = $bodysize;
        	    $message->bodytruncated = 1;
    		} else {
        	    $message->bodytruncated = 0;
    		}
    	    $message->body = str_replace("\n","\r\n", w2u(str_replace("\r","",$body)));
	    } else if (!isset($message->rtf)) {
        	$message->body = " ";
	    }
    	} else {
	    // We throw away body & rtf to make our own findings depending on body preference
	    unset($message->body);
	    unset($message->rtf);
	    // first read the compressed rtf - in case there is none where going on plain text...
	    $rtf = mapi_message_openproperty($mapimessage, PR_RTF_COMPRESSED);
	    if (!$rtf) {
		$message->airsyncbasenativebodytype=1;
	    } else {
	        $rtf_len = strlen($rtf);
	        $rtf = mapi_decompressrtf($rtf);
	        $rtf_replaced = preg_replace("/(\n.*)/m","",$rtf);
	        if (strpos($rtf_replaced,"\\fromtext") != false) {
		    // Original was plaintext...
		    $message->airsyncbasenativebodytype=1;
		} else {
		    // Original was html...
		    $message->airsyncbasenativebodytype=2;
		}
	    }
	    // Set the maximum truncation size in case it is not set by device...
	    if (isset($bodypreference[1]) && !isset($bodypreference[1]["TruncationSize"])) 
		$bodypreference[1]["TruncationSize"] = 1024*1024;
	    if (isset($bodypreference[2]) && !isset($bodypreference[2]["TruncationSize"])) 
		$bodypreference[2]["TruncationSize"] = 1024*1024;
	    if (isset($bodypreference[3]) && !isset($bodypreference[3]["TruncationSize"]))
		$bodypreference[3]["TruncationSize"] = 1024*1024;
	    $message->airsyncbasebody = new SyncAirSyncBaseBody();
    	    if (isset($bodypreference[3]) && 
    		isset($rtf) && strlen($rtf) < $bodypreference[3]["TruncationSize"]) {
		// Send RTF if possible and below the maximum truncation size
		$message->airsyncbasebody->type = 3;
		$rtf = mapi_openproperty($mapimessage, PR_RTF_COMPRESSED);
		$message->airsyncbasebody->data = base64_encode($rtf);
		$message->airsyncbasebody->estimateddatasize = strlen($rtf);
		$message->airsyncbasebody->truncated = 0;
		unset($message->airsyncbasebody->truncated);
    	    } elseif (isset($bodypreference[2]) && 
    		$message->airsyncbasenativebodytype==2) {
		// Send HTML if requested and native type was html
		$message->airsyncbasebody->type = 2;
		$html = mapi_openproperty($mapimessage, PR_HTML);
    		if(isset($bodypreference[2]["TruncationSize"]) &&
    	    	    strlen($html) > $bodypreference[2]["TruncationSize"]) {
        	    $html = substr($html, 0, $bodypreference[2]["TruncationSize"]);
		    $message->airsyncbasebody->truncated = 1;
    		} else {
		    $message->airsyncbasebody->truncated = 0;
		    unset($message->airsyncbasebody->truncated);
    		}
		$message->airsyncbasebody->data = $html;
		$message->airsyncbasebody->estimateddatasize = strlen($html);
    	    } else {
		// Send Plaintext as Fallback or if original body is plaintext
		$body = mapi_openproperty($mapimessage, PR_BODY);
		$message->airsyncbasebody->type = 1;
    		if(isset($bodypreference[1]["TruncationSize"]) &&
    		    strlen($body) > $bodypreference[1]["TruncationSize"]) {
        	    $body = substr($body, 0, $bodypreference[1]["TruncationSize"]);
		    $message->airsyncbasebody->truncated = 1;
    		} else {
		    $message->airsyncbasebody->truncated = 0;
    		    unset($message->airsyncbasebody->truncated);
    		}
		$message->airsyncbasebody->estimateddatasize = strlen($body);
    		$message->airsyncbasebody->data = str_replace("\n","\r\n", w2u(str_replace("\r","",$body)));
    	    }
	    // In case we have nothing for the body, send at least a blank... 
	    // dw2412 but only in case the body is not rtf!
    	    if ($message->airsyncbasebody->type != 3 && (!isset($message->airsyncbasebody->data) || strlen($message->airsyncbasebody->data) == 0))
        	$message->airsyncbasebody->data = " ";
    	}
	// END ADDED dw2412 Support Protocol Version 12 (added bodypreference compare)
	return $message;
    }

    // Get an SyncContact object
    // CHANGED dw2412 Support Protocol Version 12 (added bodypreference)
    function _getContact($mapimessage, $truncsize, $bodypreference, $mimesupport = 0) {
        $message = new SyncContact();

        $this->_getPropsFromMAPI($message, $mapimessage, $this->_contactmapping);

        // Override 'body' for truncation
	$message = $this->_getBody($mapimessage,$message,$truncsize,$bodypreference);

        //check the picture
        $haspic = $this->_getPropIDFromString("PT_BOOLEAN:{00062004-0000-0000-C000-000000000046}:0x8015");
        $messageprops = mapi_getprops($mapimessage, array( $haspic ));
        if (isset($messageprops[$haspic]) && $messageprops[$haspic]) {
            // Add attachments
            $attachtable = mapi_message_getattachmenttable($mapimessage);
            mapi_table_restrict($attachtable, getContactPicRestriction());
            $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM, PR_ATTACH_SIZE));

            foreach($rows as $row) {
                if(isset($row[PR_ATTACH_NUM])) {
                    if (isset($row[PR_ATTACH_SIZE]) && $row[PR_ATTACH_SIZE] < MAX_EMBEDDED_SIZE) {
                        $mapiattach = mapi_message_openattach($mapimessage, $row[PR_ATTACH_NUM]);
                        $message->picture = base64_encode(mapi_attach_openbin($mapiattach, PR_ATTACH_DATA_BIN));
                    }
                }
            }
        }
        return $message;
    }

    // Get an SyncTask object
    // CHANGED dw2412 Support Protocol Version 12 (added bodypreference)
    function _getTask($mapimessage, $truncsize, $bodypreference, $mimesupport = 0) {
        $message = new SyncTask();

        $this->_getPropsFromMAPI($message, $mapimessage, $this->_taskmapping);

        // Override 'body' for truncation
	$message = $this->_getBody($mapimessage,$message,$truncsize,$bodypreference);

        // when set the task to complete using the WebAccess, the dateComplete property is not set correctly
        if ($message->complete == 1 && !isset($message->datecompleted))
            $message->datecompleted = time();

        return $message;
    }

    // Get an SyncAppointment object
    // CHANGED dw2412 Support Protocol Version 12 (added bodypreference)
    function _getAppointment($mapimessage, $truncsize, $bodypreference, $mimesupport = 0) {
        $message = new SyncAppointment();

        // Standard one-to-one mappings first
        $this->_getPropsFromMAPI($message, $mapimessage, $this->_appointmentmapping);

        // Override 'body' for truncation
	$message = $this->_getBody($mapimessage,$message,$truncsize,$bodypreference);

        // Disable reminder if it is off
        $reminderset = $this->_getPropIDFromString("PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8503");
        $remindertime = $this->_getPropIDFromString("PT_LONG:{00062008-0000-0000-C000-000000000046}:0x8501");
        $messageprops = mapi_getprops($mapimessage, array ( $reminderset, $remindertime ));

        if(!isset($messageprops[$reminderset]) || $messageprops[$reminderset] == false)
            $message->reminder = "";
        else {
            if ($messageprops[$remindertime] == 0x5AE980E1)
                $message->reminder = 15;
            else
                $message->reminder = $messageprops[$remindertime];
        }

        $messageprops = mapi_getprops($mapimessage, array ( PR_SOURCE_KEY ));

        if(!isset($message->uid))
            $message->uid = bin2hex($messageprops[PR_SOURCE_KEY]);
        else 
            $message->uid = getICalUidFromOLUid($message->uid);

        // Get organizer information if it is a meetingrequest
        $meetingstatustag = $this->_getPropIDFromString("PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8217");
        $messageprops = mapi_getprops($mapimessage, array($meetingstatustag, PR_SENT_REPRESENTING_ENTRYID, PR_SENT_REPRESENTING_NAME));

        if(isset($messageprops[$meetingstatustag]) && $messageprops[$meetingstatustag] > 0 && isset($messageprops[PR_SENT_REPRESENTING_ENTRYID]) && isset($messageprops[PR_SENT_REPRESENTING_NAME])) {
            $message->organizeremail = w2u($this->_getSMTPAddressFromEntryID($messageprops[PR_SENT_REPRESENTING_ENTRYID]));
            $message->organizername = w2u($messageprops[PR_SENT_REPRESENTING_NAME]);
        }            
            
        $isrecurringtag = $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8223");
        $recurringstate = $this->_getPropIDFromString("PT_BINARY:{00062002-0000-0000-C000-000000000046}:0x8216");
        $timezonetag = $this->_getPropIDFromString("PT_BINARY:{00062002-0000-0000-C000-000000000046}:0x8233");

        // Now, get and convert the recurrence and timezone information
        $recurprops = mapi_getprops($mapimessage, array($isrecurringtag, $recurringstate, $timezonetag));

        if(isset($recurprops[$timezonetag])) 
            $tz = $this->_getTZFromMAPIBlob($recurprops[$timezonetag]);
        else
            $tz = $this->_getGMTTZ();

	$message->timezone = base64_encode($this->_getSyncBlobFromTZ($tz));

        if(isset($recurprops[$isrecurringtag]) && $recurprops[$isrecurringtag]) {
            // Process recurrence
            $message->recurrence = new SyncRecurrence();
	    $this->_getRecurrence($mapimessage, $recurprops, $message, $message->recurrence, $tz);

        }

        // Do attendees
        $reciptable = mapi_message_getrecipienttable($mapimessage);
        $rows = mapi_table_queryallrows($reciptable, array(PR_DISPLAY_NAME, PR_EMAIL_ADDRESS, PR_SMTP_ADDRESS, PR_ADDRTYPE));
        if(count($rows) > 0)
            $message->attendees = array();

        foreach($rows as $row) {
            $attendee = new SyncAttendee();

            $attendee->name = w2u($row[PR_DISPLAY_NAME]);
            //smtp address is always a proper email address
            if(isset($row[PR_SMTP_ADDRESS]))
                $attendee->email = w2u($row[PR_SMTP_ADDRESS]);
            elseif (isset($row[PR_ADDRTYPE]) && isset($row[PR_EMAIL_ADDRESS])) {
                //if address type is SMTP, it's also a proper email address
                if ($row[PR_ADDRTYPE] == "SMTP")
                    $attendee->email = w2u($row[PR_EMAIL_ADDRESS]);
                //if address type is ZARAFA, the PR_EMAIL_ADDRESS contains username
                elseif ($row[PR_ADDRTYPE] == "ZARAFA") {
                    $userinfo = mapi_zarafa_getuser_by_name($this->_store, $row[PR_EMAIL_ADDRESS]);
                    if (is_array($userinfo) && isset($userinfo["emailaddress"]))
                        $attendee->email = w2u($userinfo["emailaddress"]);
                }
            }
            // Some attendees have no email or name (eg resources), and if you
            // don't send one of those fields, the phone will give an error ... so
            // we don't send it in that case.
            // also ignore the "attendee" if the email is equal to the organizers' email
            if(isset($attendee->name) && isset($attendee->email) && (!isset($message->organizeremail) || (isset($message->organizeremail) && $attendee->email != $message->organizeremail)))
                array_push($message->attendees, $attendee);
        }
        // Force the 'alldayevent' in the object at all times. (non-existent == 0)
        if(!isset($message->alldayevent) || $message->alldayevent == "")
            $message->alldayevent = 0;

        return $message;
    }

    // Get an SyncXXXRecurrence
    function _getRecurrence($mapimessage, $recurprops, &$syncMessage, &$syncRecurrence, $tz) {
        $recurrence = new Recurrence($this->_store, $recurprops);

        switch($recurrence->recur["type"]) {
            case 10: // daily
                switch($recurrence->recur["subtype"]) {
                    default:
                    $syncRecurrence->type = 0;
                        break;
                    case 1:
                    $syncRecurrence->type = 0;
                    $syncRecurrence->dayofweek = 62; // mon-fri
                        break;
                }
                break;
            case 11: // weekly
                    $syncRecurrence->type = 1;
                break;
            case 12: // monthly
                switch($recurrence->recur["subtype"]) {
                    default:
                    $syncRecurrence->type = 2;
                        break;
                    case 3:
                    $syncRecurrence->type = 3;
                        break;
                }
                break;
            case 13: // yearly
                switch($recurrence->recur["subtype"]) {
                    default:
                    $syncRecurrence->type = 4;
                        break;
                    case 2:
                    $syncRecurrence->type = 5;
                        break;
                    case 3:
                    $syncRecurrence->type = 6;
                }
        }
        // Termination
        switch($recurrence->recur["term"]) {
           case 0x21:
            $syncRecurrence->until = $recurrence->recur["end"]; break;
            case 0x22:
            $syncRecurrence->occurrences = $recurrence->recur["numoccur"]; break;
            case 0x23:
                // never ends
                break;
        }

        // Correct 'alldayevent' because outlook fails to set it on recurring items of 24 hours or longer
        if($recurrence->recur["endocc"] - $recurrence->recur["startocc"] >= 1440)
            $syncMessage->alldayevent = true;

        // Interval is different according to the type/subtype
        switch($recurrence->recur["type"]) {
            case 10:
                if($recurrence->recur["subtype"] == 0)
                $syncRecurrence->interval = (int)($recurrence->recur["everyn"] / 1440);  // minutes
                break;
            case 11:
            case 12: $syncRecurrence->interval = $recurrence->recur["everyn"]; break; // months / weeks
            case 13: $syncRecurrence->interval = (int)($recurrence->recur["everyn"] / 12); break; // months
        }

        if(isset($recurrence->recur["weekdays"]))
        $syncRecurrence->dayofweek = $recurrence->recur["weekdays"]; // bitmask of days (1 == sunday, 128 == saturday
        if(isset($recurrence->recur["nday"]))
        $syncRecurrence->weekofmonth = $recurrence->recur["nday"]; // N'th {DAY} of {X} (0-5)
        if(isset($recurrence->recur["month"]))
        $syncRecurrence->monthofyear = (int)($recurrence->recur["month"] / (60 * 24 * 29)) + 1; // works ok due to rounding. see also $monthminutes below (1-12)
        if(isset($recurrence->recur["monthday"]))
        $syncRecurrence->dayofmonth = $recurrence->recur["monthday"]; // day of month (1-31)

        // All changed exceptions are appointments within the 'exceptions' array. They contain the same items as a normal appointment
        foreach($recurrence->recur["changed_occurences"] as $change) {
            $exception = new SyncAppointment();

            // start, end, basedate, subject, remind_before, reminderset, location, busystatus, alldayevent, label
            if(isset($change["start"]))
                $exception->starttime = $this->_getGMTTimeByTZ($change["start"], $tz);
            if(isset($change["end"]))
                $exception->endtime = $this->_getGMTTimeByTZ($change["end"], $tz);
            if(isset($change["basedate"]))
                $exception->exceptionstarttime = $this->_getGMTTimeByTZ($this->_getDayStartOfTimestamp($change["basedate"]) + $recurrence->recur["startocc"] * 60, $tz);
            if(isset($change["subject"]))
                $exception->subject = w2u($change["subject"]);
            if(isset($change["reminder_before"]) && $change["reminder_before"])
                $exception->reminder = $change["remind_before"];
            if(isset($change["location"]))
                $exception->location = w2u($change["location"]);
            if(isset($change["busystatus"]))
                $exception->busystatus = $change["busystatus"];
            if(isset($change["alldayevent"]))
                $exception->alldayevent = $change["alldayevent"];

            // set some data from the original appointment
            if (isset($syncMessage->uid))
                $exception->uid = $syncMessage->uid;
            if (isset($syncMessage->organizername))
                $exception->organizername = $syncMessage->organizername;
            if (isset($syncMessage->organizeremail))
                $exception->organizeremail = $syncMessage->organizeremail;

            if(!isset($syncMessage->exceptions))
                $syncMessage->exceptions = array();

            array_push($syncMessage->exceptions, $exception);
        }

        // Deleted appointments contain only the original date (basedate) and a 'deleted' tag
        foreach($recurrence->recur["deleted_occurences"] as $deleted) {
            $exception = new SyncAppointment();

            $exception->exceptionstarttime = $this->_getGMTTimeByTZ($this->_getDayStartOfTimestamp($deleted) + $recurrence->recur["startocc"] * 60, $tz);
            $exception->deleted = "1";

            if(!isset($syncMessage->exceptions))
                $syncMessage->exceptions = array();

            array_push($syncMessage->exceptions, $exception);
        }         
    }

    
    // Get an SyncEmail object
    // CHANGED dw2412 Support Protocol Version 12 (added bodypreference)
    function _getEmail($mapimessage, $truncsize, $bodypreference, $mimesupport = 0) {
        $message = new SyncMail();

        $this->_getPropsFromMAPI($message, $mapimessage, $this->_emailmapping);
	
	// start added dw2412 AS V12.0 Flag support
	// should not break anything since in proto AS12 Fields get excluded in case a lower protocol is in use
	$message->poommailflag = new SyncPoommailFlag();
    
	$this->_getPropsFromMAPI($message->poommailflag, $mapimessage, $this->_emailflagmapping);
	if (!isset($message->poommailflag->flagstatus)) {
	    $message->poommailflag->flagstatus = 0;
	}
	if (!isset($message->contentclass) || $message->contentclass=="") {
	    $message->contentclass="urn:content-classes:message";
	}
	// end added dw2412 AS V12.0 Flag Support

        // Override 'From' to show "Full Name <user@domain.com>"
	// CHANGED dw2412 to honor the Reply-To Information in messages
        $messageprops = mapi_getprops($mapimessage, array(PR_SENT_REPRESENTING_NAME, PR_SENT_REPRESENTING_ENTRYID, PR_SOURCE_KEY, PR_REPLY_RECIPIENT_ENTRIES));

        // Override 'body' for truncation
	// START CHANGED dw2412 Support Protocol Version 12 (added bodypreference compare)
	if ($bodypreference == false) {
	    $body = mapi_openproperty($mapimessage, PR_BODY);
            $bodysize = strlen($body);
	    if($bodysize > $truncsize) {
        	$body = substr($body, 0, $truncsize);
        	$message->bodysize = $bodysize;
        	$message->bodytruncated = 1;
    	    } else {
        	$message->bodytruncated = 0;
    	    }
    	    $message->body = str_replace("\n","\r\n", w2u(str_replace("\r","",$body)));
    	    if (!isset($message->body) || strlen($message->body) == 0)
        	$message->body = " ";
    	} else {
	    $rtf = mapi_message_openproperty($mapimessage, PR_RTF_COMPRESSED);
	    if (!$rtf) {
		$message->airsyncbasenativebodytype=1;
	    } else {
	        $rtf = preg_replace("/(\n.*)/m","",mapi_decompressrtf($rtf));
	        if (strpos($rtf,"\\fromtext") != false) {
		    $message->airsyncbasenativebodytype=1;
		} else {
		    $message->airsyncbasenativebodytype=2;
		}
	    }
	    if (!isset($bodypreference[1]["TruncationSize"])) {
		$bodypreference[1]["TruncationSize"] = 1024*1024;
	    }
	    $message->airsyncbasebody = new SyncAirSyncBaseBody();
	    debugLog("airsyncbasebody!");
	    if (isset($bodypreference[4]) && isset($mstream)) {
            	$mstreamcontent = mapi_stream_read($mstream, MAX_EMBEDDED_SIZE);
		$message->airsyncbasebody->type = 4;
            	if (isset($bodypreference[4]["TruncationSize"])) {
            	    $hdrend = strpos("\r\n\r\n",$mstreamcontent);
            	    $message->airsyncbasebody->data = substr($mstreamcontent,0,$hdrend+$bodypreference[4]["TruncationSize"]);
            	} else {
		    $message->airsyncbasebody->data = $mstreamcontent;
            	}
            	if (strlen ($message->airsyncbasebody->data) < $mstreamstat["cb"]) {
		    $message->airsyncbasebody->truncated = 1;
            	}
            	$message->airsyncbasebody->estimateddatasize = strlen($mstreamcontent);
    	    } else if (isset($bodypreference[3]) && 
    		$message->airsyncbasenativebodytype==3) {
		$message->airsyncbasebody->type = 3;
		$rtf = mapi_openproperty($mapimessage, PR_RTF_COMPRESSED);
		$message->airsyncbasebody->data = base64_encode($rtf);
		$message->airsyncbasebody->estimateddatasize = strlen($rtf);
		$message->airsyncbasebody->truncated = 0;
		unset($message->airsyncbasebody->truncated);
		debugLog("RTF Body!");
    	    } elseif (isset($bodypreference[2]) && 
    		$message->airsyncbasenativebodytype==2) {
		$message->airsyncbasebody->type = 2;
		$html = mapi_openproperty($mapimessage, PR_HTML);
    		if(isset($bodypreference[2]["TruncationSize"]) &&
    	    	    strlen($html) > $bodypreference[2]["TruncationSize"]) {
        	    $html = substr($html, 0, $bodypreference[2]["TruncationSize"]);
		    $message->airsyncbasebody->truncated = 1;
    		} else {
		    $message->airsyncbasebody->truncated = 0;
		    unset($message->airsyncbasebody->truncated);
    		}
		$message->airsyncbasebody->data = $html;
		$message->airsyncbasebody->estimateddatasize = strlen($html);
		debugLog("HTML Body!");
    	    } else {
		$body = mapi_openproperty($mapimessage, PR_BODY);
		$message->airsyncbasebody->type = 1;
    		if(isset($bodypreference[1]["TruncationSize"]) &&
    		    strlen($body) > $bodypreference[1]["TruncationSize"]) {
        	    $body = substr($body, 0, $bodypreference[1]["TruncationSize"]);
		    $message->airsyncbasebody->truncated = 1;
    		} else {
		    $message->airsyncbasebody->truncated = 0;
		    unset($message->airsyncbasebody->truncated);
    		}
		$message->airsyncbasebody->estimateddatasize = strlen($body);
    		$message->airsyncbasebody->data = str_replace("\n","\r\n", w2u(str_replace("\r","",$body)));
		debugLog("Plain Body!");
    	    }
    	    if (!isset($message->airsyncbasebody->data) || strlen($message->airsyncbasebody->data) == 0)
        	$message->airsyncbasebody->data = " ";
    	}
	// END CHANGED dw2412 Support Protocol Version 12 (added bodypreference compare)

        if(isset($messageprops[PR_SOURCE_KEY]))
            $sourcekey = $messageprops[PR_SOURCE_KEY];
        else
            return false;

        $fromname = $fromaddr = "";

        if(isset($messageprops[PR_SENT_REPRESENTING_NAME]))
            $fromname = $messageprops[PR_SENT_REPRESENTING_NAME];
        if(isset($messageprops[PR_SENT_REPRESENTING_ENTRYID]))
            $fromaddr = $this->_getSMTPAddressFromEntryID($messageprops[PR_SENT_REPRESENTING_ENTRYID]);

        if($fromname == $fromaddr)
            $fromname = "";

        if($fromname)
            $from = "\"" . w2u($fromname) . "\" <" . w2u($fromaddr) . ">";
        else
            $from = "\"" . w2u($fromaddr) . "\" <" . w2u($fromaddr) . ">"; //changed dw2412 to get rid at HTC Mail (Android) from error message... Not nice but effective...

        $message->from = $from;

	// START ADDED dw2412 to honor reply to address
	if(isset($messageprops[PR_REPLY_RECIPIENT_ENTRIES])) {
            $replyto = $this->_readReplyRecipientEntry($messageprops[PR_REPLY_RECIPIENT_ENTRIES]);
	    foreach ($replyto as $value) {
		$message->reply_to .= $value['email_address'].";";
	    }
	    $message->reply_to = substr($message->reply_to,0,strlen($message->reply_to)-1);
	}
	// END ADDED dw2412 to honor reply to address
	
        // process Meeting Requests
        if(isset($message->messageclass) && strpos($message->messageclass, "IPM.Schedule.Meeting") === 0) {
            $message->meetingrequest = new SyncMeetingRequest();
            $this->_getPropsFromMAPI($message->meetingrequest, $mapimessage, $this->_meetingrequestmapping);

            $goidtag = $this->_getPropIdFromString("PT_BINARY:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x3");
            $timezonetag = $this->_getPropIDFromString("PT_BINARY:{00062002-0000-0000-C000-000000000046}:0x8233");
            $recReplTime = $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x8228");
            $isrecurringtag = $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8223");
            $recurringstate = $this->_getPropIDFromString("PT_BINARY:{00062002-0000-0000-C000-000000000046}:0x8216");
            $appSeqNr = $this->_getPropIDFromString("PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8201");
            $lidIsException = $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0xA");
            $recurStartTime = $this->_getPropIDFromString("PT_LONG:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0xE");
            
            $props = mapi_getprops($mapimessage, array($goidtag, $timezonetag, $recReplTime, $isrecurringtag, $recurringstate, $appSeqNr, $lidIsException, $recurStartTime));

            // Get the GOID
            if(isset($props[$goidtag]))
                $message->meetingrequest->globalobjid = base64_encode($props[$goidtag]);

            // Set Timezone
			if(isset($props[$timezonetag]))
			    $tz = $this->_getTZFromMAPIBlob($props[$timezonetag]);
			else
			    $tz = $this->_getGMTTZ();

	        $message->meetingrequest->timezone = base64_encode($this->_getSyncBlobFromTZ($tz));

            // send basedate if exception
            if(isset($props[$recReplTime]) || (isset($props[$lidIsException]) && $props[$lidIsException] == true)) {
            	if (isset($props[$recReplTime])){
            	   $basedate = $props[$recReplTime];
            	   $message->meetingrequest->recurrenceid = $this->_getGMTTimeByTZ($basedate, $this->_getGMTTZ());  
            	}
            	else {
            	   if (!isset($props[$goidtag]) || !isset($props[$recurStartTime]) || !isset($props[$timezonetag]))
            	       debugLog("Missing property to set correct basedate for exception");
            	   else {
            	       $basedate = extractBaseDate($props[$goidtag], $props[$recurStartTime]);
            	       $message->meetingrequest->recurrenceid = $this->_getGMTTimeByTZ($basedate, $tz);
            	   }  
            	}
            }
	            
            // Organizer is the sender
            $message->meetingrequest->organizer = $message->from;

            // Process recurrence
	        if(isset($props[$isrecurringtag]) && $props[$isrecurringtag]) {
	            $myrec = new SyncMeetingRequestRecurrence();
	            // get recurrence -> put $message->meetingrequest as message so the 'alldayevent' is set correctly
	            $this->_getRecurrence($mapimessage, $props, $message->meetingrequest, $myrec, $tz);
	            $message->meetingrequest->recurrences = array($myrec);
	        }

            // Force the 'alldayevent' in the object at all times. (non-existent == 0)
            if(!isset($message->meetingrequest->alldayevent) || $message->meetingrequest->alldayevent == "")
                $message->meetingrequest->alldayevent = 0;

            // Instancetype
            // 0 = single appointment
            // 1 = master recurring appointment
            // 2 = single instance of recurring appointment 
            // 3 = exception of recurring appointment
            $message->meetingrequest->instancetype = 0;
            if (isset($props[$isrecurringtag]) && $props[$isrecurringtag] == 1)
                $message->meetingrequest->instancetype = 1;
            else if ((!isset($props[$isrecurringtag]) || $props[$isrecurringtag] == 0 )&& isset($message->meetingrequest->recurrenceid)) 
                if (isset($props[$appSeqNr]) && $props[$appSeqNr] == 0 )
                    $message->meetingrequest->instancetype = 2;
                else
                    $message->meetingrequest->instancetype = 3;

            // Disable reminder if it is off
            $reminderset = $this->_getPropIDFromString("PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8503");
            $remindertime = $this->_getPropIDFromString("PT_LONG:{00062008-0000-0000-C000-000000000046}:0x8501");
            $messageprops = mapi_getprops($mapimessage, array ( $reminderset, $remindertime ));

            if(!isset($messageprops[$reminderset]) || $messageprops[$reminderset] == false)
                $message->meetingrequest->reminder = "";
            //the property saves reminder in minutes, but we need it in secs
            else {
                ///set the default reminder time to seconds
                if ($messageprops[$remindertime] == 0x5AE980E1)
                    $message->meetingrequest->reminder = 900;
                else
                    $message->meetingrequest->reminder = $messageprops[$remindertime] * 60;
            }

            // Set sensitivity to 0 if missing
            if(!isset($message->meetingrequest->sensitivity))
                $message->meetingrequest->sensitivity = 0;
        }


        // Add attachments
        $attachtable = mapi_message_getattachmenttable($mapimessage);
	// START CHANGED dw2412 to contain the Attach Method (needed for eml discovery)
        $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM, PR_ATTACH_METHOD));
	// END CHANGED dw2412 to contain the Attach Method (needed for eml discovery)

	$n=1;
        foreach($rows as $row) {
            if(isset($row[PR_ATTACH_NUM])) {
        	$mapiattach = mapi_message_openattach($mapimessage, $row[PR_ATTACH_NUM]);
		
		// CHANGED dw2412 for HTML eMail Inline Attachments...
                $attachprops = mapi_getprops($mapiattach, array(PR_ATTACH_LONG_FILENAME,PR_ATTACH_FILENAME,PR_DISPLAY_NAME,PR_ATTACH_FLAGS,PR_ATTACH_CONTENT_ID,PR_ATTACH_MIME_TAG));
                
		// START CHANGED dw2412 EML Attachment
                if ($row[PR_ATTACH_METHOD] == ATTACH_EMBEDDED_MSG) {
		    $stream = buildEMLAttachment($mapiattach);
		} else {
	            $stream = mapi_openpropertytostream($mapiattach, PR_ATTACH_DATA_BIN);
                };
		// END CHANGED dw2412 EML Attachment

                if($stream) {
                    $stat = mapi_stream_stat($stream);

                    if(isset($message->_mapping['POOMMAIL:Attachments'])) {
            		$attach = new SyncAttachment();
            	    } else if(isset($message->_mapping['AirSyncBase:Attachments'])) {
            		$attach = new SyncAirSyncBaseAttachment();
            	    }

            	    $attach->attsize = $stat["cb"];
            	    $attach->attname = bin2hex($this->_folderid) . ":" . bin2hex($sourcekey) . ":" . $row[PR_ATTACH_NUM];

		    // START CHANGED dw2412 EML Attachment
            	    if(isset($attachprops[PR_ATTACH_LONG_FILENAME])) 
                	$attach->displayname = w2u($attachprops[PR_ATTACH_LONG_FILENAME]);
            	    else if(isset($attachprops[PR_ATTACH_FILENAME]))
			$attach->displayname = w2u($attachprops[PR_ATTACH_FILENAME]);
		    else if(isset($attachprops[PR_DISPLAY_NAME]))
			$attach->displayname = w2u($attachprops[PR_DISPLAY_NAME]);
		    else
			$attach->displayname = w2u("untitled");

        	    if (strlen($attach->displayname) == 0) {
        		$attach->displayname = "Untitled_".$n;
        		$n++;
        	    }

        	    if ($row[PR_ATTACH_METHOD] == ATTACH_EMBEDDED_MSG) $attach->displayname .= w2u(".eml");
		    // END CHANGED dw2412 EML Attachment

		    // in case the attachment has got a content id it is an inline one...
		    if (isset($attachprops[PR_ATTACH_CONTENT_ID])) {
		        $attach->isinline=true;
		        $attach->method=6;
		        $attach->contentid=$attachprops[PR_ATTACH_CONTENT_ID];
		        $attach->contenttype = $attachprops[PR_ATTACH_MIME_TAG];
		    }

                    if(isset($message->_mapping['POOMMAIL:Attachments'])) {
			if (!isset($message->attachments) ||
			    !is_array($message->attachments)) 
			    $message->attachments = array();
            		array_push($message->attachments, $attach);
		    } else if(isset($message->_mapping['AirSyncBase:Attachments'])) {
			if (!isset($message->airsyncbaseattachments) ||
			    !is_array($message->airsyncbaseattachments)) 
			    $message->airsyncbaseattachments = array();
            		array_push($message->airsyncbaseattachments, $attach);
		    }
                }
            }
        }

        // Get To/Cc as SMTP addresses (this is different from displayto and displaycc because we are putting
        // in the SMTP addresses as well, while displayto and displaycc could just contain the display names
        $to = array();
        $cc = array();

        $reciptable = mapi_message_getrecipienttable($mapimessage);
        $rows = mapi_table_queryallrows($reciptable, array(PR_RECIPIENT_TYPE, PR_DISPLAY_NAME, PR_ADDRTYPE, PR_EMAIL_ADDRESS, PR_SMTP_ADDRESS));

        foreach($rows as $row) {
            $address = "";
            $fulladdr = "";

            $addrtype = isset($row[PR_ADDRTYPE]) ? $row[PR_ADDRTYPE] : "";

            if(isset($row[PR_SMTP_ADDRESS]))
                $address = $row[PR_SMTP_ADDRESS];
            else if($addrtype == "SMTP" && isset($row[PR_EMAIL_ADDRESS]))
                $address = $row[PR_EMAIL_ADDRESS];

            $name = isset($row[PR_DISPLAY_NAME]) ? $row[PR_DISPLAY_NAME] : "";

            if($name == "" || $name == $address)
                $fulladdr = w2u($address);
            else {
                if (substr($name, 0, 1) != '"' && substr($name, -1) != '"') {
                    $fulladdr = "\"" . w2u($name) ."\" <" . w2u($address) . ">";
                }
                else {
                    $fulladdr = w2u($name) ."<" . w2u($address) . ">";
                }
            }

            if($row[PR_RECIPIENT_TYPE] == MAPI_TO) {
                array_push($to, $fulladdr);
            } else if($row[PR_RECIPIENT_TYPE] == MAPI_CC) {
                array_push($cc, $fulladdr);
            }
        }
    	if (defined('LIMIT_RECIPIENTS')) {
    	    if(count($to) > LIMIT_RECIPIENTS) {
    	        debugLog("Recipient amount limitted. No to recipients added!");
    		$to = array();
    		$message->displayto = "";
    	    }
    	    if(count($cc) > LIMIT_RECIPIENTS) {
    		debugLog("Recipient amount limitted. No cc recipients added!");
    		$cc = array();
    	    }
    	}

        $message->to = implode(", ", $to);
        $message->cc = implode(", ", $cc);

	// CHANGED dw2412 to not have this problem at my system with mapi_inetmapi_imtoinet segfault
        if ($mimesupport == 2 && function_exists("mapi_inetmapi_imtoinet") && 
    	    !isset($message->airsyncbasebody) && 
	    !defined('ICS_IMTOINET_SEGFAULT')) {
            $addrBook = mapi_openaddressbook($this->_session);
            $mstream = mapi_inetmapi_imtoinet($this->_session, $addrBook, $mapimessage, array());

            $mstreamstat = mapi_stream_stat($mstream);
            if ($mstreamstat['cb'] < MAX_EMBEDDED_SIZE) {
                $message->mimetruncated = 0;
                $mstreamcontent = mapi_stream_read($mstream, MAX_EMBEDDED_SIZE);
                $message->mimedata = $mstreamcontent;
                $message->mimesize = $mstreamstat["cb"];
                unset($message->body, $message->bodytruncated);
            }
        }

        return $message;
    }

// START ADDED dw2412 ItemOperations AirsyncBaseFileAttachment
    function _getAttachment($message,$attachnum) {
        $attachment = new SyncAirSyncBaseFileAttachment();
        $attach = mapi_message_openattach($message, $attachnum);
        if(!$attach) {
            debugLog("Unable to open attachment number $attachnum");
            return false;
        }
        
        $attachtable = mapi_message_getattachmenttable($message);
        $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM, PR_ATTACH_METHOD, PR_ATTACH_MIME_TAG));
        foreach($rows as $row) {
    	    if (isset($row[PR_ATTACH_NUM]) && $row[PR_ATTACH_NUM] == $attachnum) {
    		if ($row[PR_ATTACH_METHOD] == ATTACH_EMBEDDED_MSG) {
		    $stream = buildEMLAttachment($attach);
		} else {
		    $stream = mapi_openpropertytostream($attach, PR_ATTACH_DATA_BIN);
    		};
		$attachment->contenttype = $row[PR_ATTACH_MIME_TAG];
		break;
    	    };
        };
        
        if(!$stream) {
            debugLog("Unable to open attachment data stream");
            return false;
        }

        while(1) {
            $data = mapi_stream_read($stream, 4096);
            if(strlen($data) == 0)
                break;
            $attachment->_data .= $data;
        }

	return $attachment;
    }

// END ADDED dw2412

    function getTruncSize($truncation) {
        switch($truncation) {
            case SYNC_TRUNCATION_HEADERS:
                return 0;
            case SYNC_TRUNCATION_512B:
                return 512;
            case SYNC_TRUNCATION_1K:
                return 1024;
            case SYNC_TRUNCATION_5K:
                return 5*1024;
            case SYNC_TRUNCATION_SEVEN:
            case SYNC_TRUNCATION_ALL:
                return 1024*1024; // We'll limit to 1MB anyway
            default:
                return 1024; // Default to 1Kb
        }
    }

};

// This is our PHP hierarchy import proxy which strips MAPI information from
// the import interface. We get all the information we need from MAPI here
// and then pass it to the generic importer. It receives folder change
// information from ICS and sends it on to the next importer, which in turn
// will convert it into XML which is sent to the PDA
class PHPHierarchyImportProxy {
    function PHPHierarchyImportProxy($store, &$importer) {
        $this->importer = &$importer;
        $this->_store = $store;
    }

    function Config($stream, $flags = 0) {
    }

    function GetLastError($hresult, $ulflags, &$lpmapierror) {}

    function UpdateState($stream) {
        if(is_resource($stream)) {
            $data = mapi_stream_read($stream, 4096);
        }
    }

    function ImportFolderChange ($props) {
        $sourcekey = $props[PR_SOURCE_KEY];

        $entryid = mapi_msgstore_entryidfromsourcekey($this->_store, $sourcekey);

        $mapifolder = mapi_msgstore_openentry($this->_store, $entryid);

        $folder = $this->_getFolder($mapifolder);

        $this->importer->ImportFolderChange($folder);

        return 0;
    }

    function ImportFolderDeletion ($flags, $sourcekeys) {
        foreach ($sourcekeys as $sourcekey) {
            $this->importer->ImportFolderDeletion(bin2hex($sourcekey));
        }

        return 0;
    }

    // --------------------------------------------------------------------------------------------

    function _getFolder($mapifolder) {
        $folder = new SyncFolder();

        $folderprops = mapi_getprops($mapifolder, array(PR_DISPLAY_NAME, PR_PARENT_ENTRYID, PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY, PR_ENTRYID, PR_CONTAINER_CLASS));
        $storeprops = mapi_getprops($this->_store, array(PR_IPM_SUBTREE_ENTRYID));

        if(!isset($folderprops[PR_DISPLAY_NAME]) ||
           !isset($folderprops[PR_PARENT_ENTRYID]) ||
           !isset($folderprops[PR_SOURCE_KEY]) ||
           !isset($folderprops[PR_ENTRYID]) ||
           !isset($folderprops[PR_PARENT_SOURCE_KEY]) ||
           !isset($storeprops[PR_IPM_SUBTREE_ENTRYID])) {
            debugLog("Missing properties on folder");
            return false;
        }

        $folder->serverid = bin2hex($folderprops[PR_SOURCE_KEY]);
        if($folderprops[PR_PARENT_ENTRYID] == $storeprops[PR_IPM_SUBTREE_ENTRYID])
            $folder->parentid = "0";
        else
            $folder->parentid = bin2hex($folderprops[PR_PARENT_SOURCE_KEY]);
        $folder->displayname = w2u($folderprops[PR_DISPLAY_NAME]);
        $folder->type = $this->_getFolderType($folderprops[PR_ENTRYID]);

        // try to find a correct type if not one of the default folders
        if ($folder->type == SYNC_FOLDER_TYPE_OTHER && isset($folderprops[PR_CONTAINER_CLASS])) {
            if ($folderprops[PR_CONTAINER_CLASS] == "IPF.Note")
                $folder->type = SYNC_FOLDER_TYPE_USER_MAIL;
    	    if ($folderprops[PR_CONTAINER_CLASS] == "IPF.Task")
                $folder->type = SYNC_FOLDER_TYPE_USER_TASK;
            if ($folderprops[PR_CONTAINER_CLASS] == "IPF.Appointment")
                $folder->type = SYNC_FOLDER_TYPE_USER_APPOINTMENT;
            if ($folderprops[PR_CONTAINER_CLASS] == "IPF.Contact")
                $folder->type = SYNC_FOLDER_TYPE_USER_CONTACT;
            if ($folderprops[PR_CONTAINER_CLASS] == "IPF.StickyNote")
                $folder->type = SYNC_FOLDER_TYPE_USER_NOTE;
            if ($folderprops[PR_CONTAINER_CLASS] == "IPF.Journal")
                $folder->type = SYNC_FOLDER_TYPE_USER_JOURNAL;
        }

        return $folder;
    }

    // Gets the folder type by checking the default folders in MAPI
    function _getFolderType($entryid) {
        $storeprops = mapi_getprops($this->_store, array(PR_IPM_OUTBOX_ENTRYID, PR_IPM_WASTEBASKET_ENTRYID, PR_IPM_SENTMAIL_ENTRYID));
        $inbox = mapi_msgstore_getreceivefolder($this->_store);
        $inboxprops = mapi_getprops($inbox, array(PR_ENTRYID, PR_IPM_DRAFTS_ENTRYID, PR_IPM_TASK_ENTRYID, PR_IPM_APPOINTMENT_ENTRYID, PR_IPM_CONTACT_ENTRYID, PR_IPM_NOTE_ENTRYID, PR_IPM_JOURNAL_ENTRYID));

        if($entryid == $inboxprops[PR_ENTRYID])
            return SYNC_FOLDER_TYPE_INBOX;
        if($entryid == $inboxprops[PR_IPM_DRAFTS_ENTRYID])
            return SYNC_FOLDER_TYPE_DRAFTS;
        if($entryid == $storeprops[PR_IPM_WASTEBASKET_ENTRYID])
            return SYNC_FOLDER_TYPE_WASTEBASKET;
        if($entryid == $storeprops[PR_IPM_SENTMAIL_ENTRYID])
            return SYNC_FOLDER_TYPE_SENTMAIL;
        if($entryid == $storeprops[PR_IPM_OUTBOX_ENTRYID])
            return SYNC_FOLDER_TYPE_OUTBOX;
        if($entryid == $inboxprops[PR_IPM_TASK_ENTRYID])
            return SYNC_FOLDER_TYPE_TASK;
        if($entryid == $inboxprops[PR_IPM_APPOINTMENT_ENTRYID])
            return SYNC_FOLDER_TYPE_APPOINTMENT;
        if($entryid == $inboxprops[PR_IPM_CONTACT_ENTRYID])
            return SYNC_FOLDER_TYPE_CONTACT;
        if($entryid == $inboxprops[PR_IPM_NOTE_ENTRYID])
            return SYNC_FOLDER_TYPE_NOTE;
        if($entryid == $inboxprops[PR_IPM_JOURNAL_ENTRYID])
            return SYNC_FOLDER_TYPE_JOURNAL;

        return SYNC_FOLDER_TYPE_OTHER;
    }


};

// This is our ICS exporter which requests the actual exporter from ICS and makes sure
// that the ImportProxies are used.
class ExportChangesICS  {
    var $_folderid;
    var $_store;
    var $_session;

    function ExportChangesICS($session, $store, $folderid = false) {
        // Open a hierarchy or a contents exporter depending on whether a folderid was specified
        $this->_session = $session;
        $this->_folderid = $folderid;
        $this->_store = $store;

        if($folderid) {
            $entryid = mapi_msgstore_entryidfromsourcekey($store, $folderid);
        } else {
            $storeprops = mapi_getprops($this->_store, array(PR_IPM_SUBTREE_ENTRYID));
            $entryid = $storeprops[PR_IPM_SUBTREE_ENTRYID];
        }

        $folder = mapi_msgstore_openentry($this->_store, $entryid);
        if(!$folder) {
            $this->exporter = false;
            debugLog("ExportChangesICS->Constructor: can not open folder:".bin2hex($folderid));
            return;
        }

        // Get the actual ICS exporter
        if($folderid) {
            $this->exporter = mapi_openproperty($folder, PR_CONTENTS_SYNCHRONIZER, IID_IExchangeExportChanges, 0 , 0);
        } else {
            $this->exporter = mapi_openproperty($folder, PR_HIERARCHY_SYNCHRONIZER, IID_IExchangeExportChanges, 0 , 0);
        }
    }

    // CHANGED dw2412 Support Protocol Version 12 (added bodypreference)
    function Config(&$importer, $mclass, $restrict, $syncstate, $flags, $truncation, $bodypreference) {
        // Because we're using ICS, we need to wrap the given importer to make it suitable to pass
        // to ICS. We do this in two steps: first, wrap the importer with our own PHP importer class
        // which removes all MAPI dependency, and then wrap that class with a C++ wrapper so we can
        // pass it to ICS
        $exporterflags = 0;

        if($this->_folderid) {
            // PHP wrapper
	    // CHANGED dw2412 Support Protocol Version 12 (added bodypreference)
            $phpimportproxy = new PHPContentsImportProxy($this->_session, $this->_store, $this->_folderid, $importer, $truncation, $bodypreference);
            // ICS c++ wrapper
            $mapiimporter = mapi_wrap_importcontentschanges($phpimportproxy);
            $exporterflags |= SYNC_NORMAL | SYNC_READ_STATE;

            // Initial sync, we don't want deleted items. If the initial sync is chunked 
            // we check the change ID of the syncstate (0 at initial sync) 
            // On subsequent syncs, we do want to receive delete events.
            if(strlen($syncstate) == 0 || bin2hex(substr($syncstate,4,4)) == "00000000") {
                debugLog("synching inital data");
        	$exporterflags |= SYNC_NO_SOFT_DELETIONS | SYNC_NO_DELETIONS;
            }

        } else {
            $phpimportproxy = new PHPHierarchyImportProxy($this->_store, $importer);
            $mapiimporter = mapi_wrap_importhierarchychanges($phpimportproxy);
        }

        if($flags & BACKEND_DISCARD_DATA)
            $exporterflags |= SYNC_CATCHUP;

        // Put the state information in a stream that can be used by ICS
        $stream = mapi_stream_create();
        if(strlen($syncstate) > 0)
            mapi_stream_write($stream, $syncstate);
        else
            mapi_stream_write($stream, hex2bin("0000000000000000"));

        $this->statestream = $stream;

        switch($mclass) {
            case "Email":
                $restriction = $this->_getEmailRestriction($this->_getCutOffDate($restrict));
                break;
            case "Calendar":
                $restriction = $this->_getCalendarRestriction($this->_getCutOffDate($restrict));
                break;
            default:
            case "Contacts":
            case "Tasks":
                $restriction = false;
                break;
        };

        if($this->_folderid) {
            $includeprops = false;
        } else {
            $includeprops = array(PR_SOURCE_KEY, PR_DISPLAY_NAME);
        }

        if ($this->exporter === false) {
            debugLog("ExportChangesICS->Config failed. Exporter not available.");
            return false;
        }

        $ret = mapi_exportchanges_config($this->exporter, $stream, $exporterflags, $mapiimporter, $restriction, $includeprops, false, 1);

        if($ret) {
            $changes = mapi_exportchanges_getchangecount($this->exporter);
            if($changes || !($flags & BACKEND_DISCARD_DATA))
                debugLog("Exporter configured successfully. " . $changes . " changes ready to sync.");
        }
        else
            debugLog("Exporter could not be configured: result: " . sprintf("%X", mapi_last_hresult()));

        return $ret;
    }

    function GetState() {
        if(!isset($this->statestream) || $this->exporter === false)
            return false;

        if(mapi_exportchanges_updatestate($this->exporter, $this->statestream) != true) {
            debugLog("Unable to update state: " . sprintf("%X", mapi_last_hresult()));
            return false;
        }

        mapi_stream_seek($this->statestream, 0, STREAM_SEEK_SET);

        $state = "";
        while(true) {
            $data = mapi_stream_read($this->statestream, 4096);
            if(strlen($data))
                $state .= $data;
            else
                break;
        }

        return $state;
    }

     function GetChangeCount() {
        if ($this->exporter)
            return mapi_exportchanges_getchangecount($this->exporter);
        else
            return 0;
    }

    function Synchronize() {
        if ($this->exporter) {
            return mapi_exportchanges_synchronize($this->exporter);
        }else
           return false;
    }

    // ----------------------------------------------------------------------------------------------

    function _getCutOffDate($restrict) {
        switch($restrict) {
            case SYNC_FILTERTYPE_1DAY:
                $back = 60 * 60 * 24;
                break;
            case SYNC_FILTERTYPE_3DAYS:
                $back = 60 * 60 * 24 * 3;
                break;
            case SYNC_FILTERTYPE_1WEEK:
                $back = 60 * 60 * 24 * 7;
                break;
            case SYNC_FILTERTYPE_2WEEKS:
                $back = 60 * 60 * 24 * 14;
                break;
            case SYNC_FILTERTYPE_1MONTH:
                $back = 60 * 60 * 24 * 31;
                break;
            case SYNC_FILTERTYPE_3MONTHS:
                $back = 60 * 60 * 24 * 31 * 3;
                break;
            case SYNC_FILTERTYPE_6MONTHS:
                $back = 60 * 60 * 24 * 31 * 6;
                break;
            default:
                break;
        }

        if(isset($back)) {
            $date = time() - $back;
            return $date;
        } else
            return 0; // unlimited
    }

    function _getEmailRestriction($timestamp) {
        $restriction = array ( RES_PROPERTY,
                          array (    RELOP => RELOP_GE,
                                    ULPROPTAG => PR_MESSAGE_DELIVERY_TIME,
                                    VALUE => $timestamp
                          )
                      );

        return $restriction;
    }

    function _getPropIDFromString($stringprop) {
        return GetPropIDFromString($this->_store, $stringprop);
    }

    // Create a MAPI restriction to use in the calendar which will
    // return all future calendar items, plus those since $timestamp
    function _getCalendarRestriction($timestamp) {
        // This is our viewing window
        $start = $timestamp;
        $end = 0x7fffffff; // infinite end

        $restriction = Array(RES_OR,
             Array(
                   // OR
                   // item.end > window.start && item.start < window.end
                   Array(RES_AND,
                         Array(
                               Array(RES_PROPERTY,
                                     Array(RELOP => RELOP_LE,
                                           ULPROPTAG => $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x820d"),
                                           VALUE => $end
                                           )
                                     ),
                               Array(RES_PROPERTY,
                                     Array(RELOP => RELOP_GE,
                                           ULPROPTAG => $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x820e"),
                                           VALUE => $start
                                           )
                                     )
                               )
                         ),
                   // OR
                   Array(RES_OR,
                         Array(
                               // OR
                               // (EXIST(recurrence_enddate_property) && item[isRecurring] == true && item[end] >= start)
                               Array(RES_AND,
                                     Array(
                                           Array(RES_EXIST,
                                                 Array(ULPROPTAG => $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x8236"),
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_EQ,
                                                       ULPROPTAG => $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8223"),
                                                       VALUE => true
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_GE,
                                                       ULPROPTAG => $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x8236"),
                                                       VALUE => $start
                                                       )
                                                 )
                                           )
                                     ),
                               // OR
                               // (!EXIST(recurrence_enddate_property) && item[isRecurring] == true && item[start] <= end)
                               Array(RES_AND,
                                     Array(
                                           Array(RES_NOT,
                                                 Array(
                                                       Array(RES_EXIST,
                                                             Array(ULPROPTAG => $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x8236")
                                                                   )
                                                             )
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_LE,
                                                       ULPROPTAG => $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x820d"),
                                                       VALUE => $end
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_EQ,
                                                       ULPROPTAG => $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8223"),
                                                       VALUE => true
                                                       )
                                                 )
                                           )
                                     )
                               )
                         ) // EXISTS OR
                   )
             );        // global OR

        return $restriction;
    }
}

class BackendICS {
    var $_session;
    var $_user;
    var $_devid;
    var $_importedFolders;

    function Logon($user, $domain, $pass) {
        $pos = strpos($user, "\\");
        if($pos)
            $user = substr($user, $pos+1);

        $this->_session = mapi_logon_zarafa($user, $pass, MAPI_SERVER);

        if($this->_session === false) {
            debugLog("logon failed for user $user");
            $this->_defaultstore = false;
            return false;
        }

        // Get/open default store
        $this->_defaultstore = $this->_openDefaultMessageStore($this->_session);

        if($this->_defaultstore === false) {
            debugLog("user $user has no default store");
            return false;
        }
        $this->_importedFolders = array();

        debugLog("User $user logged on");
        return true;
    }

    function Setup($user, $devid) {
        $this->_user = $user;
        $this->_devid = $devid;

        return true;
    }

    function Logoff() {
    	global $cmd;
        //do not update last sync time on ping and provision
        if (isset($cmd) && $cmd != '' && $cmd != 'Ping' && $cmd != 'Provision' )
            $this->setLastSyncTime();

        // publish free busy time after finishing the synchronization process
        // update if the calendar folder received incoming changes
        $storeprops = mapi_getprops($this->_defaultstore, array(PR_USER_ENTRYID));
        $root = mapi_msgstore_openentry($this->_defaultstore);
        if (!$root) return true;

        $rootprops = mapi_getprops($root, array(PR_IPM_APPOINTMENT_ENTRYID));
        foreach($this->_importedFolders as $folderid) {
            $entryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, hex2bin($folderid));
            if($rootprops[PR_IPM_APPOINTMENT_ENTRYID] == $entryid) {
                debugLog("Update freebusy for ". $folderid);
                $calendar = mapi_msgstore_openentry($this->_defaultstore, $entryid);

                $pub = new FreeBusyPublish($this->_session, $this->_defaultstore, $calendar, $storeprops[PR_USER_ENTRYID]);
                $pub->publishFB(time() - (7 * 24 * 60 * 60), 6 * 30 * 24 * 60 * 60); // publish from one week ago, 6 months ahead
            }
        }

        return true;
    }

    /**
     * Checks if the sent policykey matches the latest policykey on the server
     *
     * @param string $policykey
     * @param string $devid
     *
     * @return status flag
     */ 
    function CheckPolicy($policykey, $devid) {
        global $user, $auth_pw;
	
        $status = SYNC_PROVISION_STATUS_SUCCESS;
	
        $user_policykey = $this->getPolicyKey($user, $auth_pw, $devid);
	
        if ($user_policykey != $policykey) {
            $status = SYNC_PROVISION_STATUS_POLKEYMISM;
        }
	
        if (!$policykey) $policykey = $user_policykey;
        return $status;
    }
	

    function generatePolicyKey() {
        return mt_rand(1000000000, 9999999999);
    }

    function setPolicyKey($policykey, $devid) {
        global $devtype, $useragent;
        if ($this->_defaultstore !== false) {
            //get devices properties
	    // START CHANGED dw2412 Settings support
            $devicesprops = mapi_getprops($this->_defaultstore, array(0x6880101E, 0x6881101E, 0x6882101E, 0x6883101E, 0x68841003, 0x6885101E, 0x6886101E, 0x6887101E, 0x68881040, 0x68891040,
		    0x6890101E, 0x6891101E, 0x6892101E, 0x6893101E, 0x6894101E, 0x6895101E, 0x6896101E, 0x6897101E, 0x6898101E, 0x689F101E));
	    // END CHANGED dw2412 Settings support

            if (!$policykey) {
                $policykey = $this->generatePolicyKey();
            }

            //check if devid is known
            if (isset($devicesprops[0x6881101E]) && is_array($devicesprops[0x6881101E])) {
                $ak = array_search($devid, $devicesprops[0x6881101E]);
                if ($ak !== false) {
                    //update policykey
                    $devicesprops[0x6880101E][$ak] = $policykey;
                    $devicesprops[0x6883101E][$ak] = $useragent;
                }
                else {
                    //Unknown device. Store its information.
                    $devicesprops[0x6880101E][] = $policykey;
                    $devicesprops[0x6881101E][] = $devid;
                    $devicesprops[0x6882101E][] = ($devtype) ? $devtype : "unknown";
                    $devicesprops[0x6883101E][] = $useragent;
                    $devicesprops[0x68841003][] = SYNC_PROVISION_RWSTATUS_OK;
                    $devicesprops[0x6885101E][] = "undefined"; //wipe requested (date)
                    $devicesprops[0x6886101E][] = "undefined"; //wipe requested by
                    $devicesprops[0x6887101E][] = "undefined"; //wipe executed
                    $devicesprops[0x68881040][] = time(); //first sync
                    $devicesprops[0x68891040][] = 0; //last sync
		    // START ADDED dw2412 Support settings (necessary to keep in sync with the arrays)
		    $devicesprops[0x6890101E][] = "undefined";
		    $devicesprops[0x6891101E][] = "undefined";
    		    $devicesprops[0x6892101E][] = "undefined";
		    $devicesprops[0x6893101E][] = "undefined";
		    $devicesprops[0x6894101E][] = "undefined";
	    	    $devicesprops[0x6895101E][] = "undefined";
		    $devicesprops[0x6896101E][] = "undefined";
		    $devicesprops[0x6897101E][] = "undefined";
		    $devicesprops[0x6898101E][] = "undefined";
		    $devicesprops[0x689F101E][] = "undefined";
		    // END ADDED dw2412 Support settings (necessary to keep in sync with the arrays)
                }
            }
            else {
                //First device. Store its information.
                $devicesprops[0x6880101E][] = $policykey;
                $devicesprops[0x6881101E][] = $devid;
                $devicesprops[0x6882101E][] = ($devtype) ? $devtype : "unknown";
                $devicesprops[0x6883101E][] = $useragent;
                $devicesprops[0x68841003][] = SYNC_PROVISION_RWSTATUS_OK;
                $devicesprops[0x6885101E][] = "undefined"; //wipe requested (date)
                $devicesprops[0x6886101E][] = "undefined"; //wipe requested by
                $devicesprops[0x6887101E][] = "undefined"; //wipe executed
                $devicesprops[0x68881040][] = time(); //first sync
                $devicesprops[0x68891040][] = 0; //last sync
		// START ADDED dw2412 Support settings (necessary to keep in sync with the arrays)
		$devicesprops[0x6890101E][] = "undefined";
		$devicesprops[0x6891101E][] = "undefined";
    		$devicesprops[0x6892101E][] = "undefined";
		$devicesprops[0x6893101E][] = "undefined";
		$devicesprops[0x6894101E][] = "undefined";
	    	$devicesprops[0x6895101E][] = "undefined";
		$devicesprops[0x6896101E][] = "undefined";
		$devicesprops[0x6897101E][] = "undefined";
		$devicesprops[0x6898101E][] = "undefined";
		$devicesprops[0x689F101E][] = "undefined";
		// END ADDED dw2412 Support settings (necessary to keep in sync with the arrays)
            }
            mapi_setprops($this->_defaultstore, $devicesprops);

            return $policykey;
        }
        else
            debugLog("ERROR: user store not available for policykey update");

        return false;
    }


    function getPolicyKey ($user, $pass, $devid) {
        if($this->_session === false) {
            debugLog("logon failed for user $user");
            return false;
        }
            	
        //user is logged in or can login, get the policy key and device id
        if ($this->_defaultstore !== false) {
            $devicesprops = mapi_getprops($this->_defaultstore, array(0x6880101E, 0x6881101E));
            if (isset($devicesprops[0x6881101E]) && is_array($devicesprops[0x6881101E])) {
                $ak = array_search($devid, $devicesprops[0x6881101E]);
                if ($ak !== false) {
                    //return policykey
                    return $devicesprops[0x6880101E][$ak];
                }
                else {
                    //new device is. generate new policy for it.
                    return $this->setPolicyKey(0, $devid);
                }

            }
            //user's first device, generate a new key
            //and set firstsync, deviceid, devicetype and useragent
            else {
                return $this->setPolicyKey(0, $devid);
            }
        }
        //get policy key without logging in somehow
        else {
            return false;
        }
        return false;
    }

    function getDeviceRWStatus($user, $pass, $devid) {

        if($this->_session === false) {
            debugLog("logon failed for user $user");
            return false;
        }

        // Get/open default store - we have to do this because otherwise it returns old values :(
        $defaultstore = $this->_openDefaultMessageStore($this->_session);

        //user is logged in or can login, get the remote wipe status
        if ($defaultstore !== false) {
            $devicesprops = mapi_getprops($defaultstore, array(0x68841003, 0x6881101E));
            if (isset($devicesprops[0x6881101E]) && is_array($devicesprops[0x6881101E])) {
                $ak = array_search($devid, $devicesprops[0x6881101E]);
                if ($ak !== false) {
                    //return remote wipe status
                    return $devicesprops[0x68841003][$ak];
                }
            }
            return SYNC_PROVISION_RWSTATUS_NA;
        }
        //get policy key without logging in somehow
        else {
            return false;
        }
        return false;
    }


    function setDeviceRWStatus($user, $pass, $devid, $status) {
        global $policykey;
        if($this->_session === false) {
            debugLog("Set rw status: logon failed for user $user");
            return false;
        }

        // Get/open default store - we have to do this because otherwise it returns old values :(
        $defaultstore = $this->_openDefaultMessageStore($this->_session);

        //user is logged in or can login, get the remote wipe status
        if ($defaultstore !== false) {
            $devicesprops = mapi_getprops($defaultstore, array(0x68841003, 0x6881101E, 0x6887101E, 0x6885101E, 0x6886101E));
            if (isset($devicesprops[0x6881101E]) && is_array($devicesprops[0x6881101E])) {
                $ak = array_search($devid, $devicesprops[0x6881101E]);
                if ($ak !== false) {
                    //set new status remote wipe status
                    $devicesprops[0x68841003][$ak] = $status;
                    if ($status == SYNC_PROVISION_RWSTATUS_WIPED) {
                        $devicesprops[0x6887101E][$ak] = time();
                        debugLog("RemoteWipe ".(($policykey == 0)?'sent':'executed').": Device '". $devid ."' of '". $user ."' requested by '". $devicesprops[0x6886101E][$ak] ."' at ". strftime("%Y-%m-%d %H:%M", $devicesprops[0x6885101E][$ak]));
                    }
                    mapi_setprops($defaultstore, array(0x68841003 => $devicesprops[0x68841003], 0x6887101E =>$devicesprops[0x6887101E]));
                    return true;
                }
            }
            return true;
        }
        //get policy key without logging in somehow
        else {
            return false;
        }
        return false;
    }

    function setLastSyncTime () {
        if ($this->_defaultstore !== false) {
            $devicesprops = mapi_getprops($this->_defaultstore,
            array(0x6881101E, 0x68891040));
            if (isset($devicesprops[0x6881101E]) && is_array($devicesprops[0x6881101E])) {
                $ak = array_search($this->_devid, $devicesprops[0x6881101E]);
                if ($ak !== false) {
                    //set new sync time
                    $devicesprops[0x68891040][$ak] = time();
                    mapi_setprops($this->_defaultstore, array(0x68891040=>$devicesprops[0x68891040]));
                }
                else {
                    debugLog("setLastSyncTime: No device found.");
                }
            }
            else {
                debugLog("setLastSyncTime: No devices found");
            }
        }
    }

    // START CHANGED dw2412 AS V12.0 Support 
    /* Function gets the search querry as it is provided by the request from device and the searchname
     * that should be called within the backend itself (just the DocumentLibrary since it is independent
     * from the backend is handled here
     */
    function getSearchResults($searchquery,$searchname){
	switch (strtoupper($searchname)) {
	    case "GAL"		: 
		return $this->getSearchResultsGAL($searchquery);
	    case "MAILBOX"		: 
		return $this->getSearchResultsMailbox($searchquery);
    	    case "DOCUMENTLIBRARY"	: 
		return $this->getSearchResultsDocumentLibrary($searchquery);
    	    break;
	}
    }

    // START ADDED dw2412 V12.0 Document Library Support
    // Search Query for DocumentLibrary searchname.
    function getSearchResultsDocumentLibrary($searchquery) {
	$result['rows'] = array();
	// Return status code 14 in case username/password is not provided (should not happen since we use
	// auth_user and auth_pw earlier to fill the credentials data because username and password transport
	// exists only in AS 12.1/14.0 Devices
	if (!isset($searchquery['username']) || !is_array($searchquery['username']) ||
	    !isset($searchquery['password']) || $searchquery['password'] == "") {
	    $result['status'] = "14";
	    return $result;
	}
	// The _getSearchResultsMailboxTranslation is placed in the backend itself. Since it returns by default
	// MAPI Style results it has to be adapted per backend.
	foreach($searchquery['query'] as $value) {
	    $query = $this->_getSearchResultsMailboxTranslation($value);
	}
	foreach ($query["query"] as $key => $value) {
	    $linkid = explode("/",$value);
	    foreach($linkid as $key1 => $value1) {
		if ($value1 == "file:" ||
		    $value1 == "") unset($linkid[$key1]);
	    }
	    $link = "smb://".$searchquery['username']['username'].":".$searchquery['password']."@";
	    foreach($linkid as $key2 => $value2) {
		$link .= $value2."/";
	    }
	    $link = substr($link,0,strlen($link)-1);
	    $stat = stat($link);
	    $res['linkid'] = $value;
	    $res['displayname'] = $value1;
	    $res['creationdate'] = $stat['ctime'];
	    $res['lastmodifieddate'] = $stat['mtime'];
	    $res['ishidden'] = "0";
	    if (is_dir($link)) {
	        $res['isfolder'] = "1";
	    } else {
	        $res['isfolder'] = "0";
	        $res['contentlength'] = $stat['size'];
		$res['contenttype'] = mime_content_type($link);
	    }
	    $result['rows'][]=$res;
	}
	$result['status'] = "1";
	return $result;
    }

    // Get the file by an ItemOperation call. Linkid = the filename, Credentials is an array that consists 
    // of username with a subarray that consist of username and domain. Password is provided as cleartext.
    function ItemOperationsGetDocumentLibraryLink($linkid,$credentials) {
	// Return status code 18 in case username/password is not provided (should not happen since we use
	// auth_user and auth_pw earlier to fill the credentials data because username and password transport
	// exists only in AS 12.1/14.0 Devices
	if (!isset($credentials['username']) || !is_array($credentials['username']) ||
	    !isset($credentials['password']) || $credentials['password'] == "") {
	    $result['status'] = 18;
	    return $result;
	}
	// Just keep the necessary parts from the link that we need. Value that fill be kept is as first element
	// the servername on that the file resides and in the next fields the path elements.
	$fileid = explode("/",$linkid);
	foreach($fileid as $key1 => $value1) {
	if ($value1 == "file:" ||
	    $value1 == "") unset($fileid[$key1]);
	}
	// Put things in smb.php style...
    	$link = "smb://".$credentials['username']['username'].":".$credentials['password']."@";
	foreach($fileid as $key2 => $value2) {
	    $link .= $value2."/";
	}
	$link = substr($link,0,strlen($link)-1); // remove the last slash...
	// Get the file stats and file itself
	$stat = stat($link);
	$result['total'] = $stat['size'];
	$result['version'] = $stat['mtime'];
	$file = fopen($link,'r');
	$result['data'] = "";
	while (!feof($file)) {
	    $result['data'] .= fread($file,2048);
	};
	fclose($file);
	// Since the understanding of IIS compression by gzip is a bit different from the common way
	// (YES they enhance the file during compressing and GZipped content cannot be opened by device)
	// we override here the GZIP Compression. 
	// Please find the files in Z-Push Developer forum. In case you have an idea what might be going
	// on, please tell me. I'm out of ideas with this. Overriding GZIP is the only idea left ;-)
	define ('OVERRIDE_GZIP',true);
	$result['status'] = 1;
	return $result;
    }
    // END ADDED dw2412 V12.0 Document Library Support

    function _getSearchResultsMailboxTranslation($query) {
	switch (strtolower($query["op"])) {
	    case "search:and"		: $mapiquery["query"] = array(RES_AND, array()); break;
	    case "search:or"		: $mapiquery["query"] = array(RES_OR , array()); break;
	    case "search:greaterthan"	: $RELOP = RELOP_GT; break;
	    case "search:lessthan"	: $RELOP = RELOP_LT; break;
	    case "search:equalto"	: $RELOP = RELOP_EQ; break;
	}
	if (is_array($query["value"])) {
	    foreach($query["value"] as $key=>$value) {
		switch(strtolower($key)) {
		    case "folderid"			: $mapiquery[PR_SOURCE_KEY] = hex2bin($value); break;
		    case "foldertype"			: switch (strtolower($value)) {
							      case "email" : 
								$mapiquery["query"][1][] = 
						            	    array(RES_OR,
                        						    array(
                            							array(RES_PROPERTY, array(RELOP => RELOP_EQ, ULPROPTAG => PR_MESSAGE_CLASS, VALUE => "IPM.Note")),
                            							array(RES_PROPERTY, array(RELOP => RELOP_EQ, ULPROPTAG => PR_MESSAGE_CLASS, VALUE => "IPM.Post")),
                        						    ), // RES_OR
                    							);
								break;
							  }; 
							  break;
		    case "search:freetext"		: $mapiquery["query"][1][] = 
						               array(RES_OR,
                        					    array(
                            						array(RES_CONTENT, array(FUZZYLEVEL => FL_SUBSTRING | FL_IGNORECASE, ULPROPTAG => PR_BODY, VALUE => $value)),
                            						array(RES_CONTENT, array(FUZZYLEVEL => FL_SUBSTRING | FL_IGNORECASE, ULPROPTAG => PR_SUBJECT, VALUE => $value)),
                        						), // RES_OR
                    						    );
							  break;
		    case "subquery"			: foreach($value as $subvalue) {
							      $mapiquery["query"][1][] = $this->_getSearchResultsMailboxTranslation($subvalue); 
							  }; 
							  break;
		    case "poommail:datereceived"	: $field = PR_MESSAGE_DELIVERY_TIME;
							  return array(RES_PROPERTY, array(RELOP => $RELOP,ULPROPTAG => $field, VALUE => array($field => $value))); break;
		    case "documentlibrary:linkid"	: $mapiquery["query"]["linkid"] = $value;
							  break;

		}
	    }
	}
	return $mapiquery;
    }
    
    function getSearchResultsMailbox($searchquery) {
	if (!is_array($searchquery)) return array();
	foreach($searchquery['query'] as $value) {
	    $query = $this->_getSearchResultsMailboxTranslation($value);
	}
	// get search folder
	$storeProps = mapi_getprops($this->_defaultstore, array(PR_STORE_SUPPORT_MASK, PR_FINDER_ENTRYID));
	if (($storeProps[PR_STORE_SUPPORT_MASK] & STORE_SEARCH_OK)!=STORE_SEARCH_OK) {
	    return array();
	} else {
	    // open search folders root
	    $searchFolderEntryID = mapi_msgstore_openentry($this->_defaultstore, $storeProps[PR_FINDER_ENTRYID]);
	    if (mapi_last_hresult()!=0){
		return array();
	    }
	} 
	$result = mapi_table_queryallrows(mapi_folder_gethierarchytable($searchFolderEntryID),array(PR_DISPLAY_NAME,PR_ENTRYID,PR_SOURCE_KEY));
	$folderName = "Z-Push Search ".$this->_devid;
	$found = false;
	foreach($result as $array) {
	    if (array_keys($array,$folderName)) {
	        $found = $array[PR_ENTRYID];
		$searchfoldersourcekey = $array[PR_SOURCE_KEY];
		break;
	    }
	}
	if ($found == false) {
	    // create search folder
	    $folder = mapi_folder_createfolder($searchFolderEntryID, $folderName, null, 0, FOLDER_SEARCH);
	} else {
	    $folder = mapi_openentry($this->_session,$found);
	} 
	$props = mapi_getprops($folder,array(PR_SOURCE_KEY));
	$searchfoldersourcekey = $props[PR_SOURCE_KEY];
	if (!isset($query[PR_SOURCE_KEY]) ||
	    !($startsearchinfolderid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, $query[PR_SOURCE_KEY]))) {
	    $tmp = mapi_getprops($this->_defaultstore, array(PR_ENTRYID,PR_DISPLAY_NAME,PR_IPM_SUBTREE_ENTRYID));
	    $startsearchinfolderid = $tmp[PR_IPM_SUBTREE_ENTRYID];
	};
	$search = mapi_folder_getsearchcriteria($folder);
	$range = explode("-",$searchquery['range']);
	if ($range[0] == 0 &&
	    (!isset($search['restriction']) ||
	     md5(serialize($search['restriction'])) != md5(serialize($query['query'])))) {
	    debugLog("Set Search Criteria");
	    if (($search['searchstate'] & SEARCH_REBUILD) == true &&
		($search['searchstate'] & SEARCH_RUNNING) == true) {
		mapi_folder_setsearchcriteria($folder, 
		    		    	  $query["query"],
				          array($startsearchinfolderid),
				          STOP_SEARCH);
	    }
	    mapi_folder_setsearchcriteria($folder, 
				      $query["query"],
				      array($startsearchinfolderid),
				      ((isset($searchquery['deeptraversal']) && $searchquery['deeptraversal'] == true) ? RECURSIVE_SEARCH : SHALLOW_SEARCH) |
				      ((isset($searchquery['rebuildresults']) && $searchquery['rebuildresults'] == true) ? RESTART_SEARCH : 0)  );
	}
	$i=0;
	$table = mapi_folder_getcontentstable($folder);
	do {
	    $search = mapi_folder_getsearchcriteria($folder);
	    sleep(1);
	    $i++;
	} while(($search['searchstate'] & SEARCH_REBUILD) == true &&
	        ($search['searchstate'] & SEARCH_RUNNING) == true && 
	        $i<120 &&
	        mapi_table_getrowcount($table) <= $range[1]);
	if (($rows = mapi_table_queryallrows($table, array(PR_ENTRYID, PR_SOURCE_KEY,PR_PARENT_SOURCE_KEY)))) {
	    foreach($rows as $value) {
		$res["searchfolderid"] = bin2hex($searchfoldersourcekey);
		$res["uniqueid"] = bin2hex($value[PR_ENTRYID]);
		$res["item"] = bin2hex($value[PR_SOURCE_KEY]);
		$res["parent"] = bin2hex($value[PR_PARENT_SOURCE_KEY]);
		$result['rows'][] = $res;
	    };
	    $result['status']=1;
	} else {
	    $result = array();
	    $result['status']=0;
	}
	return $result;
    }

    // MAYBE we can use the Fetch function with slight modifications.
    function ItemOperationsGetIDs($entryid) {
        $entryid = hex2bin($entryid);
        $message = mapi_msgstore_openentry($this->_defaultstore, $entryid);
        if(!$message) {
            debugLog("Unable to open message for FetchSearchResultsMailboxMessage command");
            return false;
        }
	$tmp = mapi_getprops($message, array(PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY));
	$retval["folderid"] = bin2hex($tmp[PR_PARENT_SOURCE_KEY]);
	$retval["serverentryid"] = bin2hex($tmp[PR_SOURCE_KEY]);
	return $retval;
    }

    function ItemOperationsFetchMailbox($entryid, $bodypreference, $mimesupport = 0) {
        $entryid = hex2bin($entryid);

        $dummy = false;
        $message = mapi_msgstore_openentry($this->_defaultstore, $entryid);

        if(!$message) {
            debugLog("Unable to open message for ItemOperationsFetchMailbox command");
            return false;
        }

	// Need to have the PARENT_SOURCE_KEY for folder ID so that attachments can be downloaded
	$props = mapi_getprops($message, array(PR_PARENT_SOURCE_KEY));

        // Fake a contents importer because it can do the conversion for us
        $importer = new PHPContentsImportProxy($this->_session, $this->_defaultstore, $props[PR_PARENT_SOURCE_KEY], $dummy, SYNC_TRUNCATION_ALL, $bodypreference);

        return $importer->_getMessage($message, 1024*1024, $bodypreference,$mimesupport); // Get 1MB of body size
    }

    function ItemOperationsGetAttachmentData($attname) {
        list($folderid, $id, $attachnum) = explode(":", $attname);

        if(!isset($id) || !isset($attachnum))
            return false;

        $sourcekey = hex2bin($id);
        $foldersourcekey = hex2bin($folderid);

        $entryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, $foldersourcekey, $sourcekey);
        if(!$entryid) {
            debugLog("Attachment requested for non-existing item $attname");
            return false;
        }

        $importer = new PHPContentsImportProxy($this->_session, $this->_defaultstore, $foldersourcekey, $dummy, SYNC_TRUNCATION_ALL, false);

        $message = mapi_msgstore_openentry($this->_defaultstore, $entryid);
        if(!$message) {
            debugLog("Unable to open item for attachment data for " . bin2hex($entryid));
            return false;
        }

	return $importer->_getAttachment($message,$attachnum);
    }

    function getSearchResultsGAL($searchquery){
        // only return users from who the displayName or the username starts with $name
        //TODO: use PR_ANR for this restriction instead of PR_DISPLAY_NAME and PR_ACCOUNT
        $addrbook = mapi_openaddressbook($this->_session);
        $ab_entryid = mapi_ab_getdefaultdir($addrbook);
        $ab_dir = mapi_ab_openentry($addrbook, $ab_entryid);

        $table = mapi_folder_getcontentstable($ab_dir);
        $restriction = $this->_getSearchRestriction(u2w($searchquery));

        mapi_table_restrict($table, $restriction);
        mapi_table_sort($table, array(PR_DISPLAY_NAME => TABLE_SORT_ASCEND));
	// CHANGED dw2412 AS V12.0 Support (to menetain single return way...
        $items['rows'] = array();
        for ($i = 0; $i < mapi_table_getrowcount($table); $i++) {
            $user_data = mapi_table_queryrows($table, array(PR_ACCOUNT, PR_DISPLAY_NAME, PR_SMTP_ADDRESS, PR_BUSINESS_TELEPHONE_NUMBER), $i, 1);
            $item = array();
            $item["username"] = w2u($user_data[0][PR_ACCOUNT]);
            $item["fullname"] = w2u($user_data[0][PR_DISPLAY_NAME]);
            if (strlen(trim($item["fullname"])) == 0) $item["fullname"] = $item["username"];
            $item["emailaddress"] = w2u($user_data[0][PR_SMTP_ADDRESS]);
            $item["nameid"] = $searchquery;
            $item["businessphone"] = isset($user_data[0][PR_BUSINESS_TELEPHONE_NUMBER]) ? w2u($user_data[0][PR_BUSINESS_TELEPHONE_NUMBER]) : "";

            //do not return users without email
            if (strlen(trim($item["emailaddress"])) == 0) continue;

	    // CHANGED dw2412 AS V12.0 Support (to menetain single return way...
            array_push($items['rows'], $item);
        }
	$items['status']=1;
        return $items;
    }

    // END CHANGED dw2412 AS V12.0 Support 

    function GetHierarchyImporter() {
        return new ImportHierarchyChangesICS($this->_defaultstore);
    }

    function GetContentsImporter($folderid) {
        $this->_importedFolders[] = $folderid;
        return new ImportContentsChangesICS($this->_session, $this->_defaultstore, hex2bin($folderid));
    }

    function GetExporter($folderid = false) {
        if($folderid !== false)
            return new ExportChangesICS($this->_session, $this->_defaultstore, hex2bin($folderid));
        else
            return new ExportChangesICS($this->_session, $this->_defaultstore);
    }

    function GetHierarchy() {
        $folders = array();
        $himp= new PHPHierarchyImportProxy($this->_defaultstore, &$folders);

        $rootfolder = mapi_msgstore_openentry($this->_defaultstore);
        $rootfolderprops = mapi_getprops($rootfolder, array(PR_SOURCE_KEY));
        $rootfoldersourcekey = bin2hex($rootfolderprops[PR_SOURCE_KEY]);

        $hierarchy =  mapi_folder_gethierarchytable($rootfolder, CONVENIENT_DEPTH);
        $rows = mapi_table_queryallrows($hierarchy, array(PR_ENTRYID));

        foreach ($rows as $row) {
            $mapifolder = mapi_msgstore_openentry($this->_defaultstore, $row[PR_ENTRYID]);
            $folder = $himp->_getFolder($mapifolder);

            if (isset($folder->parentid) && $folder->parentid != $rootfoldersourcekey)
                $folders[] = $folder;
        }

        return $folders;
    }

    function SendMail($rfc822, $forward = false, $reply = false, $parent = false, $protocolversion = false) {
        if (WBXML_DEBUG === true)
            debugLog("SendMail: forward: $forward   reply: $reply   parent: $parent\n" . $rfc822);

        $mimeParams = array('decode_headers' => false,
                            'decode_bodies' => true,
                            'include_bodies' => true,
					        'charset' => 'utf-8');
        
        $mimeObject = new Mail_mimeDecode($rfc822);
        $message = $mimeObject->decode($mimeParams);

        // Open the outbox and create the message there
        $storeprops = mapi_getprops($this->_defaultstore, array(PR_IPM_OUTBOX_ENTRYID, PR_IPM_SENTMAIL_ENTRYID));
        if(!isset($storeprops[PR_IPM_OUTBOX_ENTRYID])) {
            debugLog("Outbox not found to create message");
            return false;
        }

        $outbox = mapi_msgstore_openentry($this->_defaultstore, $storeprops[PR_IPM_OUTBOX_ENTRYID]);
        if(!$outbox) {
            debugLog("Unable to open outbox");
            return false;
        }

        $mapimessage = mapi_folder_createmessage($outbox);

        mapi_setprops($mapimessage, array(
            PR_SUBJECT => u2w($mimeObject->_decodeHeader(isset($message->headers["subject"])?$message->headers["subject"]:"")),
            PR_SENTMAIL_ENTRYID => $storeprops[PR_IPM_SENTMAIL_ENTRYID],
            PR_MESSAGE_CLASS => "IPM.Note",
            PR_MESSAGE_DELIVERY_TIME => time()
        ));

        if(isset($message->headers["x-priority"])) {
            switch($message->headers["x-priority"]) {
                case 1:
                case 2:
                    $priority = PRIO_URGENT;
                    $importance = IMPORTANCE_HIGH;
                    break;
                case 4:
                case 5:
                    $priority = PRIO_NONURGENT;
                    $importance = IMPORTANCE_LOW;
                    break;
                case 3:
                default:
                    $priority = PRIO_NORMAL;
                    $importance = IMPORTANCE_NORMAL;
                    break;
            }
            mapi_setprops($mapimessage, array(PR_IMPORTANCE => $importance, PR_PRIORITY => $priority));
        }

        $addresses = array();

        $toaddr = $ccaddr = $bccaddr = array();

        $Mail_RFC822 = new Mail_RFC822();
        if(isset($message->headers["to"]))
            $toaddr = $Mail_RFC822->parseAddressList($message->headers["to"]);
        if(isset($message->headers["cc"]))
            $ccaddr = $Mail_RFC822->parseAddressList($message->headers["cc"]);
        if(isset($message->headers["bcc"]))
            $bccaddr = $Mail_RFC822->parseAddressList($message->headers["bcc"]);

        // Add recipients
        $recips = array();

        if(isset($toaddr)) {
            foreach(array(MAPI_TO => $toaddr, MAPI_CC => $ccaddr, MAPI_BCC => $bccaddr) as $type => $addrlist) {
                foreach($addrlist as $addr) {
                    $mapirecip[PR_ADDRTYPE] = "SMTP";
                    $mapirecip[PR_EMAIL_ADDRESS] = $addr->mailbox . "@" . $addr->host;
                    if(isset($addr->personal) && strlen($addr->personal) > 0)
                        $mapirecip[PR_DISPLAY_NAME] = u2w($mimeObject->_decodeHeader($addr->personal));
                    else
                        $mapirecip[PR_DISPLAY_NAME] = $mapirecip[PR_EMAIL_ADDRESS];
                    $mapirecip[PR_RECIPIENT_TYPE] = $type;

                    $mapirecip[PR_ENTRYID] = mapi_createoneoff($mapirecip[PR_DISPLAY_NAME], $mapirecip[PR_ADDRTYPE], $mapirecip[PR_EMAIL_ADDRESS]);

                    array_push($recips, $mapirecip);
                }
            }
        }

        mapi_message_modifyrecipients($mapimessage, 0, $recips);

        // Loop through message subparts.
        $body = "";
        $body_html = "";
        if($message->ctype_primary == "multipart" && ($message->ctype_secondary == "mixed" || $message->ctype_secondary == "alternative")) {
    	    $mparts = $message->parts;
            for($i=0; $i<count($mparts); $i++) {
            	$part = $mparts[$i];

	        // palm pre & iPhone send forwarded messages in another subpart which are also parsed
	        if($part->ctype_primary == "multipart" && ($part->ctype_secondary == "mixed" || $part->ctype_secondary == "alternative"  || $part->ctype_secondary == "related")) {
	    	    foreach($part->parts as $spart)
	    		$mparts[] = $spart;
	    	    continue;
	    	}

            	// standard body
            	if($part->ctype_primary == "text" && $part->ctype_secondary == "plain" && isset($part->body) && (!isset($part->disposition) || $part->disposition != "attachment")) {
                    $body .= u2w($part->body); // assume only one text body
                }
                // html body
                elseif($part->ctype_primary == "text" && $part->ctype_secondary == "html") {
            	    $body_html .= u2w($part->body);
                }
                // TNEF
                elseif($part->ctype_primary == "ms-tnef" || $part->ctype_secondary == "ms-tnef") {
                    $zptnef = new ZPush_tnef($this->_defaultstore);
                    $mapiprops = array();
                    $zptnef->extractProps($part->body, $mapiprops);
                    if (is_array($mapiprops) && !empty($mapiprops)) {
                        //check if it is a recurring item
                        $tnefrecurr = GetPropIDFromString($this->_defaultstore, "PT_BOOLEAN:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x5");
                        if (isset($mapiprops[$tnefrecurr])) {
                            $this -> _handleRecurringItem($mapimessage, $mapiprops);
                        }
			debugLog(print_r($mapiprops,true));
                        mapi_setprops($mapimessage, $mapiprops);
                    }
                    else debugLog("TNEF: Mapi props array was empty");
                }

		// iCalendar 
                elseif($part->ctype_primary == "text" && $part->ctype_secondary == "calendar") {
                    $zpical = new ZPush_ical($this->_defaultstore);
                    $mapiprops = array();
                    $zpical->extractProps($part->body, $mapiprops);

                    // iPhone sends a second ICS which we ignore if we can
                    if (!isset($mapiprops[PR_MESSAGE_CLASS]) && strlen(trim($body)) == 0) {
                	debugLog("Secondary iPhone response is being ignored!! Mail dropped!");
                	return true;
                    }

                    if (!checkMapiExtVersion("6.30") && is_array($mapiprops) && !empty($mapiprops)) {
                         mapi_setprops($mapimessage, $mapiprops);
                     }
                    else {
                        // store ics as attachment
                        $this->_storeAttachment($mapimessage, $part);
                        debugLog("Sending ICS file as attachment");
                    }

                }

		// dw2412 iCalendar Nokia Nokia MfE sends secondary type x-vCalendar
                elseif($part->ctype_primary == "text" && $part->ctype_secondary == "x-vCalendar") {
                    $zpical = new ZPush_ical($this->_defaultstore);
                    $mapiprops = array();
                    $zpical->extractProps($part->body, $mapiprops);

                    if (is_array($mapiprops) && !empty($mapiprops)) {
			// dw2412 Nokia sends incomplete iCal calendar item, so we have to add properties like 
			// message class and icon index
			if ((isset($mapiprops[PR_MESSAGE_CLASS]) &&
			    $mapiprops[PR_MESSAGE_CLASS] == "IPM.Note") ||
			    !isset($mapiprops[PR_MESSAGE_CLASS])) {
			    $mapiprops[PR_ICON_INDEX] = 0x404;
			    $mapiprops[PR_OWNER_APPT_ID] = 0;
			    $mapiprops[PR_MESSAGE_CLASS] = "IPM.Schedule.Meeting.Request";
                        };
                        // dw2412 Nokia sends no location information field in case user did not type in some
                        // thing in this field...
			$namedIntentedBusyStatus = GetPropIDFromString($this->_defaultstore, "PT_LONG:{00062002-0000-0000-C000-000000000046}:8224");
			if (!isset($mapiprops[$namedIntentedBusyStatus])) $mapiprops[$namedIntentedBusyStatus] = 0;
                        $namedLocation = GetPropIDFromString($this->_defaultstore, "PT_STRING8:{00062002-0000-0000-C000-000000000046}:0x8208");
			if (!isset($mapiprops[$namedLocation])) $mapiprops[$namedLocation] = "";
                        $tnefLocation = GetPropIDFromString($this->_defaultstore, "PT_STRING8:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x2");
			if (!isset($mapiprops[$tnefLocation])) $mapiprops[$tnefLocation] = "";
    			$useTNEF = GetPropIDFromString($this->_defaultstore, "PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8582");
    			$mapiprops[$useTNEF] = true;
                        mapi_setprops($mapimessage, $mapiprops);
                    }
                    else debugLog("ICAL: Mapi props array was empty");
                }
		// any other type, store as attachment
                else
                    $this->_storeAttachment($mapimessage, $part);
            }
        } else {
	    // start dw2412 handle windows mobile html replies...
            // standard body
            if($message->ctype_primary == "text" && $message->ctype_secondary == "plain" && isset($message->body) && (!isset($message->disposition) || $message->disposition != "attachment")) {
                $body = u2w($message->body); // assume only one text body
            }
            // html body
            elseif($message->ctype_primary == "text" && $message->ctype_secondary == "html") {
                $body_html = u2w($message->body);
            }
	    // end dw2412 handle windows mobile html replies...
            // $body = u2w($message->body);
        }

        // some devices only transmit a html body
        if (strlen($body) == 0 && strlen($body_html)>0) {
            debugLog("only html body sent, transformed into plain text");
            $body = strip_tags($body_html);
        }

        if($forward)
            $orig = $forward;
        if($reply)
            $orig = $reply;

        if(isset($orig) && $orig) {
            // Append the original text body for reply/forward
            $entryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, hex2bin($parent), hex2bin($orig));
            $fwmessage = mapi_msgstore_openentry($this->_defaultstore, $entryid);

            if($fwmessage) {
                //update icon when forwarding or replying message
                if ($forward) mapi_setprops($fwmessage, array(PR_ICON_INDEX=>262));
                elseif ($reply) mapi_setprops($fwmessage, array(PR_ICON_INDEX=>261));
                mapi_savechanges($fwmessage);

                $stream = mapi_openproperty($fwmessage, PR_BODY, IID_IStream, 0, 0);
                $fwbody = "";

                while(1) {
                    $data = mapi_stream_read($stream, 1024);
                    if(strlen($data) == 0)
                        break;
                    $fwbody .= $data;
                }

                $stream = mapi_openproperty($fwmessage, PR_HTML, IID_IStream, 0, 0);
                $fwbody_html = "";

                while(1) {
                    $data = mapi_stream_read($stream, 1024);
                    if(strlen($data) == 0)
                        break;
                    $fwbody_html .= $data;
                }
                                
                $stream = mapi_openproperty($fwmessage, PR_HTML, IID_IStream, 0, 0);
                $fwbody_html = "";

                while(1) {
                    $data = mapi_stream_read($stream, 1024);
                    if(strlen($data) == 0)
                        break;
                    $fwbody_html .= $data;
                }

		// dw2412 Enable this only in case of AS2.5 Protocol... in AS12 this seem 
		// being done already by winmobile client.
                if($forward && $protocolversion<=2.5) {
                    // During a forward, we have to add the forward header ourselves. This is because
                    // normally the forwarded message is added as an attachment. However, we don't want this
                    // because it would be rather complicated to copy over the entire original message due
                    // to the lack of IMessage::CopyTo ..

                    $fwmessageprops = mapi_getprops($fwmessage, array(PR_SENT_REPRESENTING_NAME, PR_DISPLAY_TO, PR_DISPLAY_CC, PR_SUBJECT, PR_CLIENT_SUBMIT_TIME));

                    $fwheader = "\r\n\r\n";
                    $fwheader .= "-----Original Message-----\r\n";
                    if(isset($fwmessageprops[PR_SENT_REPRESENTING_NAME]))
                        $fwheader .= "From: " . $fwmessageprops[PR_SENT_REPRESENTING_NAME] . "\r\n";
                    if(isset($fwmessageprops[PR_DISPLAY_TO]) && strlen($fwmessageprops[PR_DISPLAY_TO]) > 0)
                        $fwheader .= "To: " . $fwmessageprops[PR_DISPLAY_TO] . "\r\n";
                    if(isset($fwmessageprops[PR_DISPLAY_CC]) && strlen($fwmessageprops[PR_DISPLAY_CC]) > 0)
                        $fwheader .= "Cc: " . $fwmessageprops[PR_DISPLAY_CC] . "\r\n";
                    if(isset($fwmessageprops[PR_CLIENT_SUBMIT_TIME]))
                        $fwheader .= "Sent: " . strftime("%x %X", $fwmessageprops[PR_CLIENT_SUBMIT_TIME]) . "\r\n";
                    if(isset($fwmessageprops[PR_SUBJECT]))
                        $fwheader .= "Subject: " . $fwmessageprops[PR_SUBJECT] . "\r\n";
                    $fwheader .= "\r\n";


                    // add fwheader to body and body_html
                    $body .= $fwheader;
                    if (strlen($body_html) > 0)
                        $body_html .= str_ireplace("\r\n", "<br>", $fwheader);

                }

                if(strlen($body) > 0)
                    $body .= $fwbody;

                if (strlen($body_html) > 0)
                      $body_html .= $fwbody_html;

            }
            else {
                debugLog("Unable to open item with id $orig for forward/reply");
            }
        }

        if($forward) {
            // Add attachments from the original message in a forward
            $entryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, hex2bin($parent), hex2bin($orig));
            $fwmessage = mapi_msgstore_openentry($this->_defaultstore, $entryid);

            $attachtable = mapi_message_getattachmenttable($fwmessage);
            $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM));

            foreach($rows as $row) {
                if(isset($row[PR_ATTACH_NUM])) {
                    $attach = mapi_message_openattach($fwmessage, $row[PR_ATTACH_NUM]);

                    $newattach = mapi_message_createattach($mapimessage);

                    // Copy all attachments from old to new attachment
                    $attachprops = mapi_getprops($attach);
                    mapi_setprops($newattach, $attachprops);

                    if(isset($attachprops[mapi_prop_tag(PT_ERROR, mapi_prop_id(PR_ATTACH_DATA_BIN))])) {
                        // Data is in a stream
                        $srcstream = mapi_openpropertytostream($attach, PR_ATTACH_DATA_BIN);
                        $dststream = mapi_openpropertytostream($newattach, PR_ATTACH_DATA_BIN, MAPI_MODIFY | MAPI_CREATE);

                        while(1) {
                            $data = mapi_stream_read($srcstream, 4096);
                            if(strlen($data) == 0)
                                break;

                            mapi_stream_write($dststream, $data);
                        }

                        mapi_stream_commit($dststream);
                    }
                    mapi_savechanges($newattach);
                }
            }
        }

        mapi_setprops($mapimessage, array(PR_BODY => $body));

        if(strlen($body_html) > 0){
            mapi_setprops($mapimessage, array(PR_HTML => $body_html));
        }
        mapi_savechanges($mapimessage);
        mapi_message_submitmessage($mapimessage);

        return true;
    }

    function Fetch($folderid, $id, $bodypreference=false, $mimesupport = 0) {
        $foldersourcekey = hex2bin($folderid);
        $messagesourcekey = hex2bin($id);

        $dummy = false;

        // Fake a contents importer because it can do the conversion for us
        $importer = new PHPContentsImportProxy($this->_session, $this->_defaultstore, $foldersourcekey, $dummy, SYNC_TRUNCATION_ALL, $bodypreference);

        $entryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, $foldersourcekey, $messagesourcekey);
        if(!$entryid) {
            debugLog("Unknown ID passed to Fetch");
            return false;
        }

        $message = mapi_msgstore_openentry($this->_defaultstore, $entryid);
        if(!$message) {
            debugLog("Unable to open message for Fetch command");
            return false;
        }

        return $importer->_getMessage($message, 1024*1024, $bodypreference, $mimesupport); // Get 1MB of body size
    }

    function GetWasteBasket() {
        return false;
    }


    function GetAttachmentData($attname) {
        list($folderid, $id, $attachnum) = explode(":", $attname);

        if(!isset($id) || !isset($attachnum))
            return false;

        $sourcekey = hex2bin($id);
        $foldersourcekey = hex2bin($folderid);

        $entryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, $foldersourcekey, $sourcekey);
        if(!$entryid) {
            debugLog("Attachment requested for non-existing item $attname");
            return false;
        }

        $message = mapi_msgstore_openentry($this->_defaultstore, $entryid);
        if(!$message) {
            debugLog("Unable to open item for attachment data for " . bin2hex($entryid));
            return false;
        }

        $attach = mapi_message_openattach($message, $attachnum);
        if(!$attach) {
            debugLog("Unable to open attachment number $attachnum");
            return false;
        }
        
        $attachtable = mapi_message_getattachmenttable($message);
	// START CHANGED dw2412 EML Attachment
        $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM, PR_ATTACH_METHOD));
        foreach($rows as $row) {
    	    if (isset($row[PR_ATTACH_NUM]) && $row[PR_ATTACH_NUM] == $attachnum) {
    		if ($row[PR_ATTACH_METHOD] == ATTACH_EMBEDDED_MSG) {
		    $stream = buildEMLAttachment($attach);
		} else {
		    $stream = mapi_openpropertytostream($attach, PR_ATTACH_DATA_BIN);
    		};
    	    };
        };
	// END CHANGED dw2412 EML Attachment
        
        if(!$stream) {
            debugLog("Unable to open attachment data stream");
            return false;
        }

        while(1) {
            $data = mapi_stream_read($stream, 4096);
            if(strlen($data) == 0)
                break;
            print $data;
        }

        return true;
    }

    function MeetingResponse($requestid, $folderid, $response, &$calendarid) {
        // Use standard meeting response code to process meeting request
        $reqentryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, hex2bin($folderid), hex2bin($requestid));
        $mapimessage = mapi_msgstore_openentry($this->_defaultstore, $reqentryid);

        if(!$mapimessage) {
            debugLog("Unable to open request message for response");
            return false;
        }

        $meetingrequest = new Meetingrequest($this->_defaultstore, $mapimessage);

        if(!$meetingrequest->isMeetingRequest()) {
            debugLog("Attempt to respond to non-meeting request");
            return false;
        }

        if($meetingrequest->isLocalOrganiser()) {
            debugLog("Attempt to response to meeting request that we organized");
            return false;
        }

        // Process the meeting response. We don't have to send the actual meeting response
        // e-mail, because the device will send it itself.
        switch($response) {
            case 1:     // accept
            default:
                $entryid = $meetingrequest->doAccept(false, false, false, false, false, false, true); // last true is the $userAction
                break;
            case 2:        // tentative
                $entryid = $meetingrequest->doAccept(true, false, false, false, false, false, true); // last true is the $userAction
                break;
            case 3:        // decline
                $meetingrequest->doDecline(false);
                break;
        }

        // F/B will be updated on logoff

        // We have to return the ID of the new calendar item, so do that here
        if (isset($entryid)) { 
            $newitem = mapi_msgstore_openentry($this->_defaultstore, $entryid);
            $newprops = mapi_getprops($newitem, array(PR_SOURCE_KEY));
            $calendarid = bin2hex($newprops[PR_SOURCE_KEY]);
        }
 
        // on recurring items, the MeetingRequest class responds with a wrong entryid
        if ($requestid == $calendarid) {
            debugLog("returned calender id is the same as the requestid - re-searching");
            $goidprop = GetPropIDFromString($this->_defaultstore, "PT_BINARY:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x3");

            $messageprops = mapi_getprops($mapimessage, Array($goidprop, PR_OWNER_APPT_ID));
                $goid = $messageprops[$goidprop];
                if(isset($messageprops[PR_OWNER_APPT_ID]))
                    $apptid = $messageprops[PR_OWNER_APPT_ID];
                else
                    $apptid = false;

                $items = $meetingrequest->findCalendarItems($goid, $apptid);

                if (is_array($items)) {
                   $newitem = mapi_msgstore_openentry($this->_defaultstore, $items[0]);
                   $newprops = mapi_getprops($newitem, array(PR_SOURCE_KEY));
                   $calendarid = bin2hex($newprops[PR_SOURCE_KEY]);
                   debugLog("found other calendar entryid");
                }
        }
        
        
        // delete meeting request from Inbox
        $folderentryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, hex2bin($folderid));
        $folder = mapi_msgstore_openentry($this->_defaultstore, $folderentryid);
        mapi_folder_deletemessages($folder, array($reqentryid), 0);
        
        return true;
    }

    // START ADDED dw2412 Settings Support
    function setSettings($request,$devid) 
    {
	if (isset($request["oof"])) {
	    if ($request["oof"]["oofstate"] == 1) {
		foreach ($request["oof"]["oofmsgs"] as $oofmsg) {
		    switch ($oofmsg["appliesto"]) {
			case SYNC_SETTINGS_APPLIESTOINTERNAL :  
			    $result = mapi_setprops($this->_defaultstore, array(PR_EC_OUTOFOFFICE_MSG 		=> utf8_to_windows1252(isset($oofmsg["replymessage"]) ? $oofmsg["replymessage"] : ""),
			    					    		PR_EC_OUTOFOFFICE_SUBJECT 	=> utf8_to_windows1252(_("Out of office notification"))));
			    break;
		    }
		}
		$response["oof"]["status"] = mapi_setprops($this->_defaultstore, array(PR_EC_OUTOFOFFICE => $request["oof"]["oofstate"] == 1 ? true : false)); 
	    } else {
		$response["oof"]["status"] = mapi_setprops($this->_defaultstore, array(PR_EC_OUTOFOFFICE => $request["oof"]["oofstate"] == 1 ? true : false)); 
	    }
	}
	if (isset($request["deviceinformation"])) {
	    if ($this->_defaultstore !== false) {
        	//get devices settings from store
		$props=array();
		$props[SYNC_SETTINGS_MODEL] = mapi_prop_tag(PT_MV_STRING8,  0x6890);
		$props[SYNC_SETTINGS_IMEI] =  mapi_prop_tag(PT_MV_STRING8,  0x6891);
    		$props[SYNC_SETTINGS_FRIENDLYNAME] = mapi_prop_tag(PT_MV_STRING8,  0x6892);
		$props[SYNC_SETTINGS_OS] = mapi_prop_tag(PT_MV_STRING8,  0x6893);
		$props[SYNC_SETTINGS_OSLANGUAGE] = mapi_prop_tag(PT_MV_STRING8,  0x6894);
	        $props[SYNC_SETTINGS_PHONENUMBER] = mapi_prop_tag(PT_MV_STRING8,  0x6895);
		$props[SYNC_SETTINGS_USERAGENT] = mapi_prop_tag(PT_MV_STRING8,  0x6896);
		$props[SYNC_SETTINGS_ENABLEOUTBOUNDSMS] = mapi_prop_tag(PT_MV_STRING8,  0x6897);
		$props[SYNC_SETTINGS_MOBILEOPERATOR] = mapi_prop_tag(PT_MV_STRING8,  0x6898);
        	$sprops = mapi_getprops($this->_defaultstore, array(0x6881101E,
        							    $props[SYNC_SETTINGS_MODEL],
        							    $props[SYNC_SETTINGS_IMEI],
        							    $props[SYNC_SETTINGS_FRIENDLYNAME],
        							    $props[SYNC_SETTINGS_OS],
        							    $props[SYNC_SETTINGS_OSLANGUAGE],
        							    $props[SYNC_SETTINGS_PHONENUMBER],
        							    $props[SYNC_SETTINGS_USERAGENT],
        							    $props[SYNC_SETTINGS_ENABLEOUTBOUNDSMS],
        							    $props[SYNC_SETTINGS_MOBILEOPERATOR]
        							    ));
		//try to find index of current device
        	$ak = array_search($devid, $sprops[0x6881101E]);
		// Set undefined properties to the amount of known device ids
            	foreach($props as $key => $value) {
		    if (!isset($sprops[$value])) {
			for ($i=0;$i<sizeof($sprops[0x6881101E]);$i++) {
			    $sprops[$value][] = "undefined";
			}
		    }
		}
        	if ($ak !== false) {
            	    //update settings (huh this could really occur?!?! - maybe in case of OS update)
            	    foreach($request["deviceinformation"] as $key => $value) {
			if (trim($value) != "") {
        		    $sprops[$props[$key]][$ak] = $value;
        		} else {
        		    $sprops[$props[$key]][$ak] = "undefined";
        		}
        	    }
        	} else {
        	    //new device settings for the db
                    $devicesprops[0x6881101E][] = $devid;
            	    foreach($props as $key => $value) {
			if (isset($request["deviceinformation"][$key]) && trim($request["deviceinformation"][$key]) != "") {
        		    $sprops[$value][] = $request["deviceinformation"][$key];
        		} else {
        		    $sprops[$value][] = "undefined";
        		}
        	    }
        	}
        	// save them
        	$response["deviceinformation"]["status"] = mapi_setprops($this->_defaultstore, $sprops);
	    }
	}
	if (isset($request["devicepassword"])) {
	    if ($this->_defaultstore !== false) {
        	//get devices settings from store
		$props=array();
		$props[SYNC_SETTINGS_PASSWORD] = mapi_prop_tag(PT_MV_STRING8,  0x689F);
        	$pprops = mapi_getprops($this->_defaultstore, array(0x6881101E,
        							    $props[SYNC_SETTINGS_PASSWORD]
        							    ));
		//try to find index of current device
        	$ak = array_search($devid, $pprops[0x6881101E]);
		// Set undefined properties to the amount of known device ids
            	foreach($props as $key => $value) {
		    if (!isset($pprops[$value])) {
			for ($i=0;$i<sizeof($pprops[0x6881101E]);$i++) {
			    $pprops[$value][] = "undefined";
			}
		    }
		}
        	if ($ak !== false) {
            	    //update password
		    if (trim($value) != "") {
        	        $pprops[$props[$key]][$ak] = $request["devicepassword"];
        	    } else {
        	        $pprops[$props[$key]][$ak] = "undefined";
        	    }
        	} else {
        	    //new device password for the db
                    $devicesprops[0x6881101E][] = $devid;
            	    foreach($props as $key => $value) {
			if (isset($request["devicepassword"]) && trim($request["devicepassword"]) != "") {
        		    $pprops[$value][] = $request["devicepassword"];
        		} else {
        		    $pprops[$value][] = "undefined";
        		}
        	    }
        	}
        	// save them
        	$response["devicepassword"]["status"] = mapi_setprops($this->_defaultstore, $pprops);
	    }
	}
	return $response;
    }
    function getSettings($request,$devid) 
    {
	if (isset($request["userinformation"])) {
	    $userdetails = mapi_zarafa_getuser($this->_defaultstore, $this->_user);
	    if ($userdetails != false) {
		$response["userinformation"]["status"] = 1;
		$response["userinformation"]["emailaddresses"][] = $userdetails["emailaddress"];
	    } else {
		$response["userinformation"]["status"] = false;
	    };
	}
	if (isset($request["oof"])) {
	    $props = mapi_getprops($this->_defaultstore, array(PR_EC_OUTOFOFFICE, PR_EC_OUTOFOFFICE_MSG, PR_EC_OUTOFOFFICE_SUBJECT));
	    if ($props != false) {
		$response["oof"]["status"] 	= 1;
		$response["oof"]["oofstate"]	= isset($props[PR_EC_OUTOFOFFICE]) ? ($props[PR_EC_OUTOFOFFICE] ? 1 : 0) : 0;

		$oofmsg["appliesto"]		= SYNC_SETTINGS_APPLIESTOINTERNAL;
		$oofmsg["replymessage"] 	= windows1252_to_utf8(isset($props[PR_EC_OUTOFOFFICE_MSG]) ? $props[PR_EC_OUTOFOFFICE_MSG] : "");
		$oofmsg["enabled"]		= isset($props[PR_EC_OUTOFOFFICE]) ? ($props[PR_EC_OUTOFOFFICE] ? 1 : 0) : 0;
		$oofmsg["bodytype"] 		= $request["oof"]["bodytype"];

	        $response["oof"]["oofmsgs"][]	= $oofmsg;
	    // $this->settings["outofoffice"]["subject"] = windows1252_to_utf8(isset($props[PR_EC_OUTOFOFFICE_SUBJECT]) ? $props[PR_EC_OUTOFOFFICE_SUBJECT] : "");
	    } else {
		$response["oof"]["status"] 	= 0;
	    }
	}
	return $response;
    }
    // END ADDED dw2412 Settings Support

    // ----------------------------------------------------------

    // Open the store marked with PR_DEFAULT_STORE = TRUE
    function _openDefaultMessageStore($session)
    {
        // Find the default store
        $storestables = mapi_getmsgstorestable($session);
        $result = mapi_last_hresult();
        $entryid = false;

        if ($result == NOERROR){
            $rows = mapi_table_queryallrows($storestables, array(PR_ENTRYID, PR_DEFAULT_STORE, PR_MDB_PROVIDER));
            $result = mapi_last_hresult();

            foreach($rows as $row) {
                if(isset($row[PR_DEFAULT_STORE]) && $row[PR_DEFAULT_STORE] == true) {
                    $entryid = $row[PR_ENTRYID];
                    break;
                }
            }
        }

        if($entryid) {
            return mapi_openmsgstore($session, $entryid);
        } else {
            return false;
        }
    }

    // Adds all folders in $mapifolder to $list, recursively
    function _getFoldersRecursive($mapifolder, $parent, &$list) {
        $hierarchytable = mapi_folder_gethierarchytable($mapifolder);
        $folderprops = mapi_getprops($mapifolder, array(PR_ENTRYID));
        if(!$hierarchytable)
            return false;

        $rows = mapi_table_queryallrows($hierarchytable, array(PR_DISPLAY_NAME, PR_SUBFOLDERS, PR_ENTRYID));

        foreach($rows as $row) {
            $folder = array();
            $folder["mod"] = $row[PR_DISPLAY_NAME];
            $folder["id"] = bin2hex($row[PR_ENTRYID]);
            $folder["parent"] = $parent;

            array_push($list, $folder);

            if(isset($row[PR_SUBFOLDERS]) && $row[PR_SUBFOLDERS]) {
                $this->_getFoldersRecursive(mapi_msgstore_openentry($this->_defaultstore, $row[PR_ENTRYID]), $folderprops[PR_ENTRYID], $list);
            }
        }

        return true;
    }

    // gets attachment from a parsed email and stores it to MAPI
    function _storeAttachment($mapimessage, $part) {
        // attachment
        $attach = mapi_message_createattach($mapimessage);

        $filename = "";
        // Filename is present in both Content-Type: name=.. and in Content-Disposition: filename=
        if(isset($part->ctype_parameters["name"]))
            $filename = $part->ctype_parameters["name"];
        else if(isset($part->d_parameters["name"]))
            $filename = $part->d_parameters["filename"];
        else if (isset($part->d_parameters["filename"])) // sending appointment with nokia & android only filename is set
            $filename = $part->d_parameters["filename"];
        // filenames with more than 63 chars as splitted several strings
        else if (isset($part->d_parameters["filename*0"])) {
        	for ($i=0; $i< count($part->d_parameters); $i++) 
        	   if (isset($part->d_parameters["filename*".$i]))
        	       $filename .= $part->d_parameters["filename*".$i];
        }
        else
            $filename = "untitled";

        // Android just doesn't send content-type, so mimeDecode doesn't performs base64 decoding
        // on meeting requests text/calendar somewhere inside content-transfer-encoding
        if (isset($part->headers['content-transfer-encoding']) && strpos($part->headers['content-transfer-encoding'], 'base64')) {
            if (strpos($part->headers['content-transfer-encoding'], 'text/calendar') !== false) {
                $part->ctype_primary = 'text';
                $part->ctype_secondary = 'calendar';
            }
            if (!isset($part->headers['content-type']))
                $part->body = base64_decode($part->body);
        }

        // Set filename and attachment type
        mapi_setprops($attach, array(PR_ATTACH_LONG_FILENAME => u2w($filename), PR_ATTACH_METHOD => ATTACH_BY_VALUE));

        // Set attachment data
        mapi_setprops($attach, array(PR_ATTACH_DATA_BIN => $part->body));

        // Set MIME type
        mapi_setprops($attach, array(PR_ATTACH_MIME_TAG => $part->ctype_primary . "/" . $part->ctype_secondary));

        mapi_savechanges($attach);
    }

    //handles recurring item for meeting request
    function _handleRecurringItem(&$mapimessage, &$mapiprops) {
        $props = array();
        //set isRecurring flag to true
        $props[0] = "PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8223";
        // Set named prop 8510, unknown property, but enables deleting a single occurrence of a recurring type in OLK2003.
        $props[1] = "PT_LONG:{00062008-0000-0000-C000-000000000046}:0x8510";
        //goid and goid2 from tnef
        $props[2] = "PT_BINARY:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x3";
        $props[3] = "PT_BINARY:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x23";
        $props[4] = "PT_STRING8:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x24"; //type
        $props[5] = "PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8205"; //busystatus
        $props[6] = "PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8217"; //meeting status
        $props[7] = "PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8218"; //response status
        $props[8] = "PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8582";
        $props[9] = "PT_BOOLEAN:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0xa"; //is exception

        $props[10] = "PT_I2:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x11"; //day interval
        $props[11] = "PT_I2:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x12"; //week interval
        $props[12] = "PT_I2:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x13"; //month interval
        $props[13] = "PT_I2:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x14"; //year interval

        $props = getPropIdsFromStrings($this->_defaultstore, $props);

        $mapiprops[$props[0]] = true;
        $mapiprops[$props[1]] = 369;
        //both goids have the same value
        $mapiprops[$props[3]] = $mapiprops[$props[2]];
        $mapiprops[$props[4]] = "IPM.Appointment";
        $mapiprops[$props[5]] = 1; //tentative
        $mapiprops[PR_RESPONSE_REQUESTED] = true;
        $mapiprops[PR_ICON_INDEX] = 1027;
        $mapiprops[$props[6]] = olMeetingReceived; // The recipient is receiving the request
        $mapiprops[$props[7]] = olResponseNotResponded;
        $mapiprops[$props[8]] = true;
    }

    function _getSearchRestriction($query) {
        return array(RES_AND,
                    array(
                        array(RES_OR,
                            array(
                                array(RES_CONTENT, array(FUZZYLEVEL => FL_SUBSTRING | FL_IGNORECASE, ULPROPTAG => PR_DISPLAY_NAME, VALUE => $query)),
                                array(RES_CONTENT, array(FUZZYLEVEL => FL_SUBSTRING | FL_IGNORECASE, ULPROPTAG => PR_ACCOUNT, VALUE => $query)),
                            ), // RES_OR
                        ),
                        array(
                            RES_PROPERTY,
                            array(RELOP => RELOP_EQ, ULPROPTAG => PR_OBJECT_TYPE, VALUE => MAPI_MAILUSER)
                        )
                    ) // RES_AND
        );
    }
}


// START ADDED dw2412 just to find out if include file is available (didn't find a better place to have this 
// nice function reachable to check if common classes not already integrated in Zarafa Server exists)
function include_file_exists($filename) {
     $path = explode(":", ini_get('include_path'));
     foreach($path as $value) {
        if (file_exists($value.$filename)) return true;
     }
     
     return false;
}
// END ADDED dw2412 just to find out if include file is available

?>