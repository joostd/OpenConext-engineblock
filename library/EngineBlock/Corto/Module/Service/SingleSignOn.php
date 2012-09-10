<?php

class EngineBlock_Corto_Module_Service_SingleSignOn extends EngineBlock_Corto_Module_Service_Abstract
{
    const RESPONSE_CACHE_TYPE_IN  = 'in';
    const RESPONSE_CACHE_TYPE_OUT = 'out';

    public function serve()
    {
        $request = $this->_server->getBindingsModule()->receiveRequest();
        $request[EngineBlock_Corto_XmlToArray::PRIVATE_PFX]['Transparent'] = $this->_server->getCurrentEntitySetting('TransparentProxy', false);

        // The request may specify it ONLY wants a response from specific IdPs
        // or we could have it configured that the SP may only be serviced by specific IdPs
        $scopedIdps = $this->_getScopedIdPs($request);

        $cacheResponseSent = $this->_sendCachedResponse($request, $scopedIdps);
        if ($cacheResponseSent) {
            return;
        }

        // If the scoped proxycount = 0, respond with a ProxyCountExceeded error
        if (isset($request['samlp:Scoping'][EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'ProxyCount']) && $request['samlp:Scoping'][EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'ProxyCount'] == 0) {
            $this->_server->getSessionLog()->debug("SSO: Proxy count exceeded!");
            $response = $this->_server->createErrorResponse($request, 'ProxyCountExceeded');
            $this->_server->sendResponseToRequestIssuer($request, $response);
            return;
        }

        // Get all registered Single Sign On Services
        $candidateIDPs = $this->_server->getIdpEntityIds();
        $posOfOwnIdp = array_search($this->_server->getCurrentEntityUrl('idPMetadataService'), $candidateIDPs);
        if ($posOfOwnIdp !== false) {
            unset($candidateIDPs[$posOfOwnIdp]);
        }

        $this->_server->getSessionLog()->debug(
            "SSO: Candidate idps found in metadata: " . print_r($candidateIDPs, 1)
        );

        // If we have scoping, filter out every non-scoped IdP
        if (count($scopedIdps) > 0) {
            $candidateIDPs = array_intersect($scopedIdps, $candidateIDPs);
        }

        $this->_server->getSessionLog()->debug(
            "SSO: Candidate idps found in metadata after scoping: " . print_r($candidateIDPs, 1)
        );

        // No IdPs found! Send an error response back.
        if (count($candidateIDPs) === 0) {
            $this->_server->getSessionLog()->debug("SSO: No Supported Idps!");
            if ($this->_server->getConfig('NoSupportedIDPError')!=='user') {
                $response = $this->_server->createErrorResponse($request, 'NoSupportedIDP');
                $this->_server->sendResponseToRequestIssuer($request, $response);
                return;
            }
            else {
                $output = $this->_server->renderTemplate(
                    'noidps',
                    array(
                    ));
                $this->_server->sendOutput($output);
                return;
            }
        }
        // Exactly 1 candidate found, send authentication request to the first one
        else if (count($candidateIDPs) === 1) {
            $idp = array_shift($candidateIDPs);
            $this->_server->getSessionLog()->debug("SSO: Only 1 candidate IdP: $idp");
            $this->_server->sendAuthenticationRequest($request, $idp);
            return;
        }
        // Multiple IdPs found...
        else {
            // > 1 IdPs found, but isPassive attribute given, unable to show WAYF
            if (isset($request[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'IsPassive']) && $request[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'IsPassive'] === 'true') {
                $this->_server->getSessionLog()->debug("SSO: IsPassive with multiple IdPs!");
                $response = $this->_server->createErrorResponse($request, 'NoPassive');
                $this->_server->sendResponseToRequestIssuer($request, $response);
                return;
            }
            else {
                // Store the request in the session
                $id = $request[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'ID'];
                $_SESSION[$id]['SAMLRequest'] = $request;

                // Show WAYF
                $this->_server->getSessionLog()->debug("SSO: Showing WAYF");
                $this->_showWayf($request, $candidateIDPs);
                return;
            }
        }
    }

    protected function _getScopedIdPs($request = null)
    {
        $scopedIdPs = array();
        // Add scoped IdPs (allowed IDPs for reply) from request to allowed IdPs for responding
        if (isset($request['samlp:Scoping']['samlp:IDPList']['samlp:IDPEntry'])) {
            foreach ($request['samlp:Scoping']['samlp:IDPList']['samlp:IDPEntry'] as $IDPEntry) {
                $scopedIdPs[] = $IDPEntry[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'ProviderID'];
            }
            $this->_server->getSessionLog()->debug("SSO: Request contains scoped idps: " . print_r($scopedIdPs, 1));
        }

        $presetIdPs = $this->_server->getCurrentEntitySetting('IDPList');
        $presetIdP  = $this->_server->getCurrentEntitySetting('Idp');

        // If we have ONE specific IdP pre-configured then we scope to ONLY that Idp
        if ($presetIdP) {
            $scopedIdPs = array($presetIdP);
            $this->_server->getSessionLog()->debug("SSO: Scoped idp found in metadata: " . $scopedIdPs[0]);
        }
        // If we configured an IDPList it overrides the one in the request
        else if ($presetIdPs) {
            $scopedIdPs = $presetIdPs;
            $this->_server->getSessionLog()->debug("SSO: Scoped idps found in metadata: " . print_r($scopedIdPs, 1));
        }
        return $scopedIdPs;
    }

