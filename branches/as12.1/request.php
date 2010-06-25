<?php
/***********************************************
* File      :   request.php
* Project   :   Z-Push
* Descr     :   This file contains the actual
*               request handling routines.
*               The request handlers are optimised
*               so that as little as possible
*               data is kept-in-memory, and all
*               output data is directly streamed
*               to the client, while also streaming
*               input data from the client.
*
* Created   :   01.10.2007
*
*  Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

include_once("proto.php");
include_once("wbxml.php");
include_once("statemachine.php");
include_once("backend/backend.php");
include_once("memimporter.php");
include_once("streamimporter.php");
include_once("zpushdtd.php");
include_once("zpushdefs.php");
include_once("include/utils.php");

function GetObjectClassFromFolderClass($folderclass)
{
    $classes = array ( "Email" => "syncmail", "Contacts" => "synccontact", "Calendar" => "syncappointment", "Tasks" => "synctask", "Notes" => "syncnote", "SMS" => "syncsms");

    return $classes[$folderclass];
}

function HandleMoveItems($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_MOVE_MOVES))
        return false;

    $moves = array();
    while($decoder->getElementStartTag(SYNC_MOVE_MOVE)) {
        $move = array();
        if($decoder->getElementStartTag(SYNC_MOVE_SRCMSGID)) {
            $move["srcmsgid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                break;
        }
        if($decoder->getElementStartTag(SYNC_MOVE_SRCFLDID)) {
            $move["srcfldid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                break;
        }
        if($decoder->getElementStartTag(SYNC_MOVE_DSTFLDID)) {
            $move["dstfldid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                break;
        }
        array_push($moves, $move);

        if(!$decoder->getElementEndTag())
            return false;
    }

    if(!$decoder->getElementEndTag())
        return false;

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_MOVE_MOVES);

    foreach($moves as $move) {
        $encoder->startTag(SYNC_MOVE_RESPONSE);
        $encoder->startTag(SYNC_MOVE_SRCMSGID);
        $encoder->content($move["srcmsgid"]);
        $encoder->endTag();

        $importer = $backend->GetContentsImporter($move["srcfldid"]);
        $result = $importer->ImportMessageMove($move["srcmsgid"], $move["dstfldid"]);
        // We discard the importer state for now.

        $encoder->startTag(SYNC_MOVE_STATUS);
        $encoder->content($result ? 3 : 1);
        $encoder->endTag();

        $encoder->startTag(SYNC_MOVE_DSTMSGID);
        $encoder->content(is_string($result)?$result:$move["srcmsgid"]);
        $encoder->endTag();
        $encoder->endTag();
    }

    $encoder->endTag();
    return true;
}

function HandleNotify($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_AIRNOTIFY_NOTIFY))
        return false;

    if(!$decoder->getElementStartTag(SYNC_AIRNOTIFY_DEVICEINFO))
        return false;

    if(!$decoder->getElementEndTag())
        return false;

    if(!$decoder->getElementEndTag())
        return false;

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_AIRNOTIFY_NOTIFY);
    {
        $encoder->startTag(SYNC_AIRNOTIFY_STATUS);
        $encoder->content(1);
        $encoder->endTag();

        $encoder->startTag(SYNC_AIRNOTIFY_VALIDCARRIERPROFILES);
        $encoder->endTag();
    }

    $encoder->endTag();

    return true;

}

// Handle GetHierarchy method - simply returns current hierarchy of all folders
function HandleGetHierarchy($backend, $protocolversion, $devid) {
    global $zpushdtd;
    global $output;

    // Input is ignored, no data is sent by the PIM
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    $folders = $backend->GetHierarchy();

    if(!$folders)
        return false;

    // save folder-ids for fourther syncing
    _saveFolderData($devid, $folders);

    $encoder->StartWBXML();
    $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERS);

    foreach ($folders as $folder) {
        $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDER);
        $folder->encode($encoder);
        $encoder->endTag();
    }

    $encoder->endTag();
    return true;
}

function _ErrorHandleFolderSync($errorcode) {
    global $zpushdtd;
    global $output;
    $encoder = new WBXMLEncoder($output, $zpushdtd);
    $encoder->StartWBXML();
    $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC);
    $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
    $encoder->content($errorcode);
    $encoder->endTag();
    $encoder->endTag();
}
// Handles a 'FolderSync' method - receives folder updates, and sends reply with
// folder changes on the server
function HandleFolderSync($backend, $devid, $protocolversion) {
    global $zpushdtd;
    global $input, $output;
    global $useragent;

    // Maps serverid -> clientid for items that are received from the PIM
    $map = array();

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    // Parse input

    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC))
        return false;

    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SYNCKEY))
        return false;

    $synckey = $decoder->getElementContent();

    if(!$decoder->getElementEndTag())
        return false;

    // First, get the syncstate that is associated with this synckey
    $statemachine = new StateMachine($devid);

    // The state machine will discard any sync states before this one, as they are no
    // longer required
    $syncstate = $statemachine->getSyncState($synckey);
    
    // Get Foldercache
    $SyncCache = unserialize($statemachine->getSyncCache());
    if (isset($SyncCache['folders']) &&
	is_array($SyncCache['folders'])) {
	foreach ($SyncCache['folders'] as $key=>$value) {
	    if (!isset($value['class'])) {
		$statemachine->deleteSyncCache();
		_ErrorHandleFolderSync("9");
	    	return true;
	    }
	    $exporter = $backend->GetExporter($key);
	    if (isset($exporter->exporter) &&
		$exporter->exporter === false) unset($SyncCache['folders'][$key]);
	}
    }

    // additional information about already seen folders
    if ($synckey != "0")
	$seenfolders = $statemachine->getSyncState("s".$synckey);

    // if we have any error with one of the requests bail out here!
    if (($synckey != "0" && 
	 is_numeric($seenfolders) &&
	 $seenfolders<0) ||
	(is_numeric($syncstate) &&
	 $syncstate<0)) { // if we get a numeric syncstate back it means we have an error...
	debugLog("GetSyncState ERROR (Seenfolders: ".abs($seenfolders).", Syncstate: ".abs($syncstate).")");
	if ($seenfolders < 0) $status = abs($seenfolders);
	if ($syncstate < 0) $status = abs($syncstate);
	// Output our WBXML reply now
	_ErrorHandleFolderSync(abs($status));
        return true;
    } else {
	$foldercache = unserialize($statemachine->getSyncCache());
	// Clear the foldercache in SyncCache in case the SyncKey = 0
	if ($synckey == "0") {
	    // $statemachine->deleteSyncCache();
	    unset($foldercache['folders']);
	    debugLog("Clean the folders in foldercache");
	} 
	debugLog("GetSyncState OK");
    }
    if ($synckey == "0" && 
	 (!isset($seenfolders) ||
	 (is_numeric($seenfolders) &&
	 $seenfolders<0))) $seenfolders = false;
    $seenfolders = unserialize($seenfolders);
    if (!$seenfolders) $seenfolders = array();
    
    if (!is_array($foldercache) ||
	sizeof($foldercache) == 0) $foldercache = array();
    if (!$foldercache) $foldercache = array();
    // We will be saving the sync state under 'newsynckey'
    $newsynckey = $statemachine->getNewSyncKey($synckey);
    $foldercache['hierarchy']['synckey'] = $newsynckey;

    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_CHANGES)) {
        // Ignore <Count> if present
        if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_COUNT)) {
            $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        // Process the changes (either <Add>, <Modify>, or <Remove>)
        $element = $decoder->getElement();

        if($element[EN_TYPE] != EN_TYPE_STARTTAG)
            return false;

        while(1) {
            $folder = new SyncFolder();
            if(!$folder->decode($decoder))
                break;

            // Configure importer with last state
            $importer = $backend->GetHierarchyImporter();
            $importer->Config($syncstate);

            switch($element[EN_TAG]) {
                case SYNC_ADD:
                case SYNC_MODIFY:
                    $serverid = $importer->ImportFolderChange($folder);
		    $statemachine->updateSyncCacheFolder($foldercache, $serverid, $folder->parentid, $folder->displayname, $folder->type);

                    // add folder to the serverflags
                    $seenfolders[] = $serverid;
                    break;
                case SYNC_REMOVE:
                    $serverid = $importer->ImportFolderDeletion($folder);
                    // remove folder from the folderflags array
                    if (($sid = array_search($serverid, $seenfolders)) !== false) {
                        unset($seenfolders[$sid]);
                        $seenfolders = array_values($seenfolders);    
                    }
		    $statemachine->deleteSyncCacheFolder($foldercache,$serverid);
                    break;
            }

            if($serverid)
                $map[$serverid] = $folder->clientid;
        }

        if(!$decoder->getElementEndTag())
            return false;
    }

    if(!$decoder->getElementEndTag())
        return false;

    // We have processed incoming foldersync requests, now send the PIM
    // our changes

    // The MemImporter caches all imports in-memory, so we can send a change count
    // before sending the actual data. As the amount of data done in this operation
    // is rather low, this is not memory problem. Note that this is not done when
    // sync'ing messages - we let the exporter write directly to WBXML.
    $importer = new ImportHierarchyChangesMem($encoder);

    // Request changes from backend, they will be sent to the MemImporter passed as the first
    // argument, which stores them in $importer. Returns the new sync state for this exporter.
    $exporter = $backend->GetExporter();

    $exporter->Config($importer, false, false, $syncstate, 0, 0, false);

    while(is_array($exporter->Synchronize()));

    // Output our WBXML reply now
    $encoder->StartWBXML();

    $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC);
    {
        $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
        $encoder->content(1);
        $encoder->endTag();

        $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
        $encoder->content($newsynckey);
        $encoder->endTag();

        $encoder->startTag(SYNC_FOLDERHIERARCHY_CHANGES);
        {
	    // remove unnecessary updates where details between cache and real folder are equal
    	    if(count($importer->changed) > 0) {
                foreach($importer->changed as $key=>$folder) {
		    if (isset($folder->serverid) && in_array($folder->serverid, $seenfolders) &&
			isset($foldercache['folders'][$folder->serverid]) &&
			$foldercache['folders'][$folder->serverid]['parentid'] == $folder->parentid &&
			$foldercache['folders'][$folder->serverid]['displayname'] == $folder->displayname &&
			$foldercache['folders'][$folder->serverid]['type'] == $folder->type) {
                	debugLog("Ignoring ".$folder->serverid." from importer->changed because it is folder update requests!");
    			unset($importer->changed[$key]);
    			$importer->count--;
		    }
        	}
            }
	    // remove unnecessary deletes where folders never got sent to the device
    	    if(count($importer->deleted) > 0) {
                foreach($importer->deleted as $key=>$folder) {
                    if (($sid = array_search($folder, $seenfolders)) === false) {
                	debugLog("Removing $folder from importer->deleted because sid $sid (not in seenfolders)!");
    			unset($importer->deleted[$key]);
    			$importer->count--;
    		    }
    		}
    	    }
            $encoder->startTag(SYNC_FOLDERHIERARCHY_COUNT);
    	    $encoder->content($importer->count);
            $encoder->endTag();

	    if(count($importer->changed) > 0) {
		foreach($importer->changed as $folder) {
            	    if (isset($folder->serverid) && in_array($folder->serverid, $seenfolders)){
                	$encoder->startTag(SYNC_FOLDERHIERARCHY_UPDATE);
            	    } else {
                    	$seenfolders[] = $folder->serverid;
                    	$encoder->startTag(SYNC_FOLDERHIERARCHY_ADD);
            	    }                  
		    $statemachine->updateSyncCacheFolder($foldercache, $folder->serverid, $folder->parentid, $folder->displayname, $folder->type);
                    $folder->encode($encoder);
                    $encoder->endTag();
		}
	    }

            if(count($importer->deleted) > 0) {
                foreach($importer->deleted as $folder) {
                    if (($sid = array_search($folder, $seenfolders)) !== false) {
                    $encoder->startTag(SYNC_FOLDERHIERARCHY_REMOVE);
                        $encoder->startTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID);
                            $encoder->content($folder);
                        $encoder->endTag();
                    $encoder->endTag();

                    // remove folder from the folderflags array
                        unset($seenfolders[$sid]);
			$statemachine->deleteSyncCacheFolder($foldercache,$folder);
                        $seenfolders = array_values($seenfolders);    
                    } else {
                	debugLog("Don't send $folder because sid $sid (not in seenfolders)!");
                    }
                }
            }
        }
        $encoder->endTag();
    }
    $encoder->endTag();

    // Save the sync state for the next time
    $syncstate = $exporter->GetState();
    $statemachine->setSyncState($newsynckey, $syncstate);
    $statemachine->setSyncState("s".$newsynckey, serialize($seenfolders));

    // Remove collections from foldercache for that no folder exists
    if (isset($foldercache['collections']))
	foreach ($foldercache['collections'] as $key => $value) {
	    if (!isset($foldercache['folders'][$key])) unset($foldercache['collections'][$key]);
	}
    $statemachine->setSyncCache(serialize($foldercache));

    return true;
}

function _HandleSyncError($errorcode, $limit = false) {
    global $zpushdtd;
    global $output;

    $encoder = new WBXMLEncoder($output, $zpushdtd);
    $encoder->StartWBXML();
    $encoder->startTag(SYNC_SYNCHRONIZE);
    $encoder->startTag(SYNC_STATUS);
    $encoder->content($errorcode);
    $encoder->endTag();
    if ($limit !== false) {
	$encoder->startTag(SYNC_LIMIT);
	$encoder->content($limit);
	$encoder->endTag();
    }
    $encoder->endTag();
}

function HandleSync($backend, $protocolversion, $devid) {
    global $zpushdtd;
    global $input, $output;
    global $user, $auth_pw;

    // Contains all containers requested
    $collections = array();

    // Init WBXML decoder
    $decoder = new WBXMLDecoder($input, $zpushdtd);

    // Init state machine
    $statemachine = new StateMachine($devid);

    // Start decode
    $shortsyncreq = false;
    $dataimported = false;
    $dataavailable = false;
    $maxcacheage = 960; // 15 Minutes + 1 to store it long enough for Device being connected to ActiveSync PC.
    if(!$decoder->getElementStartTag(SYNC_SYNCHRONIZE)) {
	// short request is allowed in >= 12.1 but we enforce a full sync request in case cache is older than
	// 10 minutes
	if ($protocolversion >= 12.1) {
	    if (!($SyncCache = unserialize($statemachine->getSyncCache())) ||
		!isset($SyncCache['collections']) ||
		$SyncCache['lastuntil']+$maxcacheage <= time()) {
    		_HandleSyncError("13");
		debugLog("Empty Sync request and no or too old SyncCache. ".
		"(SyncCache[lastuntil]+".$maxcacheage."=".($SyncCache['lastuntil']+$maxcacheage).", ".
		"Time now".(time()).", ".
		"SyncCache[collections]=".(isset($SyncCache['collections']) ? "Yes" : "No" ).", ".
		"SyncCache array=".(is_array($SyncCache) ? "Yes" : "No" ).") ".
		" STATUS = 13");
    		return true;
	    } else {
		$shortsyncreq = true;
		$SyncCache['timestamp'] = time();
		$statemachine->setSyncCache(serialize($SyncCache));
		debugLog("Empty Sync request and taken info from SyncCache.");
		$collections = array();
		foreach ($SyncCache['collections'] as $key=>$value) {
		    $collection = $value;
		    $collection['collectionid'] = $key;
		    if ($collection['synckey']) {
			$collection['syncstate'] = $statemachine->getSyncState($collection['synckey']);
			array_push($collections,$collection);
		    }
		}
	    }
	} else {
    	    _HandleSyncError("13");
	    debugLog("Empty Sync request. STATUS = 13");
    	    return true;
    	}
    } else {
	if ($decoder->getElementStartTag(SYNC_MAXITEMS)) {
	    $default_maxitems = $decoder->getElementContent();
	    if(!$decoder->getElementEndTag())
		return false; 
	}

	if (!isset($SyncCache)) $SyncCache = unserialize($statemachine->getSyncCache());
	// Just to update the timestamp...
	$SyncCache['timestamp'] = time();
	$SyncCache['lastuntil'] = time();
	$statemachine->setSyncCache(serialize($SyncCache));
    
	if($decoder->getElementStartTag(SYNC_FOLDERS)) {
    	    $dataimported = false;

	    while($decoder->getElementStartTag(SYNC_FOLDER)) {
    		$collection = array();
        	$collection["truncation"] = SYNC_TRUNCATION_ALL;
            	$collection["clientids"] = array();
            	$collection["fetchids"] = array();
    	        
	    	while (($type = ($decoder->getElementStartTag(SYNC_FOLDERTYPE)  	? SYNC_FOLDERTYPE 	:
	    		    	($decoder->getElementStartTag(SYNC_SYNCKEY)  		? SYNC_SYNCKEY 		:
			    	($decoder->getElementStartTag(SYNC_FOLDERID)	  	? SYNC_FOLDERID 	:
			    	($decoder->getElementStartTag(SYNC_MAXITEMS)	  	? SYNC_MAXITEMS 	:
			    	($decoder->getElementStartTag(SYNC_SUPPORTED)	  	? SYNC_SUPPORTED 	:
			    	($decoder->getElementStartTag(SYNC_CONVERSATIONMODE)	? SYNC_CONVERSATIONMODE :
			    	($decoder->getElementStartTag(SYNC_DELETESASMOVES)	? SYNC_DELETESASMOVES 	:
			    	($decoder->getElementStartTag(SYNC_GETCHANGES)		? SYNC_GETCHANGES 	:
			    	-1))))))))) != -1) {
	    	    switch ($type) {
		    	case SYNC_SYNCKEY :  
		    		$collection["synckey"] = $decoder->getElementContent();
			        if(!$decoder->getElementEndTag())
			            return false;
			        break;
		    	case SYNC_FOLDERID :  
				$collection["collectionid"] = $decoder->getElementContent();
			        if(!$decoder->getElementEndTag())
			            return false;
			        break;
		    	case SYNC_FOLDERTYPE : 
				$collection["class"] = $decoder->getElementContent();
				debugLog("Sync folder:{$collection["class"]}");
			        if(!$decoder->getElementEndTag())
			            return false;
			        break;
		    	case SYNC_MAXITEMS :  
				$collection["maxitems"] = $decoder->getElementContent();
			        if(!$decoder->getElementEndTag())
			            return false;
			        break;
		    	case SYNC_CONVERSATIONMODE :  
				if(($collection["conversationmode"] = $decoder->getElementContent()) !== false) {
			    	    if(!$decoder->getElementEndTag())
			        	return false;
			        } else {
			    	    $collection["conversationmode"] = true;
			        }
			    	break;
    		    	case SYNC_SUPPORTED : 
    		                while(1) {
            			    $el = $decoder->getElement();
            			    if($el[EN_TYPE] == EN_TYPE_ENDTAG)
                		    break;
        			}
        			break;
    		    	case SYNC_DELETESASMOVES : 
    		    		if (($collection["deletesasmoves"] = $decoder->getElementContent()) !== false) {
	    			    if(!$decoder->getElementEndTag()) {
            				return false;
            			    };
				} else {
            			    $collection["deletesasmoves"] = true;
				}
				break;
    		    	case SYNC_GETCHANGES : 
        			if (($collection["getchanges"] = $decoder->getElementContent()) !== false) {
        			    if(!$decoder->getElementEndTag()) {
            				return false;
            			    };
				} else {
            			    $collection["getchanges"] = true;
				}
				break;

	    	    };
	    	};
	    	if ($protocolversion >= 12.1 &&
	    	    !isset($collection["class"]) &&
	    	    isset($collection["collectionid"])) {
	    	    if (isset($SyncCache['folders'][$collection["collectionid"]]["class"])) {
	    		$collection["class"] = $SyncCache['folders'][$collection["collectionid"]]["class"];
			debugLog("Sync folder:{$collection["class"]}");
	    	    } else {
			_HandleSyncError("12");
			debugLog("No Class even in cache, sending status 12 to recover from this");
	        	return true;		    
	    	    }
		};

        	while($decoder->getElementStartTag(SYNC_OPTIONS)) {
            	    while(1) {
		    // dw2412 in as14 this is used to sent SMS type messages
                	if($decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
            	    	    $collection["optionfoldertype"] = $decoder->getElementContent();
                    	    if(!$decoder->getElementEndTag())
                        	return false;
                	}
                	if($decoder->getElementStartTag(SYNC_FILTERTYPE)) {
	            	    if (isset($collection["optionfoldertype"])) 
                		$collection[$collection["optionfoldertype"]]["filtertype"] = $decoder->getElementContent();
		    	    else
                		$collection["filtertype"] = $decoder->getElementContent();
                    	    if(!$decoder->getElementEndTag())
                        	return false;
                	}
                	if($decoder->getElementStartTag(SYNC_TRUNCATION)) {
	            	    if (isset($collection["optionfoldertype"])) 
                		$collection[$collection["optionfoldertype"]]["truncation"] = $decoder->getElementContent();
		    	    else
                		$collection["truncation"] = $decoder->getElementContent();
                    	    if(!$decoder->getElementEndTag())
                        	return false;
            		}
                	if($decoder->getElementStartTag(SYNC_RTFTRUNCATION)) {
	            	    if (isset($collection["optionfoldertype"])) 
                		$collection[$collection["optionfoldertype"]]["rtftruncation"] = $decoder->getElementContent();
		    	    else
                		$collection["rtftruncation"] = $decoder->getElementContent();
                    	    if(!$decoder->getElementEndTag())
                        	return false;
                	}

                	if($decoder->getElementStartTag(SYNC_MIMESUPPORT)) {
	            	    if (isset($collection["optionfoldertype"])) 
                		$collection[$collection["optionfoldertype"]]["mimesupport"] = $decoder->getElementContent();
		    	    else
                		$collection["mimesupport"] = $decoder->getElementContent();
                    	    if(!$decoder->getElementEndTag())
                        	return false;
                	}

                	if($decoder->getElementStartTag(SYNC_MIMETRUNCATION)) {
	            	    if (isset($collection["optionfoldertype"])) 
                		$collection[$collection["optionfoldertype"]]["mimetruncation"] = $decoder->getElementContent();
		    	    else
                		$collection["mimetruncation"] = $decoder->getElementContent();
                    	    if(!$decoder->getElementEndTag())
                        	return false;
                	}

                	if($decoder->getElementStartTag(SYNC_CONFLICT)) {
	            	    if (isset($collection["optionfoldertype"])) 
                		$collection[$collection["optionfoldertype"]]["conflict"] = $decoder->getElementContent();
		    	    else
                		$collection["conflict"] = $decoder->getElementContent();
                    	    if(!$decoder->getElementEndTag())
                        	return false;
                	}
	
			// START ADDED dw2412 V12.0 Sync Support
			if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE)) {
		    	    if (!isset($bodypreference)) $bodypreference=array();
        	    	    while(1) {
            			if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
	            	    	    $bodypreference["Type"] = $decoder->getElementContent();
    		            	    if(!$decoder->getElementEndTag())
                            		return false;
    	    			}

            			if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
	            	    	    $bodypreference["TruncationSize"] = $decoder->getElementContent();
    		            	    if(!$decoder->getElementEndTag())
                        		return false;
    	    			}

            			if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_PREVIEW)) {
	            	    	    $bodypreference["Preview"] = $decoder->getElementContent();
    		            	    if(!$decoder->getElementEndTag())
                        		return false;
    	    			}

            			if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
	            	    	    $bodypreference["AllOrNone"] = $decoder->getElementContent();
    		            	    if(!$decoder->getElementEndTag())
                        		return false;
    	    			}

            			$e = $decoder->peek();
            			if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
            		    	    $decoder->getElementEndTag();
/*			        if (isset($bodypreference["Type"]))
				    if (!isset($collection["BodyPreference"]["wanted"]))
				        $collection["BodyPreference"]["wanted"] = $bodypreference["Type"];
*/	                     	
				    if (isset($collection["optionfoldertype"])) 
	            			$collection["BodyPreference"][$collection["optionfoldertype"]][$bodypreference["Type"]] = $bodypreference;
	            	    	    else
					$collection["BodyPreference"][$bodypreference["Type"]] = $bodypreference;
    		    	    	    break;
	        		}
                    	    }
		        }
			// END ADDED dw2412 V12.0 Sync Support
                	$e = $decoder->peek();
                	if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
                    	    $decoder->getElementEndTag();
                    	    break;
                	}
            	    }
        	}

        	// compatibility mode - get folderid from the state directory
        	if (!isset($collection["collectionid"])) {
            	    $collection["collectionid"] = _getFolderID($devid, $collection["class"]);
        	}

        	// set default conflict behavior from config if the device doesn't send a conflict resolution parameter
        	if (!isset($collection["conflict"])) {
            	    $collection["conflict"] = SYNC_CONFLICT_DEFAULT;
        	}

        	// Get our sync state for this collection
        	$collection["syncstate"] = $statemachine->getSyncState($collection["synckey"]);
		if(is_numeric($collection["syncstate"]) && 
	    	    $collection["syncstate"] < 0) {
	    	    debugLog("GetSyncState: Got an error in HandleSync");
	    	    $collection["syncstate"] = false;
		} 
		if (isset($collection["optionfoldertype"])) {
        	    $collection[$collection["optionfoldertype"]."syncstate"] = $statemachine->getSyncState($collection["optionfoldertype"].$collection["synckey"]);
	    	    if (is_numeric($collection[$collection["optionfoldertype"]."syncstate"]) && 
	    		$collection[$collection["optionfoldertype"]."syncstate"] < 0) {
	    		debugLog("GetSyncState: Got an error in HandleSync");
	    		$collection[$collection["optionfoldertype"]."syncstate"] = false;
	    	    }
		}
        	if($decoder->getElementStartTag(SYNC_PERFORM)) {
    
            	    // Configure importer with last state
            	    $importer = $backend->GetContentsImporter($collection["collectionid"]);
            	    $importer->Config($collection["syncstate"], $collection["conflict"]);
		    if (isset($collection["optionfoldertype"])) {
            		$optionimporter[$collection["optionfoldertype"]] = $backend->GetContentsImporter($collection["collectionid"]);
            		$optionimporter[$collection["optionfoldertype"]]->Config($collection[$collection["optionfoldertype"]."syncstate"], $collection["conflict"]);
	    	    }
		
            	    $nchanges = 0;
            	    while(1) {
                	$element = $decoder->getElement(); // MODIFY or REMOVE or ADD or FETCH

                	if($element[EN_TYPE] != EN_TYPE_STARTTAG) {
                    	    $decoder->ungetElement($element);
                    	    break;
                	}
    
                	$nchanges++;
    
    			// dw2412 in as14 this is used to sent SMS type messages
                	if($decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
                    	    $foldertype = $decoder->getElementContent();
                	    if(!$decoder->getElementEndTag()) // end foldertype
                    		return false;
                	} else {
            	    	    $foldertype = false;
                	}

                	if($decoder->getElementStartTag(SYNC_SERVERENTRYID)) {
                    	    $serverid = $decoder->getElementContent();

                    	    if(!$decoder->getElementEndTag()) // end serverid
                        	return false;
                	} else {
                    	    $serverid = false;
                	}

                	if($decoder->getElementStartTag(SYNC_CLIENTENTRYID)) {
                    	    $clientid = $decoder->getElementContent();

                    	    if(!$decoder->getElementEndTag()) // end clientid
                        	return false;
                	} else {
                    	    $clientid = false;
                	}

            		// Get application data if available
                	if($decoder->getElementStartTag(SYNC_DATA)) {
                    	    switch($collection["class"]) {
                        	case "Email":
                        	    $appdata = new SyncMail();
                            	    $appdata->decode($decoder);
                            	    break;
                        	case "Contacts":
                            	    $appdata = new SyncContact($protocolversion);
                            	    $appdata->decode($decoder);
                            	    break;
                        	case "Calendar":
                            	    $appdata = new SyncAppointment();
                            	    $appdata->decode($decoder);
                            	    break;
                        	case "Tasks":
                            	    $appdata = new SyncTask();
                            	    $appdata->decode($decoder);
                            	    break;
                        	case "Notes":
                            	    $appdata = new SyncNotes();
                            	    $appdata->decode($decoder);
                            	    break;
                    		}
                    	    if(!$decoder->getElementEndTag()) // end applicationdata
                        	return false;
    
                	}

                	switch($element[EN_TAG]) {
                    	    case SYNC_MODIFY:
                    		if(isset($appdata)) {
                            	    if ($foldertype) {
                            		if ($appdata->_setchange == true || 
                            		    ($appdata->_setread == false &&
                            		     $appdata->_setflag == false)) {
                                	    $optionimporter[$foldertype]->ImportMessageChange($serverid, $appdata);
                            		} else {
                            		    if ($appdata->_setflag == true) {
	    					$optionimporter[$foldertype]->ImportMessageFlag($serverid, $appdata->poommailflag);
                            			$collection[$foldertype."flagids"][$serverid] = true;
                            		    } 
                            		    if ($appdata->_setread == true) {
                                		$optionimporter[$foldertype]->ImportMessageReadFlag($serverid, $appdata->read);
                            			$collection[$foldertype."readids"][$serverid] = true;
					    }
					}
                            	    } else {
                            		if ($appdata->_setchange == true || 
                            		    ($appdata->_setread == false &&
                            		     $appdata->_setflag == false)) {
                                	    $importer->ImportMessageChange($serverid, $appdata);
                            		} else {
                            		    if ($appdata->_setflag == true) {
	    					$importer->ImportMessageFlag($serverid, $appdata->poommailflag);
                            			$collection["flagids"][$serverid] = true;
                            		    }
                            		    if ($appdata->_setread == true) {
                                	        $importer->ImportMessageReadFlag($serverid, $appdata->read);
                            			$collection["readids"][$serverid] = true;
                            		    }
                            		}
				    }
                            	    $collection["importedchanges"] = true;
                        	}
                        	break;
                    	    case SYNC_ADD:
                        	if(isset($appdata)) {
                                
                            	    if ($foldertype) {
                            		$id = $optionimporter[$foldertype]->ImportMessageChange(false, $appdata);
                            	    } else {
                            		$id = $importer->ImportMessageChange(false, $appdata);
				    }
    
                            	    if($clientid && $id) {
                            		$collection["clientids"][$clientid]['serverid'] = $id;
                            		if ($foldertype) {
                            		    $collection["clientids"][$clientid]['optionfoldertype'] = $foldertype;
                                	}
                                	$collection["importedchanges"] = true;
                            	    }
                        	}
                        	break;
                    	    case SYNC_REMOVE:
                        	if(isset($collection["deletesasmoves"])) {
                            	    $folderid = $backend->GetWasteBasket();
    
                            	    if($folderid) {
                            		if ($foldertype) {
                                	    $optionimporter[$foldertype]->ImportMessageMove($serverid, $folderid);
                                	} else {
                                	    $importer->ImportMessageMove($serverid, $folderid);
                            		}
                                	$collection["importedchanges"] = true;
                                	break;
                            	    }
                        	}
    
                        	if ($foldertype) {
                        	    $optionimporter[$foldertype]->ImportMessageDeletion($serverid);
                    		} else {
                        	    $importer->ImportMessageDeletion($serverid);
                    	        }
                        	$collection["importedchanges"] = true;
                        	break;
                    	    case SYNC_FETCH:
                        	array_push($collection["fetchids"], $serverid);
                        	break;
            		}
    
                	if(!$decoder->getElementEndTag()) // end change/delete/move
                    	    return false;
            	    }	
    
                    debugLog("Processed $nchanges incoming changes");
    
            	    // Save the updated state, which is used for the exporter later
            	    $collection["syncstate"] = $importer->getState();
		    if (isset($collection['optionfoldertype'])) 
            		$collection[$collection['optionfoldertype']."syncstate"] = $optionimporter[$collection['optionfoldertype']]->getState();
	    	    if ($collection["importedchanges"] == true) $dataimported = true;
        
    		    // if (isset($collection["synckey"])) $SyncCache['collections'][$collection["collectionid"]]["synckey"] = $collection["synckey"];
    

        	    if(!$decoder->getElementEndTag()) // end commands
            		return false;
    		}

    		if(!$decoder->getElementEndTag()) // end collection
        	    return false;

    		array_push($collections, $collection);
		if (isset($collection["collectionid"])) {
		    if (isset($collection["class"])) 		$SyncCache['collections'][$collection["collectionid"]]["class"] = $collection["class"];
	    	    if (isset($collection["maxitems"])) 	$SyncCache['collections'][$collection["collectionid"]]["maxitems"] = $collection["maxitems"];
	    	    if (isset($collection["optionfoldertype"])) $SyncCache['collections'][$collection["collectionid"]]["optionfoldertype"] = $collection["optionfoldertype"];
	    	    if (isset($collection["deletesasmoves"])) 	$SyncCache['collections'][$collection["collectionid"]]["deletesasmoves"] = $collection["deletesasmoves"];
	    	    if (isset($collection["getchanges"])) 	$SyncCache['collections'][$collection["collectionid"]]["getchanges"] = $collection["getchanges"];
	    	    if (isset($collection["filtertype"])) 	$SyncCache['collections'][$collection["collectionid"]]["filtertype"] = $collection["filtertype"];
	    	    if (isset($collection["truncation"])) 	$SyncCache['collections'][$collection["collectionid"]]["truncation"] = $collection["truncation"];
	    	    if (isset($collection["rtftruncation"]))  	$SyncCache['collections'][$collection["collectionid"]]["rtftruncation"] = $collection["rtftruncation"];
	    	    if (isset($collection["mimesupport"])) 	$SyncCache['collections'][$collection["collectionid"]]["mimesupport"] = $collection["mimesupport"];
	    	    if (isset($collection["mimetruncation"])) 	$SyncCache['collections'][$collection["collectionid"]]["mimetruncation"] = $collection["mimetruncation"];
	    	    if (isset($collection["conflict"]))	  	$SyncCache['collections'][$collection["collectionid"]]["conflict"] = $collection["conflict"];
	    	    if (isset($collection["BodyPreference"])) 	$SyncCache['collections'][$collection["collectionid"]]["BodyPreference"] = $collection["BodyPreference"];
		};
    	    }
	    if (!$decoder->getElementEndTag() ) // end collections
    		return false;
	}

	foreach ($collections as $key=>$values) {
	    // Lets go through the collections. AS12.1 does not send the folderclass. 
	    // This needs to be taken from cache. In case not found there we need to rebuild the cache and 
	    // therefor set status to 
    	    if (!isset($values["class"])) {
		if (isset($SyncCache['folders'][$values["collectionid"]]["class"])) {
		    $collections[$key]["class"] = $SyncCache['folders'][$values["collectionid"]]["class"];
		} else {
		    _HandleSyncError("12");
		    debugLog("No Class even in cache, sending status 12 to recover from this");
	    	    return true;		    
		}
	    
	    };
	    if ($protocolversion >= 12.0) {
		if (!isset($values["BodyPreference"]) && $values['synckey'] != '0') {
		    if (isset($SyncCache['collections'][$values["collectionid"]]['BodyPreference'])) {
			$collections[$key]["BodyPreference"] = $SyncCache['collections'][$values["collectionid"]]['BodyPreference'];
		    } else {
			_HandleSyncError("12");
			debugLog("No BodyPreference even in cache, sending status 12 to recover from this");
	    		return true;		    
		    }
		}
	    }
    	    if (!isset($values["filtertype"]) && $values['synckey'] != '0' && ($values['class'] == 'Email' || $values['class'] == 'Calendar' || $values['class'] == 'Tasks')) {
		if (isset($SyncCache['collections'][$values["collectionid"]]['filtertype'])) {
		    $collections[$key]["filtertype"] = $SyncCache['collections'][$values["collectionid"]]['filtertype'];
		} else {
		    $collections[$key]["filtertype"] = 0;
		}
	    }
	    if (!isset($values["maxitems"])) 
		$collections[$key]["maxitems"] = (isset($SyncCache['collections'][$values["collectionid"]]['maxitems']) ? 
						    $SyncCache['collections'][$values["collectionid"]]['maxitems'] : 
						    (isset($default_maxitems) ?  
						        $default_maxitems : 50));
	    if (isset($values["maxitems"]) &&
	        isset($default_maxitems)) {
		$collections[$key]["maxitems"] = $default_maxitems;
	    }
	    if (isset($values['synckey']) && 
		$values['synckey'] == '0' && 
		isset($SyncCache['collections'][$values["collectionid"]]['synckey']) && 
		$SyncCache['collections'][$values["collectionid"]]['synckey'] != '0') {
		debugLog("ERROR Synckey 0 and Cache has synckey... Invalidation disabled, check of maybe existing dups!");
	    }
	}
	if (!isset($SyncCache['hierarchy']['synckey'])) {
	    _HandleSyncError("12");
	    debugLog("HandleSync Error No Hierarchy SyncKey in SyncCache... Invalidate! (STATUS = 12)");
	    return true;		    
	}
	
	if($decoder->getElementStartTag(SYNC_WAIT)) {
    	    $wait = $decoder->getElementContent();
	    debugLog("Got Wait Sync ($wait Minutes)");
	    $SyncCache['wait'] = $wait;
	    $decoder->getElementEndTag();
	} else {
	    $SyncCache['wait'] = false;
	}

	if($decoder->getElementStartTag(SYNC_HEARTBEATINTERVAL)) {
    	    $SyncCache['hbinterval'] = $decoder->getElementContent();
	    debugLog("Got Heartbeat Interval Sync (".$SyncCache['hbinterval']." Seconds)");
    	    $decoder->getElementEndTag();
	} else {
	    $SyncCache['hbinterval'] = false;
        }

	if ($SyncCache['hbinterval'] !== false &&
	    $SyncCache['wait'] !== false) {
	    _HandleSyncError("4");
	    debugLog("HandleSync got Found HeartbeatInterval and Wait in request. This violates the protocol spec. (STATUS = 4)");
	    return true;		    
	}
	if ($SyncCache['wait'] > ((REAL_SCRIPT_TIMEOUT-600)/60)) {
	    _HandleSyncError("14",((REAL_SCRIPT_TIMEOUT-600)/60));
	    debugLog("Wait larger than ".((REAL_SCRIPT_TIMEOUT-600)/60)." Minutes. This violates the protocol spec. (STATUS = 14, LIMIT = ".((REAL_SCRIPT_TIMEOUT-600)/60).")");
	    return true;		    
	}
	if ($SyncCache['hbinterval'] > (REAL_SCRIPT_TIMEOUT-600)) {
	    _HandleSyncError("14",(REAL_SCRIPT_TIMEOUT-600));
	    debugLog("HeartbeatInterval larger than ".(REAL_SCRIPT_TIMEOUT-600)." Seconds. This violates the protocol spec. (STATUS = 14, LIMIT = ".(REAL_SCRIPT_TIMEOUT-600).")");
	    return true;		    
	}
