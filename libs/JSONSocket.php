<?php

trait JSONSocketClient {
    protected function WSCCreate() {
        $this->RegisterMessage(0, IPS_KERNELSHUTDOWN);
    }

    protected function WSCResetState() {
        $this->SetReceiveDataFilter('');
        $this->MUSetBuffer('Data', '');
        $this->MUSetBuffer('State', 0);
        $this->MUSetBuffer('PayloadType', 0);
        $this->MUSetBuffer('PayloadData', '');
    }

    protected function WSCSetReceiveDataFilter($filter) {
        $this->MUSetBuffer('WSCReceiveDataFilter', $filter);
        if($this->MUGetBuffer('State') == 2) {
            if($filter) {
                $this->SetReceiveDataFilter($filter);
            } else {
                $this->SetReceiveDataFilter('');
            }
        }
    }

    protected function WSCRequestAction($value) {
    }

    protected function WSCMessageSink($TimeStamp, $SenderID, $Message, $Data) {
    }

    /**
     *
     */
    protected function WSCConnect($ip, $path, $cookie)
    {
        $Header[] = 'GET ' . $path . ' HTTP/1.1';
        $Header[] = 'Host: ' . $ip;
        $Header[] = "\r\n";
        $SendData = implode("\r\n", $Header);
        //$this->SendDebug('Send Handshake', $SendData, 0);

        $this->MUSetBuffer('State', 1);

        $JSON['DataID'] = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
        $JSON['Buffer'] = utf8_encode($SendData);
        $JsonString = json_encode($JSON);
        parent::SendDataToParent($JsonString);

        return true;
    }

    protected function WSCGetState() {
        return $this->MUGetBuffer('State');
    } 

    protected function WSCDisconnect($canReconnect = true) {
        $parentID = $this->GetConnectionID();
        if (!IPS_GetProperty($parentID, 'Open')) {
            return;
        }
        IPS_SetProperty($parentID, 'Open', false);
        @IPS_ApplyChanges($parentID);

        if($canReconnect && $this->WSCOnDisconnect()) {
            IPS_SetProperty($parentID, 'Open', true);
            @IPS_ApplyChanges($parentID);
        }
    }

    protected function WSCReceiveData($data)
    {
        // unpack & decode data
        $data = json_decode($data);
        $data = utf8_decode($data->Buffer);

        $state = $this->MUGetBuffer('State');
        $data = $this->MUGetBuffer('Data') . $data;

        if($state === 0) {
            $this->SendDebug('Error', 'Unexpected data received while connecting', 0);
        } else if($state === 1) {
            try {
                if (strpos($data, "\r\n\r\n") !== false) {
                    //$this->SendDebug('Handshake response', $data, 0);

                    $this->MUSetBuffer('Data', '');
                    $this->MUSetBuffer('State', 2);

                    $filter = $this->MUGetBuffer('WSCReceiveDataFilter');
                    if($filter) {
                        $this->SetReceiveDataFilter($filter);
                    }
                    return;
                } else {
                    $this->SendDebug('Incomplete handshake response', $data, 0);
                    throw new Exception("Incomplete handshake response received");
                }
            }  catch (Exception $exc) {
                $this->SendDebug('Error', $exc->GetMessage(), 0);
                $this->WSCDisconnect();
                trigger_error($exc->getMessage(), E_USER_NOTICE);
                return;
            }
        } else if($state === 2) {
            while(true) {
                $idx = strpos($data, "\n");
                if($idx === false) break;
                $packet = substr($data, 0, $idx);
                $data = substr($data, $idx+1);
                try {
                    $this->WSCOnReceiveData(json_decode($packet, true));
                } catch(Exception $e) {
                    trigger_error("Error in websocket data handler: " . $exc->getMessage(), E_USER_WARNING);
                    $this->SendDebug('Received Data', $data, 0);
                }
            }
        }

        if(strlen($data) > 1024 * 1024) {
            $this->WSCDisconnect();
            trigger_error("Maximum frame size exceeded", E_USER_NOTICE);
            return;
        }

        $this->MUSetBuffer('Data', $data);
    }
}