<?php

trait JSONSocketClient {
    protected function JSCCreate() {
        $this->RegisterMessage(0, IPS_KERNELSHUTDOWN);
    }

    protected function JSCResetState() {
        $this->SetReceiveDataFilter('');
        $this->MUSetBuffer('Data', '');
        $this->MUSetBuffer('State', 0);
        $this->MUSetBuffer('PayloadType', 0);
        $this->MUSetBuffer('PayloadData', '');
    }

    protected function JSCSetReceiveDataFilter($filter) {
        $this->MUSetBuffer('JSCReceiveDataFilter', $filter);
        if($this->MUGetBuffer('State') == 2) {
            if($filter) {
                $this->SetReceiveDataFilter($filter);
            } else {
                $this->SetReceiveDataFilter('');
            }
        }
    }

    protected function JSCRequestAction($value) {
    }

    protected function JSCMessageSink($TimeStamp, $SenderID, $Message, $Data) {
    }

    /**
     *
     */
    protected function JSCConnect($ip, $path)
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

    protected function JSCGetState() {
        return $this->MUGetBuffer('State');
    } 

    protected function JSCDisconnect($canReconnect = true) {
        $parentID = $this->GetConnectionID();
        if (!IPS_GetProperty($parentID, 'Open')) {
            return;
        }
        IPS_SetProperty($parentID, 'Open', false);
        @IPS_ApplyChanges($parentID);

        if($canReconnect && $this->JSCOnDisconnect()) {
            IPS_SetProperty($parentID, 'Open', true);
            @IPS_ApplyChanges($parentID);
        }
    }

    protected function JSCReceiveData($data)
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

                    $filter = $this->MUGetBuffer('JSCReceiveDataFilter');
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
                $this->JSCDisconnect();
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
                    $this->JSCOnReceiveData(json_decode($packet, true));
                } catch(Exception $e) {
                    trigger_error("Error in websocket data handler: " . $exc->getMessage(), E_USER_WARNING);
                    $this->SendDebug('Received Data', $data, 0);
                }
            }
        }

        if(strlen($data) > 1024 * 1024) {
            $this->JSCDisconnect();
            trigger_error("Maximum frame size exceeded", E_USER_NOTICE);
            return;
        }

        $this->MUSetBuffer('Data', $data);
    }
}