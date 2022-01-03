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
        $this->RegisterVariableString("Artist", "Artist");
        $this->RegisterVariableString("Album", "Album");
        $this->RegisterVariableString("Title", "Title");
        $this->RegisterVariableInteger("Position", "Position");
        $this->RegisterVariableInteger("Duration", "Duration");
        $this->RegisterVariableString("Cover", "Cover");
        $this->RegisterVariableFloat("Volume", "Volume");
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
            $this->SetValue("Title", isset($data['name']) ? $data['name'] : '-');
            $this->SetValue("Album", isset($data['artist']) ? $data['artist'] : '-');
            $this->SetValue("Artist", isset($data['album']) ? $data['album'] : '-');
            if(isset($data['trackImage']) && is_array($data['trackImage']) &&
            count($data['trackImage']) >= 1 && isset($data['trackImage'][0]['url'])) {
                $cover = $data['trackImage'][0]['url'];
            } else {
                $cover = "";
            }
            $this->SetValue("Cover", $cover);
            $this->SetValue("Duration", isset($data['duration']) ? $data['duration'] : 0);
            $this->SetValue("Position", 0);
            $this->SetValue("Application", isset($data['originalSource']) ? $data['originalSource'] : '-');
        }
        if($type === 'NOW_PLAYING_ENDED' && $kind === 'playing') {
            $this->SetValue("Artist", '-');
            $this->SetValue("Album", '-');
            $this->SetValue("Title", '-');
            $this->SetValue("Cover", "");
            $this->SetValue("Source", "-");
            $this->SetValue("Application", "-");
            $this->SetValue("Duration", 0);
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

        if($ident === 'Volume') {
            $this->SetVolume($this->GetHost(), $value);
        }

        $this->SendDebug('Action', $ident, 0);
    }

    //------------------------------------------------------------------------------------
    // external methods
    //------------------------------------------------------------------------------------
    public function SetVolume($volume) {
        return $this->SetVolume($this->GetHost());
    }

    public function Play($host) {
        return $this->Play($this->GetHost());
    }

    public function Pause($host) {
        return $this->Pause($this->GetHost());
    }

    public function Stop($host) {
        return $this->Stop($this->GetHost());
    }

    public function Next($host) {
        return $this->Next($this->GetHost());
    }

    public function Prev($host) {
        return $this->Prev($this->GetHost());
    }

    //------------------------------------------------------------------------------------
    // module internals
    //------------------------------------------------------------------------------------
    private function ResetState() {
        $this->SetValue("Artist", '-');
        $this->SetValue("Album", '-');
        $this->SetValue("Title", '-');
        $this->SetValue("Cover", "");
        $this->SetValue("Source", "-");
        $this->SetValue("Application", "-");
        $this->SetValue("Duration", 0);
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

    private function GetHost() {
        $parentID = $this->GetConnectionID();
        $ip = IPS_GetProperty($parentID, 'Host');
        $port = IPS_GetProperty($parentID, 'Port');
        return "$ip:$port";
    }

    private function Disconnect() {
        $this->JSCDisconnect(true);
    }
}