// Partial sync but with Folders and Options so we need to set collections
	if($decoder->getElementStartTag(SYNC_PARTIAL)) {
	    $partial = true;
	    debugLog("Partial Sync");

	    $TempSyncCache = unserialize($statemachine->getSyncCache());

	    $foundsynckey = false;
	    foreach ($collections as $key=>$value) {
		if (isset($value['synckey'])) {
		    $foundsynckey = true;
		}
		if (isset($TempSyncCache['collections'][$value['collectionid']])) {
		    debugLog("Received collection info updating ".$TempSyncCache['folders'][$value['collectionid']]['displayname']);
		    $collections[$key]['class'] = $TempSyncCache['collections'][$value['collectionid']]['class'];
		    unset($TempSyncCache['collections'][$value['collectionid']]);
		}
	    }

	    foreach ($TempSyncCache['collections'] as $key=>$value) {
		if (isset($value['synckey'])) {
	    	    $collection = $value;
	    	    $collection['collectionid'] = $key;
    		    if (isset($default_maxitems)) 	$collection["maxitems"] = $default_maxitems;
		    $collection['syncstate'] = $statemachine->getSyncState($collection["synckey"]);
		    if ($collection['syncstate'] < 0) {
		        _HandleSyncError("3");
			debugLog("GetSyncState ERROR (Syncstate: ".abs($collection['syncstate']).")");
			return true;		    
		    }
		    debugLog("Using SyncCache State for ".$TempSyncCache['folders'][$key]['displayname']);
		    array_push($collections, $collection);
		}
	    }
	    unset($TempSyncCache);
	}
	
	// Update the synckeys in SyncCache
	foreach($SyncCache['collections'] as $key=>$value) {
	    if (isset($SyncCache['collections'][$key]['synckey'])) {
	        debugLog("Removing SyncCache[synckey] from collection ".$key);
	        unset($SyncCache['collections'][$key]['synckey']);
	    }
	}
	
	foreach($collections as $key=>$value) {
	    if (isset($value['synckey'])) {
	        debugLog("Adding SyncCache[synckey] from collection ".$value['collectionid']);
	        $SyncCache['collections'][$value['collectionid']]['synckey'] = $value['synckey'];
	    }
	    if (strlen($value['syncstate']) == 0 || bin2hex(substr($value['syncstate'],4,4)) == "00000000") {
		if (isset($value['BodyPreference']))
		    $collections[$key]['getchanges'] = true;
	    }
	}
	// End Update the synckeys in SyncCache
	
	if(!$decoder->getElementEndTag()) // end sync
    	    return false;
    };

    // From Version 12.1 the sync is being used to wait for changes. 
    // The ping looks like being used only by AS Protocol up to 12.0
    // AS12.1 uses the wait in minutes, 
    // AS14 the HeartbeatInterval in seconds.
    // Both is handeled below.
    if ($protocolversion >= 12.1 &&
	($SyncCache['wait'] !== false ||
	 $SyncCache['hbinterval'] !== false)) {
	$dataavailable = false;
	$timeout = 10;
	if ($SyncCache['wait'] !== false) $until = time()+($SyncCache['wait']*60);
	else if ($SyncCache['hbinterval'] !== false) $until = time()+($SyncCache['hbinterval']);
	debugLog("Looking for changes for ".($until - time())." seconds");
	$SyncCache['lastuntil'] = $until;
	// Reading current state of the hierarchy state for determining changes during heartbeat/wait
        $hierarchystate = $statemachine->getSyncState($SyncCache['hierarchy']['synckey']);

	while (time()<$until) {
	    // we try to find changes as long as time is lower than wait time 

	    // In case something changed in SyncCache regarding the folder hierarchy exit this function
	    $TempSyncCache = unserialize($statemachine->getSyncCache());
    	    if ($TempSyncCache['timestamp'] > $SyncCache['timestamp']) {
		debugLog("HandleSync: Changes in cache determined during Sync Wait/Heartbeat, exiting here.");
    		return true;
    	    }
    	    if (PROVISIONING === true) {
		$rwstatus = $backend->getDeviceRWStatus($user, $auth_pw, $devid);
		if ($rwstatus == SYNC_PROVISION_RWSTATUS_PENDING || $rwstatus == SYNC_PROVISION_RWSTATUS_WIPED) {
	    	    //return 12 because it forces folder sync
		    _HandleSyncError("12");
		    return true;		    
		}
	    }

    	    if(count($collections) == 0) {
        	$error = 1;
        	break;
    	    }

    	    for($i=0;$i<count($collections);$i++) {
        	$collection = $collections[$i];

        	$state = $collection["syncstate"];
        	$class = $collection["class"];
        	$truncation = $collection["truncation"];
        	$filtertype = (isset($collection["filtertype"]) ? $collection["filtertype"] : 0);
        	$waitimporter = false;
                $onlyoptionbodypreference = $protocolversion >= 14.0 && (!isset($collection["BodyPreference"][1]) && !isset($collection["BodyPreference"][2]) && !isset($collection["BodyPreference"][3]) && !isset($collection["BodyPreference"][4]));
		
		if ($onlyoptionbodypreference == false) {
        	    $exporter = $backend->GetExporter($collection["collectionid"]);
        	    $ret = $exporter->Config($waitimporter, $collection["class"], $filtertype, $state, BACKEND_DISCARD_DATA, 0, (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false));

        	    // stop ping if exporter can not be configured (e.g. after Zarafa-server restart)
        	    if ($ret === false ) {
            		debugLog("Sync Wait/Heartbeat error: Exporter can not be configured. Waiting 30 seconds before sync is retried.");
			debugLog($collection["collectionid"]);
            		sleep(30);
        	    }

        	    $changecount = $exporter->GetChangeCount();

        	    if($changecount > 0 ||
        	       (strlen($state) == 0 || bin2hex(substr($state,4,4)) == "00000000")) {
            		debugLog("Found change in folder ".$SyncCache['folders'][$collection["collectionid"]]['displayname']);
            		$dataavailable = true;
			$collections[$i]["getchanges"] = true;
        	    }

        	    // Discard any data
        	    while(is_array($exporter->Synchronize()));
		};
	
		if (isset($collection["optionfoldertype"]) &&
		    $collection["optionfoldertype"] == "SMS") {
        	    $exporter = $backend->GetExporter($collection["collectionid"]);
        	    $state = $collection[$collection["optionfoldertype"]."syncstate"];
        	    $filtertype = (isset($collection["optionfoldertype"]["filtertype"]) ? $collection["optionfoldertype"]["filtertype"] : 0);
        	    $waitimporter = false;
        	    $ret = $exporter->Config($waitimporter, $collection["optionfoldertype"], $filtertype, $state, BACKEND_DISCARD_DATA, 0, (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false));

        	    // stop ping if exporter can not be configured (e.g. after Zarafa-server restart)
        	    if ($ret === false ) {
            		debugLog("Sync Wait/Heartbeat error: Exporter can not be configured. Waiting 30 seconds before sync is retried.");
			debugLog($collection["collectionid"]);
            		sleep(30);
        	    }

        	    $changecount = $exporter->GetChangeCount();

        	    if($changecount > 0 ||
        	       (strlen($state) == 0 || bin2hex(substr($state,4,4)) == "00000000")) {
            		debugLog("Option: Found change in folder ".$SyncCache['folders'][$collection["collectionid"]]['displayname']);
            		$dataavailable = true;
			$collections[$i]["getchanges"] = true;
        	    }

        	    // Discard any data
        	    while(is_array($exporter->Synchronize()));
		}
		    
    	    }

    	    if($dataavailable) {
        	debugLog("Found change");
        	break;
    	    }

	    // Check for folder Updates
	    $hierarchychanged = false;
	    $exporter = $backend->GetExporter();
	    if ($hierarchystate >= 0) {
		$waitimporter = false;
		$exporter->Config($waitimporter, false, false, $hierarchystate, BACKEND_DISCARD_DATA, 0, false);
		if ($exporter->GetChangeCount() > 0) {
		    $hierarchychanged = true;
		}

    		while(is_array($exporter->Synchronize()));

		if ($hierarchychanged) {
            	    debugLog("HandleSync found hierarchy changes during Wait/Heartbeat Interval... Sending status 12 to get changes (STATUS = 12)");
		    _HandleSyncError("12");
		    return true;		    
		}
	    } else {
            	debugLog("Error in Syncstate during Wait/Heartbeat Interval... Sending status 12 to enforce hierarchy sync (STATUS = 12)");
		_HandleSyncError("12");
		return true;		    
	    }
	    // 5 seconds sleep to keep the load low...
	    sleep ($timeout);
	};
    }

    // Do a short answer to allow short sync requests
    debugLog("dataavailable: ".($dataavailable == true ? "Yes" : "No")." dataimported: ".($dataimported == true ? "Yes" : "No"));
    if ($protocolversion >= 12.1 &&
	isset($dataavailable) &&
	$dataavailable == false &&
	isset($dataimported) &&
	$dataimported == false &&
	($SyncCache['wait'] !== false ||
	 $SyncCache['hbinterval'] !== false)) {
        $statemachine->setSyncCache(serialize($SyncCache));
	return true;	
    }	

    $encoder = new WBXMLEncoder($output, $zpushdtd);
    $encoder->startWBXML();
    // START ADDED dw2412 Protocol Version 12 Support
    if (isset($collection["BodyPreference"])) $encoder->_bodypreference = $collection["BodyPreference"];
    // END ADDED dw2412 Protocol Version 12 Support
    
    $encoder->startTag(SYNC_SYNCHRONIZE);
    {
        $encoder->startTag(SYNC_FOLDERS);
        {
            foreach($collections as $collection) {
                // Get a new sync key to output to the client if any changes have been requested or have been sent
                if (isset($collection["importedchanges"]) || isset($collection["getchanges"]) || $collection["synckey"] == "0")
                    $collection["newsynckey"] = $statemachine->getNewSyncKey($collection["synckey"]);

		$folderstatus=1;
                if(isset($collection["getchanges"])) {
                    // Try to get the exporter. In case it is not possible (i.e. folder removed) set
                    // status according. 
                    $exporter = $backend->GetExporter($collection["collectionid"]);
		    debugLog("Exporter Value: ".is_object($exporter). " " .(isset($exporter->exporter) ? $exporter->exporter : "unset"));
            	    if (!is_object($exporter) || (isset($exporter->exporter) && $exporter->exporter === false)) {
            		$folderstatus = 8;
            	    }
                };

                $encoder->startTag(SYNC_FOLDER);
    
		// FolderType/Class is only being returned by AS up to 12.0. 
		// In 12.1 it could break the sync.
		if (isset($collection["class"]) &&
		    $protocolversion <= 12.0) {
            	    $encoder->startTag(SYNC_FOLDERTYPE);
            	    $encoder->content($collection["class"]);
            	    $encoder->endTag();
                }

                $encoder->startTag(SYNC_SYNCKEY);

                if(isset($collection["newsynckey"]))
                    $encoder->content($collection["newsynckey"]);
                else
                    $encoder->content($collection["synckey"]);

                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERID);
                $encoder->content($collection["collectionid"]);
                $encoder->endTag();

                $encoder->startTag(SYNC_STATUS);
                $encoder->content($folderstatus);
                $encoder->endTag();

                //check the mimesupport because we need it for advanced emails
                $mimesupport = isset($collection['mimesupport']) ? $collection['mimesupport'] : 0;

                // Output server IDs for new items we received from the PDA
                if(isset($collection["clientids"]) || (isset($collection["fetchids"]) && count($collection["fetchids"]) > 0)) {
                    $encoder->startTag(SYNC_REPLIES);
                    foreach($collection["clientids"] as $clientid => $servervals) {
                        $encoder->startTag(SYNC_ADD);
			if (isset($clientid["optionfoldertype"]) && is_array($servervals['serverid'])) {
                    	    $encoder->startTag(SYNC_FOLDERTYPE);
			    $encoder->content($collection["optionfoldertype"]);
			    $encoder->endTag();
			}
                        $encoder->startTag(SYNC_CLIENTENTRYID);
                        $encoder->content($clientid);
                        $encoder->endTag();
			if(is_array($servervals['serverid'])) {
                    	    $encoder->startTag(SYNC_SERVERENTRYID);
                    	    $encoder->content($servervals['serverid']['sourcekey']);
                    	    $encoder->endTag();
                        } else {
                    	    $encoder->startTag(SYNC_SERVERENTRYID);
                    	    $encoder->content($servervals['serverid']);
                    	    $encoder->endTag();
                        }
                        $encoder->startTag(SYNC_STATUS);
                        $encoder->content(1);
                        $encoder->endTag();
			if (is_array($servervals['serverid'])) {
                    	    $encoder->startTag(SYNC_DATA);
                    	    $encoder->startTag(SYNC_POOMMAIL2_CONVERSATIONID);
                    	    $encoder->contentopaque($servervals['serverid']['convid']);
                    	    $encoder->endTag();
                    	    $encoder->startTag(SYNC_POOMMAIL2_CONVERSATIONINDEX);
                    	    $encoder->contentopaque($servervals['serverid']['convidx']);
                    	    $encoder->endTag();
                    	    $encoder->endTag();
			}
                        $encoder->endTag();
                    }
                    foreach($collection["fetchids"] as $id) {
			// CHANGED dw2412 to support bodypreference
                        $data = $backend->Fetch($collection["collectionid"], $id, $collection["BodyPreference"], $mimesupport);
                        if($data !== false) {
                            $encoder->startTag(SYNC_FETCH);
                            $encoder->startTag(SYNC_SERVERENTRYID);
                            $encoder->content($id);
                            $encoder->endTag();
                            $encoder->startTag(SYNC_STATUS);
                            $encoder->content(1);
                            $encoder->endTag();
                            $encoder->startTag(SYNC_DATA);
                            $data->encode($encoder);
                            $encoder->endTag();
                            $encoder->endTag();
                        } else {
                            debugLog("unable to fetch $id");
                        }
                    }
                    $encoder->endTag();
                }

                if (isset($collection["getchanges"])) {
                    // Use the state from the importer, as changes may have already happened

                    $filtertype = isset($collection["filtertype"]) ? $collection["filtertype"] : false;
                    debugLog("FilterType for getchanges ".$filtertype);
                    $onlyoptionbodypreference = $protocolversion >= 14.0 && (!isset($collection["BodyPreference"][1]) && !isset($collection["BodyPreference"][2]) && !isset($collection["BodyPreference"][3]) && !isset($collection["BodyPreference"][4]));

                    if ($onlyoptionbodypreference === false) {
                	$exporter = $backend->GetExporter($collection["collectionid"]);
            		$exporter->Config($importer, $collection["class"], $filtertype, $collection["syncstate"], 0, $collection["truncation"], (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false));

                	$changecount = $exporter->GetChangeCount();
		    };
		    
		    if (isset($collection['optionfoldertype']) &&
			$collection['optionfoldertype'] == "SMS") {
    	    		$optionexporter[$collection['optionfoldertype']] = $backend->GetExporter($collection["collectionid"]);
            		$optionexporter[$collection['optionfoldertype']]->Config($optionimporter[$collection['optionfoldertype']], $collection["optionfoldertype"], $filtertype, $collection[$collection['optionfoldertype']."syncstate"], 0, $collection["truncation"], (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false));

                	$changecount = $changecount + $optionexporter[$collection['optionfoldertype']]->GetChangeCount();
		    }

            	    if($changecount > $collection["maxitems"]) {
                	$encoder->startTag(SYNC_MOREAVAILABLE, false, true);
            	    }

            	    // Output message changes per folder
            	    $encoder->startTag(SYNC_PERFORM);
                    
                    if ($onlyoptionbodypreference == false) {

                	$filtertype = isset($collection["filtertype"]) ? $collection["filtertype"] : 0;

                	// Stream the changes to the PDA
			$ids = array("readids" => (isset($collection["readids"]) ? $collection["readids"]: array()),
				     "flagids" => (isset($collection["flagids"]) ? $collection["flagids"]: array()));
			$importer = new ImportContentsChangesStream($encoder, GetObjectClassFromFolderClass($collection["class"]), false, $ids);

                	$n = 0;
                	while(1) {
                    	    $progress = $exporter->Synchronize();
                    	    if(!is_array($progress))
                        	break;
                    	    if ($importer->_lastObjectStatus == 1) 
                    		$n++;
			    debugLog("_lastObjectStatus = ".$importer->_lastObjectStatus);

                    	    if ($n >= $collection["maxitems"]) {
                    		debugLog("Exported maxItems of messages: ". $collection["maxitems"] . " - more available");
	                	break;
                    	    }

                	}
			
		    }
	
		    if (isset($collection['optionfoldertype']) &&
			$collection['optionfoldertype'] == "SMS" &&
			$n < $collection["maxitems"]) {
                	$filtertype = isset($collection["filtertype"]) ? $collection["filtertype"] : 0;
		    
                	// Stream the changes to the PDA
			$ids = array("readids" => (isset($collection[$collection['optionfoldertype']."readids"]) ? $collection[$collection['optionfoldertype']."readids"]: array()),
				     "flagids" => (isset($collection[$collection['optionfoldertype']."flagids"]) ? $collection[$collection['optionfoldertype']."flagids"]: array()));
			$optionimporter[$collection['optionfoldertype']] = new ImportContentsChangesStream($encoder, GetObjectClassFromFolderClass($collection["class"]), GetObjectClassFromFolderClass($collection["optionfoldertype"]), $ids);

                	$n = 0;
                	while(1) {
                    	    $progress = $optionexporter[$collection['optionfoldertype']]->Synchronize();
                    	    if(!is_array($progress))
                        	break;
                    	    if ($optionimporter[$collection['optionfoldertype']]->_lastObjectStatus == 1)
                    		$n++;
			    debugLog("_lastObjectStatus = ".$optionimporter[$collection['optionfoldertype']]->_lastObjectStatus);

                    	    if ($n >= $collection["maxitems"]) {
                    		debugLog("Exported maxItems of messages: ". $collection["maxitems"] . " - more available");
	                	break;
                    	    }

                	}
		    
		    }
		    		    
            	    $encoder->endTag();
                
                }

                $encoder->endTag();

                // Save the sync state for the next time
                if(isset($collection["newsynckey"])) {
                    if (isset($exporter) && $exporter)
                        $state = $exporter->GetState();

                    // nothing exported, but possible imported
                    else if (isset($importer) && $importer)
                        $state = $importer->GetState();

                    // if a new request without state information (hierarchy) save an empty state
                    else if ($collection["synckey"] == "0")
                        $state = "";

                    if (isset($state)) $statemachine->setSyncState($collection["newsynckey"], $state);
                    else debugLog("error saving " . $collection["newsynckey"] . " - no state information available");
                    
                    if (isset($collection["optionfoldertype"])) {
			unset($state);
                	if (isset($optionexporter[$collection["optionfoldertype"]]) && $optionexporter[$collection["optionfoldertype"]])
                    	    $state = $optionexporter[$collection["optionfoldertype"]]->GetState();

                	// nothing exported, but possible imported
                	else if (isset($optionimporter[$collection["optionfoldertype"]]) && $optionimporter[$collection["optionfoldertype"]])
                    	    $state = $optionimporter[$collection["optionfoldertype"]]->GetState();

                	// if a new request without state information (hierarchy) save an empty state
                	else if ($collection["synckey"] == "0")
                    	    $state = "";

                	if (isset($state)) $statemachine->setSyncState($collection["optionfoldertype"].$collection["newsynckey"], $state);
                	else debugLog("error saving " . $collection["optionfoldertype"].$collection["newsynckey"] . " - no state information available");
            	    }
                }
            
        	if (isset($collection["collectionid"])) {
		    if (isset($collection["newsynckey"])) 	
			$SyncCache['collections'][$collection["collectionid"]]["synckey"] = $collection["newsynckey"];
		    else
			$SyncCache['collections'][$collection["collectionid"]]["synckey"] = $collection["synckey"];
		    if (isset($collection["class"])) 		$SyncCache['collections'][$collection["collectionid"]]["class"] = $collection["class"];
		    if (isset($collection["optionfoldertype"])) $SyncCache['collections'][$collection["collectionid"]]["optionfoldertype"] = $collection["optionfoldertype"];
		    if (isset($collection["maxitems"])) 	$SyncCache['collections'][$collection["collectionid"]]["maxitems"] = $collection["maxitems"];
		    if (isset($collection["deletesasmoves"])) 	$SyncCache['collections'][$collection["collectionid"]]["deletesasmoves"] = $collection["deletesasmoves"];	
		    if (isset($collection["getchanges"])) 	$SyncCache['collections'][$collection["collectionid"]]["getchanges"] = $collection["getchanges"];	
		    if (isset($collection["filtertype"])) 	$SyncCache['collections'][$collection["collectionid"]]["filtertype"] = $collection["filtertype"];	
		    if (isset($collection["truncation"])) 	$SyncCache['collections'][$collection["collectionid"]]["truncation"] = $collection["truncation"];	
		    if (isset($collection["rtftruncation"])) 	$SyncCache['collections'][$collection["collectionid"]]["rtftruncation"] = $collection["rtftruncation"];	
		    if (isset($collection["mimesupport"])) 	$SyncCache['collections'][$collection["collectionid"]]["mimesupport"] = $collection["mimesupport"];	
		    if (isset($collection["mimetruncation"])) 	$SyncCache['collections'][$collection["collectionid"]]["mimetruncation"] = $collection["mimetruncation"];	
		    if (isset($collection["conflict"])) 	$SyncCache['collections'][$collection["collectionid"]]["conflict"] = $collection["conflict"];	
		    if (isset($collection["BodyPreference"])) 	$SyncCache['collections'][$collection["collectionid"]]["BodyPreference"] = $collection["BodyPreference"];	
		};
            }
        }
        $encoder->endTag();
    }
    $encoder->endTag();
    $statemachine->setSyncCache(serialize($SyncCache));

    return true;
}

