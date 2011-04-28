<?php
/***********************************************
* File      :   hierarchymemorywrapper.php
* Project   :   Z-Push
* Descr     :   HierarchyCache implementation
*               Classes that collect changes in memory
*
* Created   :   01.10.2007
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

class HierarchyCache {
    private $changed = false;
    protected $cacheById;
    protected $cacheByType;
    private $cacheByIdOld;

    /**
     * Constructor of the HierarchyCache
     *
     * @access public
     * @return
     */
    public function HierarchyCache() {
        $this->cacheById = array();
        $this->cacheByType = array();
        $this->cacheByIdOld = $this->cacheById;
        $this->changed = true;
    }

    /**
     * Indicates if the cache was changed
     *
     * @access public
     * @return boolean
     */
    public function isStateChanged() {
        return $this->changed;
    }

    /**
     * Copy current CacheById to memory
     *
     * @access public
     * @return boolean
     */
    public function copyOldState() {
        $this->cacheByIdOld = $this->cacheById;
        return true;
    }

    /**
     * Returns the SyncFolder object for a folder id
     * If $oldstate is set, then the data from the previous state is returned
     *
     * @param string    $serverid
     * @param boolean   $oldstate       (optional) by default false
     *
     * @access public
     * @return SyncObject/boolean       false if not found
     */
    public function getFolder($serverid, $oldState = false) {
        if (!$oldState && array_key_exists($serverid, $this->cacheById)) {
            return $this->cacheById[$serverid];
        }
        else if ($oldState && array_key_exists($serverid, $this->cacheByIdOld)) {
            return $this->cacheByIdOld[$serverid];
        }
        return false;
    }

    /**
     * Returns the default SyncFolder id for a specific type
     * This is generally used when doing AS 1.0
     *
     * @param int       $type
     *
     * @access public
     * @return string
     */
    public function getFolderIdByType($type) {
        // this data is available only for default folders
        if (isset($type) && $type > SYNC_FOLDER_TYPE_OTHER && $type < SYNC_FOLDER_TYPE_USER_MAIL) {
            if (isset($this->cacheByType[$type]))
                return $this->cacheByType[$type];

            // Old Palm Treos always do initial sync for calendar and contacts, even if they are not made available by the backend.
            // We need to fake these folderids, allowing a fake sync/ping, even if they are not supported by the backend
            // if the folderid would be available, they would already be returned in the above statement
            if ($type == SYNC_FOLDER_TYPE_APPOINTMENT || $type == SYNC_FOLDER_TYPE_CONTACT)
                return SYNC_FOLDER_TYPE_DUMMY;
        }
        return false;
    }

    /**
     * Adds a folder to the HierarchyCache
     *
     * @param SyncObject    $folder
     *
     * @access public
     * @return boolean
     */
    public function addFolder($folder) {
        ZLog::Write(LOGLEVEL_DEBUG, "HierarchyCache: addFolder() serverid: {$folder->serverid} displayname: {$folder->displayname}");

        // add/update
        $this->cacheById[$folder->serverid] = $folder;

        // add folder to the byType cache - only default folders
        if (isset($folder->type) && $folder->type > SYNC_FOLDER_TYPE_OTHER && $folder->type < SYNC_FOLDER_TYPE_USER_MAIL)
            $this->cacheByType[$folder->type] = $folder->serverid;

        $this->changed = true;
        return true;
    }

    /**
     * Removes a folder to the HierarchyCache
     *
     * @param string    $serverid           id of folder to be removed
     *
     * @access public
     * @return boolean
     */
    public function delFolder($serverid) {
        // delete from byType cache first, as we need the foldertype
        $ftype = $this->getFolder($serverid);
        if ($ftype->type)
            unset($this->cacheByType[$ftype->type]);

        ZLog::Write(LOGLEVEL_DEBUG, "HierarchyCache: delFolder() serverid: $serverid - type (from cache): {$ftype->type}");
        unset($this->cacheById[$serverid]);
        $this->changed = true;
        return true;
    }

    /**
     * Imports a folder array to the HierarchyCache
     *
     * @param array     $folders            folders to the HierarchyCache
     *
     * @access public
     * @return boolean
     */
    public function importFolders($folders) {
        if (!is_array($folders))
            return false;

        $this->cacheById = array();
        $this->cacheByType = array();

        foreach ($folders as $folder) {
            if (!isset($folder->type))
                continue;
            $this->addFolder($folder);
        }
        return true;
    }

    /**
     * Exports all folders from the HierarchyCache
     *
     * @param boolean   $oldstate           (optional) by default false
     *
     * @access public
     * @return array
     */
    public function exportFolders($oldstate = false) {
        if ($oldstate === false)
            return $this->cacheById;
        else
            return $this->cacheByIdOld;
    }

    /**
     * Returns all folder objects which were deleted in this operation
     *
     * @access public
     * @return array        with SyncFolder objects
     */
    public function getDeletedFolders() {
        // diffing the OldCacheById with CacheByIdwe know if folders were deleted
        return array_diff_key($this->cacheByIdOld, $this->cacheById);
    }

    /**
     * Returns some statistics about the HierarchyCache
     *
     * @access public
     * @return string
     */
    public function getStat() {
        return "HierarchyChache is ".((isset($this->cacheById))?"up":"down"). " - Cached objects: ". ((isset($this->cacheById))?count($this->cacheById):"0");
    }

    /**
     * Returns objects which should be persistent
     * called before serialization
     *
     * @access public
     * @return array
     */
    public function __sleep() {
        return array("cacheById", "cacheByType");
    }

}


