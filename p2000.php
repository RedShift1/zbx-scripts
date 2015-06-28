#!/usr/bin/php
<?php
require_once('zbx_shared.php');

class P2000
{

    private $address;
    private $username;
    private $password;
    private $CURL;
    private $sessionKey;
    private $lastResponse;

    public function __construct($address, $username, $password)
    {
        $this->address = $address;
        $this->username = $username;
        $this->password = $password;
    }

    public function __destruct()
    {
        if ($this->sessionKey)
        {
            $this->Logout();
        }
    }

    public function Login()
    {
        $AuthStr = md5($this->username . '_' . $this->password);
        $this->DoCURLRequest('api/login/' . $AuthStr);

        $XML = simplexml_load_string($this->lastResponse);
        $return = $XML->xpath('/RESPONSE/OBJECT/PROPERTY[@name="return-code"]');
        if ((int) $return[0] != 1)
        {
            $response = $XML->xpath('/RESPONSE/OBJECT/PROPERTY[@name="response"]');
            throw new \Exception("Login failed: {$response[0]}");
        }
        $response = $XML->xpath('/RESPONSE/OBJECT/PROPERTY[@name="response"]');
        $this->sessionKey = (string) $response[0];
    }

    public function GetLastResponse()
    {
        return $this->lastResponse;
    }

    public function Discovery($What)
    {
        return $this->JSONIfyDiscovery($this->DoCURLRequest('api/show/' . $What));
    }

    public function dataToZbxSender($class)
    {
        $tmp = "";
        $XML = simplexml_load_string($this->DoCURLRequest("api/show/{$class}",
                        true));

        foreach ($XML->xpath('/RESPONSE/OBJECT[not(@basetype="status")]') as $Entry)
        {
            foreach ($Entry->children() as $Attr)
            {
                $value = trim($Attr);
                if ($value == '')
                {
                    $value = 0;
                }
                $tmp .= "- {$Entry["name"]}.";
                $tmp .= "{$Attr["name"]}[{$Entry["oid"]}] " . $value . "\n";
            }
        }

        return $tmp;
    }

    public function bulkQueryAndSend($what)
    {
        $xml = simplexml_load_string($this->DoCURLRequest("api/show/{$what}"));
    }

    public function SingleValue($What, $SelectOn, $Value, $Field)
    {
        $Field = $this->fixPropName(strtolower($Field));
        $XML = simplexml_load_string($this->DoCURLRequest("api/show/{$What}"));

        foreach ($XML->xpath('/RESPONSE/OBJECT[not(@basetype="status")]') as $Entry)
        {
            foreach ($Entry->children() as $Attr)
            {
                if (strtolower($Attr["name"]) == strtolower($SelectOn) && ((string) $Attr) == $Value)
                {
                    $prop = $Entry->xpath("PROPERTY[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ-', 'abcdefghijklmnopqrstuvwxyz_')='{$Field}']");
                    return (string) trim($prop[0]);
                }
            }
        }

        throw new Exception("{$What} not found when selecting on field {$SelectOn} with value {$Value}");
    }

    public function Logout()
    {
        $this->DoCURLRequest('api/exit');
    }

