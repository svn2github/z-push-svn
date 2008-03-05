<?
/***********************************************
* File      :   combined.php
* Project   :   Z-Push
* Descr     :   This backend is for combining
*               backends.
*
* Created   :   01.03.2008
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

//the ExportHierarchyChangesCombined class is returned from GetExporter for hierarchy changes.
//it combines the hierarchy changes from all backends and prepends all folderids with the backendid

class ExportHierarchyChangesCombined{
	var $_backend;
	var $_syncstates;
	var $_exporters;
	var $_importwraps;
	function ExportHierarchyChangesCombined(&$backend) {
		debugLog('ExportHierarchyChangesCombined constructed');
		$this->_backend =& $backend;
	}
	
	function Config(&$importer, $folderid, $restrict, $syncstate, $flags, $truncation) {
		debugLog('ExportHierarchyChangesCombined::Config(...)');
		if($folderid){
			return false;
		}
		$this->_syncstates = unserialize($syncstate);
		if(!is_array($this->_syncstates)){
			$this->_syncstates = array();
		}
		foreach($this->_backend->_backends as $i => $b){
			if(isset($this->_syncstates[$i])){
				$state = $this->_syncstates[$i];
			} else {
				$state = '';
			}
			
			if(!isset($this->_importwraps[$i])){
				$this->_importwraps[$i] = new ImportHierarchyChangesCombinedWrap($i, &$this->_backend ,&$importer);
			}
			
			$this->_exporters[$i] = $this->_backend->_backends[$i]->GetExporter();
			$this->_exporters[$i]->Config(&$this->_importwraps[$i], $folderid, $restrict, $state, $flags, $truncation);
		}
		debugLog('ExportHierarchyChangesCombined::Config complete');
	}
	function GetChangeCount() {
		debugLog('ExportHierarchyChangesCombined::GetChangeCount()');
		$c = 0;
		foreach($this->_exporters as $i => $e){
			$C += $this->_exporters[$i]->GetChangeCount();
		}
		return $c;
	}
	function Synchronize() {
		debugLog('ExportHierarchyChangesCombined::Synchronize()');
		foreach($this->_exporters as $i => $e){
			while(is_array($this->_exporters[$i]->Synchronize()));
		}
		return true;
	}
	function GetState() {
		debugLog('ExportHierarchyChangesCombined::GetState()');
		foreach($this->_exporters as $i => $e){
			$this->_syncstates[$i] = $this->_exporters[$i]->GetState();
		}
		return serialize($this->_syncstates);
	}
};

//the ImportHierarchyChangesCombined class is returned from GetHierarchyImporter.
//it forwards all hierarchy changes to the right backend

class ImportHierarchyChangesCombined{
	var $_backend;
	var $_syncstates = array();
	
	function ImportHierarchyChangesCombined(&$backend) {
		$this->_backend =& $backend;
	}
	function Config($state) {
		debugLog('ImportHierarchyChangesCombined::Config(...)');
		$this->_syncstates = unserialize($state);
		if(!is_array($this->_syncstates))
			$this->_syncstates = array();
	}
	function ImportFolderChange($id, $parent, $displayname, $type) {
		debugLog('ImportHierarchyChangesCombined::ImportFolderChange('.$id.', '.$parent.', '.$displayname.', '.$type.')');
		if($parent == '0'){
			if($id){
				$backendid = $this->_backend->GetBackendId($id);
			}else{
				$backendid = $this->_backend->_config['rootcreatefolderbackend'];
			}
		}else{
			$backendid = $this->_backend->GetBackendId($parent);
			$parent = $this->_backend->GetBackendFolder($parent);
		}
		if($id != false){
			if($backendid != $this->_backend->GetBackendId($id))
				return false;//we can not move a folder from 1 backend to an other backend
			$id = $this->_backend->GetBackendFolder($id);
			
		}
		
		$importer = $this->_backends[$backendid]->GetHierarchyImporter();
		if(isset($this->_syncstates[$backendid])){
			$state = $this->_syncstates[$backendid];
		}else{
			$state = '';
		}
		$importer->Config($state);
		$res = $importer->ImportFolderChange($id, $parent, $displayname, $type);
		$this->_syncstates[$backendid] = $importer->GetState();
		return $backendid.$this->_backend->_config['delimiter'].$res;
	}
	function ImportFolderDeletion($id, $parent) {
		debugLog('ImportHierarchyChangesCombined::ImportFolderDeletion('.$id.', '.$parent.')');
		$backend = $this->_backend->GetBackend($id);
		$id = $this->_backend->GetBackendFolder($id);
		if($parent != '0')
			$parent = $this->_backend->GetBackendFolder($parent);
		$importer = $backend->GetHierarchyImporter();
		if(isset($this->_syncstates[$this->_backend->GetBackendId($id)])){
			$state = $this->_syncstates[$this->_backend->GetBackendId($id)];
		}else{
			$state = '';
		}
		$importer->Config($state);
		$res = $importer->ImportFolderDeletion($id, $parent);
		$this->_syncstates[$this->_backend->GetBackendId($id)] = $importer->GetState();
		return $res;
	}
	function GetState(){
		return serialize($this->_syncstates);
	}
};

//the ImportHierarchyChangesCombinedWrap class wraps the importer given in ExportHierarchyChangesCombined::Config.
//it prepends the backendid to all folderids and checks foldertypes.

class ImportHierarchyChangesCombinedWrap {
	var $_ihc;
	var $_backend;
	var $_backendid;
	
	function ImportHierarchyChangesCombinedWrap($backendid, &$backend, &$ihc) {
		debugLog('ImportHierarchyChangesCombinedWrap::ImportHierarchyChangesCombinedWrap('.$backendid.',...)');
		$this->_backendid = $backendid;
		$this->_backend =& $backend;
		$this->_ihc = &$ihc;
	}
	
	function ImportFolderChange($folder) {
		$folder->serverid = $this->_backendid.$this->_backend->_config['delimiter'].$folder->serverid;
		if($folder->parentid != '0'){
			$folder->parentid = $this->_backendid.$this->_backend->_config['delimiter'].$folder->parentid;
		}
		if(isset($this->_backend->_config['folderbackend'][$folder->type]) && $this->_backend->_config['folderbackend'][$folder->type] != $this->_backendid){
			debugLog('not ussing folder: '.$folder->displayname.' ('.$folder->serverid.')');
			return true;//$folder->type = SYNC_FOLDER_TYPE_OTHER;
		}
		debugLog('ImportHierarchyChangesCombinedWrap::ImportFolderChange('.$folder->serverid.')');
		return $this->_ihc->ImportFolderChange($folder);
	}
	
	function ImportFolderDeletion($id) {
		debugLog('ImportHierarchyChangesCombinedWrap::ImportFolderDeletion('.$id.')');
		return $this->_ihc->ImportFolderDeletion($this->_backendid.$this->_delimiter.$id);
	}
};

//the ImportContentsChangesCombinedWrap class wraps the importer given in GetContentsImporter.
//it allows to check and change the folderid on ImportMessageMove.

class ImportContentsChangesCombinedWrap{
	var $_icc;
	var $_backend;
	var $_folderid;
	
	function ImportContentsChangesCombinedWrap($folderid, &$backend, &$icc){
		debugLog('ImportContentsChangesCombinedWrap::ImportContentsChangesCombinedWrap('.$folderid.',...)');
		$this->_folderid = $folderid;
		$this->_backend = &$backend;
		$this->_icc = &$icc;
	}
	
	function Config($state, $flags = 0) {
		return $this->_icc->Config($state, $flags);
	}
	function ImportMessageChange($id, $message){
		return $this->_icc->ImportMessageChange($id, $message);
	}
	function ImportMessageDeletion($id) {
		return $this->_icc->ImportMessageDeletion($id);
	}
	function ImportMessageReadFlag($id, $flags){
		return $this->_icc->ImportMessageReadFlag($id, $flags);
	}
	function ImportMessageMove($id, $newfolder) {
		if($this->_backend->GetBackendId($this->_folderid) != $this->_backend->GetBackendId($newfolder)){
			//can not move messages between backends
			return false;
		}
		return $this->_icc->ImportMessageMove($id, $this->_backend->GetBackendFolder($newfolder));
	}
	function getState(){
		return $this->_icc->getState();
	}
};

class BackendCombined {
	var $_config;
	var $_backends;
	
	function BackendCombined($config){
		$this->_config = $config;
		foreach ($this->_config['backends'] as $i => $b){
			eval('$this->_backends[$i] = new '.$b['name'].'($b[\'config\']);');
		}
		debugLog('Combined '.count($this->_backends). ' backends loaded.');
	}
	
	// try to logon on each backend
	function Logon($username, $domain, $password) {
		debugLog('Combined::Logon('.$username.', '.$domain.',***)');
		if(!is_array($this->_backends)){
			return false;
		}
		foreach ($this->_backends as $i => $b){
			if($this->_backends[$i]->Logon($username, $domain, $password) == false){
				debugLog('Combined login failed on'. $this->_config['backends'][$i]['name']);
				return false;
			}
		}
		debugLog('Combined login success');
		return true;
	}
	
	//try to setup each backend
	function Setup($user, $devid, $protocolversion){
		debugLog('Combined::Setup('.$user.', '.$devid.', '.$protocolversion.')');
		if(!is_array($this->_backends)){
			return false;
		}
		foreach ($this->_backends as $i => $b){
			if($this->_backends[$i]->Setup($user, $devid, $protocolversion) == false){
				debugLog('Combined::Setup failed');
				return false;
			}
		}
		debugLog('Combined::Setup success');
		return true;
	}
	
	function Logoff() {
		foreach ($this->_backends as $i => $b){
			$this->_backends[$i]->Logoff();
		}
		return true;
	}
	
	// get the contents importer from the folder in a backend
	// the importer is wrapped to check foldernames in the ImportMessageMove function
	function GetContentsImporter($folderid){
		debugLog('Combined::GetContentsImporter('.$folderid.')');
		$backend = $this->GetBackend($folderid);
		if($backend === false)
			return false;
		return new ImportContentsChangesCombinedWrap($folderid, $this, $backend->GetContentsImporter($this->GetBackendFolder($folderid)));
	}
	
	//return our own hierarchy importer which send each change to the right backend
	function GetHierarchyImporter(){
		debugLog('Combined::GetHierarchyImporter()');
		return new ImportHierarchyChangesCombined(&$this);
	}
	
	//get hierarchy from all backends combined
	function GetHierarchy(){
		debugLog('Combined::GetHierarchy()');
		$ha = array();
		foreach ($this->_backends as $i => $b){
			$h = $this->_backends[$i]->GetHierarchy();
			if(is_array($h)){
				foreach($h as $j => $f){
					$h[$j]->serverid = $i.$this->_config['delimiter'].$h[$j]->serverid;
					if($h[$j]->parentid != '0'){
						$h[$j]->parentid = $i.$this->_config['delimiter'].$h[$j]->parentid;
					}
					if(isset($this->_config['folderbackend'][$h[$j]->type]) && $this->_config['folderbackend'][$h[$j]->type] != $i){
						$h[$j]->type = SYNC_FOLDER_TYPE_OTHER;
					}
				}
				$ha = array_merge($ha, $h);
			}
		}
		return $ha;
	}
	
	//return exporter from right backend for contents exporter and our own exporter for hierarchy exporter
	function GetExporter($folderid = false){
		debugLog('Combined::GetExporter('.$folderid.')');
		if($folderid){
			$backend = $this->GetBackend($folderid);
			if($backend == false)
				return false;
			return $backend->GetExporter($this->GetBackendFolder($folderid));
		}
		return new ExportHierarchyChangesCombined(&$this);
	}
	
	//if the wastebasket is set to one backend, return the wastebasket of that backend
	//else return the first waste basket we can find
	function GetWasteBasket(){
		debugLog('Combined::GetWasteBasket()');
		if(isset($this->_config['folderbackend'][SYNC_FOLDER_TYPE_WASTEBASKET])){
			return $this->_config['folderbackend'][SYNC_FOLDER_TYPE_WASTEBASKET].$this->_config['delimiter'].$this->_backends[$this->_config['folderbackend'][SYNC_FOLDER_TYPE_WASTEBASKET]]->GetWasteBasket();
		}
		foreach($this->_backends as $i => $b){
			$w = $this->_backends[$i]->GetWasteBasket();
			if($w){
				return $i.$this->_config['delimiter'].$w;
			}
		}
		return false;
	}
	
	//forward to right backend
	function Fetch($folderid, $id){
		debugLog('Combined::Fetch('.$folderid.', '.$id.')');
		$backend = $this->GetBackend($folderid);
		if($backend == false)
			return false;
		return $backend->Fetch($this->GetBackendFolder($folder), $id);
	}
	
	//there is no way to tell which backend the attachment is from, so we try them all
	function GetAttachmentData($attname){
		debugLog('Combined::GetAttachmentData('.$attname.')');
		foreach ($this->_backends as $i => $b){
			if($this->_backends[$i]->GetAttachmentData($attname) == true){
				return true;
			}
		}
		return false;
	}
	
	//send mail with the first backend returning true
	function SendMail($rfc822, $forward = false, $reply = false, $parent = false) {
		foreach ($this->_backends as $i => $b){
			if($this->_backends[$i]->SendMail($rfc822, $forward, $reply, $parent) == true){
				return true;
			}
		}
		return false;
	}
	
	function MeetingResponse($requestid, $folderid, $error, &$calendarid) {
		$backend = $this->GetBackend($folderid);
		if($backend === false)
			return false;
		return $backend->MeetingResponse($requestid, $this->GetBackendFolder($folderid), $error, $calendarid);
	}
	
	function GetBackend($folderid){
		$pos = strpos($folderid, $this->_config['delimiter']);
		if($pos === false)
			return false;
		$id = substr($folderid, 0,$pos);
		if(!isset($this->_backends[$id]))
			return false;
		return $this->_backends[$id];
	}
	
	function GetBackendFolder($folderid){
		$pos = strpos($folderid, $this->_config['delimiter']);
		if($pos === false)
			return false;
		return substr($folderid,$pos + strlen($this->_config['delimiter']));
	}
	
	function GetBackendId($folderid){
		$pos = strpos($folderid, $this->_config['delimiter']);
		if($pos === false)
			return false;
		return substr($folderid,0,$pos);
	}
};

?>