class ChangesMemoryWrapper extends HierarchyCache implements IImportChanges, IExportChanges {
    const CHANGE = 1;
    const DELETION = 2;

    private $_changes;
    private $_step;
    private $destinationImporter;
    private $exportImporter;

    /**
     * Constructor
     *
     * @access public
     * @return
     */
    function ChangesMemoryWrapper() {
        $this->_changes = array();
        $this->_step = 0;
        parent::HierarchyCache();
    }

    /**
     * Only used to load additional folder sync information for hierarchy changes
     *
     * @param array    $state               current state of additional hierarchy folders
     *
     * @access public
     * @return boolean
     */
    public function Config($state, $flags = 0) {
        // we should never forward this changes to a backend
        if (!isset($this->destinationImporter)) {
            foreach($state as $addKey => $addFolder) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("ChangesMemoryWrapper->Config(AdditionalFolders) : process folder '%s'", $addFolder->displayname));
                if (isset($addFolder->NoBackendFolder) && $addFolder->NoBackendFolder == true) {
                    $hasRights = ZPush::GetBackend()->Setup($addFolder->Store, true, $addFolder->serverid);
                    // delete the folder on the device
                    if (! $hasRights) {
                        // delete the folder only if it was an additional folder before, else ignore it
                        $synchedfolder = $this->getFolder($addFolder->serverid);
                        if (isset($synchedfolder->NoBackendFolder) && $synchedfolder->NoBackendFolder == true)
                            $this->ImportFolderDeletion($addFolder->serverid, $addFolder->parentid);
                        continue;
                    }
                }
                // add folder to the device - if folder is already on the device, nothing will happen
                $this->ImportFolderChange($addFolder);
            }

