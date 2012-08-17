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

// SOAP Class:
class ACS {
	public $SAMS;
	public $ID;
	public $SessionID;
	public $Username;
	public $Password;
	public $CWMP;
	public $xsd;
	public $DeviceID;
	public $RawEvents;
	public $MaxEnvelopes;
	public $CurrentTime;
	public $RetryCount;
	public $RawParameterList;
	public $Data;
	public $Events;
	public $Params;
	public $Queued;

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
		file_put_contents('/tmp/ACS.dumped.txt',$dumping,LOCK_EX|FILE_APPEND);
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
							array('Type'=>$VarType,'Name'=>$ObjectName,'Value'=>($DataReqd?"1":"0"))
						));
						$this->Enqueue("GetParameterValues",'ARRAY',array('ParameterNames','string',array($ObjectName)));
					}
				break;
				case 'string':
				case 'unsignedInt':
				case 'int':
					if ($this->Data[$ObjectName] !== $DataReqd) {
						$this->Enqueue("SetParameterValues",'SETLIST',array('ParameterList','ParameterValueStruct',
							array('Type'=>$VarType,'Name'=>$ObjectName,'Value'=>$DataReqd)
						));
						$this->Enqueue("GetParameterValues",'ARRAY',array('ParameterNames','string',array($ObjectName)));
					}
				break;
				default:
					$this->ERROR('CheckDataChange',"TYPE $VarType UNKNOWN");
			}
		} else $this->ERROR('CheckDataChange',"OBJECT $ObjectName NOT FOUND");
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
		$xDigitMap = '(000|106|#x.T|101|013|12[23]x|*x.T|124xx|125xxx|119[46]|130xxxxxxx|13[1-3]xxx|1345xxxx|136xxx|130000|180[01]xxxxxx|180[2-9]xxx|183x.T|18[4-7]xx|18[89]xx|[5689]xxxxxxx|0[23478]xxxxxxxx|001x.T)';

		if (array_key_exists($xVpPrefix.'1.Enable',$this->Data)) {
			$this->CheckDataChange($xVpPrefix.'1.DigitMap',                                  'string',$xDigitMap);
			$this->CheckDataChange($xVpPrefix.'1.Enable',                                    'string','Enabled');
			$this->CheckDataChange($xVpPrefix.'1.RTP.DSCPMark',                              'unsignedInt',46);
			$this->CheckDataChange($xVpPrefix.'1.SIP.DSCPMark',                              'unsignedInt',46);
			$this->CheckDataChange($xVpPrefix.'1.SIP.OutboundProxy',                         'string',$GLOBALS['ACS_SIP_SBC']);
			$this->CheckDataChange($xVpPrefix.'1.SIP.ProxyServer',                           'string',$GLOBALS['ACS_SIP_REG']);
			$this->CheckDataChange($xVpPrefix.'1.SIP.RegisterExpires',                       'unsignedInt',3600);
			$this->CheckDataChange($xVpPrefix.'1.SIP.RegistrationPeriod',                    'unsignedInt',3240);
			$this->CheckDataChange($xVpPrefix.'1.SIP.UserAgentDomain',                       'string','');
			$this->CheckDataChange($xVpPrefix.'1.Line.1.Enable',                             'string','Enabled');
			$this->CheckDataChange($xVpPrefix.'1.Line.1.FaxT38.Enable',                      'boolean',TRUE);
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
		}
		if (array_key_exists($xVpPrefix.'3.Enable',$this->Data)) {
			// send DeleteObject
			$this->Enqueue("DeleteObject",'FLAT',array('ObjectName'=>$xVpPrefix.'3.'),"DeleteObject");
		}
		if (array_key_exists($xVpPrefix.'4.Enable',$this->Data)) {
			// send DeleteObject
			$this->Enqueue("DeleteObject",'FLAT',array('ObjectName'=>$xVpPrefix.'4.'),"DeleteObject");
		}
		return (count($this->Queued)>0);
	}

	private function FetchSAMS() {
		$dbh = DBH();
		if (is_null($this->SAMS)) {
			if (is_object($this->DeviceID))
			if (strlen($this->DeviceID->SerialNumber)>0) {
				$sth = $dbh->query(sprintf(
					"select * from nbn.avc_provisioning_univ where avc_id='%s'",
					$this->Data['InternetGatewayDevice.DeviceInfo.ProvisioningCode']
				));
				$this->SAMS = $sth->fetch(PDO::FETCH_ASSOC);
			} else $this->SAMS = NULL; // stays NULL
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
		$this->FetchSAMS(); // updates?
	}

	public function __construct() { 
		$this->DEBUG('CONSTRUCT','SessionID = '.session_id());
		$this->CWMP = 'urn:dslforum-org:cwmp-1-0';
		$this->xsd = 'http://www.w3.org/2001/XMLSchema';
		$this->SessionID = session_id();
		$this->Events = array();
		$this->Queued = array();
		$this->Username = $_SESSION['PHP_AUTH_USER'];
		$this->Password = $_SESSION['PHP_AUTH_PW'];
	}

	public function __sleep() {
		// see: http://au.php.net/manual/en/language.oop5.magic.php#object.sleep
		$this->DEBUG('SLEEP','SessionID = '.session_id());
		return array(
			'SAMS',
			'ID', 'SessionID', 'Username', 'Password',
			'CWMP', 'xsd', 'DeviceID', 'RawEvents', 'MaxEnvelopes', 'CurrentTime', 'RetryCount', 'RawParameterList',
			'Data', 'Events', 'Params', 'Queued'
		);
	}

	public function __destruct() {
		$this->DEBUG('DESTRUCT','SessionID = '.session_id());
	}

	// yes, I know, but it is easier than using an XML class ...
	public function XML($_array) {
		$XML  = '';
		if (is_array($_array)) {
			$TYPE = $_array['TYPE'];
			switch ($TYPE) {
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
						$XML.='<'.$P.' SOAP-ENC:arrayType="xsd:'.$T.'[1]">';
						foreach($_array['Request'][2] as $A) $XML.='<'.$T.'>'.$A.'</'.$T.'>';
						$XML.='</'.$P.'>';
					}
				break;
				case 'SETLIST';
					// a lil bit o parsing ...
					if (is_array($_array['Request'][2])) {
						$P = trim($_array['Request'][0]);
						$T = trim($_array['Request'][1]);
						$XML.='<'.$P.' SOAP-ENC:arrayType="cwmp:'.$T.'[1]">';
						$A = $_array['Request'][2];
						$XML.='<'.$T.'>';
						$XML.='<Name>'.$A["Name"].'</Name><Value xsi:type="xsd:'.$A["Type"].'">'.$A["Value"].'</Value>';
						$XML.='</'.$T.'>';
						$XML.='</'.$P.'>';
						$XML.='<ParameterKey>'.$_array['ParameterKey'].'</ParameterKey>';
					}
				break;
			}
		}
		return $XML;
	}

	function Enqueue($Method,$TYPE,$Request,$ParameterKey='') {
		$this->Queued[] = array(
			'Method' => $Method,
			'Arguments' => array(
				'TYPE' => $TYPE,
				'Request' => $Request,
				'ParameterKey' => $ParameterKey
			)
		);
	}

	public function SendJobs() {
		$this->DEBUG('SendJobs',sprintf("%d JOBS IN QUEUE",count($this->Queued)));
		//
		// check for the empty queue and now we can safely send changes:
		if (count($this->Queued)==0) $this->SkyMesh(); // SkyMesh does our defaults
		if (count($this->Queued)==0) $this->NBN(); // NBN sets up the ATA
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
		$JOB = array_shift($this->Queued);
		$XML = file_get_contents('request.xml');
		$XML = str_replace('%%Method%%',$JOB['Method'],$XML);
		$XML = str_replace('%%Arguments%%',$this->XML($JOB['Arguments']),$XML);
		$this->DEBUG($Method,sprintf("SENDING %s",$JOB['Method']));
		header('Content-Type: text/xml; charset=utf-8');
		die($XML);
	}

	public function __call($Method, $Arguments) {
		$this->DEBUG('METHOD:'.$Method,'SessionID = '.session_id());

		if (is_object($this->DeviceID)) {
			$this->logger($Method,sprintf("Manufacturer = %s\n",$this->DeviceID->Manufacturer));
			$this->logger($Method,sprintf("OUI          = %s\n",$this->DeviceID->OUI));
			$this->logger($Method,sprintf("ProductClass = %s\n",$this->DeviceID->ProductClass));
			$this->logger($Method,sprintf("SerialNumber = %s\n",$this->DeviceID->SerialNumber));
		}

		$this->FetchSAMS(); // updates?

		switch ($Method) {

			case "Fault":
				$this->DUMPER("FAULT DATA",array(
					$Method,$Arguments
				));
				$this->SendJobs();
			break;

			case "Finish":
				$this->SendJobs();
			break;

			case "GetParameterNamesResponse":
				$response = $Arguments[0];
				foreach ($response as $R) {
					$this->DEBUG($Method,sprintf("%s = %s",$R->Name,($R->Writable==1)?'Writable':'ReadOnly'));
					switch (substr($R->Name,-1)) {
						case ".": // more branches ...
							$this->Enqueue("GetParameterNames",'FLAT',array('ParameterPath'=>$R->Name,'NextLevel'=>"1"),NULL);
						break;
						default:
							$this->Enqueue("GetParameterValues",'ARRAY',array('ParameterNames','string',array($R->Name)),NULL);
							$this->Params[$R->Name] = $R->Writable;
					}
				}
				$this->SendJobs();
			break;

			case "GetParameterValuesResponse":
				$response = $Arguments[0];
				foreach ($response as $R) {
					$this->DEBUG('GetParameterValues',sprintf("%s = %s",$R->Name,$R->Value));
					$this->Data[$R->Name] = $R->Value;
				}
				$this->SendJobs();
			break;

			case "SetParameterValuesResponse":
				$this->SendJobs();
			break;

			case "AddObjectResponse":
				$this->SendJobs();
			break;

			case "DeleteObjectResponse":
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
				// schedule update of params:
				$this->Enqueue("GetParameterNames",'FLAT',array('ParameterPath'=>'InternetGatewayDevice.','NextLevel'=>"1"),NULL);
				// the reply:
				// FIXME: does not work: return new SoapVar(1,XSD_INT,'unsignedInt',NULL,'MaxEnvelopes');
				return NULL;
			break;

			case "ID":
				// Add Session ID to SOAP HEADER:
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
	syslog(LOG_INFO, sprintf("[%d] AUTH::%s (%s)",getmypid(),"SESSION ACTIVE",session_id()) );
} elseif (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
	$_SESSION['PHP_AUTH_USER'] = $_SERVER['PHP_AUTH_USER'];
	$_SESSION['PHP_AUTH_PW']   = $_SERVER['PHP_AUTH_PW'];
} else {
	// no auth, no access ...
	header('HTTP/1.1 401 Unauthorized');
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
