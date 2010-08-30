<?php
 
class EngineBlock_Router_Service extends EngineBlock_Router_Abstract
{
    public function route($uri)
    {
        $urlParts = preg_split('/\//', $uri, 0, PREG_SPLIT_NO_EMPTY);

        if ($urlParts[0] !== 'service') {
            return false;
        }

        $this->_moduleName      = 'Service';
        $this->_controllerName  = 'Rest';
        $this->_actionName      = $urlParts[1];
        $this->_actionArguments = array(
            implode('/', array_slice($urlParts, 1))
        );
        
        return true;
    }
}