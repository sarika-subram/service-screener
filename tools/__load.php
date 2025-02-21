<?php 
include_once(__DIR__ .'/config.php');
include_once(__DIR__ .'/aws-sdk-custom-credential-provider.php');
include_once(__DIR__ .'/arguParser.class.php');
include_once(__DIR__ .'/pageBuilder.class.php');
include_once(__DIR__ .'/reporter.class.php');
include_once(__DIR__ .'/feedback.class.php');
include_once(__DIR__ .'/policy.class.php');
include_once(__DIR__ .'/uploader.class.php');
include_once(__DIR__ .'/excelBuilder.class.php');

use Aws\Ec2\Ec2Client;

function __pr($o){
    global $DEBUG;
    
    if($DEBUG){
        print_r($o);
        echo PHP_EOL;
    }
}

function __info($o){
    __printStatus("info", $o);
}

function __warn($o){
    __printStatus("\033[1;41m__!! WARNING !!__\033[0m", $o);
}

function __printStatus($status, $o){
    $o = "[$status] " . $o;
    __pr($o);
}

function __formatException($e){
    $o = $e->getTrace();
    #$o = array_reverse($o);
    $msg = ["[ERROR]: ".$e->getMessage()."\n"];
    foreach($o as $ind => $item){
        $cls = '';
        if(!empty($item['class'])) $cls = $item['class'].'::';
    
        $func = $item['function'];
        $line = $item['line'];
        $file = $item['file'];
        
        $args = [];
        foreach($item['args'] as $arg){
            if(is_object($arg)) $args[] = "CLASS=".get_class($arg);
            else if (is_array($arg)){
                array_walk($arg, function(&$v, $k){
                    $val = $v;
                    if(is_array($v)) $val = 'ARRAY(...)';
                    else if(is_object($v)) $val = 'OBJECT(...)';
                    $v = "{$k}:$val";
                });
        
                $args[] = implode(', ', $arg);
            }else $args[] = $arg;
        }
    
        $args = implode(', ', $args);
        $indent = "";
        for($i=1;$i<=$ind;$i++)
            $indent .= "\t";
    
        $msg[] = $indent . "[$cls$func($args)]: $file ($line)\n";
    }
    
    return implode('', $msg);
}

function __aws_parseInstanceFamily($instanceFamilyInString){
    global $CURRENT_REGION, $CONFIG;
    if(empty($CURRENT_REGION))
        $CURRENT_REGION = 'us-east-1';
    
    $arr = explode('.', $instanceFamilyInString);
    if(sizeof($arr) > 3 || sizeof($arr) == 1){
        return $instanceFamilyInString;
    }
    
    if(sizeof($arr) == 3 && strtolower($arr[0]) =="db"){
        $p = $arr[1];
        $s = $arr[2];
    }else{
        $p = $arr[0];
        $s = $arr[1];
    }
    
    $patterns = "/([a-zA-Z]+)(\d+)([a-zA-Z]*)/i";
        $result = preg_match(
        $patterns, 
        $p, 
        $output
    );
    
    $cpu = $memory = 0;
    
    $family = "$p.$s";
    $CACHE_KEYWORD = 'INSTANCE_SPEC::'.$family;
    $spec = $CONFIG->get($CACHE_KEYWORD,[]);
    if(empty($spec)){
        $arr = [];
        $arr['region'] = $CURRENT_REGION;
        $arr['version'] = CONFIG::AWS_SDK['EC2CLIENT_VERS'];
        $ec2c = new Ec2Client($arr);
        
        $resp = $ec2c->describeInstanceTypes([
            'InstanceTypes' => [$family]
        ]);
        
        $iType = $resp->get('InstanceTypes');
        if(!empty($iType)){
            $info = $iType[0];
            $cpu = $info['VCpuInfo']['DefaultVCpus'];
            $memory = round($info['MemoryInfo']['SizeInMiB']/1024, 2);
        }
        
        $spec = [
            'vcpu' => $cpu,
            'memoryInGiB' => $memory
        ];
        
        $CONFIG->set($CACHE_KEYWORD, $spec);
    }
    
    $result = [
        "full" => $instanceFamilyInString,
        "prefix" => $p,
        "suffix" => $s,
        "specification" => $spec,
        "prefixDetail" => [
            "family" => $output[1],
            "version" => $output[2],
            "attributes" => $output[3]
        ]
    ];
    
    return $result;
}