function HandleGetItemEstimate($backend, $protocolversion, $devid) {
    global $zpushdtd;
    global $input, $output;

    $collections = array();

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    // Init state machine
    $statemachine = new StateMachine($devid);

    $SyncCache = unserialize($statemachine->getSyncCache());
    
    // Check the validity of the sync cache. If state is errornous set the syncstatus to 2 as retval for client
    $syncstatus=1;

    if(!$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE))
        return false;

    if(!$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERS))
        return false;

    while($decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDER)) {
        $collection = array();

	unset($class);
	unset($filtertype);
	unset($synckey);
	$conversationmode = false;
	while (($type = ($decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERTYPE)  ? SYNC_GETITEMESTIMATE_FOLDERTYPE :
	    		($decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERID)	? SYNC_GETITEMESTIMATE_FOLDERID :
			($decoder->getElementStartTag(SYNC_FILTERTYPE)	  		? SYNC_FILTERTYPE :
			($decoder->getElementStartTag(SYNC_SYNCKEY)	  		? SYNC_SYNCKEY :
			($decoder->getElementStartTag(SYNC_CONVERSATIONMODE)	 	? SYNC_CONVERSATIONMODE :
			-1)))))) != -1) {
	    switch ($type) {
		case SYNC_GETITEMESTIMATE_FOLDERTYPE :  
				$class = $decoder->getElementContent();
			        if(!$decoder->getElementEndTag())
			            return false;
			        break;
		case SYNC_GETITEMESTIMATE_FOLDERID :  
				$collectionid = $decoder->getElementContent();
			        if(!$decoder->getElementEndTag())
			            return false;
			        break;
		case SYNC_FILTERTYPE : 
				$filtertype = $decoder->getElementContent();
			        if(!$decoder->getElementEndTag())
			            return false;
			        break;
		case SYNC_SYNCKEY : 
				$synckey = $decoder->getElementContent();
			        if(!$decoder->getElementEndTag())
			            return false;
			        break;
		case SYNC_CONVERSATIONMODE : 
				if(($conversationmode = $decoder->getElementContent()) !== false) {
			    	    if(!$decoder->getElementEndTag())
			        	return false;
			        } else {
			    	    $conversationmode = true;
			        }
			        break;
	    };
	};

        if ($protocolversion >= 14.0 &&
    	    $decoder->getElementStartTag(SYNC_OPTIONS)) {
	    while (($type = ($decoder->getElementStartTag(SYNC_FOLDERTYPE)	? SYNC_FOLDERTYPE :
	    		    ($decoder->getElementStartTag(SYNC_MAXITEMS)	? SYNC_MAXITEMS :
			    ($decoder->getElementStartTag(SYNC_FILTERTYPE)	? SYNC_FILTERTYPE :
			-1)))) != -1) {
		switch ($type) {
		    case SYNC_FOLDERTYPE : 
				$foldertype= $decoder->getElementContent();
			        if(!$decoder->getElementEndTag())
			            return false;
			        break;
		    case SYNC_MAXITEMS : 
				$maxitems = $decoder->getElementContent();
			        if(!$decoder->getElementEndTag())
			            return false;
			        break;
		    case SYNC_FILTERTYPE : 
				$filtertype = $decoder->getElementContent();
			        if(!$decoder->getElementEndTag())
			            return false;
			        break;
		};
	    };

        }

        if(!$decoder->getElementEndTag())
            return false;

        // compatibility mode - get folderid from the state directory
        if (!isset($collectionid)) {
            $collectionid = _getFolderID($devid, $class);
        }

	if ($protocolversion >= 12.1 && !isset($class)) {
	    $class = $SyncCache['folders'][$collectionid]['class'];
	} else if ($protocolversion >= 12.1)  {
	    $SyncCache['folders'][$collectionid]['class'] = $class;
	}
	if ($protocolversion >= 12.1 && !isset($filtertype)) {
	    debugLog("filtertype not set! SyncCache Result ".$SyncCache['collections'][$collectionid]['filtertype']);
	    $filtertype = $SyncCache['collections'][$collectionid]['filtertype'];
	} else if ($protocolversion >= 12.1)  {
	    $SyncCache['collections'][$collectionid]['filtertype'] = $filtertype;
	}
	if ($protocolversion >= 12.1 && !isset($synckey)) {
	    $synckey = $SyncCache['collections'][$collectionid]['synckey'];
	} else if ($protocolversion >= 12.1) {
	    $SyncCache['collections'][$collectionid]['synckey'] = $synckey;
	}
	if ($protocolversion >= 12.1 && !isset($conversationmode)) {
	    $conversationmode = $SyncCache['collections'][$collectionid]['conversationmode'];
	} else if ($protocolversion >= 12.1) {
	    $SyncCache['collections'][$collectionid]['conversationmode'] = $conversationmode;
	}


        $collection = array();
        $collection["synckey"] = $synckey;
    	$collection["class"] = $class;
        $collection["filtertype"] = $filtertype;
        $collection["collectionid"] = $collectionid;

        array_push($collections, $collection);
    }

    $encoder->startWBXML();

    $encoder->startTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE);
    {
        foreach($collections as $collection) {
            $encoder->startTag(SYNC_GETITEMESTIMATE_RESPONSE);
            {
                $importer = new ImportContentsChangesMem();

                $statemachine = new StateMachine($devid);
                $syncstate = $statemachine->getSyncState($collection["synckey"]);
		$syncstatus = 1;
		if(is_numeric($syncstate) &&
		    $syncstate < 0) {
		    debugLog("GetSyncState: Got an error in HandleGetItemEstimate");
		    $syncstate = false;
		    if ($collection["synckey"] != '0') $syncstatus = 2;
		} 

                $exporter = $backend->GetExporter($collection["collectionid"]);
                $exporter->Config($importer, $collection["class"], $collection["filtertype"], $syncstate, 0, 0, false);

		$changecount = $exporter->GetChangeCount();
		if ($changecount === false) {
		    $syncstatus=2;
		}
                
                $encoder->startTag(SYNC_GETITEMESTIMATE_STATUS);
                $encoder->content($syncstatus);
                $encoder->endTag();
                
                $encoder->startTag(SYNC_GETITEMESTIMATE_FOLDER);
                {
		    if ($protocolversion <= 12.0) {
		        $encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERTYPE);
			debugLog("Collection Class is ".$collection["class"]);
                	$encoder->content($collection["class"]);
            		$encoder->endTag();
		    };

                    $encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERID);
                    $encoder->content($collection["collectionid"]);
                    $encoder->endTag();

                    $encoder->startTag(SYNC_GETITEMESTIMATE_ESTIMATE);

                    $encoder->content($changecount);

                    $encoder->endTag();
                }
                $encoder->endTag();
            }
            $encoder->endTag();
        }
    }
    $encoder->endTag();
    $statemachine->setSyncCache(serialize($SyncCache));

    return true;
}

