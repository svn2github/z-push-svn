// Begin Contribution - Resolve Multiple Recipients - liverpoolfcfan
// The following is a sample implementation of the Backend Function ResolveRecipients
// that uses the new structure containing 0..many SyncResolveRecipientResponse objects
// This code is from the zimbra backend so obviously has calls that are specific to zimnbra
// but should give a good idea of the structure required and error conditions to be handled 
// Note: It does not implement the return of actual certificates for the recipients at this time
// instead passing back a No Valid Cert response for each

    /** ResolveRecipients
     *
     */
    function ResolveRecipients($resolveRecipients) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Zimbra->(): ' . 'START ResolveRecipient { resolveRecipients = ' . '<hidden>' . ' }');

        if (!$resolveRecipients instanceof SyncResolveRecipients) {
            ZLog::Write(LOGLEVEL_WARN, "Not a valid SyncResolveRecipients object.");
            // return a SyncResolveRecipients object so that sync doesn't fail
            $r = new SyncResolveRecipients();
            $r->status = SYNC_RESOLVERECIPSSTATUS_PROTOCOLERROR;
            $r->recipient = array();
            return $r;
        }

        $recipCount = count( $resolveRecipients->to );
        if (isset($resolveRecipients->options)) {
            $rrOptions = new SyncRROptions();
            $rrOptions = $resolveRecipients->options;
            if (isset($rrOptions->maxambiguousrecipients) && $rrOptions->maxambiguousrecipients > 0) { 
                $limit = $rrOptions->maxambiguousrecipients;
            } else {
                $limit = 19; // default to 19
            }
            if (isset($rrOptions->availability)) {
                $availability = 'availability';
                $rrAvailability = new SyncRRAvailability();
                $rrAvailability = $rrOptions->availability;

                $starttime = str_replace( "-", "", str_replace( ":", "", $rrAvailability->starttime) );
                $starttime = $this->Date4ActiveSync( $starttime, "UTC" );
                $endtime = str_replace( "-", "", str_replace( ":", "", $rrAvailability->endtime) );
                $endtime = $this->Date4ActiveSync( $endtime, "UTC" );

                $duration = $endtime - $starttime;
                $timeslots = intval( $duration / 1800);
                if (($duration % 1800) != 0) $timeslots += 1;

                if ($timeslots > 32767) {
                    ZLog::Write(LOGLEVEL_DEBUG, 'Zimbra->(): ' .  'Availability Request would generate too many timeslots - returning Status=5' );
                    $resolveRecipients->status = SYNC_RESOLVERECIPSSTATUS_PROTOCOLERROR;
                    return $resolveRecipients;
                }

            }
            if (isset($rrOptions->certificateretrieval)) {
                $certificateretrieval = $rrOptions->certificateretrieval;
                if (isset($rrOptions->maxcertificates)) {
                    $maxcertificates = $rrOptions->maxcertificates;
                } else {
                    $maxcertificates = 9999;
                }
            }
        }

        $resolveRecipients->status = SYNC_COMMONSTATUS_SUCCESS;
        $resolveRecipients->recipientresponse = array();

        for ($recip=0;$recip<$recipCount; $recip++) {
            $recipient = $resolveRecipients->to[$recip];

            $recipientResponse = new SyncResolveRecipientResponse();
            $recipientResponse->to = $recipient;
            $recipientResponse->status = SYNC_COMMONSTATUS_SUCCESS;

            $found = false;

            // First look in the GAL
            $soap = '<SearchGalRequest  type="all"  limit="'.$limit.'"  xmlns="urn:zimbraAccount" >
                         <name>'. $recipient .'</name>
                     </SearchGalRequest>';

            $returnJSON = true;
            $response = $this->SoapRequest($soap, false, false, $returnJSON);
            if($response) {
                $array = json_decode($response, true);
                unset($response);

                if ( isset( $array['Body']['SearchGalResponse']['cn'] )) {
                    $galEntry = $array['Body']['SearchGalResponse']['cn'];

                    $recipientResponse->recipientcount = count($galEntry);
                    $recipientResponse->recipient = array();
                    $limit = $limit - count($galEntry);   // Further limit the number of Contacts returned

                    unset($array);

                    for ($i=0;$i<count($galEntry);$i++) {
                        $thisGalEntry = new SyncResolveRecipient();

                        if (isset($galEntry[$i]['_attrs']['fullName'])) {
                            $thisGalEntry->displayname = $galEntry[$i]['_attrs']['fullName'];
                        } elseif (isset($galEntry[$i]['_attrs']['firstName'])) {
                            $thisGalEntry->displayname = $galEntry[$i]['_attrs']['firstName'];
                            if (isset($galEntry[$i]['_attrs']['lastName'])) {
                                $thisGalEntry->displayname .= ' ' . $galEntry[$i]['_attrs']['lastName'];
                            }
                        } elseif (isset($galEntry[$i]['_attrs']['lastName'])) {
                            $thisGalEntry->displayname = $galEntry[$i]['_attrs']['lastName'];
                        } 
                        if (isset($galEntry[$i]['_attrs']['email'])) {
                            $thisGalEntry->emailaddress = $galEntry[$i]['_attrs']['email'];
                        }

                        $thisGalEntry->type = SYNC_RESOLVERECIPIENTS_TYPE_GAL;

                        $recipientResponse->recipient[] = $thisGalEntry;
                        unset($thisGalEntry);
                    }
                    $found = true;
                }

            } else {
                ZLog::Write(LOGLEVEL_DEBUG, 'Zimbra->(): ' . 'END ResolveRecipients { false (SOAP Error) }');
                $resolveRecipients->status = SYNC_RESOLVERECIPSSTATUS_SERVERERROR;
                return $resolveRecipients;

            }


            // Next, Search Contacts
            $soap = '<SearchRequest  limit="'.$limit.'" types="contact"  xmlns="urn:zimbraMail" >
                         <query>'. $recipient .'</query>
                     </SearchRequest>';

            $returnJSON = true;
            $response = $this->SoapRequest($soap, false, false, $returnJSON);
            if($response) {
                $array = json_decode($response, true);
                unset($response);

                if ( isset( $array['Body']['SearchResponse']['cn'] )) {
                    $contactEntry = $array['Body']['SearchResponse']['cn'];

                    $recipientResponse->recipientcount = (isset($recipientResponse->recipientcount) ? ($recipientResponse->recipientcount + count($contactEntry)) : count($contactEntry));
                    if (!isset($recipientResponse->recipient)) {
                        $recipientResponse->recipient = array();
                    }
                    unset($array);

                    for ($i=0;$i<count($contactEntry);$i++) {
                        if (isset($contactEntry[$i]['_attrs']['type']) && $contactEntry[$i]['_attrs']['type'] == 'group') {  // Skip GROUPS for now
                            $recipientResponse->recipientcount -= 1;
                            continue;
                        }
                        
                        $thisContactEntry = new SyncResolveRecipient();

                        if (isset($contactEntry[$i]['_attrs']['fullName'])) {
                            $thisContactEntry->displayname = $contactEntry[$i]['_attrs']['fullName'];
                        } elseif (isset($contactEntry[$i]['_attrs']['firstName'])) {
                            $thisContactEntry->displayname = $contactEntry[$i]['_attrs']['firstName'];
                            if (isset($contactEntry[$i]['_attrs']['lastName'])) {
                                $thisContactEntry->displayname .= ' ' . $contactEntry[$i]['_attrs']['lastName'];
                            }
                        } elseif (isset($contactEntry[$i]['_attrs']['lastName'])) {
                            $thisContactEntry->displayname = $contactEntry[$i]['_attrs']['lastName'];
                        } 
                        if (isset($contactEntry[$i]['_attrs']['email'])) {
                            $thisContactEntry->emailaddress = $contactEntry[$i]['_attrs']['email'];
                        }

                        $thisContactEntry->type = SYNC_RESOLVERECIPIENTS_TYPE_CONTACT;

                        $recipientResponse->recipient[] = $thisContactEntry;
                        unset($thisContactEntry);
                    }
                    $found = true;
                }

            } else {
                ZLog::Write(LOGLEVEL_DEBUG, 'Zimbra->(): ' . 'END ResolveRecipients { false (SOAP Error) }');
                $resolveRecipients->status = SYNC_RESOLVERECIPSSTATUS_SERVERERROR;
                return $resolveRecipients;

            }

            // If any recipient match is found from either search check for availability and/or certificate(s)
            if ($found) {
                $recipientResponse->recipientcount = count($recipientResponse->recipient);

                if (isset($availability)) {
                        ZLog::Write(LOGLEVEL_DEBUG, 'Zimbra->(): ' .  'Resolve Availability' );


                        $possibleRecipientsFound = count($recipientResponse->recipient);
                        $recipientList = "";
                        for ($i = 0; $i < $possibleRecipientsFound; $i++) {
                            $recipientList .= $recipientResponse->recipient[$i]->emailaddress . ",";
                     
                            $recipientResponse->recipient[$i]->availability = new SyncRRAvailability();
                            $recipientResponse->recipient[$i]->availability->status = 163;
                        }
                        $soap = '<GetFreeBusyRequest  s="'.$starttime.'000" e="'.$endtime.'000" name="'.$recipientList.'"  xmlns="urn:zimbraMail" />';

                        $returnJSON = true;
                        $response = $this->SoapRequest($soap, false, false, $returnJSON);
                        if($response) {
                            $array = json_decode($response, true);
                            unset($response);

                            if ( isset( $array['Body']['GetFreeBusyResponse']['usr'][0] )) {
                                $countFB = count($array['Body']['GetFreeBusyResponse']['usr']);
                            } else {
                                $countFB = 0;
                            }
                     
                            for ($fb = 0; $fb < $countFB; $fb++) {
                                $mergedFreeBusy = str_pad('4', $timeslots, '4' );

                                $userResponse = $array['Body']['GetFreeBusyResponse']['usr'][$fb];
                     
                                $fbaSoapKeyResponseKey = array( 'n'=>'4', 'f'=>'0', 't'=>'1', 'b'=>'2', 'u'=>'3' );

                                foreach ($fbaSoapKeyResponseKey as $soapKey=>$responseKey) {
                                    if (isset( $userResponse[$soapKey] )) {
                                        for ($i=0;$i<count($userResponse[$soapKey]);$i++) {
                                            $halfHourStart = $starttime;

                                            $periodStart = substr( $userResponse[$soapKey][$i]['s'], 0, 10);
                                            $periodEnd = substr( $userResponse[$soapKey][$i]['e'], 0, 10);

                                            for ($j=0;$j<=$timeslots;$j++) {
                                                if (($periodStart < $halfHourStart+1800) && ($periodEnd > $halfHourStart)) {
                                                    $mergedFreeBusy[$j] = $responseKey;
                                                }
                                                $halfHourStart += 1800;
                                            }

                                        }
                         
                                    }
                                }

                                for ($i = 0; $i < $possibleRecipientsFound; $i++) {
                                    if ($recipientResponse->recipient[$i]->emailaddress == $userResponse['id']) {
                     
                                        $recipientResponse->recipient[$i]->availability->status = 1;
                                        $recipientResponse->recipient[$i]->availability->mergedfreebusy = $mergedFreeBusy;
                                    }
                                }

                                unset( $soapKey );
                                unset( $responseKey );
                            }
                            unset($array);

                        } else {
                            ZLog::Write(LOGLEVEL_DEBUG, 'Zimbra->(): ' . 'END ResolveRecipients { false (SOAP Error) }');
                            $resolveRecipients->status = SYNC_RESOLVERECIPSSTATUS_SERVERERROR;
                            return $resolveRecipients;
                        }
                }

                if (isset($certificateretrieval)) {

                    // TO DO - Implement certificate retrieval - For now - Return NOVALIDCERT

                    $maxcerts = $data['maxcerts'];
                    $maxambigious = $data['maxambigious'];

                    $possibleRecipientsFound = count($recipientResponse->recipient);
                    for ($i = 0; $i < $possibleRecipientsFound; $i++) {
                        $recipientResponse->recipient[$i]->certificates = new SyncRRCertificates();
                        $recipientResponse->recipient[$i]->certificates->status = SYNC_RESOLVERECIPSSTATUS_CERTIFICATES_NOVALIDCERT;
                    }
                }
            } else { // if ($found)
                $recipientResponse->status = SYNC_RESOLVERECIPSSTATUS_RESPONSE_UNRESOLVEDRECIP;
            }
            $resolveRecipients->recipientresponse[$recip] = $recipientResponse;
            unset($recipientResponse);

        }
        if (count($resolveRecipients->recipientresponse) > 0) { 
            $resolveRecipients->status = SYNC_RESOLVERECIPSSTATUS_SUCCESS;

            ZLog::Write(LOGLEVEL_DEBUG, 'Zimbra->(): ' . 'END ResolveRecipients { true }');
            ZLog::Write(LOGLEVEL_DEBUG, 'Zimbra->(): ' . 'resolveRecipients RETURN' . print_r( $resolveRecipients, true ));
            return $resolveRecipients;
        }

        ZLog::Write(LOGLEVEL_DEBUG, 'Zimbra->(): ' . 'END ResolveRecipients { false (no soap) }');
        return false;
    } // end ResolveRecipients
