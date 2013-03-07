<?
//
// ACS
//
// ref: http://www.broadband-forum.org/technical/download/TR-069_Amendment-2.pdf
// pp comments refer to a page in this document
//
ob_start('ns1_filter'); // beware: cwmp xsd is added as "ns1" by php soap, so we rename it later...
ini_set("soap.wsdl_cache_enabled",0); 
ini_set("session.auto_start",0);

// Configuration:
include('/etc/sams/acs.php');

// this class must be defined before we start a session or SOAP Server:
class ACS {
	public $SAMS;               // SkyMesh Application Management System (source of provisioning data)
	public $ID;                 // ID from cwmp Soap Header
	public $SessionID;          // PHPSESSID aka 'ID' in the http header cookie
	public $Username;           // http username
	public $Password;           // http password
	public $CWMP;               // URN for cwmp 1.0
	public $xsd;                // xsd for XMLSchema
	public $DeviceID;           // Object with CPE data (SerialNumber etc)
	public $RawEvents;          // (raw) Object of events from last Inform
	public $MaxEnvelopes;       // ignored ...
	public $CurrentTime;        // Timestamp from last Inform
	public $RetryCount;         // retry counter from last Inform (>0 = errors?)
	public $RawParameterList;   // (raw) Object of parameters from Inform
	public $Methods;            // CPE Methods linear array of strings
	public $Data;               // Object data as assoc array
	public $Events;             // Events since last Inform linear array
	public $Params;             // CPE parameters, ReadOnly or Writable
	public $Queued;             // Reqs to sent to CPE
	public $VarTypes;           // The (get)type of each CPE parameter
	public $Attributes;         // captured attributes per parameter
	public $Calls;              // counter of '__wakeup' calls
	public $BulkReq;            // certain Methods support up to 256 reqs

	private function DEBUG($pre,$str)  { syslog(LOG_DEBUG,sprintf("[%d] %s::%s",getmypid(),$pre,$str)); }
	private function logger($pre,$str) { syslog(LOG_INFO, sprintf("[%d] %s::%s",getmypid(),$pre,$str)); }
	private function ERROR($pre,$str)  { syslog(LOG_ERR,  sprintf("[%d] %s::%s",getmypid(),$pre,$str)); }

	private function DUMPER($TITLE, $anything) {
		ob_start();
		print "===============================================================\n";
		print "*** $TITLE ***\n";
		print "===============================================================\n";
		var_dump($anything);
		print "===============================================================\n";
		$dumping = ob_get_clean();
		///file_put_contents('/tmp/ACS.dumped.txt',$dumping,LOCK_EX|FILE_APPEND);
		foreach(explode("\n",$dumping) as $line) $this->DEBUG('DUMPER',$line);
	}

	// FIXME: needs to auth to the CPE!
	private function RequestConnect() {
		$URL = $this->Data['InternetGatewayDevice.ManagementServer.ConnectionRequestURL'];
		file_get_contents($URL);	
	}

	// match the type and the value, or send a change ...
	private function CheckDataChange( $ObjectName, $VarType, $DataReqd ) {
		if (array_key_exists($ObjectName,$this->Data)) {
			switch ($VarType) {
				case 'boolean':
					if ($this->Data[$ObjectName] !== $DataReqd) {
						$this->Enqueue("SetParameterValues",'SETLIST',array('ParameterList','ParameterValueStruct',
							array('Type'=>$VarType,'Name'=>$ObjectName,'Value'=>($DataReqd?"1":"0")),1),"SET");
						$this->Enqueue("GetParameterValues",'ARRAY',array('ParameterNames','string',array($ObjectName),1),NULL);
					}
				break;
				case 'string':
				case 'unsignedInt':
				case 'int':
					if ($this->Data[$ObjectName] !== $DataReqd) {
						$this->Enqueue("SetParameterValues",'SETLIST',array('ParameterList','ParameterValueStruct',
							array('Type'=>$VarType,'Name'=>$ObjectName,'Value'=>$DataReqd),1),"SET");
						$this->Enqueue("GetParameterValues",'ARRAY',array('ParameterNames','string',array($ObjectName),1),NULL);
					}
				break;
				default:
					$this->ERROR('CheckDataChange',"TYPE $VarType UNKNOWN");
			}
		} else $this->ERROR('CheckDataChange',"OBJECT $ObjectName NOT FOUND");
	}