function HandleGetAttachment($backend, $protocolversion) {
    $attname = $_GET["AttachmentName"];

    if(!isset($attname))
        return false;

    header("Content-Type: application/octet-stream");

    $backend->GetAttachmentData($attname);

    return true;
}

function _HandlePingError($errorcode, $limit = false) {
    global $zpushdtd;
    global $output;

    $encoder = new WBXMLEncoder($output, $zpushdtd);
    $encoder->StartWBXML();
    $encoder->startTag(SYNC_PING_PING);
    $encoder->startTag(SYNC_PING_STATUS);
    $encoder->content($errorcode);
    $encoder->endTag();
    if ($limit !== false) {
	$encoder->startTag(SYNC_PING_LIFETIME);
	$encoder->content($limit);
	$encoder->endTag();
    }
    $encoder->endTag();
}

function HandlePing($backend, $devid) {
    global $zpushdtd, $input, $output;
    global $user, $auth_pw;
    $timeout = 10;

    debugLog("Ping received");

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    $collections = array();
    $lifetime = 0;
    $pingfiletime = false;

    // Get previous defaults if they exist
    $file = BASE_PATH . STATE_DIR . "/" . strtolower($devid) . "/". $devid;
    if (file_exists($file)) {
        $ping = unserialize(file_get_contents($file));
        $collections = $ping["collections"];
        $lifetime = $ping["lifetime"];
	$pingfiletime = filemtime($file);
    }

    if($decoder->getElementStartTag(SYNC_PING_PING)) {
        debugLog("Ping init");
        if($decoder->getElementStartTag(SYNC_PING_LIFETIME)) {
            $lifetime = $decoder->getElementContent();
            $decoder->getElementEndTag();
        }

        if($decoder->getElementStartTag(SYNC_PING_FOLDERS)) {
            // avoid ping init if not necessary
            $saved_collections = $collections;

            $collections = array();

            while($decoder->getElementStartTag(SYNC_PING_FOLDER)) {
                $collection = array();

                if($decoder->getElementStartTag(SYNC_PING_SERVERENTRYID)) {
                    $collection["serverid"] = $decoder->getElementContent();
                    $decoder->getElementEndTag();
                }
                if($decoder->getElementStartTag(SYNC_PING_FOLDERTYPE)) {
                    $collection["class"] = $decoder->getElementContent();
                    $decoder->getElementEndTag();
                }

                $decoder->getElementEndTag();

                // initialize empty state
                $collection["state"] = "";

                // try to find old state in saved states
                foreach ($saved_collections as $saved_col) {
                    if ($saved_col["serverid"] == $collection["serverid"] && $saved_col["class"] == $collection["class"]) {
                        $collection["state"] = $saved_col["state"];
                        debugLog("reusing saved state for ". $collection["class"]);
                        break;
                    }
                }

                if ($collection["state"] == "")
                    debugLog("empty state for ". $collection["class"]);

                // Create start state for this collection
                $exporter = $backend->GetExporter($collection["serverid"]);
                $importer = false;
                $exporter->Config($importer, false, false, $collection["state"], BACKEND_DISCARD_DATA, 0, (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false));
                while(is_array($exporter->Synchronize()));
                $collection["state"] = $exporter->GetState();
                array_push($collections, $collection);
            }

            if(!$decoder->getElementEndTag())
                return false;
        }

        if(!$decoder->getElementEndTag())
            return false;
    }

    $changes = array();
    $dataavailable = false;

    if ($lifetime < 60) {
	_HandlePingError("5","60");
	debugLog("Lifetime lower than 60 Seconds. This violates the protocol spec. (STATUS = 5, LIMIT min = 60)");
	return true;		    
    }
    if ($lifetime > (REAL_SCRIPT_TIMEOUT-600)) {
	_HandlePingError("5",(REAL_SCRIPT_TIMEOUT-600));
	debugLog("Lifetime larger than ".(REAL_SCRIPT_TIMEOUT-600)." Seconds. This violates the protocol spec. (STATUS = 5, LIMIT max = ".(REAL_SCRIPT_TIMEOUT-600).")");
	return true;		    
    }

    debugLog("Waiting for changes... (lifetime $lifetime)");
    // Wait for something to happen
    for($n=0;$n<$lifetime / $timeout; $n++ ) {
	$file = BASE_PATH . STATE_DIR . "/" . strtolower($devid) . "/". $devid;
	
	if (filemtime($file) > $pingfiletime) {
	    debugLog("Another ping started so this process exits now");
	    return true;
	}

        //check the remote wipe status
        if (PROVISIONING === true) {
	    $rwstatus = $backend->getDeviceRWStatus($user, $auth_pw, $devid);
	    if ($rwstatus == SYNC_PROVISION_RWSTATUS_PENDING || $rwstatus == SYNC_PROVISION_RWSTATUS_WIPED) {
	        //return 7 because it forces folder sync
	        $pingstatus = 7;
	        break;
	    }
        }

        if(count($collections) == 0) {
            $error = 1;
            break;
        }

        for($i=0;$i<count($collections);$i++) {
            $collection = $collections[$i];

            $exporter = $backend->GetExporter($collection["serverid"]);
            $state = $collection["state"];
            $importer = false;
            $ret = $exporter->Config($importer, false, false, $state, BACKEND_DISCARD_DATA, 0, (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false));

            // stop ping if exporter can not be configured (e.g. after Zarafa-server restart)
            if ($ret === false ) {
                // force "ping" to stop
                $n = $lifetime / $timeout;
                debugLog("Ping error: Exporter can not be configured. Waiting 30 seconds before ping is retried.");
                sleep(30);
                break;
            }

            $changecount = $exporter->GetChangeCount();

            if($changecount > 0) {
                $dataavailable = true;
                $changes[$collection["serverid"]] = $changecount;
            }

            // Discard any data
            while(is_array($exporter->Synchronize()));

            // Record state for next Ping
            $collections[$i]["state"] = $exporter->GetState();
        }

        if($dataavailable) {
            debugLog("Found change");
            break;
        }

        sleep($timeout);
    }

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_PING_PING);
    {
        $encoder->startTag(SYNC_PING_STATUS);
        if(isset($error))
            $encoder->content(3);
        elseif (isset($pingstatus))
            $encoder->content($pingstatus);
        else
            $encoder->content(count($changes) > 0 ? 2 : 1);
        $encoder->endTag();

        $encoder->startTag(SYNC_PING_FOLDERS);
        foreach($collections as $collection) {
            if(isset($changes[$collection["serverid"]])) {
                $encoder->startTag(SYNC_PING_FOLDER);
                $encoder->content($collection["serverid"]);
                $encoder->endTag();
            }
        }
        $encoder->endTag();
    }
    $encoder->endTag();

    // Save the ping request state for this device
    file_put_contents(BASE_PATH . "/" . STATE_DIR . "/" . strtolower($devid). "/" . $devid, serialize(array("lifetime" => $lifetime, "collections" => $collections)));

    return true;
}

