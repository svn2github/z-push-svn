<?php
/***********************************************
* File      :   mapiutils.php
* Project   :   Z-Push
* Descr     :
*
* Created   :   14.02.2011
*
* Copyright 2007 - 2011 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

/**
 *
 * MAPI to AS mapping class
 *
 *
 */
class MAPIUtils {

    /**
     * Create a MAPI restriction to use within an email folder which will
     * return all messages since since $timestamp
     *
     * @param long       $timestamp     Timestamp since when to include messages
     *
     * @access public
     * @return array
     */
    public static function GetEmailRestriction($timestamp) {
        $restriction = array ( RES_PROPERTY,
                          array (   RELOP => RELOP_GE,
                                    ULPROPTAG => PR_MESSAGE_DELIVERY_TIME,
                                    VALUE => $timestamp
                          )
                      );

        return $restriction;
    }


    /**
     * Create a MAPI restriction to use in the calendar which will
     * return all future calendar items, plus those since $timestamp
     *
     * @param long       $timestamp     Timestamp since when to include messages
     *
     * @access public
     * @return array
     */
    //TODO getting named properties
    public static function GetCalendarRestriction($store, $timestamp) {
        // This is our viewing window
        $start = $timestamp;
        $end = 0x7fffffff; // infinite end

        $props = MAPIMapping::GetAppointmentProperties();
        $props = getPropIdsFromStrings($store, $props);

        $restriction = Array(RES_OR,
             Array(
                   // OR
                   // item.end > window.start && item.start < window.end
                   Array(RES_AND,
                         Array(
                               Array(RES_PROPERTY,
                                     Array(RELOP => RELOP_LE,
                                           ULPROPTAG => $props["starttime"],
                                           VALUE => $end
                                           )
                                     ),
                               Array(RES_PROPERTY,
                                     Array(RELOP => RELOP_GE,
                                           ULPROPTAG => $props["endtime"],
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
                                                 Array(ULPROPTAG => $props["recurrenceend"],
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_EQ,
                                                       ULPROPTAG => $props["isrecurring"],
                                                       VALUE => true
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_GE,
                                                       ULPROPTAG => $props["recurrenceend"],
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
                                                             Array(ULPROPTAG => $props["recurrenceend"]
                                                                   )
                                                             )
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_LE,
                                                       ULPROPTAG => $props["starttime"],
                                                       VALUE => $end
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_EQ,
                                                       ULPROPTAG => $props["isrecurring"],
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


    /**
     * Create a MAPI restriction in order to check if a contact has a picture
     *
     * @access public
     * @return array
     */
    public static function GetContactPicRestriction() {
        return array ( RES_PROPERTY,
                        array (
                            RELOP => RELOP_EQ,
                            ULPROPTAG => mapi_prop_tag(PT_BOOLEAN, 0x7FFF),
                            VALUE => true
                        )
        );
    }


    /**
     * Create a MAPI restriction for search
     *
     * @access public
     *
     * @param string $query
     * @return array
     */
    public static function GetSearchRestriction($query) {
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


    /**
     * Handles recurring item for meeting request coming from tnef
     *
     * @access public
     *
     * @param array $mapiprops
     * @param array $props
     */
    public static function handleRecurringItem(&$mapiprops, &$props) {
        $mapiprops[$props["isrecurringtag"]] = true;
        $mapiprops[$props["sideeffects"]] = 369;
        //both goids have the same value
        $mapiprops[$props["goid2tag"]] = $mapiprops[$props["goidtag"]];
        $mapiprops[$props["type"]] = "IPM.Appointment";
        $mapiprops[$props["busystatus"]] = 1; //tentative
        $mapiprops[PR_RESPONSE_REQUESTED] = true;
        $mapiprops[PR_ICON_INDEX] = 1027;
        $mapiprops[$props["meetingstatus"]] = olMeetingReceived; // The recipient is receiving the request
        $mapiprops[$props["responsestatus"]] = olResponseNotResponded;
        $mapiprops[$props["usetnef"]] = true;
    }


    /**
     * Reads data of large properties from a stream
     *
     * @access public
     *
     * @param MAPIMessage $message
     * @param long $prop
     * @return string
     */
    public static function readPropStream($message, $prop) {
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


    /**
     * Checks if a store supports properties containing unicode characters
     *
     * @access public
     * @param MAPIStore $store
     */
    public static function IsUnicodeStore($store) {
        $supportmask = mapi_getprops($store, array(PR_STORE_SUPPORT_MASK));
        if (isset($supportmask[PR_STORE_SUPPORT_MASK]) && ($supportmask[PR_STORE_SUPPORT_MASK] & STORE_UNICODE_OK)) {
            ZLog::Write(LOGLEVEL_DEBUG, "Store supports properties containing Unicode characters.");
            define('STORE_SUPPORTS_UNICODE', true);
            //setlocale to UTF-8 in order to support properties containing Unicode characters
            setlocale(LC_CTYPE, "en_US.UTF-8");
        }
    }

}

?>