	// match the type and the value, or send a change ...
	private function CheckAttrChange( $ObjectName, $ReqdNotificationLevel ) {
		$_level = -1;
		switch ($ReqdNotificationLevel) {
			case "Active": $_level = 2; break;
			case "Passive": $_level = 1; break;
			case "Off": $_level = 0; break;
			default:
				$this->ERROR('CheckAttrChange',"INVALID ReqdNotificationLevel");
				return;
		}
		if (array_key_exists($ObjectName,$this->Attributes)) {
			if ($this->Attribute[$ObjectName]->Notification !== $ReqdNotificationLevel) {
				$this->Enqueue("SetParameterAttributes",'SETATTR',array('ParameterList','SetParameterAttributesStruct',
					array(
						'Name'=>$ObjectName,
						'NotificationChange'=>"1",
						'Notitification'=>$_level,
						'AccessListChange'=>"0",
						'AccessList'=>""
					),1),"ATTR");
				$this->Enqueue("GetParameterAttributes",'ARRAY',array('ParameterNames','string',array($ObjectName),1),NULL);
			}
		} else $this->ERROR('CheckAttrChange',"OBJECT $ObjectName NOT FOUND");
	}

	private function SkyMesh() {
		$mgmt_prefix = 'InternetGatewayDevice.ManagementServer.';
		$username = $this->Data['InternetGatewayDevice.DeviceInfo.ProvisioningCode'];
		$password = $this->Data['InternetGatewayDevice.DeviceInfo.ProvisioningCode'];
		$this->CheckDataChange($mgmt_prefix.'PeriodicInformEnable','boolean',TRUE); // Inform
		$this->CheckDataChange($mgmt_prefix.'PeriodicInformInterval','unsignedInt',3600); // 1 hour
		$this->CheckDataChange($mgmt_prefix.'Username','string',$username);
		// special case: the CPE will not show us the password!
		if ($this->Password !== $password) {
			$this->CheckDataChange($mgmt_prefix.'Password','string',$password);
			$this->Password = $password;
		}
		//
		return (count($this->Queued)>0);
	}