function HandleSendMail($backend, $protocolversion) {
    // All that happens here is that we receive an rfc822 message on stdin
    // and just forward it to the backend. We provide no output except for
    // an OK http reply
    global $zpushdtd;
    global $input, $output;

    $data['task'] = 'new';
    $result = 1;
    if($protocolversion >= 14.0) {
	$decoder = new WBXMLDecoder($input, $zpushdtd);
	$encoder = new WBXMLEncoder($output, $zpushdtd);

        if(!$decoder->getElementStartTag(SYNC_COMPOSEMAIL_SENDMAIL))
	    $result = 102;
	while (($tag = 	($decoder->getElementStartTag(SYNC_COMPOSEMAIL_SAVEINSENTITEMS) 	? SYNC_COMPOSEMAIL_SAVEINSENTITEMS :
			($decoder->getElementStartTag(SYNC_COMPOSEMAIL_CLIENTID) 		? SYNC_COMPOSEMAIL_CLIENTID :
			($decoder->getElementStartTag(SYNC_COMPOSEMAIL_MIME)			? SYNC_COMPOSEMAIL_MIME :
			-1)))) != -1 &&
		$result == 1) {
	    switch ($tag) {
		case SYNC_COMPOSEMAIL_SAVEINSENTITEMS : 
			    $data['saveinsentitems'] = true; 
			    break;
		case SYNC_COMPOSEMAIL_CLIENTID :
    			    $data['clientid'] = $decoder->getElementContent();
    			    if(!$decoder->getElementEndTag())
				$result = 102;
    			    break;
		case SYNC_COMPOSEMAIL_MIME :
			    $mime = $decoder->getElementContent();
    			    if(!$decoder->getElementEndTag())
				$result = 102;
    			    break;
	    }
	}
	if (!isset($data['clientid'])) 
	    $result = 103;

        if(!$decoder->getElementEndTag()) // End Sendmail
	    $result = 102;

	$rfc822 = $mime;
	if ($result == 1)
	    $result = $backend->SendMail($rfc822, $data, $protocolversion);
        $encoder->startWBXML();
	$encoder->startTag(SYNC_COMPOSEMAIL_SENDMAIL);
	$encoder->startTag(SYNC_COMPOSEMAIL_STATUS);
        $encoder->content(($result === true ? "1" : $result));
        $encoder->endTag();
        $encoder->endTag();
    } else {
        $rfc822 = readStream($input);
	$result = $backend->SendMail($rfc822, $data, $protocolversion);
    };

    return $result;
}

function HandleSmartForward($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;
    // SmartForward is a normal 'send' except that you should attach the
    // original message which is specified in the URL

    $data['task'] = 'forward';    
    $result = 1;
    if($protocolversion >= 14.0) {
	$decoder = new WBXMLDecoder($input, $zpushdtd);
	$encoder = new WBXMLEncoder($output, $zpushdtd);

        if(!$decoder->getElementStartTag(SYNC_COMPOSEMAIL_SMARTFORWARD))
	    $result = 102;
	while (($tag = 	($decoder->getElementStartTag(SYNC_COMPOSEMAIL_SAVEINSENTITEMS) 	? SYNC_COMPOSEMAIL_SAVEINSENTITEMS :
			($decoder->getElementStartTag(SYNC_COMPOSEMAIL_CLIENTID) 		? SYNC_COMPOSEMAIL_CLIENTID :
			($decoder->getElementStartTag(SYNC_COMPOSEMAIL_MIME)			? SYNC_COMPOSEMAIL_MIME :
			($decoder->getElementStartTag(SYNC_COMPOSEMAIL_REPLACEMIME)		? SYNC_COMPOSEMAIL_REPLACEMIME :
			($decoder->getElementStartTag(SYNC_COMPOSEMAIL_SOURCE)			? SYNC_COMPOSEMAIL_SOURCE :
			-1)))))) != -1 &&
		$result == 1) {
	    switch ($tag) {
		case SYNC_COMPOSEMAIL_SAVEINSENTITEMS : 
			    $data['saveinsentitems'] = true; 
			    break;
		case SYNC_COMPOSEMAIL_CLIENTID :
    			    $data['clientid'] = $decoder->getElementContent();
    			    if(!$decoder->getElementEndTag())
				$result = 102;
    			    break;
		case SYNC_COMPOSEMAIL_MIME :
			    $mime = $decoder->getElementContent();
    			    if(!$decoder->getElementEndTag())
				$result = 102;
    			    break;
		case SYNC_COMPOSEMAIL_SOURCE :
			    while (($tag = 	($decoder->getElementStartTag(SYNC_COMPOSEMAIL_FOLDERID) 	? SYNC_COMPOSEMAIL_FOLDERID :
						($decoder->getElementStartTag(SYNC_COMPOSEMAIL_ITEMID) 		? SYNC_COMPOSEMAIL_ITEMID :
						($decoder->getElementStartTag(SYNC_COMPOSEMAIL_LONGID)		? SYNC_COMPOSEMAIL_LONGID :
						($decoder->getElementStartTag(SYNC_COMPOSEMAIL_INSTANCEID)	? SYNC_COMPASEMAIL_INSTANCEID :
						-1))))) != -1 &&
				    $result == 1) {
				switch ($tag) {
				    case SYNC_COMPOSEMAIL_FOLDERID :
					    $data['folderid'] = $decoder->getElementContent();
					    if(!$decoder->getElementEndTag())
						$result = 102;
					    break;
				    case SYNC_COMPOSEMAIL_ITEMID :
					    $data['itemid'] = $decoder->getElementContent();
					    if(!$decoder->getElementEndTag())
						$result = 102;
					    break;
				    case SYNC_COMPOSEMAIL_LONGID :
					    $data['longid'] = $decoder->getElementContent();
					    if(!$decoder->getElementEndTag())
						$result = 102;
					    break;
				    case SYNC_COMPOSEMAIL_INSTANCEID :
					    $data['instanceid'] = $decoder->getElementContent();
					    if(!$decoder->getElementEndTag())
						$result = 102;
					    break;
				}
			    }
			    if ((isset($data['folderid']) && !isset($data['itemid'])) ||
				(!isset($data['folderid']) && isset($data['itemid'])) ||
				(isset($data['longid']) && (isset($data['folderid']) || isset($data['itemid']) || isset($data['instanceid']))))
				$result = 103;
    			    if(!$decoder->getElementEndTag()) // End Source
				$result = 102;
			    break;
		case SYNC_COMPOSEMAIL_REPLACEMIME :
			    $data['replacemime'] = $decoder->getElementContent();
    			    if(!$decoder->getElementEndTag())
				$result = 102;
			    break;
	    }
	}
	if (!isset($data['clientid'])) 
	    $result = 103;

        if(!$decoder->getElementEndTag()) // End SmartReply
	    $result = 102;

	$rfc822 = $mime;
	if ($result == 1)
	    $result = $backend->SendMail($rfc822, $data, $protocolversion);
        $encoder->startWBXML();
	$encoder->startTag(SYNC_COMPOSEMAIL_SMARTFORWARD);
	$encoder->startTag(SYNC_COMPOSEMAIL_STATUS);
        $encoder->content(($result === true ? "1" : $result));
        $encoder->endTag();
        $encoder->endTag();
    } else {
	if(isset($_GET["ItemId"]))
    	    $data['itemid'] = $_GET["ItemId"];
        else
	    $data['itemid'] = false;

        if(isset($_GET["CollectionId"]))
	    $data['folderid'] = $_GET["CollectionId"];
        else
	    $data['folderid'] = false;
        $rfc822 = readStream($input);
	$result = $backend->SendMail($rfc822, $data, $protocolversion);
    };
    	
    return $result;
}

function HandleSmartReply($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    // Smart reply should add the original message to the end of the message body

    // In some way there could be a header in XML and not only in _GET...

    $data['task'] = 'reply';    
    $result = 1;
    if($protocolversion >= 14.0) {
	$decoder = new WBXMLDecoder($input, $zpushdtd);
	$encoder = new WBXMLEncoder($output, $zpushdtd);

        if(!$decoder->getElementStartTag(SYNC_COMPOSEMAIL_SMARTREPLY))
	    $result = 102;
	while (($tag = 	($decoder->getElementStartTag(SYNC_COMPOSEMAIL_SAVEINSENTITEMS) 	? SYNC_COMPOSEMAIL_SAVEINSENTITEMS :
			($decoder->getElementStartTag(SYNC_COMPOSEMAIL_CLIENTID) 		? SYNC_COMPOSEMAIL_CLIENTID :
			($decoder->getElementStartTag(SYNC_COMPOSEMAIL_MIME)			? SYNC_COMPOSEMAIL_MIME :
			($decoder->getElementStartTag(SYNC_COMPOSEMAIL_REPLACEMIME)		? SYNC_COMPOSEMAIL_REPLACEMIME :
			($decoder->getElementStartTag(SYNC_COMPOSEMAIL_SOURCE)			? SYNC_COMPOSEMAIL_SOURCE :
			-1)))))) != -1 &&
		$result == 1) {
	    switch ($tag) {
		case SYNC_COMPOSEMAIL_SAVEINSENTITEMS : 
			    $data['saveinsentitems'] = true; 
			    break;
		case SYNC_COMPOSEMAIL_CLIENTID :
    			    $data['clientid'] = $decoder->getElementContent();
    			    if(!$decoder->getElementEndTag())
				$result = 102;
    			    break;
		case SYNC_COMPOSEMAIL_MIME :
			    $mime = $decoder->getElementContent();
    			    if(!$decoder->getElementEndTag())
				$result = 102;
    			    break;
		case SYNC_COMPOSEMAIL_SOURCE :
			    while (($tag = 	($decoder->getElementStartTag(SYNC_COMPOSEMAIL_FOLDERID) 	? SYNC_COMPOSEMAIL_FOLDERID :
						($decoder->getElementStartTag(SYNC_COMPOSEMAIL_ITEMID) 		? SYNC_COMPOSEMAIL_ITEMID :
						($decoder->getElementStartTag(SYNC_COMPOSEMAIL_LONGID)		? SYNC_COMPOSEMAIL_LONGID :
						($decoder->getElementStartTag(SYNC_COMPOSEMAIL_INSTANCEID)	? SYNC_COMPASEMAIL_INSTANCEID :
						-1))))) != -1 &&
				    $result == 1) {
				switch ($tag) {
				    case SYNC_COMPOSEMAIL_FOLDERID :
					    $data['folderid'] = $decoder->getElementContent();
					    if(!$decoder->getElementEndTag())
    						$result = 102;
					    break;
				    case SYNC_COMPOSEMAIL_ITEMID :
					    $data['itemid'] = $decoder->getElementContent();
					    if(!$decoder->getElementEndTag())
    						$result = 102;
					    break;
				    case SYNC_COMPOSEMAIL_LONGID :
					    $data['longid'] = $decoder->getElementContent();
					    if(!$decoder->getElementEndTag())
    						$result = 102;
					    break;
				    case SYNC_COMPOSEMAIL_INSTANCEID :
					    $data['instanceid'] = $decoder->getElementContent();
					    if(!$decoder->getElementEndTag())
    						$result = 102;
					    break;
				}
			    }
			    if ((isset($data['folderid']) && !isset($data['itemid'])) ||
				(!isset($data['folderid']) && isset($data['itemid'])) ||
				(isset($data['longid']) && (isset($data['folderid']) || isset($data['itemid']) || isset($data['instanceid']))))
				$result = 103;
    			    if(!$decoder->getElementEndTag()) // End Source
    				$result = 102;
			    break;
		case SYNC_COMPOSEMAIL_REPLACEMIME :
			    $data['replacemime'] = $decoder->getElementContent();
    			    if(!$decoder->getElementEndTag())
    				$result = 102;
			    break;
	    }
	}
	if (!isset($data['clientid'])) 
	    $result = 103;

        if(!$decoder->getElementEndTag()) // End SmartReply
	    $result = 102;

	$rfc822 = $mime;
	if ($result == 1)
	    $result = $backend->SendMail($rfc822, $data, $protocolversion);
        $encoder->startWBXML();
	$encoder->startTag(SYNC_COMPOSEMAIL_SMARTREPLY);
	$encoder->startTag(SYNC_COMPOSEMAIL_STATUS);
        $encoder->content(($result === true ? "1" : $result));
        $encoder->endTag();
        $encoder->endTag();
    } else {
	if(isset($_GET["ItemId"]))
            $data['itemid'] = $_GET["ItemId"];
        else
            $data['itemid'] = false;

        if(isset($_GET["CollectionId"]))
	    $data['folderid'] = $_GET["CollectionId"];
        else
	    $data['folderid'] = false;

	$rfc822 = readStream($input);
	$result = $backend->SendMail($rfc822, $data, $protocolversion);
    }

    return $result;
}

function HandleFolderCreate($backend, $devid, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    $el = $decoder->getElement();

    if($el[EN_TYPE] != EN_TYPE_STARTTAG)
        return false;

    $create = $update = $delete = false;

    if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERCREATE)
        $create = true;
    else if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERUPDATE)
        $update = true;
    else if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERDELETE)
        $delete = true;

    if(!$create && !$update && !$delete)
        return false;

    // SyncKey
    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SYNCKEY))
        return false;
    $synckey = $decoder->getElementContent();
    if(!$decoder->getElementEndTag())
        return false;

    // ServerID
    $serverid = false;
    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID)) {
        $serverid = $decoder->getElementContent();
        if(!$decoder->getElementEndTag())
            return false;
    }

    // when creating or updating more information is necessary
    if (!$delete) {
	    // Parent
	    $parentid = false;
	    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_PARENTID)) {
	        $parentid = $decoder->getElementContent();
	        if(!$decoder->getElementEndTag())
	            return false;
	    }
	
	    // Displayname
	    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_DISPLAYNAME))
	        return false;
	    $displayname = $decoder->getElementContent();
	    if(!$decoder->getElementEndTag())
	        return false;
	
	    // Type
	    $type = false;
	    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_TYPE)) {
	        $type = $decoder->getElementContent();
	        if(!$decoder->getElementEndTag())
	            return false;
	    }
    }

    if(!$decoder->getElementEndTag())
        return false;

    // Get state of hierarchy
    $statemachine = new StateMachine($devid);
    $syncstate = $statemachine->getSyncState($synckey);
    if (is_numeric($syncstate) &&
	$syncstate < 0) {
	debugLog("GetSyncState: Got an error in HandleGetFolderCreate - syncstate");
	$syncstate = false;
    } 
    $newsynckey = $statemachine->getNewSyncKey($synckey);

    // additional information about already seen folders
    $seenfolders = $statemachine->getSyncState("s".$synckey);
    if ($synckey != "0" &&
	is_numeric($seenfolders) &&
	$seenfolders < 0) {
	debugLog("GetSyncState: Got an error in HandleGetFolderCreate - seenfolders");
	$seenfolders = false;
    } 
    $seenfolders = unserialize($seenfolders);;
    if (!$seenfolders) $seenfolders = array();

    // get the foldercache from synccache
    $foldercache = unserialize($statemachine->getSyncCache());
    if (!$delete && !$create) {
	debugLog("Here1 folder create serverid: ".$serverid." type: ".$type." displayname: ".$displayname." parentid: ".$parentid);
	if (!isset($serverid) || $serverid === false) return false;
	if ($type === false && isset($foldercache['folders'][$serverid]['type'])) 
	    $type = $foldercache['folders'][$serverid]['type'];
	if ($displayname === false && isset($foldercache['folders'][$serverid]['displayname'])) 
	    $displayname = $foldercache['folders'][$serverid]['displayname'];
	if ($parentid === false && isset($foldercache['folders'][$serverid]['parentid'])) 
	    $parentid = $foldercache['folders'][$serverid]['parentid'];
	if ($type === false || $displayname === false || $parentid === false) return false;
	debugLog("Here2 folder create serverid: ".$serverid." type: ".$type." displayname: ".$displayname." parentid: ".$parentid);
    }
    // Configure importer with last state
    $importer = $backend->GetHierarchyImporter();
    $importer->Config($syncstate);

    if (!$delete) {
	    // Send change
	    $serverid = $importer->ImportFolderChange($serverid, $parentid, $displayname, $type);

	    // add the folderinfo to synccache
	    $statemachine->updateSyncCacheFolder($foldercache, $serverid, $parentid, $displayname, $type);

    }
    else {
    	// delete folder
    	$deletedstat = $importer->ImportFolderDeletion($serverid, 0);
	// remove the folder from synccache
	$statemachine->deleteSyncCacheFolder($foldercache,$serverid);
    }
                   
    $encoder->startWBXML();
    if ($create) {
    	// add folder id to the seen folders
        $seenfolders[] = $serverid;
        
        $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERCREATE);
        {
            {
                $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
                $encoder->content(1);
                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                $encoder->content($newsynckey);
                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID);
                $encoder->content($serverid);
                $encoder->endTag();
            }
            $encoder->endTag();
        }
        $encoder->endTag();
    }

    elseif ($update) {

        $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERUPDATE);
        {
            {
                $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
                $encoder->content(1);
                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                $encoder->content($newsynckey);
                $encoder->endTag();
            }
            $encoder->endTag();
        }
    }
    elseif ($delete) {

        $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERDELETE);
        {
            {
                $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
                $encoder->content($deletedstat);
                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                $encoder->content($newsynckey);
                $encoder->endTag();
            }
            $encoder->endTag();
        }
        
        // remove folder from the folderflags array
        if (($sid = array_search($serverid, $seenfolders)) !== false) {
            unset($seenfolders[$sid]);
            $seenfolders = array_values($seenfolders);
            debugLog("deleted from seenfolders: ". $serverid);    
        }
    }   

    $encoder->endTag();
    // Save the sync state for the next time
    $statemachine->setSyncState($newsynckey, $importer->GetState());
    $statemachine->setSyncState("s".$newsynckey, serialize($seenfolders));
    $statemachine->setSyncCache(serialize($foldercache));
    
    return true;
}