            // look for folders which are currently on the device if there are now not to be synched anymore
            $alreadyDeleted = $this->getDeletedFolders();
            foreach ($this->exportFolders(true) as $sid => $folder) {
                // we are only looking at additional folders
                if (isset($folder->NoBackendFolder)) {
                    // look if this folder is still in the list of additional folders and was not already deleted (e.g. missing permissions)
                    if (!array_key_exists($sid, $state) && !array_key_exists($sid, $alreadyDeleted)) {
                        ZLog::Write(LOGLEVEL_INFO, sprintf("ChangesMemoryWrapper->Config(AdditionalFolders) : previously synchronized folder '%s' is not to be synched anymore. Sending delete to mobile.", $folder->displayname));
                        $this->ImportFolderDeletion($folder->serverid, $folder->parentid);
                    }
                }
            }
        }
        return true;
    }


    /**
     * Implement interfaces which are never used
     */
    public function GetState() { return false;}
    public function LoadConflicts($mclass, $filtertype, $state) { return true; }
    public function ConfigContentParameters($mclass, $restrict, $truncation) { return true; }
    public function ImportMessageReadFlag($id, $flags) { return true; }
    public function ImportMessageMove($id, $newfolder) { return true; }

    /**----------------------------------------------------------------------------------------------------------
     * IImportChanges & destination importer
     */

    /**
     * Sets an importer where incoming changes should be sent to
     *
     * @param IImportChanges    $importer   message to be changed
     *
     * @access public
     * @return boolean
     */
    public function setDestinationImporter(&$importer) {
        $this->destinationImporter = $importer;
    }

    /**
     * Imports a message change, which is imported into memory
     *
     * @param string        $id         id of message which is changed
     * @param SyncObject    $message    message to be changed
     *
     * @access public
     * @return boolean
     */
    function ImportMessageChange($id, $message) {
        $this->_changes[] = array(self::CHANGE, $id);
        return true;
    }

    /**
     * Imports a message deletion, which is imported into memory
     *
     * @param string        $id     id of message which is deleted
     *
     * @access public
     * @return boolean
     */
    function ImportMessageDeletion($id) {
        $this->_changes[] = array(self::DELETION, $id);
        return true;
    }

    // TODO check if isChanged() and isDeleted() work as expected
    /**
     * Checks if a message id is flagged as changed
     *
     * @param string        $id     message id
     *
     * @access public
     * @return boolean
     */
    function isChanged($id) {
        return array_search(array(self::CHANGE, $id), $this->_changes);
    }

    /**
     * Checks if a message id is flagged as deleted
     *
     * @param string        $id     message id
     *
     * @access public
     * @return boolean
     */
    function isDeleted($id) {
       return array_search(array(self::DELETION, $id), $this->_changes);
    }

    /**
     * Imports a folder change
     *
     * @param SyncFolder    $folder     folder to be changed
     *
     * @access public
     * @return boolean
     */
    public function ImportFolderChange($folder) {
        // if the destinationImporter is set, then this folder should be processed by another importer
        // instead of being loaded in memory.
        if (isset($this->destinationImporter)) {
            $ret = $this->destinationImporter->ImportFolderChange($folder);
            // if the operation was sucessfull, update the HierarchyCache
            if ($ret)
                $this->addFolder($folder);
            return $ret;
        }
        // load into memory
        else {
            if (isset($folder->serverid)) {
                // The Zarafa HierarchyExporter exports all kinds of changes for folders (e.g. update no. of unread messages in a folder).
                // These changes are not relevant for the mobiles, as something changes but the relevant displayname and parentid
                // stay the same. These changes will be dropped and are not sent!
                $cacheFolder = $this->getFolder($folder->serverid);
                if ($folder->equals($this->getFolder($folder->serverid))) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("Change for folder '%s' will not be sent as modification is not relevant.", $folder->displayname));
                    return false;
                }

                // load this change into memory
                $this->_changes[] = array(self::CHANGE, $folder);

                // HierarchyCache: already add/update the folder so changes are not sent twice (if exported twice)
                $this->addFolder($folder);
                return true;
            }
            return false;
        }
    }

    /**
     * Imports a folder deletion
     *
     * @param string        $id
     * @param string        $parent     (opt) the parent id of the folders
     *
     * @access public
     * @return boolean
     */
    public function ImportFolderDeletion($id, $parent = false) {
        // if the forwarder is set, then this folder should be processed by another importer
        // instead of being loaded in mem.
        if (isset($this->destinationImporter)) {
            $ret = $this->destinationImporter->ImportFolderDeletion($id, $parent);

            // if the operation was sucessfull, update the HierarchyCache
            if ($ret)
                $this->delFolder($id);

            return $ret;
        }
        else {
            // if this folder is not in the cache, the change does not need to be streamed to the mobile
            if ($this->getFolder($id)) {

                // load this change into memory
                $this->_changes[] = array(self::DELETION, $id, $parent);

                // HierarchyCache: delete the folder so changes are not sent twice (if exported twice)
                $this->delFolder($id);
                return true;
            }
        }
    }


    /**----------------------------------------------------------------------------------------------------------
     * IExportChanges & destination importer
     */

    /**
     * Initializes the Exporter where changes are synchronized to
     *
     * @param IImportChanges    $importer
     *
     * @access public
     * @return boolean
     */
    public function InitializeExporter(&$importer) {
        $this->exportImporter = $importer;
        $this->_step = 0;
        return true;
    }

    /**
     * Returns the amount of changes to be exported
     *
     * @access public
     * @return int
     */
    public function GetChangeCount() {
        return count($this->_changes);
    }

    /**
     * Synchronizes a change. Only HierarchyChanges will be Synchronized()
     *
     * @access public
     * @return array
     */
    public function Synchronize() {
        if($this->_step < count($this->_changes) && isset($this->exportImporter)) {

            $change = $this->_changes[$this->_step];

            if ($change[0] == self::CHANGE) {
                if (! $this->getFolder($change[1]->serverid, true))
                    $change[1]->flags = SYNC_NEWMESSAGE;

                $this->exportImporter->ImportFolderChange($change[1]);
            }
            // deletion
            else {
                $this->exportImporter->ImportFolderDeletion($change[1], $change[2]);
            }
            $this->_step++;

            // return progress array
            return array("steps" => count($this->_changes), "progress" => $this->_step);
        }
        else
            return false;
    }

    /**
     * Initializes a few instance variables
     * called after unserialization
     *
     * @access public
     * @return array
     */
    public function __wakeup() {
        $this->_changes = array();
        $this->_step = 0;
    }
}

?>