	private function NBN() {
		$xVpPrefix = 'InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.';
		$xDigitMap = '(000E|106E|111|121|151|181|*xx.T|013|12[23]x|124xx|125xxx|119[46]|130xxxxxxx|13xxxx|1345xxxx|180[01]xxxxxx|180[2-9]xxx|183x.T|18[4-7]xx|18[89]xx|[345689]xxxxxxx|0[23478]xxxxxxxx|001x.T)';

		if (array_key_exists($xVpPrefix.'1.Enable',$this->Data)) {
			$this->CheckDataChange($xVpPrefix.'1.DigitMap',                                  'string',$xDigitMap);
			$this->CheckDataChange($xVpPrefix.'1.Enable',                                    'string','Enabled');
			$this->CheckDataChange($xVpPrefix.'1.RTP.DSCPMark',                              'unsignedInt',46);
			$this->CheckDataChange($xVpPrefix.'1.SIP.DSCPMark',                              'unsignedInt',46);
			$this->CheckDataChange($xVpPrefix.'1.SIP.OutboundProxy',                         'string',$GLOBALS['ACS_SIP_SBC']);
			$this->CheckDataChange($xVpPrefix.'1.SIP.ProxyServer',                           'string',$GLOBALS['ACS_SIP_REG']);
			$this->CheckDataChange($xVpPrefix.'1.SIP.RegisterExpires',                       'unsignedInt',1800);
			$this->CheckDataChange($xVpPrefix.'1.SIP.RegistrationPeriod',                    'unsignedInt',1740);
			$this->CheckDataChange($xVpPrefix.'1.SIP.UserAgentDomain',                       'string','');
			$this->CheckDataChange($xVpPrefix.'1.FaxT38.Enable',                             'boolean',TRUE);
			$this->CheckDataChange($xVpPrefix.'1.Line.1.Enable',                             'string','Enabled');
			$this->CheckDataChange($xVpPrefix.'1.Line.1.PhyReferenceList',                   'string',"1");
			$this->CheckDataChange($xVpPrefix.'1.Line.1.CallingFeatures.CallWaitingEnable',  'boolean',TRUE);
			$this->CheckDataChange($xVpPrefix.'1.Line.1.CallingFeatures.MWIEnable',          'boolean',TRUE);
			$this->CheckDataChange($xVpPrefix.'1.Line.1.CallingFeatures.CallTransferEnable', 'boolean',FALSE);
			$this->CheckDataChange($xVpPrefix.'1.Line.1.Codec.List.1.Enable',                'boolean',TRUE);
			$this->CheckDataChange($xVpPrefix.'1.Line.1.Codec.List.1.PacketizationPeriod',   'string',"20");
			$this->CheckDataChange($xVpPrefix.'1.Line.1.Codec.List.1.SilenceSuppression',    'boolean',FALSE);
			$this->CheckDataChange($xVpPrefix.'1.Line.1.Codec.List.2.Enable',                'boolean',TRUE);
			$this->CheckDataChange($xVpPrefix.'1.Line.1.Codec.List.2.PacketizationPeriod',   'string',"20");
			$this->CheckDataChange($xVpPrefix.'1.Line.1.Codec.List.2.SilenceSuppression',    'boolean',FALSE);
			$this->CheckDataChange($xVpPrefix.'1.Line.1.Codec.List.3.Enable',                'boolean',FALSE);
			$this->CheckDataChange($xVpPrefix.'1.Line.1.Codec.List.3.PacketizationPeriod',   'string',"20");
			$this->CheckDataChange($xVpPrefix.'1.Line.1.Codec.List.3.SilenceSuppression',    'boolean',FALSE);

			// FIXME: inifinte loop: $this->CheckAttrChange($xVpPrefix.'1.Line.1.Status',    "Active");
			// FIXME: inifinte loop: $this->CheckAttrChange($xVpPrefix.'1.Line.1.CallState', "Active");

			// send SIP login details, if known .... else?
			if (!is_null($this->SAMS)) {
				$this->CheckDataChange($xVpPrefix.'1.Line.1.SIP.AuthUserName','string',$this->SAMS['number']);
				$this->CheckDataChange($xVpPrefix.'1.Line.1.SIP.AuthPassword','string',$this->SAMS['password']);
			}

		} else {
			// send AddObject for $xVpPrefix
			$this->Enqueue("AddObject",'FLAT',array('ObjectName'=>$xVpPrefix,'ParameterKey'=>""));
			// kick off a walk of the object to get the current values
			$this->Enqueue("GetParameterNames",'FLAT',array('ParameterPath'=>$xVpPrefix,'NextLevel'=>"1"),NULL);
		}
		if (array_key_exists($xVpPrefix.'2.Enable',$this->Data)) {
			// send DeleteObject
			$this->Enqueue("DeleteObject",'FLAT',array('ObjectName'=>$xVpPrefix.'2.'),"DeleteObject");
			$this->ERROR('DeleteObject',$xVpPrefix.'2');
		}
		if (array_key_exists($xVpPrefix.'3.Enable',$this->Data)) {
			// send DeleteObject
			$this->Enqueue("DeleteObject",'FLAT',array('ObjectName'=>$xVpPrefix.'3.'),"DeleteObject");
			$this->ERROR('DeleteObject',$xVpPrefix.'3');
		}
		if (array_key_exists($xVpPrefix.'4.Enable',$this->Data)) {
			// send DeleteObject
			$this->Enqueue("DeleteObject",'FLAT',array('ObjectName'=>$xVpPrefix.'4.'),"DeleteObject");
			$this->ERROR('DeleteObject',$xVpPrefix.'4');
		}
		return (count($this->Queued)>0);
	}

