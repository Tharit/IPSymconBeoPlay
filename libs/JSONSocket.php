<?php

trait JSONSocketClient {
    protected function JSCCreate() {
        $this->RegisterMessage(0, IPS_KERNELSHUTDOWN);
    }

    protected function JSCResetState() {
        $this->SetReceiveDataFilter('');
        $this->MUSetBuffer('Data', '');
        $this->MUSetBuffer('State', 0);
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
            $idx = strpos($data, "\r\n\r\n");
            if ($idx !== false) {
                //$this->SendDebug('Handshake response', $data, 0);

                $this->MUSetBuffer('Data', '');
                $this->MUSetBuffer('State', 2);

                $filter = $this->MUGetBuffer('JSCReceiveDataFilter');
                if($filter) {
                    $this->SetReceiveDataFilter($filter);
                }

                $this->JSCOnConnect();

                $data = substr($data, $idx+4);
            }
        } else if($state === 2) {
            // chunked encoding
            // <#octets>CRLF<data>CRLF
            while(true) {
                $this->SendDebug('Received Chunk', $data, 0);
                $idx = strpos($data, "\r\n");
                if($idx === false) break;
                $this->SendDebug('Received Chunk Size', substr($data, 0, $idx), 0);
                $numOctets = hexdec(substr($data, 0, $idx));

                // if zero then this is the last chunk
                if($numOctets === 0) {
                    $this->SendDebug('Received data', 'Last chunk received', 0);
                    $this->JSCResetState();
                    $this->JSCOnReceiveData(null);
                    return;
                }

                $chunk = substr($data, $idx+2, $numOctets);
                $data = substr($data, $idx+2+$numOctets+2);
                if($chunk) {
                    while(true) {

                        $idx = strpos($chunk, "\r\n");
                        if($idx === false) break;
                        $packet = substr($chunk, 0, $idx);
                        $chunk = substr($chunk, $idx+2);

                        if($packet) {
                            $this->SendDebug('Received Data', $packet, 0);
                            try {
                                $this->JSCOnReceiveData(json_decode($packet, true));
                            } catch(Exception $e) {
                                trigger_error("Error in websocket data handler: " . $exc->getMessage(), E_USER_WARNING);
                            }
                        }
                    }
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