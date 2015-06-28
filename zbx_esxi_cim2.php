#!/usr/bin/php
<?php
/*
Required parameters:
-h host: host address
-u user: username
-p password: password
-m mode: zbxsender, query
-c class: which CIM class to enumerate

Discovery mode
This returns all the elements in JSON format compatible with Zabbix

zbxsender mode
Sends all data using zabbix_sender
-k key: index multiple entries with "key"
-j secondary key: index multiple entries with "key"
-z zbxhost: which zabbix hostname
-f "field1,field2,fieldn...": only send these fields
-d : dump data, do not send

If two keys are specified, they will be in formation k.j 
 
*/
if(!$Options = getopt('h:u:p:m:c:s:w:f:z:dk:j:')) {
    echo "No options";
}

if(!$User = @$Options['u']) {
    die("No username specified\n");
}
if(!$Password = @$Options['p']) {
    die("No password specified\n");
}
if(!$Host = @$Options['h']) {
    die("No host specified\n");
}
if(!$Class = @$Options['c']) {
    die("No CIM class specified\n");
}
if(@$Options['m'] != 'zbxsender' && @$Options['m'] != 'discovery') {
    die("Mode must be discovery or zbxsender");
}

if(@$Options['m'] == 'zbxsender')
{
    if(!$zabbixHost = @$Options['z'])
    {
        die("-z parameter (zabbix host) missing\n");
    }
    
    $keys = array();
    
    if(@$Options['k'])
    {
        $keys[] = $Options['k'];
    }
    if(@$Options['j'])
    {
        $keys[] = $Options['j'];
    }

    if(!$fields = @$Options['f'])
    {
        $fields = null;
    }
    else
    {
        $fields = explode(',', $Options['f']);
    }
}


class WBEM
{
    private $host;
    private $user;
    private $pass;
    private $secure;
    private $port;
    private $connectTimeout;
    private $dataTimeout;

    public function __construct($host, $user, $pass, $secure = true, $port = null, $connectTimeout = 7, $dataTimeout = 20)
    {
        $this->host     = $host;
        $this->user     = $user;
        $this->pass     = $pass;
        $this->secure   = $secure;
        $this->port     = $port;
        $this->connectTimeout = $connectTimeout;
        $this->dataTimeout = $dataTimeout;
    }

    private function authHeader()
    {
        $encoded = base64_encode("{$this->user}:{$this->pass}");
        return "Authorization: Basic {$encoded}";
    }
    
    public function enumerateInstances($className, $keys = array())
    {
        $request = '<?xml version="1.0" encoding="utf-8" ?>';
        $request .= '<CIM CIMVERSION="2.0" DTDVERSION="2.0">';
        $request .= '<MESSAGE ID="4711" PROTOCOLVERSION="1.0">';
        $request .= '<SIMPLEREQ>';
        $request .= '<IMETHODCALL NAME="EnumerateInstances">';
        $request .= '<LOCALNAMESPACEPATH>';
        $request .= '<NAMESPACE NAME="root"></NAMESPACE>';
        $request .= '<NAMESPACE NAME="cimv2"></NAMESPACE>';
        $request .= '</LOCALNAMESPACEPATH>';
        $request .= '<IPARAMVALUE NAME="ClassName"><CLASSNAME NAME="' . $className . '"/></IPARAMVALUE>';
        $request .= '<IPARAMVALUE NAME="DeepInheritance"><VALUE>TRUE</VALUE></IPARAMVALUE>';
        $request .= '<IPARAMVALUE NAME="LocalOnly"><VALUE>FALSE</VALUE></IPARAMVALUE>';
        $request .= '<IPARAMVALUE NAME="IncludeQualifiers"><VALUE>FALSE</VALUE></IPARAMVALUE>';
        $request .= '<IPARAMVALUE NAME="IncludeClassOrigin"><VALUE>TRUE</VALUE></IPARAMVALUE>';
        $request .= '</IMETHODCALL>';
        $request .= '</SIMPLEREQ>';
        $request .= '</MESSAGE>';
        $request .= '</CIM>';
        
        $headers[] = $this->authHeader();
        $headers[] = 'CIMProtocolVersion: 1.0';
        $headers[] = 'CIMOperation: MethodCall';
        $headers[] = 'TE: trailers';
        $headers[] = 'CIMMethod: EnumerateInstances';
        $headers[] = 'CIMObject: root/cimv2';
        $headers[] = 'Content-Type: application/xml; charset="utf-8"';
        
        $url = $this->secure ? 'https://' : 'http://';
        $url .= $this->host;
        
        if($this->secure && is_null($this->port))
        {
            $url .= ':5989';
        }
        elseif($this->secure && !is_null($this->port))
        {
            $url .= ":{$this->port}";
        }
        elseif(!$this->secure && is_null($this->port))
        {
            $url .= ':5988';
        }
        elseif(!$this->secure && !is_null($this->port))
        {
            $url .= ":{$this->port}";
        }
        
        $url .= is_null($this->port) ? '' : ":{$this->port}";
        $url .= '/cimom';
        
        $curl = curl_init($url);
        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->dataTimeout);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $request);
        
        $response = curl_exec($curl);
        
        if(!$response)
        {
            throw new \Exception("Request failed: CURL error: " . curl_error($curl));
        }

        $xml = simplexml_load_string($response);

        $error = $xml->xpath('/CIM/MESSAGE/SIMPLERSP/IMETHODRESPONSE/ERROR');
        if($error)
        {
            $msg = "Server returned error while processing: {$error[0]->attributes()->CODE} [{$error[0]->attributes()->DESCRIPTION}]";
            throw new \Exception($msg);
        }
        
        $root = '/CIM/MESSAGE/SIMPLERSP/IMETHODRESPONSE/IRETURNVALUE/VALUE.NAMEDINSTANCE';
        
        $result = array();
        
        foreach($xml->xpath("{$root}/INSTANCE") as $instance)
        {
            $props = array();
            
            foreach($instance->xpath('PROPERTY') as $prop)
            {
                if(!$rawValue = $prop->xpath('VALUE'))
                {
                    $value = null;
                }
                else
                {
                    $value = trim((string) $rawValue[0]);
                }
                
                $props[(string) $prop->attributes()->NAME] = (string) $value;
            }
            
            foreach($instance->xpath('PROPERTY.ARRAY') as $prop)
            {
                foreach($prop->xpath('VALUE.ARRAY/VALUE') as $arrElement)
                {
                    $props[(string) $prop->attributes()->NAME][] = (string) $arrElement;
                }
            }
            
            if($keys)
            {
                $key = array();
                foreach($keys as $keyName)
                {
                    $keyValue = $instance->xpath("PROPERTY[@NAME='{$keyName}']/VALUE");
                    $key[] = "{$keyName}:{$keyValue[0]}";
                }
                $result[implode(',', $key)] = $props;
            }
            else
            {
                $result[] = $props;
            }
        }
        
        return $result;
    }
}