	private function FetchSAMS() {
		$dbh = DBH();
		if (is_null($this->SAMS)) {
			if (is_object($this->DeviceID)) {
				if (strlen($this->DeviceID->SerialNumber)>0) {
					$sth = $dbh->query(sprintf(
						"select * from nbn.avc_provisioning_univ where avc_id='%s' or ipaddr='%s'",
						$this->Data['InternetGatewayDevice.DeviceInfo.ProvisioningCode'],$_SERVER['REMOTE_ADDR']
					));
					$this->SAMS = $sth->fetch(PDO::FETCH_ASSOC);
					$this->DUMPER('SAMS DATA',$this->SAMS);
				} else {
					$this->ERROR('SAMS DATA','NOT FOUND');
					// $this->SAMS = NULL; // stays NULL
					// exit ........
					session_destroy();
					header('HTTP/1.1 204 No Content');
					die; // just send an empty response ...
				}
			}
		}
	}

	private function SaveToCache() {
		// save the object data in the SAMS memcache:
		$memcache = MC();
		if (is_object($this->DeviceID))
			if (strlen($this->DeviceID->SerialNumber)>0)
				$memcache->set($this->DeviceID->SerialNumber,
					array_merge(array('TS'=>time()),get_object_vars($this)),0,604799);
	}

	public function __wakeup() {
		// see: http://au.php.net/manual/en/language.oop5.magic.php#object.wakeup
		$this->DEBUG('WAKEUP','SessionID = '.session_id());
		$this->Calls++;
		$this->FetchSAMS(); // updates?
	}

	public function __construct() { 
		$this->DEBUG('CONSTRUCT','SessionID = '.session_id());
		$this->CWMP = 'urn:dslforum-org:cwmp-1-0';
		$this->xsd = 'http://www.w3.org/2001/XMLSchema';
		$this->SessionID = session_id();
		$this->Methods = array();
		$this->Events = array();
		$this->Queued = array();
		$this->Username = $_SESSION['PHP_AUTH_USER'];
		$this->Password = $_SESSION['PHP_AUTH_PW'];
		$this->Calls = 0;
	}

	public function __sleep() {
		// see: http://au.php.net/manual/en/language.oop5.magic.php#object.sleep
		$this->DEBUG('SLEEP','SessionID = '.session_id());
		return array(
			'SAMS', 'Calls',
			'ID', 'SessionID', 'Username', 'Password',
			'CWMP', 'xsd', 'DeviceID', 'RawEvents', 'MaxEnvelopes', 'CurrentTime', 'RetryCount', 'RawParameterList',
			'Data', 'Events', 'Params', 'Queued', 'VarTypes', 'Attributes', 'Methods'
		);
	}

	public function __destruct() {
		$this->DEBUG('DESTRUCT','SessionID = '.session_id());
		// DEBUG: $this->DEBUG('COUNTERS',sprintf('%d PARAMETER NAMES',count($this->Params)));
		// DEBUG: $this->DEBUG('COUNTERS',sprintf('%d PARAMETER VALUES',count($this->Data)));
		// DEBUG: $this->DEBUG('COUNTERS',sprintf('%d PARAMETER ATTRIBUTES',count($this->Attributes)));
		// DEBUG: $this->DEBUG('COUNTERS',sprintf('%d WAKEUP CALLS',$this->Calls));
	}