    protected function _sendCachedResponse($request, $scopedIdps)
    {
        if (isset($request[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'ForceAuthn']) && $request[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'ForceAuthn']) {
            return false;
        }

        if (!isset($_SESSION['CachedResponses'])) {
            return false;
        }

        $cachedResponses = $_SESSION['CachedResponses'];

        $requestIssuerEntityId  = $request['saml:Issuer'][EngineBlock_Corto_XmlToArray::VALUE_PFX];

        // First, if there is scoping, we reject responses from idps not in the list
        if (count($scopedIdps) > 0) {
            foreach ($cachedResponses as $key => $cachedResponse) {
                if (!in_array($cachedResponse['idp'], $scopedIdps)) {
                    unset($cachedResponses[$key]);
                }
            }
        }
        if (empty($cachedResponses)) {
            return false;
        }

        $cachedResponse = $this->_pickCachedResponse($cachedResponses, $request, $requestIssuerEntityId);
        if (!$cachedResponse) {
            return false;
        }

        if ($cachedResponse['type'] === self::RESPONSE_CACHE_TYPE_OUT) {
            $this->_server->getSessionLog()->debug("SSO: Cached response found for SP");
            $response = $this->_server->createEnhancedResponse($request, $cachedResponse['response']);
            $this->_server->sendResponseToRequestIssuer($request, $response);
        }
        else {
            $this->_server->getSessionLog()->debug("SSO: Cached response found from Idp");
            // Note that we would like to repurpose the response,
            // but that's tricky as it is probably no longer valid (lifetime is usually something like 5 minutes)
            // so instead we scope the request to that Idp and trust the Idp to do the remembering.
            $this->_server->sendAuthenticationRequest($request, $cachedResponse['idp']);
        }
        return true;
    }

    protected function _pickCachedResponse(array $cachedResponses, array $request, $requestIssuerEntityId)
    {
        // Then we look for OUT responses for this sp
        $idpEntityIds = $this->_server->getIdpEntityIds();
        foreach ($cachedResponses as $cachedResponse) {
            if ($cachedResponse['type'] !== self::RESPONSE_CACHE_TYPE_OUT) {
                continue;
            }

            // Check if it is for the requester
            if ($cachedResponse['sp'] !== $requestIssuerEntityId) {
                continue;
            }

            // Check if it is for a valid idp
            if (!in_array($cachedResponse['idp'], $idpEntityIds)) {
                continue;
            }

            if (isset($cachedResponse['vo'])) {
                $this->_server->setVirtualOrganisationContext($cachedResponse['vo']);
            }

            return $cachedResponse;
        }

        // Then we look for IN responses for this sp
        foreach ($cachedResponses as $cachedResponse) {
            if ($cachedResponse['type'] !== self::RESPONSE_CACHE_TYPE_IN) {
                continue;
            }

            // Check if it is for a valid idp
            if (!in_array($cachedResponse['idp'], $idpEntityIds)) {
                continue;
            }

            if (isset($cachedResponse['vo'])) {
                $this->_server->setVirtualOrganisationContext($cachedResponse['vo']);
            }

            return $cachedResponse;
        }

        return false;
    }

    protected function _showWayf($request, $candidateIdPs)
    {
        // Post to the 'continueToIdp' service
        $action = $this->_server->getCurrentEntityUrl('continueToIdP');

        $requestIssuer = $request['saml:Issuer'][EngineBlock_Corto_XmlToArray::VALUE_PFX];

        $remoteEntity = $this->_server->getRemoteEntity($requestIssuer);

        $idpList = $this->_transformIdpsForWAYF($candidateIdPs);

        $output = $this->_server->renderTemplate(
            'discover',
            array(
                'preselectedIdp'    => $this->_server->getCookie('selectedIdp'),
                'action'            => $action,
                'ID'                => $request[EngineBlock_Corto_XmlToArray::ATTRIBUTE_PFX . 'ID'],
                'idpList'           => $idpList,
                'metaDataSP'        => $remoteEntity,
            ));
        $this->_server->sendOutput($output);
    }

    protected function _transformIdpsForWayf($idps)
    {
        $wayfIdps = array();
        foreach ($idps as $idp) {
            $remoteEntities = $this->_server->getRemoteEntities();
            $metadata = ($remoteEntities[$idp]);
            $additionalInfo = new EngineBlock_Log_Message_AdditionalInfo(
                null, $idp, null, null
            );

            if (isset($metadata['DisplayName']['nl'])) {
                $nameNl = $metadata['DisplayName']['nl'];
            }
            else if (isset($metadata['Name']['nl'])) {
                $nameNl = $metadata['Name']['nl'];
            }
            else {
                $nameNl = 'Geen naam gevonden';
                EngineBlock_ApplicationSingleton::getLog()->warn('No NL displayName and name found for idp: ' . $idp, $additionalInfo);
            }

            if (isset($metadata['DisplayName']['en'])) {
                $nameEn = $metadata['DisplayName']['en'];
            }
            else if (isset($metadata['Name']['en'])) {
                $nameEn = $metadata['Name']['en'];
            }
            else {
                $nameEn = 'No name found';
                EngineBlock_ApplicationSingleton::getLog()->warn('No EN displayName and name found for idp: ' . $idp, $additionalInfo);
            }

            $wayfIdp = array(
                'Name_nl' => $nameNl,
                'Name_en' => $nameEn,
                'Logo' => isset($metadata['Logo']['URL']) ? $metadata['Logo']['URL']
                    : EngineBlock_View::staticUrl() . '/media/idp-logo-not-found.png',
                'Keywords' => isset($metadata['Keywords']['en']) ? explode(' ', $metadata ['Keywords']['en'])
                    : isset($metadata['Keywords']['nl']) ? explode(' ', $metadata['Keywords']['nl']) : 'Undefined',
                'Access' => '1',
                'ID' => md5($idp),
                'EntityId' => $idp,
            );
            $wayfIdps[] = $wayfIdp;
        }

        return $wayfIdps;
    }
}