    private function DoCURLRequest($Command, $verifyResponse = false)
    {
        $url = "https://{$this->address}/{$Command}";
        $CURL = curl_init($url);
        curl_setopt($CURL, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($CURL, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($CURL, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($CURL, CURLOPT_CONNECTTIMEOUT, 10);

        if (isset($this->sessionKey))
        {
            curl_setopt($CURL, CURLOPT_HTTPHEADER,
                array(
                    "sessionKey: {$this->sessionKey}"
                )
            );
        }

        if (($this->lastResponse = curl_exec($CURL)) === false)
        {
            throw new \Exception("Request to {$url} failed: " . curl_error($CURL));
        }
        elseif ($this->lastResponse === "")
        {
            throw new \Exception("Response was empty");
        }

        if ($verifyResponse)
        {
            $XML = simplexml_load_string($this->lastResponse);

            $return = $XML->xpath('/RESPONSE/OBJECT/PROPERTY[@name="return-code"]');
            if ((int) $return[0] != 0)
            {
                $response = $XML->xpath('/RESPONSE/OBJECT/PROPERTY[@name="response"]');
                throw new Exception("Command failed, unit responded with: return-code: {$return[0]}, response: {$response[0]}");
            }
        }

        return $this->lastResponse;
    }

    private function JSONIfyDiscovery($RawXML)
    {
        $XML = simplexml_load_string($RawXML);

        $Entries = array();

        foreach ($XML->xpath('/RESPONSE/OBJECT[not(@basetype="status")]') as $Entry)
        {
            $Attrs["{#OID}"] = "{$Entry["oid"]}";
            foreach ($Entry->children() as $Attr)
            {
                $Attrs[strtoupper("{#" . (string) $this->fixPropName($Attr["name"]) . "}")] = (string) $Attr;
            }
            $Entries[] = $Attrs;
        }

        return json_encode(array('data' => $Entries));
    }

    private function fixPropName($name)
    {
        return str_replace('-', '_', $name);
    }

}

/*
  -h host: host address
  -u user: username
  -p password: password
  -m mode: discovery, query, zbxsender
  -c class: which device class to enumerate (controllers/disks/vdisks)

  Discovery mode
  This returns all the elements in JSON format compatible with Zabbix

  Query
  Returns a single value from a single element

  ZbxSender
  Sends all data via zabbix_sender
  -z host: hostname in Zabbix

  Query options
  -s select: select on this field
  -w what: select on this field value
  -f field: return this field

 */

if (!$Options = getopt('h:u:p:m:c:s:w:f:z:d'))
{
    echo "No options";
}
if (!$User = @$Options['u'])
{
    die("No username specified\n");
}
if (!$Password = @$Options['p'])
{
    die("No password specified\n");
}
if (!$Hosts = @$Options['h'])
{
    die("No host specified\n");
}
else
{
    if (!is_array($Options['h']))
    {
        $Hosts = array($Options['h']);
    }
}
if (!$Class = @$Options['c'])
{
    die("No device class specified\n");
}
if (@$Options['m'] != 'discovery' && @$Options['m'] != 'query' && @$Options['m'] != 'zbxsender')
{
    die("Mode must be discovery, query or zbxsender");
}

if (@$Options['m'] == 'query')
{
    if (!$Select = @$Options['s'])
    {
        die("-s parameter (select on this field) missing\n");
    }
    if (!$What = @$Options['w'])
    {
        die("-w parameter (select on this field value) missing\n");
    }
    if (!$Field = @$Options['f'])
    {
        die("-f parameter (return this field) missing\n");
    }
}

if (@$Options['m'] == 'discovery' && @$Options['s'])
{
    if (!$Select = @$Options['s'])
    {
        die("-s parameter (select on this field) missing\n");
    }
    if (!$What = @$Options['w'])
    {
        die("-w parameter (select on this field value) missing\n");
    }
}

if (@$Options['m'] == 'zbxsender')
{
    if (!$ZabbixHost = @$Options['z'])
    {
        die("-z parameter (zabbix host) missing\n");
    }
}

reset($Hosts);
$connected = false;

while (($host = current($Hosts)) !== false)
{
    try
    {
        $P2000 = new P2000($host, $User, $Password);
        $P2000->Login();
        $connected = true;
    }
    catch (\Exception $ex)
    {

    }
    next($Hosts);
}

if (!$connected)
{
    exitWithError($ex->getMessage());
}


if (@$Options['m'] == 'discovery')
{
    echo $P2000->Discovery($Class);
}
elseif (@$Options['m'] == 'query')
{
    echo $P2000->SingleValue($Class, $Select, $What, $Field);
}
elseif (@$Options['m'] == 'zbxsender')
{
    $data = $P2000->dataToZbxSender($Class);
    if (@isset($Options['d']))
    {
        var_dump($data);
        exit;
    }
    $cmd = "zabbix_sender -z 127.0.0.1 -vv -s {$ZabbixHost} -i -";

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

    if ($return == 1)
    {
        exitWithError("cmd: {$cmd}, return code: {$return}, stdout: {$stdout}, stderr: {$stderr}");
    }
    elseif ($return == 2 || $return == 0)
    {
        $regex = '/info from server: "processed: (\d+); failed: (\d+); total: (\d+); seconds spent: (\d+\.\d+)"/';
        preg_match($regex, $stdout, $matches);
        echo "OK: [processed {$matches[1]}/{$matches[3]} in {$matches[4]}]\n";
        /* echo $cmd . "\n";
          echo "stdout: {$stdout}, stderr: {$stderr}\n";
          echo "data: " . print_r($data, true); */
    }
    else
    {
        exitWithError("Unknown return code {$return}");
    }
}