	// yes, I know, but it is easier than using an XML class ...
	public function XML($_array) {
		$XML  = '';
		if (is_array($_array)) {
			$TYPE = $_array['TYPE'];
			switch ($TYPE) {
				case 'VOID':
					if (is_array($_array['Request']))
						if (count($_array['Request'])>0)
							$this->ERROR('XML','PARAMS NOT PASSED TO CPE, VOID METHOD');
					if (!is_null($_array['ParameterKey']))
						$XML.='<ParameterKey>'.$_array['ParameterKey'].'</ParameterKey>';
				break;
				case 'FLAT':
					foreach($_array['Request'] as $A => $V) $XML.='<'.$A.'>'.$V.'</'.$A.'>';
					if (!is_null($_array['ParameterKey']))
						$XML.='<ParameterKey>'.$_array['ParameterKey'].'</ParameterKey>';
				break;
				case 'ARRAY':
					// a lil bit o parsing ...
					if (is_array($_array['Request'][2])) {
						$P = trim($_array['Request'][0]);
						$T = trim($_array['Request'][1]);
						$C = is_integer($_array['Request'][3]) ? $_array['Request'][3] : 1;
						$XML.='<'.$P.' SOAP-ENC:arrayType="xsd:'.$T.'['.$C.']">';
						foreach($_array['Request'][2] as $A) $XML.='<'.$T.'>'.$A.'</'.$T.'>';
						$XML.='</'.$P.'>';
					}
				break;
				case 'SETLIST';
					// a lil bit o parsing ...
					if (is_array($_array['Request'][2])) {
						$P = trim($_array['Request'][0]);
						$T = trim($_array['Request'][1]);
						$C = is_integer($_array['Request'][3]) ? $_array['Request'][3] : 1;
						$XML.='<'.$P.' SOAP-ENC:arrayType="cwmp:'.$T.'['.$C.']">';
						$A = $_array['Request'][2]; // only 1
						$XML.='<'.$T.'>';
						$XML.='<Name>'.$A["Name"].'</Name><Value xsi:type="xsd:'.$A["Type"].'">'.$A["Value"].'</Value>';
						$XML.='</'.$T.'>';
						$XML.='</'.$P.'>';
						$XML.='<ParameterKey>'.$_array['ParameterKey'].'</ParameterKey>';
					}
				break;
				case 'SETATTR';
					// a lil bit o parsing ...
					if (is_array($_array['Request'][2])) {
						$P = trim($_array['Request'][0]);
						$T = trim($_array['Request'][1]);
						$C = is_integer($_array['Request'][3]) ? $_array['Request'][3] : 1;
						$XML.='<'.$P.' SOAP-ENC:arrayType="cwmp:'.$T.'['.$C.']">';
						$A = $_array['Request'][2]; // only 1
						$XML.='<'.$T.'>';
						foreach(array('Name','NotificationChange','Notitification','AccessListChange','AccessList') as $X)
							$XML.='<'.$X.'>'.$A[$X].'</'.$X.'>';
						$XML.='</'.$T.'>';
						$XML.='</'.$P.'>';
						$XML.='<ParameterKey>'.$_array['ParameterKey'].'</ParameterKey>';
					}
				break;
			}
		}
		return $XML;
	}

	public function Enqueue($Method,$TYPE,$Request,$ParameterKey='') {
		$this->Queued[] = array(
			'Method' => $Method,
			'Arguments' => array(
				'TYPE' => $TYPE,
				'Request' => $Request,
				'ParameterKey' => $ParameterKey
			)
		);
	}

	// FIXME: NBN ATA will only respond to the 1st 7 strings and ignore the rest ...
	//
	public function BulkSend($Method) {
		// $this->BulkReq ...
		if (array_key_exists($Method,$this->BulkReq))
		if (array_key_exists('Request',$this->BulkReq[$Method])) {
			$this->Queued[] = array(
				'Method' => $Method,
				'Arguments' => array(
					'TYPE' => $this->BulkReq[$Method]['TYPE'],
					'Request' => $this->BulkReq[$Method]["Request"],
					'ParameterKey' => $this->BulkReq[$Method]['ParameterKey']
				)
			);
			unset($this->BulkReq[$Method]);
		}
	}

	// note: same interface as Enqueue
	public function BulkEnqueue($Method,$TYPE,$Request,$ParameterKey='') {
		// initialize the queue:
		if (!array_key_exists($Method,$this->BulkReq)) $this->BulkReq[$Method] = array();
		if (!array_key_exists('Request',$this->BulkReq[$Method]))
			$this->BulkReq[$Method]["Request"] = array();
		switch ($Method) {
			case 'GetParameterValues':
			case 'GetParameterAttributes':
				if (is_array($Request[2]))
				if (is_string($Request[2][0])) {
					$ObjectName = $Request[2][0];
					$this->BulkReq[$Method]["Request"][0] = 'ParameterNames';
					$this->BulkReq[$Method]["Request"][1] = 'string';
					if (count($this->BulkReq[$Method]["Request"])<3) 
						$this->BulkReq[$Method]["Request"][2] = array($ObjectName);
					else $this->BulkReq[$Method]["Request"][2][]= $ObjectName;
					$this->BulkReq[$Method]["Request"][3]++; // incr # of Name/Value pairs
					$this->BulkReq[$Method]['TYPE'] = $TYPE;
					$this->BulkReq[$Method]['ParameterKey'] = $ParameterKey;
				} else $this->ERROR('BulkEnqueue',"REQUEST[2] MUST BE ARRAY OF STRINGS");
			break;
			default:
				$this->ERROR('BulkEnqueue',"UNSUPPORTED BULKREQ METHOD");
		}
	}

