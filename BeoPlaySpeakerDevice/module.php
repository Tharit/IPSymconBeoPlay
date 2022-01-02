<?php

/**
API to fetch events

https://192.168.1.1/proxy/network/api/s/default/stat/event?start=1637485298&end=1637488857&_limit=100

 */
require_once(__DIR__ . '/../libs/ModuleUtilities.php');
require_once(__DIR__ . '/../libs/JSONSocket.php');
require_once(__DIR__ . '/../libs/BeoPlayAPI.php');

class BeoPlaySpeakerDevice extends IPSModule
{
    use ModuleUtilities;
    use JSONSocketClient;
    use BeoPlayAPI;

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RequireParent('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'); // IO Client Socket

        // timers
        $this->JSCCreate();

        // variables
        $this->RegisterVariableString("Source", "Source");
        $this->RegisterVariableString("Application", "Application");
        $this->RegisterVariableString("State", "State");
        $this->RegisterVariableString("Title", "Title");
        $this->RegisterVariableString("Position", "Position");
        $this->RegisterVariableString("Cover", "Cover");
        $this->RegisterVariableFloat("Volume", "Volume", "~Intensity.1");
        $this->EnableAction("Volume");

        // messages
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        // clear state on startup
        $this->ResetState();

        // if this is not the initial creation there might already be a parent
        if($this->UpdateConnection() && $this->HasActiveParent()) {
            $this->SendDebug('Module Create', 'Already connected', 0);
            $this->Disconnect();
        }
    }

    /**
     * Configuration changes
     */
    public function ApplyChanges()
    {
        $parentID = $this->GetConnectionID();

        if (IPS_GetProperty($parentID, 'Open')) {
            $this->JSCDisconnect(false);
            //IPS_SetProperty($parentID, 'Open', false);
            //@IPS_ApplyChanges($parentID);
        }

        parent::ApplyChanges();

        if (!IPS_GetProperty($parentID, 'Open')) {
            IPS_SetProperty($parentID, 'Open', true);
            @IPS_ApplyChanges($parentID);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->JSCMessageSink($TimeStamp, $SenderID, $Message, $Data);

        switch ($Message) {
            case IPS_KERNELSTARTED:
            case FM_CONNECT:
                $this->SendDebug('STARTED / CONNECT', 'resetting connection', 0);
                // if new parent and it is already active: connect immediately
                if($this->UpdateConnection() && $this->HasActiveParent()) {
                    $this->ApplyChanges();
                }
                break;
            case FM_DISCONNECT:
                $this->ResetState();
                $this->UpdateConnection();
                break;
            case IM_CHANGESTATUS:
                // reset state
                $this->ResetState();

                $this->SendDebug('CHANGESTATUS', json_encode($Data), 0);

                // if parent became active: connect
                if ($Data[0] === IS_ACTIVE) {
                    $this->Connect();
                }
                break;
            default:
                break;
        }
    }

    public function ReceiveData($data) {
        $this->JSCReceiveData($data);
    }

    protected function JSCOnDisconnect() {
        return true;
    }
 
    protected function JSCOnReceiveData($data) {
        if(!isset($data['notification'])) return;
        $data = $data['notification'];
        $type = $data['type'];
        $kind = $data['kind'];
        $data = $data['data'];

        // volume
        if($type === 'VOLUME' && $kind === 'renderer' &&
            isset($data['speaker']) && isset($data['speaker']['level'])) {
            $this->SetValue("Volume", $data['speaker']['level']);
        }

        // source
        if($type === 'SOURCE' && $kind === 'source' &&
            isset($data['primaryExperience']) && isset($data['primaryExperience']['source'])) {
            $source = isset($data['primaryExperience']['source']['friendlyName']) ?
                $data['primaryExperience']['source']['friendlyName'] : '-';
            $this->SetValue("Source", $source);
        }

        // title
        if($type === 'NOW_PLAYING_STORED_MUSIC' && $kind === 'playing') {
            if(isset($data['name'])) {
                $newTitle = $data['name'];
                if(isset($data['artist'])) {
                    $newTitle .= ' • ' . $data['artist'];
                }
            } else {
                $newTitle = '-';
            }
            $this->SetValue("Title", $newTitle);
            if(isset($data['trackImage']) && is_array($data['trackImage']) &&
            count($data['trackImage']) >= 1 && isset($data['trackImage'][0]['url'])) {
                $cover = $data['trackImage'][0]['url'];
            } else {
                $cover = "";
            }
            $this->SetValue("Cover", $cover);
        }
        if($type === 'NOW_PLAYING_ENDED' && $kind === 'playing') {
            $this->SetValue("Title", '-');
            $this->SetValue("Cover", "");
        }

        // progress
        if($type === 'PROGRESS_INFORMATION' && $kind === 'playing') {
            $newState = isset($data['state']) ? $data['state'] : 'stop';
            $this->SetValue("State", $newState);
            $this->SetValue("Position", isset($data['position']) ? $data['position'] : 0);
        }
    }

    public function RequestAction($ident, $value)
    {
        if($ident === 'JSC') {
            $this->JSCRequestAction($value);
            return;
        }

        $this->SendDebug('Action', $ident, 0);
    }

    //------------------------------------------------------------------------------------
    // external methods
    //------------------------------------------------------------------------------------
    public function GetTrackerData() {
        $data = $this->MUGetBuffer('Tracker');
        return json_encode(empty($data) ? null : $data);
    }

    public function GetMediaData() {
        $data = $this->MUGetBuffer('Media');
        return json_encode(empty($data) ? null : $data);
    }

    //------------------------------------------------------------------------------------
    // module internals
    //------------------------------------------------------------------------------------
    private function ResetState() {
        $this->SetValue('Title', '-');
        $this->SetValue('Source', '-');
        $this->SetValue('State', 'stop');
        $this->MUSetBuffer('Media', null);
        $this->MUSetBuffer('Tracker', null);
        $this->JSCResetState();
    }

    private function Connect() {
        if($this->JSCGetState() != 0) {
            IPS_LogMessage('JSC', 'Tried to connect while already connected');
            return;
        }
        $parentID = $this->GetConnectionID();
        $ip = IPS_GetProperty($parentID, 'Host');
        $path = '/BeoNotify/Notifications';
        $this->JSCConnect($ip, $path);
    }

    private function Disconnect() {
        $this->JSCDisconnect(true);
    }
}