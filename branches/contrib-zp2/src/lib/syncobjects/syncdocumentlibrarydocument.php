<?php
/***********************************************
* File      :   syncdocumentlibrarydocument.php
* Project   :   Z-Push
* Descr     :   WBXML appointment entities that can be
*               parsed directly (as a stream) from WBXML.
*               It is automatically decoded
*               according to $mapping,
*               and the Sync WBXML mappings
*
* Created   :   09.08.2013
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

// Begin contribution - DocumentSearch - liverpoolfcfan

class SyncDocumentLibraryDocument extends SyncObject {
    public $longid;
    public $linkid;
    public $displayname;
    public $isfolder;
    public $creationdate;
    public $lastmodifieddate;
    public $ishidden;
    public $contentlength;
    public $contenttype;

    public function SyncDocumentLibraryDocument() {
        $mapping = array (
            SYNC_DOCUMENTLIBRARY_LINKID                      => array (  self::STREAMER_VAR      => "linkid"),
            SYNC_DOCUMENTLIBRARY_DISPLAYNAME                 => array (  self::STREAMER_VAR      => "displayname"),
            SYNC_DOCUMENTLIBRARY_ISFOLDER                    => array (  self::STREAMER_VAR      => "isfolder",
                                                                         self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_ZEROORONE      => self::STREAMER_CHECK_SETZERO)),

            SYNC_DOCUMENTLIBRARY_CREATIONDATE                => array (  self::STREAMER_VAR      => "creationdate",
                                                                         self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

            SYNC_DOCUMENTLIBRARY_LASTMODIFIEDDATE            => array (  self::STREAMER_VAR      => "lastmodifieddate",
                                                                         self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

            SYNC_DOCUMENTLIBRARY_ISHIDDEN                    => array (  self::STREAMER_VAR      => "ishidden",
                                                                         self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_ZEROORONE      => self::STREAMER_CHECK_SETZERO)),

            SYNC_DOCUMENTLIBRARY_CONTENTLENGTH               => array (  self::STREAMER_VAR      => "contentlength",
                                                                         self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_CMPHIGHER      => -1)),

            SYNC_DOCUMENTLIBRARY_CONTENTTYPE                 => array (  self::STREAMER_VAR      => "contenttype"),
        );

        parent::SyncObject($mapping);
    }

}

// End contribution - DocumentSearch - liverpoolfcfan
?>