	public function SendAllBulkRequests() {
		$this->DEBUG('SendAllBulkRequests',sprintf("%d BULK JOBS IN QUEUE",count($this->BulkReq)));
		if (is_array($this->BulkReq)) {
			$Methods = array_keys($this->BulkReq);
			foreach ($Methods as $M) $this->BulkSend($M);
		}
	}

	public function SendJobs() {
		$this->DEBUG('SendJobs',sprintf("%d JOBS IN QUEUE",count($this->Queued)));
		// Queue up all bulk reqs:
		// NO BULK REQ: $this->SendAllBulkRequests();
		if (count($this->Queued)==0) $this->SkyMesh(); // 'standard' settings
		if (count($this->Queued)==0) $this->NBN(); // sets up and maintains the NBN ATA
		//
		// XXX: pp 38-39, 3.7.2.4 Session Termination
		if (count($this->Queued)==0) {
			$this->DEBUG('DESTROY','SessionID = '.session_id());
			$this->SaveToCache();
			session_destroy();
			header('HTTP/1.1 204 No Content');
			die; // just send an empty response ...
		}
		//
		// jobs in the queue, send one along now ...
		$Method = 'SendJobs';
		$JOB = array_shift($this->Queued); // from the front of the queue
		$XML = file_get_contents('request.xml');
		$XML = str_replace('%%Method%%',$JOB['Method'],$XML);
		$XML = str_replace('%%Arguments%%',$this->XML($JOB['Arguments']),$XML);
		$this->DEBUG($Method,sprintf("SENDING %s",$JOB['Method']));
		header('Content-Type: text/xml; charset=utf-8');
		die($XML);
	}