// Handle meetingresponse method
function HandleMeetingResponse($backend, $protocolversion) {
    global $zpushdtd;
    global $output, $input;

    $requests = Array();

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_MEETINGRESPONSE_MEETINGRESPONSE))
        return false;

    while($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_REQUEST)) {
        $req = Array();

        if($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_USERRESPONSE)) {
            $req["response"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        if($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_FOLDERID)) {
            $req["folderid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        if($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_REQUESTID)) {
            $req["requestid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        if(!$decoder->getElementEndTag())
            return false;

        array_push($requests, $req);
    }

    if(!$decoder->getElementEndTag())
        return false;

    // Start output, simply the error code, plus the ID of the calendar item that was generated by the
    // accept of the meeting response

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_MEETINGRESPONSE_MEETINGRESPONSE);

    foreach($requests as $req) {
        $calendarid = "";
        $ok = $backend->MeetingResponse($req["requestid"], $req["folderid"], $req["response"], $calendarid);
        $encoder->startTag(SYNC_MEETINGRESPONSE_RESULT);
            $encoder->startTag(SYNC_MEETINGRESPONSE_REQUESTID);
                $encoder->content($req["requestid"]);
            $encoder->endTag();

            $encoder->startTag(SYNC_MEETINGRESPONSE_STATUS);
                $encoder->content($ok ? 1 : 2);
            $encoder->endTag();

            if($ok) {
                $encoder->startTag(SYNC_MEETINGRESPONSE_CALENDARID);
                    $encoder->content($calendarid);
                $encoder->endTag();
            }

        $encoder->endTag();
    }

    $encoder->endTag();

    return true;
}

function HandleFolderUpdate($backend, $devid, $protocolversion) {
    return HandleFolderCreate($backend, $devid, $protocolversion);
}

function HandleFolderDelete($backend, $devid, $protocolversion) {
    return HandleFolderCreate($backend, $devid, $protocolversion);
}

function HandleProvision($backend, $devid, $protocolversion) {
    global $user, $auth_pw, $policykey;

    global $zpushdtd, $policies;
    global $output, $input;

    $status = SYNC_PROVISION_STATUS_SUCCESS;

    $phase2 = true;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_PROVISION_PROVISION))
        return false;

    //handle android remote wipe.
    if ($decoder->getElementStartTag(SYNC_PROVISION_REMOTEWIPE)) {
        if(!$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
            return false;

        $status = $decoder->getElementContent();

        if(!$decoder->getElementEndTag())
            return false;

        if(!$decoder->getElementEndTag())
            return false;
    }

    else {

        if(!$decoder->getElementStartTag(SYNC_PROVISION_POLICIES))
            return false;

        if(!$decoder->getElementStartTag(SYNC_PROVISION_POLICY))
            return false;

        if(!$decoder->getElementStartTag(SYNC_PROVISION_POLICYTYPE))
            return false;

        $policytype = $decoder->getElementContent();
// START CHANGED dw2412 Support V12.0
	if ($protocolversion >= 12.0) {
    	    if ($policytype != 'MS-EAS-Provisioning-WBXML') {
        	$status = SYNC_PROVISION_STATUS_SERVERERROR;
    	    }
    	} else {
    	    if ($policytype != 'MS-WAP-Provisioning-XML') {
        	$status = SYNC_PROVISION_STATUS_SERVERERROR;
    	    }
        }
// END CHANGED dw2412 Support V12.0
        if(!$decoder->getElementEndTag()) //policytype
            return false;

        if ($decoder->getElementStartTag(SYNC_PROVISION_POLICYKEY)) {
            $devpolicykey = $decoder->getElementContent();

            if(!$decoder->getElementEndTag())
                return false;

            if(!$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
                return false;

            $status = $decoder->getElementContent();
            //do status handling
            $status = SYNC_PROVISION_STATUS_SUCCESS;

            if(!$decoder->getElementEndTag())
                return false;

            $phase2 = false;
        }

        if(!$decoder->getElementEndTag()) //policy
            return false;

        if(!$decoder->getElementEndTag()) //policies
            return false;

        if ($decoder->getElementStartTag(SYNC_PROVISION_REMOTEWIPE)) {
            if(!$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
                return false;

            $status = $decoder->getElementContent();

            if(!$decoder->getElementEndTag())
                return false;

            if(!$decoder->getElementEndTag())
                return false;
        }
    }
    if(!$decoder->getElementEndTag()) //provision
        return false;

    $encoder->StartWBXML();

    //set the new final policy key in the backend
    if (!$phase2) {
        $policykey = $backend->generatePolicyKey();
        $backend->setPolicyKey($policykey, $devid);
    } else {
        $policykey = $backend->generatePolicyKey();
    }

    $encoder->startTag(SYNC_PROVISION_PROVISION);
    {
        $encoder->startTag(SYNC_PROVISION_STATUS);
            $encoder->content($status);
        $encoder->endTag();

        $encoder->startTag(SYNC_PROVISION_POLICIES);
            $encoder->startTag(SYNC_PROVISION_POLICY);

            $encoder->startTag(SYNC_PROVISION_POLICYTYPE);
                   $encoder->content($policytype);
            $encoder->endTag();

            $encoder->startTag(SYNC_PROVISION_STATUS);
                $encoder->content($status);
            $encoder->endTag();

            $encoder->startTag(SYNC_PROVISION_POLICYKEY);
                   $encoder->content($policykey);
            $encoder->endTag();

            if ($phase2) {
                $encoder->startTag(SYNC_PROVISION_DATA);
                if ($policytype == 'MS-WAP-Provisioning-XML') {
                    $encoder->content('<wap-provisioningdoc><characteristic type="SecurityPolicy"><parm name="4131" value="1"/><parm name="4133" value="1"/></characteristic></wap-provisioningdoc>');
/* dw2412 maybe we can make use of this later on in as2.5 proivsioning.
        	    <characteristic type="Registry">
// 0 = no frequency 1 = set and take minutes from FrequencyValue
                        <characteristic type="HKLM\Comm\Security\Policy\LASSD\AE\{50C13377-C66D-400C-889E-C316FC4AB374}">
                            <parm name="AEFrequencyType" value="1"/>
                            <parm name="AEFrequencyValue" value="3"/>
                        </characteristic>
// Wipe after n unsuccessful password entries.
                	<characteristic type="HKLM\Comm\Security\Policy\LASSD">
                    	    <parm name="DeviceWipeThreshold" value="6"/>
            		</characteristic>
// Show password reminder after n attemps
                	<characteristic type="HKLM\Comm\Security\Policy\LASSD">
                    	    <parm name="CodewordFrequency" value="3"/>
                	</characteristic>
// if not send there is no PIN required
                        <characteristic type="HKLM\Comm\Security\Policy\LASSD\LAP\lap_pw">
                            <parm name="MinimumPasswordLength" value="5"/>
                        </characteristic>
// 0 = require alphanum, 1 = require numeric, 2 = anything
                        <characteristic type="HKLM\Comm\Security\Policy\LASSD\LAP\lap_pw">
                            <parm name="PasswordComplexity" value="2"/>
                        </characteristic>
                    </characteristic>
*/
                } else if ($policytype == 'MS-EAS-Provisioning-WBXML') {
		    $encoder->startTag('Provision:EASProvisionDoc');
		    $encoder->startTag('Provision:DevicePasswordEnabled');$encoder->content('0');$encoder->endTag();
		    $encoder->startTag('Provision:AlphanumericDevicePasswordRequired');$encoder->content('0');$encoder->endTag();
		    $encoder->startTag('Provision:PasswordRecoveryEnabled');$encoder->content('1');$encoder->endTag();
		    $encoder->startTag('Provision:DeviceEncryptionEnabled');$encoder->content('0');$encoder->endTag();
		    $encoder->startTag('Provision:AttachmentsEnabled');$encoder->content('1');$encoder->endTag();
		    $encoder->startTag('Provision:MinDevicePasswordLength');$encoder->content('1');$encoder->endTag();
		    $encoder->startTag('Provision:MaxInactivityTimeDeviceLock');$encoder->content('0');$encoder->endTag();
		    $encoder->startTag('Provision:MaxDevicePasswordFailedAttempts');$encoder->content('5');$encoder->endTag();
		    $encoder->startTag('Provision:MaxAttachmentSize');$encoder->content('5000000');$encoder->endTag();
		    $encoder->startTag('Provision:AllowSimpleDevicePassword');$encoder->content('1');$encoder->endTag();
		    $encoder->startTag('Provision:DevicePasswordExpiration');$encoder->content('');$encoder->endTag();
		    $encoder->startTag('Provision:DevicePasswordHistory');$encoder->content('0');$encoder->endTag();
		    if ($protocolversion >= 12.1) {
			$encoder->startTag('Provision:AllowStorageCard');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowCamera');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:RequireDeviceEncryption');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:AllowUnsignedApplications');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowUnsignedInstallationPackages');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:MinDevicePasswordComplexCharacters');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:AllowWiFi');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowTextMessaging');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowPOPIMAPEmail');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowBluetooth');$encoder->content('2');$encoder->endTag();
			$encoder->startTag('Provision:AllowIrDA');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:RequireManualSyncWhenRoaming');$encoder->content('0');$encoder->endTag(); // Set to one in case you'd like to save money...
			$encoder->startTag('Provision:AllowDesktopSync');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:MaxCalendarAgeFilter');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:AllowHTMLEmail');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:MaxEmailAgeFilter');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:MaxEmailBodyTruncationSize');$encoder->content('5000000');$encoder->endTag();
			$encoder->startTag('Provision:MaxHTMLBodyTruncationSize');$encoder->content('5000000');$encoder->endTag();
			$encoder->startTag('Provision:RequireSignedSMIMEMessages');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:RequireEncryptedSMIMEMessages');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:RequireSignedSMIMEAlgorithm');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:RequireEncryptedSMIMEAlgorithm');$encoder->content('0');$encoder->endTag();
			$encoder->startTag('Provision:AllowSMIMEEncryptionAlgorithmNegotiation');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowSMIMESoftCerts');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowBrowser');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowConsumerEmail');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowRemoteDesktop');$encoder->content('1');$encoder->endTag();
			$encoder->startTag('Provision:AllowInternetSharing');$encoder->content('1');$encoder->endTag();
//			$encoder->startTag('Provision:UnapprovedInROMApplicationList');$encoder->content('');$encoder->endTag();
//			$encoder->startTag('Provision:ApplicationName');$encoder->content('');$encoder->endTag();
//			$encoder->startTag('Provision:ApprovedApplicationList');$encoder->content('');$encoder->endTag();
//			$encoder->startTag('Provision:Hash');$encoder->content('');$encoder->endTag();
		    };
		    $encoder->endTag();
		}
                else {
                    debugLog("Wrong policy type");
                    return false;
                }

                $encoder->endTag();//data
            }
            $encoder->endTag();//policy
        $encoder->endTag(); //policies
    }
    $rwstatus = $backend->getDeviceRWStatus($user, $auth_pw, $devid);


    //wipe data if status is pending or wiped
    if ($rwstatus == SYNC_PROVISION_RWSTATUS_PENDING || $rwstatus == SYNC_PROVISION_RWSTATUS_WIPED) {
        $encoder->startTag(SYNC_PROVISION_REMOTEWIPE, false, true);
        $backend->setDeviceRWStatus($user, $auth_pw, $devid, SYNC_PROVISION_RWSTATUS_WIPED);
        //$rwstatus = SYNC_PROVISION_RWSTATUS_WIPED;
    }

    $encoder->endTag();//provision

    return true;
}
function ParseQuery($decoder, $subquery=NULL) {
    $query = array();
    while (($type = ($decoder->getElementStartTag(SYNC_SEARCH_AND)  		? SYNC_SEARCH_AND :
		    ($decoder->getElementStartTag(SYNC_SEARCH_OR)  		? SYNC_SEARCH_OR :
		    ($decoder->getElementStartTag(SYNC_SEARCH_EQUALTO)  	? SYNC_SEARCH_EQUALTO :
		    ($decoder->getElementStartTag(SYNC_SEARCH_LESSTHAN)  	? SYNC_SEARCH_LESSTHAN :
		    ($decoder->getElementStartTag(SYNC_SEARCH_GREATERTHAN)  	? SYNC_SEARCH_GREATERTHAN :
		    ($decoder->getElementStartTag(SYNC_SEARCH_FREETEXT)  	? SYNC_SEARCH_FREETEXT :
		    ($decoder->getElementStartTag(SYNC_FOLDERID)	  	? SYNC_FOLDERID :
		    ($decoder->getElementStartTag(SYNC_FOLDERTYPE)	  	? SYNC_FOLDERTYPE :
		    ($decoder->getElementStartTag(SYNC_DOCUMENTLIBRARY_LINKID) 	? SYNC_DOCUMENTLIBRARY_LINKID :
		    ($decoder->getElementStartTag(SYNC_POOMMAIL_DATERECEIVED)  	? SYNC_POOMMAIL_DATERECEIVED :
		    -1))))))))))) != -1) {
	switch ($type) {
	    case SYNC_SEARCH_AND 		:
	    case SYNC_SEARCH_OR  		:
	    case SYNC_SEARCH_EQUALTO	 	:
	    case SYNC_SEARCH_LESSTHAN 		:
	    case SYNC_SEARCH_GREATERTHAN	:
		    $q["op"] = $type;
		    $q["value"] = ParseQuery($decoder,true);
		    if ($subquery==true) {
			$query["subquery"][] = $q;
		    } else {
			$query[] = $q;
		    }
		    $decoder->getElementEndTag();
		    break;
	    default 			:
		    if (($query[$type] = $decoder->getElementContent()) !== false) {
			$decoder->getElementEndTag();
	    	    } else {
			$decoder->getElementStartTag(SYNC_SEARCH_VALUE);
		        $query[$type] = $decoder->getElementContent();
			switch ($type) {
			    case SYNC_POOMMAIL_DATERECEIVED :
    				if(preg_match("/(\d{4})[^0-9]*(\d{2})[^0-9]*(\d{2})T(\d{2})[^0-9]*(\d{2})[^0-9]*(\d{2})(.\d+)?Z/", $query[$type], $matches)) {
        			    if ($matches[1] >= 2038){
            				$matches[1] = 2038;
            				$matches[2] = 1;
            				$matches[3] = 18;
            				$matches[4] = $matches[5] = $matches[6] = 0;
        			    }
        			$query[$type] = gmmktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]);
    				}
				break;
			}
			$decoder->getElementEndTag();
		    };
		    break;
	};
    };	
    return $query;	    
}

