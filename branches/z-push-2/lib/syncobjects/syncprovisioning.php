<?php
/***********************************************
* File      :   syncprovisioning.php
* Project   :   Z-Push
* Descr     :   WBXML AS12+ provisionign entities that
*               can be parsed directly (as a stream) from WBXML.
*               It is automatically decoded
*               according to $mapping,
*               and the Sync WBXML mappings.
*
* Created   :   05.09.2011
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


// TODO define checks for SyncProvisioning (??)
class SyncProvisioning extends SyncObject {
    //AS 12.0, 12.1 and 14.0 props
    public $devpwenabled = 0;
    public $alphanumpwreq = 0;
    public $devencenabled;
    public $pwrecoveryenabled;
    public $docbrowseenabled;
    public $attenabled;
    public $mindevpwlenngth;
    public $maxinacttimedevlock;
    public $maxdevpwfailedattempts;
    public $maxattsize;
    public $allowsimpledevpw;
    public $devpwexpiration;
    public $devpwhistory;

    //AS 12.1 and 14.0 props
    public $allostoragecard;
    public $allowcam;
    public $reqdevenc;
    public $allowunsignedapps;
    public $allowunsigninstallpacks;
    public $mindevcomplexchars;
    public $allowwifi;
    public $allowtextmessaging;
    public $allowpopimapemail;
    public $allowbluetooth;
    public $allowirda;
    public $reqmansyncroam;
    public $allowdesktopsync;
    public $maxcalagefilter;
    public $allowhtmlemail;
    public $maxemailagefilter;
    public $maxemailbodytruncsize;
    public $maxemailhtmlbodytruncsize;
    public $reqsignedsmimemessages;
    public $reqencsmimemessages;
    public $reqsignedsmimealgorithm;
    public $reqencsmimealgorithm;
    public $allowsmimeencalgneg;
    public $allowsmimesoftcerts;
    public $allowbrowser;
    public $allowconsumeremail;
    public $allowremotedesk;
    public $allowinternetsharing;
    public $unapprovedinromapplist;
    public $approvedapplist;


    function SyncProvisioning() {
        $mapping = array (
                    SYNC_PROVISION_DEVPWENABLED                         => array (  self::STREAMER_VAR      => "devpwenabled"),
                    SYNC_PROVISION_ALPHANUMPWREQ                        => array (  self::STREAMER_VAR      => "alphanumpwreq"),
                    SYNC_PROVISION_PWRECOVERYENABLED                    => array (  self::STREAMER_VAR      => "pwrecoveryenabled"),
                    SYNC_PROVISION_DEVENCENABLED                        => array (  self::STREAMER_VAR      => "devencenabled"),
                    SYNC_PROVISION_DOCBROWSEENABLED                     => array (  self::STREAMER_VAR      => "docbrowseenabled"),//not seen in usage
                    SYNC_PROVISION_ATTENABLED                           => array (  self::STREAMER_VAR      => "attenabled"),
                    SYNC_PROVISION_MINDEVPWLENGTH                       => array (  self::STREAMER_VAR      => "mindevpwlenngth"),
                    SYNC_PROVISION_MAXINACTTIMEDEVLOCK                  => array (  self::STREAMER_VAR      => "maxinacttimedevlock"),
                    SYNC_PROVISION_MAXDEVPWFAILEDATTEMPTS               => array (  self::STREAMER_VAR      => "maxdevpwfailedattempts"),
                    SYNC_PROVISION_MAXATTSIZE                           => array (  self::STREAMER_VAR      => "maxattsize"),
                    SYNC_PROVISION_ALLOWSIMPLEDEVPW                     => array (  self::STREAMER_VAR      => "allowsimpledevpw"),
                    SYNC_PROVISION_DEVPWEXPIRATION                      => array (  self::STREAMER_VAR      => "devpwexpiration"),
                    SYNC_PROVISION_DEVPWHISTORY                         => array (  self::STREAMER_VAR      => "devpwhistory"),
                );

        if(Request::GetProtocolVersion() >= 12.1) {
            $mapping += array (
                    SYNC_PROVISION_ALLOWSTORAGECARD                     => array (  self::STREAMER_VAR      => "allostoragecard"),
                    SYNC_PROVISION_ALLOWCAM                             => array (  self::STREAMER_VAR      => "allowcam"),
                    SYNC_PROVISION_REQDEVENC                            => array (  self::STREAMER_VAR      => "reqdevenc"),
                    SYNC_PROVISION_ALLOWUNSIGNEDAPPS                    => array (  self::STREAMER_VAR      => "allowunsignedapps"),
                    SYNC_PROVISION_ALLOWUNSIGNEDINSTALLATIONPACKAGES    => array (  self::STREAMER_VAR      => "allowunsigninstallpacks"),
                    SYNC_PROVISION_MINDEVPWCOMPLEXCHARS                 => array (  self::STREAMER_VAR      => "mindevcomplexchars"),
                    SYNC_PROVISION_ALLOWWIFI                            => array (  self::STREAMER_VAR      => "allowwifi"),
                    SYNC_PROVISION_ALLOWTEXTMESSAGING                   => array (  self::STREAMER_VAR      => "allowtextmessaging"),
                    SYNC_PROVISION_ALLOWPOPIMAPEMAIL                    => array (  self::STREAMER_VAR      => "allowpopimapemail"),
                    SYNC_PROVISION_ALLOWBLUETOOTH                       => array (  self::STREAMER_VAR      => "allowbluetooth"),
                    SYNC_PROVISION_ALLOWIRDA                            => array (  self::STREAMER_VAR      => "allowirda"),
                    SYNC_PROVISION_REQMANUALSYNCWHENROAM                => array (  self::STREAMER_VAR      => "reqmansyncroam"),
                    SYNC_PROVISION_ALLOWDESKTOPSYNC                     => array (  self::STREAMER_VAR      => "allowdesktopsync"),
                    SYNC_PROVISION_MAXCALAGEFILTER                      => array (  self::STREAMER_VAR      => "maxcalagefilter"),
                    SYNC_PROVISION_ALLOWHTMLEMAIL                       => array (  self::STREAMER_VAR      => "allowhtmlemail"),
                    SYNC_PROVISION_MAXEMAILAGEFILTER                    => array (  self::STREAMER_VAR      => "maxemailagefilter"),
                    SYNC_PROVISION_MAXEMAILBODYTRUNCSIZE                => array (  self::STREAMER_VAR      => "maxemailbodytruncsize"),
                    SYNC_PROVISION_MAXEMAILHTMLBODYTRUNCSIZE            => array (  self::STREAMER_VAR      => "maxemailhtmlbodytruncsize"),
                    SYNC_PROVISION_REQSIGNEDSMIMEMESSAGES               => array (  self::STREAMER_VAR      => "reqsignedsmimemessages"),
                    SYNC_PROVISION_REQENCSMIMEMESSAGES                  => array (  self::STREAMER_VAR      => "reqencsmimemessages"),
                    SYNC_PROVISION_REQSIGNEDSMIMEALGORITHM              => array (  self::STREAMER_VAR      => "reqsignedsmimealgorithm"),
                    SYNC_PROVISION_REQENCSMIMEALGORITHM                 => array (  self::STREAMER_VAR      => "reqencsmimealgorithm"),
                    SYNC_PROVISION_ALLOWSMIMEENCALGORITHNEG             => array (  self::STREAMER_VAR      => "allowsmimeencalgneg"),
                    SYNC_PROVISION_ALLOWSMIMESOFTCERTS                  => array (  self::STREAMER_VAR      => "allowsmimesoftcerts"),
                    SYNC_PROVISION_ALLOWBROWSER                         => array (  self::STREAMER_VAR      => "allowbrowser"),
                    SYNC_PROVISION_ALLOWCONSUMEREMAIL                   => array (  self::STREAMER_VAR      => "allowconsumeremail"),
                    SYNC_PROVISION_ALLOWREMOTEDESKTOP                   => array (  self::STREAMER_VAR      => "allowremotedesk"),
                    SYNC_PROVISION_ALLOWINTERNETSHARING                 => array (  self::STREAMER_VAR      => "allowinternetsharing"),
                    SYNC_PROVISION_UNAPPROVEDINROMAPPLIST               => array (  self::STREAMER_VAR      => "unapprovedinromapplist",
                                                                                    self::STREAMER_ARRAY    => SYNC_PROVISION_APPNAME),  //TODO check

                    SYNC_PROVISION_APPROVEDAPPLIST                      => array (  self::STREAMER_VAR      => "approvedapplist",
                                                                                    self::STREAMER_ARRAY     => SYNC_PROVISION_HASH), //TODO check
            );
        }

        parent::SyncObject($mapping);
    }

    public function Load($policies = array()) {
        if (empty($policies)) {
            $this->attenabled = 1;
        }
        else foreach ($policies as $p=>$v) {
            if (!isset($this->mapping[$p])) {
                ZLog::Write(LOGLEVEL_INFO, sprintf("Policy '%s' not supported by the device, ignoring", substr($p, strpos($p,':')+1)));
                continue;
            }
            ZLog::Write(LOGLEVEL_INFO, sprintf("Policy '%s' enforced with: %s", substr($p, strpos($p,':')+1), Utils::PrintAsString($v)));

            $var = $this->mapping[$p][self::STREAMER_VAR];
            $this->$var = $v;
        }
    }
}

?>