	public function __call($Method, $Arguments) {
		$this->DEBUG('METHOD:'.$Method,'SessionID = '.session_id());

		// DEBUG:
		if (is_object($this->DeviceID)) {
			$this->logger($Method,sprintf("Manufacturer = %s\n",$this->DeviceID->Manufacturer));
			$this->logger($Method,sprintf("OUI          = %s\n",$this->DeviceID->OUI));
			$this->logger($Method,sprintf("ProductClass = %s\n",$this->DeviceID->ProductClass));
			$this->logger($Method,sprintf("SerialNumber = %s\n",$this->DeviceID->SerialNumber));
		}

		$this->FetchSAMS(); // updates?

		switch ($Method) {

			case "Fault":
				// FIXME: Need better fault handling!
				$this->DUMPER("FAULT DATA",array(
					$Method,$Arguments
				));
				$this->SendJobs();
			break;

			case "Finish":
				// Finish is not a TR-069 defined method, the CPE has asked for pending requests ...
				$this->SendJobs();
			break;

			case "GetRPCMethodsResponse":
				$response = $Arguments[0];
				foreach ($response as $idx => $R) {
					$this->Methods[$idx]=$R;
					$this->DEBUG($Method,sprintf("[%02d] %s",$idx,$R));
				}
				$this->SendJobs();
			break;

			case "GetParameterNamesResponse":
				// we are statelessly walking the Object tree on the CPE ...
				$response = $Arguments[0];
				foreach ($response as $R) {
					$this->DEBUG($Method,sprintf("%s = %s",$R->Name,($R->Writable==1)?'Writable':'ReadOnly'));
					switch (substr($R->Name,-1)) {
						case ".": // more branches ...
							$this->Enqueue("GetParameterNames",'FLAT',array('ParameterPath'=>$R->Name,'NextLevel'=>"1"),NULL);
						break;
						default:
							// FIXME: queue a bulk req for Values and Attributes
							$this->Enqueue("GetParameterValues",'ARRAY',array('ParameterNames','string',array($R->Name),1),NULL);
							$this->Enqueue("GetParameterAttributes",'ARRAY',array('ParameterNames','string',array($R->Name),1),NULL);
							$this->Params[$R->Name] = ($R->Writable=="1")?"Writable":"ReadOnly";
					}
				}
				$this->SendJobs();
			break;

			case "GetParameterValuesResponse":
				$response = $Arguments[0];
				foreach ($response as $R) {
					$this->DEBUG('GetParameterValues',sprintf("%s = (%s) %s",$R->Name,gettype($R->Value),$R->Value));
					$this->Data[$R->Name] = $R->Value;
					$this->VarTypes[$R->Name] = gettype($R->Value);
				}
				$this->SendJobs();
			break;

			case "GetParameterAttributesResponse":
				$response = $Arguments[0];
				foreach ($response as $R) {
					$this->DEBUG($Method,sprintf("%s",$R->Name));
					$this->Attributes[$R->Name] = (object)array(
						'Notification' =>
							($R->Notification > 1)?"Active":(($R->Notification > 0)?"Passive":"Off"),
						'AccessList'   => (count($R->AccessList)==0)?array('ACS'):$R->AccessList
							// pp 56: "Subscriber" means the CPE has a customer GUI able to change this value
							// NBN: ATA can report "ACSNotAllowed": Attribute NOT changes allowed
					);
				}
				$this->SendJobs();
			break;

			case "SetParameterValuesResponse":
				// FIXME: fault processing, rejections
				$this->SendJobs();
			break;

			case "SetParameterAttributesResponse":
				// FIXME: fault processing, rejections
				$this->SendJobs();
			break;

			case "AddObjectResponse":
				// FIXME: fault processing, rejections
				$this->SendJobs();
			break;

			case "DeleteObjectResponse":
				// FIXME: fault processing, rejections
				$this->SendJobs();
			break;

			case "Inform":
				// get the data ...
				$this->DeviceID = array_shift($Arguments);
				$this->RawEvents = array_shift($Arguments);
				$this->MaxEnvelopes = array_shift($Arguments);
				$this->CurrentTime = array_shift($Arguments);
				$this->RetryCount = array_shift($Arguments);
				$this->RawParameterList = array_shift($Arguments);
				$this->DEBUG($Method,sprintf("Manufacturer = %s\n",$this->DeviceID->Manufacturer));
				$this->DEBUG($Method,sprintf("OUI          = %s\n",$this->DeviceID->OUI));
				$this->DEBUG($Method,sprintf("ProductClass = %s\n",$this->DeviceID->ProductClass));
				$this->DEBUG($Method,sprintf("SerialNumber = %s\n",$this->DeviceID->SerialNumber));
				$this->DEBUG($Method,sprintf("MaxEnvelopes = %s\n",$this->MaxEnvelopes));
				$this->DEBUG($Method,sprintf("CurrentTime  = %s\n",$this->CurrentTime));
				$this->DEBUG($Method,sprintf("RetryCount   = %s\n",$this->RetryCount));
				foreach ($this->RawEvents as $idx => $E) $this->Events[$idx]=(array)$E;
				foreach ($this->Events as $idx => $E)
				$this->DEBUG($Method,sprintf("Event:[%02x] %s %s\n",$idx,$E["EventCode"],$E["CommandKey"]));
				foreach ($this->RawParameterList as $P) $this->Data[$P->Name]=$P->Value;
				foreach ($this->Data as $N => $V)
				$this->DEBUG($Method,sprintf("%s = %s\n",$N,$V));
				// check up on CPE Methods
				$this->Enqueue("GetRPCMethods",'VOID',NULL,NULL);
				// kick off a cycle of reqs to walk the CPE Object tree:
				$this->Enqueue("GetParameterNames",'FLAT',array('ParameterPath'=>'InternetGatewayDevice.','NextLevel'=>"1"),NULL);
				// the reply:
				// FIXME: does not work: return new SoapVar(1,XSD_INT,'unsignedInt',NULL,'MaxEnvelopes');
				return NULL;
			break;

			case "ID":
				// TODO: Add Session ID to SOAP HEADER?
				$this->ID = $Arguments[0];
				// FIXME: likely not needed: $this->server->addSoapHeader( new SoapHeader($this->CWMP, 'ID', $this->ID, TRUE) );
				return NULL;
			break;

			default:
    		$this->ERROR($Method,"***UNKNOWN METHOD***");
				$this->DUMPER("METHOD DATA",array(
					$Method,$Arguments
				));
				$this->SendJobs();
			break;
		}

	}

}