function HandleSearch($backend, $devid, $protocolversion) {
    global $zpushdtd;
    global $input, $output;
    global $auth_user,$auth_domain,$auth_pw;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_SEARCH_SEARCH))
        return false;

    if(!$decoder->getElementStartTag(SYNC_SEARCH_STORE))
        return false;

    if(!$decoder->getElementStartTag(SYNC_SEARCH_NAME))
        return false;
    $searchname = $decoder->getElementContent();
    if(!$decoder->getElementEndTag())
        return false;

    if(!$decoder->getElementStartTag(SYNC_SEARCH_QUERY))
        return false;
    //START CHANGED dw2412 V12.0 Support
    switch (strtolower($searchname)) {
	case 'documentlibrary'  : 
		$searchquery['query'] = ParseQuery($decoder);
		break;	
	case 'mailbox'  : 
		$searchquery['query'] = ParseQuery($decoder);
		break;	
	case 'gal'	: 
		$searchquery = $decoder->getElementContent(); 
		break;
    }
    if(!$decoder->getElementEndTag())
        return false;

    if($decoder->getElementStartTag(SYNC_SEARCH_OPTIONS)) {
	$searchquerydeeptraversal = false;
	$searchqueryrebuildresults = false;
        $searchschema = false;
	while(1) {
            if($decoder->getElementStartTag(SYNC_SEARCH_RANGE)) {
                $searchrange = $decoder->getElementContent();
                if(!$decoder->getElementEndTag())
                    return false;
                }
    //START ADDED dw2412 V12.0 Support
            if($decoder->getElementStartTag(SYNC_SEARCH_DEEPTRAVERSAL)) {
                if (!($searchdeeptraversal = $decoder->getElementContent()))  
            	    $searchquerydeeptraversal = true;
            	else
            	    if(!$decoder->getElementEndTag())
                	return false;
            }
            if($decoder->getElementStartTag(SYNC_SEARCH_REBUILDRESULTS)) {
                if (!($searchrebuildresults = $decoder->getElementContent()))  
            	    $searchqueryrebuildresults = true;
            	else
            	    if(!$decoder->getElementEndTag())
                	return false;
            }
            if($decoder->getElementStartTag(SYNC_SEARCH_USERNAME)) {
                if (!($searchqueryusername = $decoder->getElementContent()))  
            	    return false;
            	else
            	    if(!$decoder->getElementEndTag())
                	return false;
            }
            if($decoder->getElementStartTag(SYNC_SEARCH_PASSWORD)) {
                if (!($searchquerypassword = $decoder->getElementContent()))  
            	    return false;
            	else
            	    if(!$decoder->getElementEndTag())
                	return false;
            }
            if($decoder->getElementStartTag(SYNC_SEARCH_SCHEMA)) {
                if (!($searchschema = $decoder->getElementContent()))  
            	    $searchschema = true;
            	else
            	    if(!$decoder->getElementEndTag())
                	return false;
            }
	    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE)) {
	        $bodypreference=array();
    	        while(1) {
            	    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
	        	$bodypreference["Type"] = $decoder->getElementContent();
    		        if(!$decoder->getElementEndTag())
                    	    return false;
    	    		}

            		if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
	                    $bodypreference["TruncationSize"] = $decoder->getElementContent();
    		            if(!$decoder->getElementEndTag())
                        	return false;
    	    		}

            		if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
	                    $bodypreference["AllOrNone"] = $decoder->getElementContent();
    		            if(!$decoder->getElementEndTag())
                        	return false;
    	    		}

            		$e = $decoder->peek();
            		if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
            		    $decoder->getElementEndTag();
			    if (!isset($searchbodypreference["wanted"]))
				$searchbodypreference["wanted"] = $bodypreference["Type"];
			    if (isset($bodypreference["Type"]))
				$searchbodypreference[$bodypreference["Type"]] = $bodypreference;
    		    	    break;
	        	}
                    }
		}
    
    //END ADDED dw2412 V12.0 Support
            
                $e = $decoder->peek();
                if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
                    $decoder->getElementEndTag();
                    break;
                }
            }
	}
    if(!$decoder->getElementEndTag()) //store
        return false;

    if(!$decoder->getElementEndTag()) //search
        return false;


    //START CHANGED dw2412 V12.0 Support
    switch (strtolower($searchname)) {
	case 'documentlibrary'  : 
		if (isset($searchqueryusername)) {
		    if (strpos($searchqueryusername,"\\")) {
			list($searchquery['username']['domain'],$searchquery['username']['username']) = explode("\\",$searchqueryusername);
		    } else {
			$searchquery['username'] = array('domain' => "",'username' => $searchqueryusername);
		    }
		} else {
		    $searchquery['username']['domain'] = $auth_domain;
		    $searchquery['username']['username'] = $auth_user;
		};
            	$searchquery['password'] = (isset($searchquerypassword) ? $searchquerypassword : $auth_pw);
                $searchquery['range'] = $searchrange;
            	break;
	case 'mailbox'  : 
            	$searchquery['rebuildresults'] = $searchqueryrebuildresults;
            	$searchquery['deeptraversal'] =  $searchquerydeeptraversal;
                $searchquery['range'] = $searchrange;
		break;	
    }
    //get search results from backend
    $result = $backend->getSearchResults($searchquery,$searchname);
    //END CHANGED dw2412 V12.0 Support
    

    $encoder->startWBXML();
    // START ADDED dw2412 Protocol Version 12 Support
    if (isset($searchbodypreference)) $encoder->_bodypreference = $searchbodypreference;
    // END ADDED dw2412 Protocol Version 12 Support

    $encoder->startTag(SYNC_SEARCH_SEARCH);

        $encoder->startTag(SYNC_SEARCH_STATUS);
        $encoder->content(1);
        $encoder->endTag();

        $encoder->startTag(SYNC_SEARCH_RESPONSE);
            $encoder->startTag(SYNC_SEARCH_STORE);

                $encoder->startTag(SYNC_SEARCH_STATUS);
                $encoder->content($result['status']);
                $encoder->endTag();

		// CHANGED dw2412 AS V12.0 Support (mentain single return way...)
                if (is_array($result['rows']) && !empty($result['rows'])) {
                    $searchtotal = count($result['rows']);
		    // CHANGED dw2412 AS V12.0 Support (honor the range in request...)
		    eregi("(.*)\-(.*)",$searchrange,$range);
		    $returnitems = $range[2] - $range[1];
                    $returneditems=0;
		    $result['rows'] = array_slice($result['rows'],$range[1],$returnitems+1,true);
		    // CHANGED dw2412 AS V12.0 Support (mentain single return way...)
                    foreach ($result['rows'] as $u) {

			    // CHANGED dw2412 AS V12.0 Support (honor the range in request...)
                    	    if ($returneditems>$returnitems) break; 
                    	    $returneditems++;

			    switch (strtolower($searchname)) {
				case 'documentlibrary'  : 
                    		    $encoder->startTag(SYNC_SEARCH_RESULT);
                        		$encoder->startTag(SYNC_SEARCH_PROPERTIES);
					$encoder->startTag(SYNC_DOCUMENTLIBRARY_LINKID);
                    		    	$encoder->content($u['linkid']);
					$encoder->endTag();
					$encoder->startTag(SYNC_DOCUMENTLIBRARY_DISPLAYNAME);
                    		    	$encoder->content($u['displayname']);
					$encoder->endTag();
					$encoder->startTag(SYNC_DOCUMENTLIBRARY_CREATIONDATE);
                    		    	$encoder->content($u['creationdate']);
					$encoder->endTag();
					$encoder->startTag(SYNC_DOCUMENTLIBRARY_LASTMODIFIEDDATE);
                    		    	$encoder->content($u['lastmodifieddate']);
					$encoder->endTag();
					$encoder->startTag(SYNC_DOCUMENTLIBRARY_ISHIDDEN);
                    		    	$encoder->content($u['ishidden']);
					$encoder->endTag();
	    				$encoder->startTag(SYNC_DOCUMENTLIBRARY_ISFOLDER);
					$encoder->content($u['isfolder']);
					$encoder->endTag();
	    				if ($u['isfolder'] == "0") {
					    $encoder->startTag(SYNC_DOCUMENTLIBRARY_CONTENTLENGTH);
                    		    	    $encoder->content($u['contentlength']);
					    $encoder->endTag();
					    $encoder->startTag(SYNC_DOCUMENTLIBRARY_CONTENTTYPE);
					    $encoder->content($u['contenttype']);
					    $encoder->endTag();
				        }
                            		$encoder->endTag();//result
                    		    $encoder->endTag();//properties
				    break;
				case 'mailbox'  : 
                    		    $encoder->startTag(SYNC_SEARCH_RESULT);
                        		$encoder->startTag(SYNC_FOLDERTYPE);
                        		$encoder->content('Email');
                        		$encoder->endTag();
                        		$encoder->startTag(SYNC_SEARCH_LONGID);
                        		$encoder->content($u['uniqueid']);
                        		$encoder->endTag();
                        		$encoder->startTag(SYNC_FOLDERID);
                        		$encoder->content($u['searchfolderid']);
                        		$encoder->endTag();
                        		$encoder->startTag(SYNC_SEARCH_PROPERTIES);
				    $msg = $backend->ItemOperationsFetchMailbox($u['uniqueid'], $searchbodypreference);
				    $msg->encode($encoder);
                    		        $encoder->endTag();//properties
                        	    $encoder->endTag();//result
				    break;
				case 'gal'  : 
                    		    $encoder->startTag(SYNC_SEARCH_RESULT);
                        		$encoder->startTag(SYNC_SEARCH_PROPERTIES);
                            		
				    if (isset($u["fullname"]) && $u["fullname"] != "") {
                            		$encoder->startTag(SYNC_GAL_DISPLAYNAME);
                            		$encoder->content($u["fullname"]);
                        		$encoder->endTag();
				    }

				    if (isset($u["phone"]) && $u["phone"] != "") {
                            		$encoder->startTag(SYNC_GAL_PHONE);
            				$encoder->content($u["phone"]);
                            		$encoder->endTag();
				    }

				    if (isset($u["homephone"]) && $u["homephone"] != "") {
                            		$encoder->startTag(SYNC_GAL_HOMEPHONE);
            				$encoder->content($u["homephone"]);
                            		$encoder->endTag();
				    }

				    if (isset($u["mobilephone"]) && $u["mobilephone"] != "") {
                            		$encoder->startTag(SYNC_GAL_MOBILEPHONE);
            				$encoder->content($u["mobilephone"]);
                            		$encoder->endTag();
				    }

				    if (isset($u["company"]) && $u["company"] != "") {
                            		$encoder->startTag(SYNC_GAL_COMPANY);
            				$encoder->content($u["company"]);
                            		$encoder->endTag();
				    }

				    if (isset($u["office"]) && $u["office"] != "") {
                            		$encoder->startTag(SYNC_GAL_OFFICE);
                            		$encoder->content($u["office"]);
                            		$encoder->endTag();
				    }

				    if (isset($u["title"]) && $u["title"] != "") {
                            	        $encoder->startTag(SYNC_GAL_TITLE);
                            	        $encoder->content($u["title"]);
                            		$encoder->endTag();
				    }

				    if (isset($u["username"]) && $u["username"] != "") {
                            		$encoder->startTag(SYNC_GAL_ALIAS);
                            		$encoder->content($u["username"]);
                            		$encoder->endTag();
				    }

                            	    //it's not possible not get first and last name of an user
                            	    //from the gab and user functions, so we just set fullname
                            	    //to lastname and leave firstname empty because nokia needs
                            	    //first and lastname in order to display the search result
                            	    $encoder->startTag(SYNC_GAL_FIRSTNAME);
                            	    $encoder->content("");
                            	    $encoder->endTag();

                            	    $encoder->startTag(SYNC_GAL_LASTNAME);
                        	    $encoder->content($u["fullname"]);
                            	    $encoder->endTag();

                        	    $encoder->startTag(SYNC_GAL_EMAILADDRESS);
                        	    $encoder->content($u["emailaddress"]);
	                    	    $encoder->endTag();
                    		
                    		    $encoder->endTag();//result
                		    $encoder->endTag();//properties
				    break;
			    };
                    }
                    $searchrange = $range[1]."-".($range[1]+$returneditems-1);
                    $encoder->startTag(SYNC_SEARCH_RANGE);
                    $encoder->content($searchrange);
                    $encoder->endTag();

                    $encoder->startTag(SYNC_SEARCH_TOTAL);
                    $encoder->content($searchtotal);
                    $encoder->endTag();
                }

            $encoder->endTag();//store
        $encoder->endTag();//response
    $encoder->endTag();//search


    return true;
}

// START ADDED dw2412 Settings Support
function HandleSettings($backend, $devid, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_SETTINGS_SETTINGS))
        return false;

    $request = array();
    while (($reqtype = ($decoder->getElementStartTag(SYNC_SETTINGS_OOF) 	      ?   SYNC_SETTINGS_OOF               :
		       ($decoder->getElementStartTag(SYNC_SETTINGS_DEVICEINFORMATION) ?   SYNC_SETTINGS_DEVICEINFORMATION :
		       ($decoder->getElementStartTag(SYNC_SETTINGS_USERINFORMATION)   ?   SYNC_SETTINGS_USERINFORMATION   :
 		       ($decoder->getElementStartTag(SYNC_SETTINGS_DEVICEPASSWORD)    ?   SYNC_SETTINGS_DEVICEPASSWORD    :
		       -1))))) != -1) {
	if($decoder->getElementStartTag(SYNC_SETTINGS_GET)) {
	    if($reqtype == SYNC_SETTINGS_OOF) {
		if(!$decoder->getElementStartTag(SYNC_SETTINGS_BODYTYPE))
        	    return false;
                $bodytype = $decoder->getElementContent();
                if(!$decoder->getElementEndTag())
                    return false; // end SYNC_SETTINGS BODYTYPE
                if(!$decoder->getElementEndTag())
                    return false; // end SYNC_SETTINGS GET
                if(!$decoder->getElementEndTag())
                    return false; // end SYNC_SETTINGS_OOF
		$request["get"]["oof"]["bodytype"] = $bodytype;    


	    } elseif ($reqtype == SYNC_SETTINGS_USERINFORMATION) {
		$request["get"]["userinformation"] = array();    
    	    } else { return false; };
    	} elseif($decoder->getElementStartTag(SYNC_SETTINGS_SET)) {
    	    if($reqtype == SYNC_SETTINGS_OOF) {
        	$decoder->getElementStartTag(SYNC_SETTINGS_OOFSTATE);
        	$oofstate = $decoder->getElementContent();
        	$decoder->getElementEndTag(); // end SYNC_SETTINGS_OOFSTATE
		$request["set"]["oof"]["oofstate"] = $oofstate;    
    	        if ($oofstate != 0) {
    		    $decoder->getElementStartTag(SYNC_SETTINGS_OOFMESSAGE);

		    $oofmsgs = array();
		    while (($type = ($decoder->getElementStartTag(SYNC_SETTINGS_APPLIESTOINTERNAL)        ? SYNC_SETTINGS_APPLIESTOINTERNAL :
				    ($decoder->getElementStartTag(SYNC_SETTINGS_APPLIESTOEXTERNALKNOWN)   ? SYNC_SETTINGS_APPLIESTOEXTERNALKNOWN :
				    ($decoder->getElementStartTag(SYNC_SETTINGS_APPLIESTOEXTERNALUNKNOWN) ? SYNC_SETTINGS_APPLIESTOEXTERNALUNKNOWN :
				    -1)))) != -1) {
			$oof = array();
        		$oof["appliesto"] = $type;
        		$decoder->getElementStartTag(SYNC_SETTINGS_ENABLED);
        		$oof["enabled"] = $decoder->getElementContent();
        		$decoder->getElementEndTag(); // end SYNC_SETTINGS_ENABLED
        		$decoder->getElementStartTag(SYNC_SETTINGS_REPLYMESSAGE);
        		$oof["replymessage"] = $decoder->getElementContent();
        		$decoder->getElementEndTag(); // end SYNC_SETTINGS_REPLYMESSAGE
        		$decoder->getElementStartTag(SYNC_SETTINGS_BODYTYPE);
        		$oof["bodytype"] = $decoder->getElementContent();
        		$decoder->getElementEndTag(); // end SYNC_SETTINGS_BODYTYPE
			$oofmsgs[]=$oof;
		    }; 
        	    $request["set"]["oof"]["oofmsgs"] = $oofmsgs;    

        	    $decoder->getElementEndTag(); // end SYNC_SETTINGS_OOFMESSAGE
		};
    		$decoder->getElementEndTag(); // end SYNC_SETTINGS_SET
        	$decoder->getElementEndTag(); // end SYNC_SETTINGS_OOF


	    } elseif ($reqtype == SYNC_SETTINGS_DEVICEINFORMATION) {
		while (($field = ($decoder->getElementStartTag(SYNC_SETTINGS_MODEL) 				 ? SYNC_SETTINGS_MODEL					: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_IMEI) 				 ? SYNC_SETTINGS_IMEI 					: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_FRIENDLYNAME) 			 ? SYNC_SETTINGS_FRIENDLYNAME 				: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_OS) 				 ? SYNC_SETTINGS_OS 					: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_OSLANGUAGE) 			 ? SYNC_SETTINGS_OSLANGUAGE 				: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_PHONENUMBER) 			 ? SYNC_SETTINGS_PHONENUMBER 				: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_USERAGENT) 			 ? SYNC_SETTINGS_USERAGENT 				: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_MOBILEOPERATOR) 			 ? SYNC_SETTINGS_MOBILEOPERATOR 			: 
				 ($decoder->getElementStartTag(SYNC_SETTINGS_ENABLEOUTBOUNDSMS)		 	 ? SYNC_SETTINGS_ENABLEOUTBOUNDSMS			: 
				 -1)))))))))) != -1) {
        	    if (($deviceinfo[$field] = $decoder->getElementContent()) !== false) {
        	        $decoder->getElementEndTag(); // end $field
		    }
		};
		$request["set"]["deviceinformation"] = $deviceinfo;    
     		$decoder->getElementEndTag(); // end SYNC_SETTINGS_SET
        	$decoder->getElementEndTag(); // end SYNC_SETTINGS_DEVICEINFORMATION

	    } elseif ($reqtype == SYNC_SETTINGS_DEVICEPASSWORD) {
		$decoder->getElementStartTag(SYNC_SETTINGS_PASSWORD);
        	if (($password = $decoder->getElementContent()) !== false) $decoder->getElementEndTag(); // end $field
		$request["set"]["devicepassword"] = $password;    
	
    	    } else { return false; };
    
	} else { return false; };
    }
    $decoder->getElementEndTag(); // end SYNC_SETTINGS_SETTINGS

    if (isset($request["set"])) $result["set"] = $backend->setSettings($request["set"],$devid);
    if (isset($request["get"])) $result["get"] = $backend->getSettings($request["get"],$devid);

    $encoder->startWBXML();
    $encoder->startTag(SYNC_SETTINGS_SETTINGS);
    $encoder->startTag(SYNC_SETTINGS_STATUS);
    $encoder->content(1);
    $encoder->endTag(); // end SYNC_SETTINGS_STATUS
    if (isset($request["set"]["oof"])) {
        $encoder->startTag(SYNC_SETTINGS_OOF);
        $encoder->startTag(SYNC_SETTINGS_STATUS);
	if (!isset($result["set"]["oof"]["status"])) {
    	    $encoder->content(0);
    	} else {
    	    $encoder->content($result["set"]["oof"]["status"]);
    	}
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
        $encoder->endTag(); // end SYNC_SETTINGS_OOF
    };
    if (isset($request["set"]["deviceinformation"])) {
        $encoder->startTag(SYNC_SETTINGS_DEVICEINFORMATION);
        $encoder->startTag(SYNC_SETTINGS_SET);
        $encoder->startTag(SYNC_SETTINGS_STATUS);
	if (!isset($result["set"]["deviceinformation"]["status"])) {
    	    $encoder->content(0);
    	} else {
    	    $encoder->content($result["set"]["deviceinformation"]["status"]);
    	}
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
        $encoder->endTag(); // end SYNC_SETTINGS_SET
        $encoder->endTag(); // end SYNC_SETTINGS_DEVICEINFORMATION
    };
    if (isset($request["set"]["devicepassword"])) {
        $encoder->startTag(SYNC_SETTINGS_DEVICEPASSWORD);
        $encoder->startTag(SYNC_SETTINGS_SET);
        $encoder->startTag(SYNC_SETTINGS_STATUS);
	if (!isset($result["set"]["devicepassword"]["status"])) {
    	    $encoder->content(0);
    	} else {
    	    $encoder->content($result["set"]["devicepassword"]["status"]);
    	}
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
        $encoder->endTag(); // end SYNC_SETTINGS_SET
        $encoder->endTag(); // end SYNC_SETTINGS_DEVICEPASSWORD
    };
    if (isset($request["get"]["userinformation"])) {
        $encoder->startTag(SYNC_SETTINGS_USERINFORMATION);
        $encoder->startTag(SYNC_SETTINGS_STATUS);
        $encoder->content($result["get"]["userinformation"]["status"]);
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
        $encoder->startTag(SYNC_SETTINGS_GET);
        $encoder->startTag(SYNC_SETTINGS_EMAILADDRESSES);
	foreach($result["get"]["userinformation"]["emailaddresses"] as $value) {
	    $encoder->startTag(SYNC_SETTINGS_SMTPADDRESS);
    	    $encoder->content($value);
    	    $encoder->endTag(); // end SYNC_SETTINGS_SMTPADDRESS
        };
        $encoder->endTag(); // end SYNC_SETTINGS_EMAILADDRESSES
        $encoder->endTag(); // end SYNC_SETTINGS_GET
        $encoder->endTag(); // end SYNC_SETTINGS_USERINFORMATION
    };
    if (isset($request["get"]["oof"])) {
        $encoder->startTag(SYNC_SETTINGS_OOF);
            
        $encoder->startTag(SYNC_SETTINGS_STATUS);
        $encoder->content(1);
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
            
        $encoder->startTag(SYNC_SETTINGS_GET);
        $encoder->startTag(SYNC_SETTINGS_OOFSTATE);
        $encoder->content($result["get"]["oof"]["oofstate"]);
        $encoder->endTag(); // end SYNC_SETTINGS_OOFSTATE
//	This we maybe need later on (OOFSTATE=2). It shows that OOF Messages could be send depending on Time being set in here. 
//	Unfortunately cannot proof it working on my device.
/*      $encoder->startTag(SYNC_SETTINGS_STARTTIME);
        $encoder->content("2007-05-08T10:45:51.250Z");
        $encoder->endTag(); // end SYNC_SETTINGS_STARTTIME
        $encoder->startTag(SYNC_SETTINGS_ENDTIME);
        $encoder->content("2007-05-11T10:45:51.250Z");
        $encoder->endTag(); // end SYNC_SETTINGS_ENDTIME
*/
        foreach($result["get"]["oof"]["oofmsgs"] as $oofentry) {
            $encoder->startTag(SYNC_SETTINGS_OOFMESSAGE);
            $encoder->startTag($oofentry["appliesto"],false,true);
            $encoder->startTag(SYNC_SETTINGS_ENABLED);
            $encoder->content($oofentry["enabled"]);
            $encoder->endTag(); // end SYNC_SETTINGS_ENABLED
    	    $encoder->startTag(SYNC_SETTINGS_REPLYMESSAGE);
            $encoder->content($oofentry["replymessage"]);
            $encoder->endTag(); // end SYNC_SETTINGS_REPLYMESSAGE
            $encoder->startTag(SYNC_SETTINGS_BODYTYPE);
	    switch (strtolower($oofentry["bodytype"])) {
		case "text" : $encoder->content("Text"); break;
		case "HTML" : $encoder->content("HTML"); break;
	    };
            $encoder->endTag(); // end SYNC_SETTINGS_BODYTYPE
            $encoder->endTag(); // end SYNC_SETTINGS_OOFMESSAGE
        };
    
        $encoder->endTag(); // end SYNC_SETTINGS_GET
        $encoder->endTag(); // end SYNC_SETTINGS_OOF
    
    };
    $encoder->endTag(); // end SYNC_SETTINGS_SETTINGS
    
    return true;
}