function exitWithError($msg, $code = 1)
{
    fwrite(STDERR, "FAILED: {$msg}\n");
    exit($code);
}

if($Options['m'] == 'discovery') {
    
    $wbem = new wbem($Host, $User, $Password);
    try
    {
        $elements = $wbem->enumerateInstances($Class);
    }
    catch(\Exception $ex)
    {
        exitWithError($ex->getMessage());
    }
    
    $entries = array();
    
    foreach($elements as $element)
    {
        foreach($element as $key => $value)
        {
            $entry['{#' .strtoupper($key) . '}'] = $value;
        }
        $entries[] = $entry;
    }
    
    if(count($entries) > 0) {
        echo '{"data":';
        echo json_encode($entries);
        echo '}';
        echo "\n";
    }
}

if(@$Options['m'] == 'zbxsender') {

    $wbem = new wbem($Host, $User, $Password);
    
    try
    {
        $elements = $wbem->enumerateInstances($Class, $keys);
    }
    catch(\Exception $ex)
    {
        exitWithError($ex->getMessage());
    }
    
    $data = "";

    foreach($elements as $index => $attrs)
    {
        foreach($attrs as $name => $value)
        {
            if(is_array($value) || $value == '')
            {
                continue;
            }
            if($fields !== null && !in_array($name, $fields))
            {
                continue;
            }
            $data .= "- \"esxicim.{$Class}.{$name}[$index]\" {$value}\n";
        }
    }
    
	if(@isset($Options['d']))
	{
		var_dump($data);
        die;
	}
    
	$cmd = "zabbix_sender -z 127.0.0.1 -vv -s {$zabbixHost} -i -";

	$descriptorspec = array(
		0 => array("pipe", "r"), // stdin is a pipe that the child will read from
		1 => array("pipe", "w"), // stdout is a pipe that the child will write to
		2 => array("pipe", "w")  // stderr is a file to write to
	);
	$proc = proc_open($cmd, $descriptorspec, $pipes);
	fwrite($pipes[0], $data);
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	$return = proc_close($proc);

	if($return == 1)
	{
		exitWithError("FAILED: cmd: {$cmd}, return code: {$return}, stdout: {$stdout}, stderr: {$stderr}");
	}
	elseif($return == 2 || $return == 0)
	{
		$regex = '/info from server: "processed: (\d+); failed: (\d+); total: (\d+); seconds spent: (\d+\.\d+)"/';
        $processed = 0;
        $failed = 0;
        $total = 0;
        $secondsSpent = 0.0;
        foreach(explode("\n", $stdout) as $line)
        {
            if(preg_match($regex, $line, $matches))
            {
                $processed      += (int)   $matches[1];
                $total          += (int)   $matches[3];
                $secondsSpent   += (float) $matches[4];
            }
        }
		echo "OK [{$processed}/{$total} processed in {$secondsSpent}]\n";
		/*echo $cmd . "\n";
		echo "stdout: {$stdout}, stderr: {$stderr}\n";
		echo "data: " . print_r($data, true);*/
	}
	else
	{
        exitWithError("Unknown return code {$return}");
	}

}
?>