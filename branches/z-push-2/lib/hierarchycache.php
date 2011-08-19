<?php
/***********************************************
* File      :   hierarchycache.php
* Project   :   Z-Push
* Descr     :   HierarchyCache implementation
*
* Created   :   18.08.2011
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
    public function IsStateChanged() {
        return $this->changed;
    }

    /**
     * Copy current CacheById to memory
     *
     * @access public
     * @return boolean
     */
    public function CopyOldState() {
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
    public function GetFolder($serverid, $oldState = false) {
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
    public function GetFolderIdByType($type) {
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
    public function AddFolder($folder) {
        ZLog::Write(LOGLEVEL_DEBUG, "HierarchyCache: AddFolder() serverid: {$folder->serverid} displayname: {$folder->displayname}");

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
    public function DelFolder($serverid) {
        // delete from byType cache first, as we need the foldertype
        $ftype = $this->GetFolder($serverid);
        if ($ftype->type)
            unset($this->cacheByType[$ftype->type]);

        ZLog::Write(LOGLEVEL_DEBUG, "HierarchyCache: DelFolder() serverid: $serverid - type (from cache): {$ftype->type}");
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
    public function ImportFolders($folders) {
        if (!is_array($folders))
            return false;

        $this->cacheById = array();
        $this->cacheByType = array();

        foreach ($folders as $folder) {
            if (!isset($folder->type))
                continue;
            $this->AddFolder($folder);
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
    public function ExportFolders($oldstate = false) {
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
    public function GetDeletedFolders() {
        // diffing the OldCacheById with CacheByIdwe know if folders were deleted
        return array_diff_key($this->cacheByIdOld, $this->cacheById);
    }

    /**
     * Returns some statistics about the HierarchyCache
     *
     * @access public
     * @return string
     */
    public function GetStat() {
        return sprintf("HierarchyCache is %s - Cached objects: %d", ((isset($this->cacheById))?"up":"down"), ((isset($this->cacheById))?count($this->cacheById):"0"));
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

?>