// END ADDED dw2412 Settings Support

// START ADDED dw2412 ItemOperations Support
function HandleItemOperations($backend, $devid, $protocolversion, $multipart) {
    global $zpushdtd;
    global $input, $output;
    global $auth_user,$auth_domain,$auth_pw;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_ITEMOPERATIONS))
        return false;

    $request = array();
    while (($reqtype = ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_FETCH)       		?   SYNC_ITEMOPERATIONS_FETCH      	  	:
		       ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_EMPTYFOLDERCONTENT) 	?   SYNC_ITEMOPERATIONS_EMPTYFOLDERCONTENT	:
		       -1))) != -1) {
	if ($reqtype == SYNC_ITEMOPERATIONS_FETCH) {
	    $thisio["type"] = "fetch";
	    while (($reqtag = ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_STORE)       		?   SYNC_ITEMOPERATIONS_STORE  	  	:
			      ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_OPTIONS)	 	?   SYNC_ITEMOPERATIONS_OPTIONS		:
			      ($decoder->getElementStartTag(SYNC_SERVERENTRYID)			 	?   SYNC_SERVERENTRYID			:
			      ($decoder->getElementStartTag(SYNC_FOLDERID)			 	?   SYNC_FOLDERID			:
			      ($decoder->getElementStartTag(SYNC_DOCUMENTLIBRARY_LINKID)	 	?   SYNC_DOCUMENTLIBRARY_LINKID		:
			      ($decoder->getElementStartTag(SYNC_AIRSYNCBASE_FILEREFERENCE)	 	?   SYNC_AIRSYNCBASE_FILEREFERENCE	:
			      ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_USERNAME)	 	?   SYNC_ITEMOPERATIONS_USERNAME	:
			      ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_PASSWORD)	 	?   SYNC_ITEMOPERATIONS_PASSWORD	:
			      ($decoder->getElementStartTag(SYNC_SEARCH_LONGID)			 	?   SYNC_SEARCH_LONGID			:
		    	      -1)))))))))) != -1) {
    		if ($reqtag == SYNC_ITEMOPERATIONS_OPTIONS) {
		    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE)) {
		        $bodypreference=array();
        	        while(1) {
            	    	    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
	                        $bodypreference["Type"] = $decoder->getElementContent();
    		                if(!$decoder->getElementEndTag())
                            	    return false;
    	    	    	    }
    
                	    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
        	        	$bodypreference["TruncationSize"] = $decoder->getElementContent();
        		        if(!$decoder->getElementEndTag())
                            	    return false;
        	    	    }
    
                	    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
        	                $bodypreference["AllOrNone"] = $decoder->getElementContent();
        		        if(!$decoder->getElementEndTag())
                    		    return false;
    	    		    }

            	    	    $e = $decoder->peek();
            		    if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
            			$decoder->getElementEndTag();
				if (!isset($thisio["bodypreference"]["wanted"]))
				    $thisio["bodypreference"]["wanted"] = $bodypreference["Type"];
				if (isset($bodypreference["Type"]))
				    $thisio["bodypreference"][$bodypreference["Type"]] = $bodypreference;
    		    		break;
	        	    }
                	}
		    }
		} elseif ($reqtag == SYNC_ITEMOPERATIONS_STORE) {
    	    	    $thisio["store"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_ITEMOPERATIONS_USERNAME) {
    	    	    $thisio["username"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_ITEMOPERATIONS_PASSWORD) {
    	    	    $thisio["password"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_SEARCH_LONGID) {
    	    	    $thisio["searchlongid"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_AIRSYNCBASE_FILEREFERENCE) {
		    $thisio["airsyncbasefilereference"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_SERVERENTRYID) {
		    $thisio["serverentryid"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_FOLDERID) {
		    $thisio["folderid"] = $decoder->getElementContent();
		} elseif ($reqtag == SYNC_DOCUMENTLIBRARY_LINKID) {
		    $thisio["documentlibrarylinkid"] = $decoder->getElementContent();
		} 
    		$e = $decoder->peek();
    	        if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
    		    $decoder->getElementEndTag();
		}
	    }
	    $itemoperations[] = $thisio;		    
	    $decoder->getElementEndTag(); // end SYNC_ITEMOPERATIONS_FETCH
	}
    }
    $decoder->getElementEndTag(); // end SYNC_ITEMOPERATIONS_ITEMOPERATIONS

    if ($multipart == true) {
        $encoder->startWBXML(true);
    } else {
        $encoder->startWBXML(false);
    }
    $encoder->startTag(SYNC_ITEMOPERATIONS_ITEMOPERATIONS);
    $encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
    $encoder->content(1);
    $encoder->endTag(); // end SYNC_ITEMOPERATIONS_STATUS
    $encoder->startTag(SYNC_ITEMOPERATIONS_RESPONSE);
    foreach($itemoperations as $value) {
	switch($value["type"]) {
	    case "fetch" :
		switch(strtolower($value["store"])) {
		    case "mailbox" :
	    		$encoder->startTag(SYNC_ITEMOPERATIONS_FETCH);
			$encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
			$encoder->content(1);
			$encoder->endTag(); // end SYNC_ITEMOPERATIONS_STATUS
			if (isset($value["airsyncbasefilereference"])) {
			    $encoder->startTag(SYNC_AIRSYNCBASE_FILEREFERENCE);
			    $encoder->content($value["airsyncbasefilereference"]);
			    $encoder->endTag(); // end SYNC_SERVERENTRYID
			} else {
			    if (isset($value["folderid"])) {
    		    		$encoder->startTag(SYNC_FOLDERID);
				$encoder->content($value["folderid"]);
	    			$encoder->endTag(); // end SYNC_FOLDERID
			    }
		    	    if (isset($value["serverentryid"])) {
				$encoder->startTag(SYNC_SERVERENTRYID);
				$encoder->content($value["serverentryid"]);
				$encoder->endTag(); // end SYNC_SERVERENTRYID
			    } 
			    if (isset($value["searchlongid"])) {
				$ids = $backend->ItemOperationsGetIDs($value['searchlongid']);
    		    		$encoder->startTag(SYNC_FOLDERID);
				$encoder->content($ids["folderid"]);
	    	    		$encoder->endTag(); // end SYNC_FOLDERID
				$encoder->startTag(SYNC_SERVERENTRYID);
				$encoder->content($ids["serverentryid"]);
				$encoder->endTag(); // end SYNC_SERVERENTRYID
			    } 
            		    $encoder->startTag(SYNC_FOLDERTYPE);
                	    $encoder->content("Email");
            		    $encoder->endTag();
		        }
            		$encoder->startTag(SYNC_ITEMOPERATIONS_PROPERTIES);
//			if (isset($value['bodypreference'])) $encoder->_bodypreference = $value['bodypreference'];
			if (isset($value["searchlongid"])) {
			    $msg = $backend->ItemOperationsFetchMailbox($value['searchlongid'], $value['bodypreference']);
			} else if(isset($value["airsyncbasefilereference"])) {
			    $msg = $backend->ItemOperationsGetAttachmentData($value["airsyncbasefilereference"]);
			} else {
			    $msg = $backend->Fetch($value['folderid'], $value['serverentryid'], $value['bodypreference']);
//			    $msg->airsyncbasebody->estimateddatasize=0;
//			    $msg->airsyncbasebody->data=0;
			};
        		$msg->encode($encoder);
		    
            		$encoder->endTag(); // end SYNC_ITEMOPERATIONS_PROPERTIES
			$encoder->endTag(); // end SYNC_ITEMOPERATIONS_FETCH
			break;
		    case "documentlibrary" :
			if (isset($value['username'])) {
			    if (strpos($value['username'],"\\")) {
				list($cred['username']['domain'],$cred['username']['username']) = explode("\\",$value['username']);
			    } else {
				$cred['username'] = array('domain' => "",'username' => $value['username']);
			    }
			} else {
			    $cred['username']['domain'] = $auth_domain;
			    $cred['username']['username'] = $auth_user;
			}
        		$cred['password'] = (isset($value['password']) ? $value['password'] : $auth_pw);
			$result = $backend->ItemOperationsGetDocumentLibraryLink($value["documentlibrarylinkid"],$cred);
	    		$encoder->startTag(SYNC_ITEMOPERATIONS_FETCH);
			$encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
			$encoder->content($result['status']);
			// $encoder->content(1);
			$encoder->endTag(); // end SYNC_ITEMOPERATIONS_STATUS
			$encoder->startTag(SYNC_DOCUMENTLIBRARY_LINKID);
			$encoder->content($value["documentlibrarylinkid"]);
			$encoder->endTag(); // end SYNC_DOCUMENTLIBRARY_LINKID
			if ($result['status'] == 1) {
            		    $encoder->startTag(SYNC_ITEMOPERATIONS_PROPERTIES);
                	    if ($multipart == true) {
                		$encoder->_bodyparts[] = $result['data'];
                		$encoder->startTag(SYNC_ITEMOPERATIONS_PART);
                		$encoder->content("".(sizeof($encoder->_bodyparts))."");
                		$encoder->endTag();
			    } else {
        			$encoder->startTag(SYNC_ITEMOPERATIONS_DATA);
				$encoder->content($result['data']);
				$encoder->endTag(); // end SYNC_ITEMOPERATIONS_DATA
            		    };
            		    $encoder->startTag(SYNC_ITEMOPERATIONS_VERSION);
            		    $encoder->content(gmstrftime("%Y-%m-%dT%H:%M:%S.000Z", $result['version']));
			    $encoder->endTag(); // end SYNC_ITEMOPERATIONS_VERSION
			    $encoder->endTag(); // end SYNC_ITEMOPERATIONS_PROPERTIES
			} else {
			    $encoder->_bodyparts = array();
			}
			$encoder->endTag(); // end SYNC_ITEMOPERATIONS_FETCH
			break;
		    default :	    
			debugLog ("Store ".$value["type"]." not supported by HandleItemOperations");
		        break;
		}
		break;
	    default :
		debugLog ("Operations ".$value["type"]." not supported by HandleItemOperations");
		break;
	}
    }
    $encoder->endTag(); //end SYNC_ITEMOPERATIONS_RESPONSE
    $encoder->endTag(); //end SYNC_ITEMOPERATIONS_ITEMOPERATIONS

    return true;
}

// END ADDED dw2412 ItemOperations Support

function HandleRequest($backend, $cmd, $devid, $protocolversion, $multipart) {

    switch($cmd) {
        case 'Sync':
            $status = HandleSync($backend, $protocolversion, $devid);
            break;
        case 'SendMail':
            $status = HandleSendMail($backend, $protocolversion);
            break;
        case 'SmartForward':
            $status = HandleSmartForward($backend, $protocolversion);
            break;
        case 'SmartReply':
            $status = HandleSmartReply($backend, $protocolversion);
            break;
        case 'GetAttachment':
            $status = HandleGetAttachment($backend, $protocolversion);
            break;
        case 'GetHierarchy':
            $status = HandleGetHierarchy($backend, $protocolversion, $devid);
            break;
        case 'CreateCollection':
            $status = HandleCreateCollection($backend, $protocolversion);
            break;
        case 'DeleteCollection':
            $status = HandleDeleteCollection($backend, $protocolversion);
            break;
        case 'MoveCollection':
            $status = HandleMoveCollection($backend, $protocolversion);
            break;
        case 'FolderSync':
            $status = HandleFolderSync($backend, $devid, $protocolversion);
            break;
        case 'FolderCreate':
            $status = HandleFolderCreate($backend, $devid, $protocolversion);
            break;
        case 'FolderDelete':
            $status = HandleFolderDelete($backend, $devid, $protocolversion);
            break;
        case 'FolderUpdate':
            $status = HandleFolderUpdate($backend, $devid, $protocolversion);
            break;
        case 'MoveItems':
            $status = HandleMoveItems($backend, $protocolversion);
            break;
        case 'GetItemEstimate':
            $status = HandleGetItemEstimate($backend, $protocolversion, $devid);
            break;
        case 'MeetingResponse':
            $status = HandleMeetingResponse($backend, $protocolversion);
            break;
        case 'Notify': // Used for sms-based notifications (pushmail)
            $status = HandleNotify($backend, $protocolversion);
            break;
        case 'Ping': // Used for http-based notifications (pushmail)
            $status = HandlePing($backend, $devid, $protocolversion);
            break;
        case 'Provision':
	    $status = (PROVISIONING === true) ? HandleProvision($backend, $devid, $protocolversion) : false;
            break;
        case 'Search':
            $status = HandleSearch($backend, $devid, $protocolversion);
            break;
        case 'Settings':
            $status = HandleSettings($backend, $devid, $protocolversion);
            break;
        case 'ItemOperations':
            $status = HandleItemOperations($backend, $devid, $protocolversion, $multipart);
            break;

        default:
            debugLog("unknown command - not implemented");
            $status = false;
            break;
    }

    return $status;
}

function readStream(&$input) {
    $s = "";

    while(1) {
        $data = fread($input, 4096);
        if(strlen($data) == 0)
            break;
        $s .= $data;
    }

    return $s;
}

?>