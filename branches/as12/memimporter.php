<?php
/***********************************************
* File      :   memimporter.php
* Project   :   Z-Push
* Descr     :   Classes that collect changes
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

class ImportContentsChangesMem extends ImportContentsChanges {
    var $_changes;
    var $_deletions;

    function ImportContentsChangesMem() {
        $this->_changes = array();
        $this->_deletions = array();
    }
    
    function ImportMessageChange($id, $message) {
        $this->_changes[] = $id; 
        return true;
    }

    function ImportMessageDeletion($id) { 
        $this->_deletions[] = $id;
        return true;
    }
    
    function ImportMessageReadFlag($message) { return true; }

    function ImportMessageMove($message) { return true; }

    function isChanged($id) {
        return in_array($id, $this->_changes);
    }
    
    function isDeleted($id) {
        return in_array($id, $this->_deletions);
    }

};

// This simply collects all changes so that they can be retrieved later, for
// statistics gathering for example
class ImportHierarchyChangesMem extends ImportHierarchyChanges {
    var $changed;
    var $deleted;
    var $count;
    var $foldercache;
    
    function ImportHierarchyChangesMem($foldercache) {
    	$this->foldercache = $foldercache;
        $this->changed = array();
        $this->deleted = array();
        $this->count = 0;
        
        return true;
    }
    
    function ImportFolderChange($folder) {
    	// The HierarchyExporter exports all kinds of changes.
    	// Frequently these changes are not relevant for the mobiles, 
    	// as something changes but the relevant displayname and parentid 
    	// stay the same. These changes will be dropped and not sent
    	if (is_array($this->foldercache) &&
    	    array_key_exists($folder->serverid, $this->foldercache) &&
    	    $this->foldercache[$folder->serverid]->displayname == $folder->displayname &&
            $this->foldercache[$folder->serverid]->parentid == $folder->parentid &&
            $this->foldercache[$folder->serverid]->type == $folder->type
           ) {
            debugLog("Change for folder '".$folder->displayname."' will not be sent as modification is not relevant");  	
            return true;
    	}
   	
        array_push($this->changed, $folder);
        $this->count++;
        return true;
    }

    function ImportFolderDeletion($id) {
        array_push($this->deleted, $id);
        
        $this->count++;
        
        return true;
    }
};

?>