/***********************************************************/
/* WARNING: CPE gSOAP is BROKEN, it adds a spurious COOKIE */
foreach ($_COOKIE as $I => $V) {
$_COOKIE[$I]=preg_replace('/^(\S+), CharCode=293a1567c77f25780de94981d4b8b907ba280ee2baa0c4$/',"$1",$V); }
/* WARNING: CPE gSOAP is BROKEN, it adds a spurious COOKIE */
/***********************************************************/

session_name('ID'); // make the phpsessid act like CWMP SESSION ID.
session_set_cookie_params(NULL,NULL);
session_start();

foreach(getallheaders() as $h => $v) {
	$HEADERS[$h]=$v;
	// debug: syslog(LOG_INFO, sprintf("[%d] **HEADER** %s: %s",getmypid(),$h,$v) );
}
// debug: syslog(LOG_INFO, sprintf("[%d] **HEADER** %s: %s",getmypid(),"SESSION ID (COOKIE)",$_COOKIE['ID']) );

if ( count($_SESSION)>0 ) {
	// if we have a session with data, CPE is "logged in"
	syslog(LOG_INFO, sprintf("[%d] AUTH::%s (%s)",getmypid(),"SESSION ACTIVE",session_id()) );
} elseif (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
	// if we have a username and password, CPE is "logging in"
	$_SESSION['PHP_AUTH_USER'] = $_SERVER['PHP_AUTH_USER'];
	$_SESSION['PHP_AUTH_PW']   = $_SERVER['PHP_AUTH_PW'];
	// FIXME: more secure to use Digest 
	// $_SESSION['PHP_AUTH_DIGEST'] = $_SERVER['PHP_AUTH_DIGEST'];
	// TODO: check the source of the request?
	// $_SESSION['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'];
} else {
	// no auth, no access ...
	header('HTTP/1.1 401 Unauthorized');
	// FIXME: more secure to use Digest 
	header('WWW-Authenticate: Basic realm="ACS"');
	die;
}

function DBH() {
	// cannot serialize PDO objects in the session -- do not move to ACS class
	try {
		// (re)connect DB
		$dbh = new PDO( $GLOBALS['ACS_DSN'], $GLOBALS['ACS_DBUSER'], $GLOBALS['ACS_DBPASS'], array(PDO::ATTR_PERSISTENT=>true));
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		return $dbh;
	} catch (Exception $e) {
		die($e->getMessage());
	}
}

function MC() {
	// ditto
	try {
		// re(connect) Memcache!
		$mc = new Memcache;
		$mc->addServer( $GLOBALS['ACS_MCHOST'], 11211);
		return $mc;
	} catch (Exception $e) {
		die($e->getMessage());
	}
}

function ns1_filter($input) {
	$search = '/ns1/';
	$replace = 'cwmp';
	$output = preg_replace($search, $replace, $input);
	return $output;
}

$server = new SoapServer(NULL,array(
	'location' => "http://10.0.0.1:80/wsdl/acs.php",
	'uri' => "urn:dslforum-org:cwmp-1-0"
));
if ($_SERVER['REQUEST_METHOD'] !== "POST") $server->fault('Sender',"ONLY POST METHOD SUPPORTED");
$server->setClass('ACS');
$server->setPersistence(SOAP_PERSISTENCE_SESSION);
try {
	switch ($HEADERS["Content-Length"]) {
		case 0:
			$server->handle(file_get_contents('finish.xml'));
		break;
		default: $server->handle();
	}
} catch (Exception $e) {
	$server->fault('Sender',$e->getMessage());
}

ob_end_flush();
?>
