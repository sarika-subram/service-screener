<?php 
## https://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.Ec2.Ec2Client.html
use Aws\Ec2\Ec2Client;
use Aws\Ec2\Exception;
use Aws\ComputeOptimizer\ComputeOptimizerClient;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;
use Aws\AutoScaling\AutoScalingClient;
use Aws\CostExplorer\CostExplorerClient;
use Aws\Ssm\SsmClient;

class ec2 extends service{
    const EC2_SDK_VERSION = '2016-11-15';
    public $ec2Client;
    public $compOptClient;
    public $elbClient;
    public $elbClassicClient;
    public $asgClient;
    public $ssmClient;
    
    function __construct($region){
        parent::__construct($region);
        
        $this->__AWS_OPTIONS['version'] = CONFIG::AWS_SDK['EC2CLIENT_VERS'];
        $this->ec2Client = new Ec2Client($this->__AWS_OPTIONS);
        
        $this->__AWS_OPTIONS['version'] = CONFIG::AWS_SDK['COMPOPTCLIENT_VERS'];
        $this->compOptClient = new ComputeOptimizerClient($this->__AWS_OPTIONS);
        
        $this->__AWS_OPTIONS['version'] = CONFIG::AWS_SDK['ELBCLIENT_VERS'];
        $this->elbClient = new ElasticLoadBalancingV2Client($this->__AWS_OPTIONS);
        
        $this->__AWS_OPTIONS['version'] = CONFIG::AWS_SDK['ELBCLASSICCLIENT_VERS'];
        $this->elbClassicClient = new ElasticLoadBalancingClient($this->__AWS_OPTIONS);
        
        $this->__AWS_OPTIONS['version'] = CONFIG::AWS_SDK['ASGCLIENT_VERS'];
        $this->asgClient = new AutoScalingClient($this->__AWS_OPTIONS);
        
        $this->__AWS_OPTIONS['version'] = CONFIG::AWS_SDK['COSTEXPLORERCLIENT_VERS'];
        $this->costExplorerClient = new CostExplorerClient($this->__AWS_OPTIONS);
        
        $this->__AWS_OPTIONS['version'] = CONFIG::AWS_SDK['SSMCLIENT_VERS'];
        $this->ssmClient = new SsmClient($this->__AWS_OPTIONS);
        
        $this->__loadDrivers();
    }
    
    function getResources(){
        $param = ['Filters' => $this->tags];
        if(empty($this->tags))
            $param = [];
        
        $results = $this->ec2Client->describeInstances($param);
        
        $arr = $results->get('Reservations');
        while($results->get('NextToken') !== null){
            $param = [
                'NextToken' => $results->get('NextToken')
            ];
            
            if(!empty($this->tags))
                $param['Filters'] = $this->tags;
            
            $results = $this->ec2Client->describeInstances($param);    
            $arr = array_merge($arr, $results->get('Reservations'));
        }
        
        return $arr;
    }
    
    function getELB(){
        $results = $this->elbClient->describeLoadBalancers();
        ## TODO, use describeTags
        
        $arr = $results->get('LoadBalancers');
        while($results->get('NextToken') !== null){
            $results = $this->elbClient->describeLoadBalancers([
                'NextToken' => $results->get('NextToken')
            ]);
            
            $arr = array_merge($arr, $results->get('LoadBalancers'));
        }
        
        if(empty($this->tags))
            return $arr;
        
        $filteredResults = [];
        foreach($arr as $ind => $elb){
            ##Can supports up to 20 resources;
            $tmp = $this->elbClient->describeTags([
                'ResourceArns' => [$elb['LoadBalancerArn']]
            ]);
            
            $tagDesc = $tmp->get('TagDescriptions');
            if(isset($tagDesc) && isset($tagDesc[0]) && isset($tagDesc[0]['Tags']) && $this->resourceHasTags($tagDesc[0]['Tags'])){
                $filteredResults[] = $elb;
            }
        }
        
        return $filteredResults;
    }
    
    function getELBClassic(){
        $results = $this->elbClassicClient->describeLoadBalancers();
        
        $arr = $results->get('LoadBalancerDescriptions');
        while($results->get('NextToken') !== null){
            $results = $this->elbClient->describeLoadBalancers([
                'NextToken' => $results->get('NextToken')
            ]);
            
            $arr = array_merge($arr, $results->get('LoadBalancers'));
        }
        
        return $arr;
    }
    
    function getEC2SecurityGroups($instance){
        if(array_key_exists(0, $instance['Instances']) && array_key_exists('SecurityGroups', $instance['Instances'][0])){
                
            $securityGroups = $instance['Instances'][0]['SecurityGroups'];
            
            $groupIds = array();
            foreach($securityGroups as $group){
                $groupIds[] = $group['GroupId'];
            }
            
            if(empty($groupIds)){
                return array();
            }
            
            $param = ['GroupIds' => $groupIds];
            if(!empty($this->tags))
                $param['Filters'] = $this->tags;
            
            $results = $this->ec2Client->describeSecurityGroups($param);
            $arr = $results->get('SecurityGroups');
            while($results->get('NextToken') !== null){
                $param = ['NextToken' => $results->get('NextToken')];
                if(!empty($this->tags))
                    $param['Filters'] = $this->tags;
                
                $results = $this->ec2Client->describeSecurityGroups($param);
                $arr = array_merge($arr, $results->get('SecurityGroups'));
            }
            
            return $arr;
        }
    }
    
    function getELBSecurityGroup($elb){
        if(!isset($elb['SecurityGroups'])){
            return [];
        }
        
        $securityGroups = $elb['SecurityGroups'];
        $groupIds = array();
        
        foreach($securityGroups as $groupId){
            $groupIds[] = $groupId;
        }
        
        if(empty($groupIds)){
            return array();
        }
        
        $param = ['GroupIds' => $groupIds];
        if(!empty($this->tags))
            $param['Filters'] = $this->tags;
        
        $results = $this->ec2Client->describeSecurityGroups($param);
        $arr = $results->get('SecurityGroups');
        while($results->get('NextToken') !== null){
            $param = ['NextToken' => $results->get('NextToken')];
            if(!empty($this->tags))
                $param['Filters'] = $this->tags;
            
            $results = $this->ec2Client->describeSecurityGroups($param);    
            $arr = array_merge($arr, $results->get('SecurityGroups'));
        }
        
        return $arr;
    }
    
    function getEBS(){
        $param = [];
        if(!empty($this->tags))
            $param['Filters'] = $this->tags;
        
        $results = $this->ec2Client->describeVolumes($param);
        $arr = $results->get('Volumes');
        
        while($results->get('NextToken') !== null){
            $param = ['NextToken' => $results->get('NextToken')];
            if(!empty($this->tags))
                $param['Filters'] = $this->tags;
                
            $results = $this->ec2Client->describeVolumes($param);
            $arr = array_merge($arr, $results->get('Reservations'));
        }
        
        return $arr;
    }
    
    function getAsg(){
        $results = $this->asgClient->describeAutoScalingGroups(['Filters' => $this->tags]);
        $arr = $results->get('AutoScalingGroups');
        
        while($results->get('NextToken') !== null){
            $results = $this->asgClient->describeAutoScalingGroups([
                'Filters' => $this->tags,
                'NextToken' => $results->get('NextToken')
            ]);
            $arr = $results->get('AutoScalingGroups');
        }
        
        return $arr;
    }
    
    function advise(){
        $objs = [];
        $securityGroups = [];
        
        ## Check Compute Optimize available for the region
        // $this->ssmClient->
        $region = $this->__AWS_OPTIONS['region'];
        $compOptPath = "/aws/service/global-infrastructure/regions/".$region."/services/compute-optimizer";
        $compOptCheck = $this->ssmClient->getParametersByPath([
            'Path' => $compOptPath
        ]);
        
        if(isset($compOptCheck['Parameters']) && sizeof($compOptCheck['Parameters']) > 0){
            $driver = 'ec2_compopt';
            if (class_exists($driver)){
                __info('... (Compute Optimizer) inspecting');
                $obj = new $driver($this->compOptClient);
                $obj->run();
                
                $objs['ComputeOptimizer'] = $obj->getInfo();
                unset($obj);
            }
        }
        
        $driver = 'ec2_costExplorer';
        if (class_exists($driver)){
            __info('... (Cost Explorer) inspecting');
            $obj = new $driver($this->costExplorerClient);
            $obj->run();
            
            $objs['CostExplorer'] = $obj->getInfo();
            unset($obj);
        }
        
        $instances = $this->getResources();
        
        $driver = 'ec2_ec2';
        foreach($instances as $instance){
            if (class_exists($driver)){
                __info('... (EC2::Instance) inspecting ' . $instance['Instances'][0]['InstanceId']);
                $obj = new $driver($instance, $this->ec2Client);
                $obj->run();
            }
            
            $objs['EC2::' . $instance['Instances'][0]['InstanceId']] = $obj->getInfo();
            unset($obj);
            
            $instanceSGs = $this->getEC2SecurityGroups($instance);
            foreach($instanceSGs as $group){
                if(!isset($securityGroups[$group['GroupId']])){
                    $securityGroups[$group['GroupId']] = $group;
                }
            }
        }
        
        $ebsBlocks = $this->getEBS();
        $driver = 'ec2_ebs';
        foreach($ebsBlocks as $block){
            if (class_exists($driver)){
                __info('... (EBS::Volume) inspecting ' . $block['VolumeId']);
                $obj = new $driver($block, $this->ec2Client);
                $obj->run();
                
                $objs['EBS::' . $block['VolumeId']] = $obj->getInfo();
                unset($obj);
            }
        }
        
        $elbList = $this->getELB();
        $driver = 'ec2_elb';
        foreach($elbList as $elb){
            if (class_exists($driver)){
                __info('... (ELB::Load Balancer) inspecting ' . $elb['LoadBalancerName']);
                $obj = new $driver($elb, $this->elbClient);
                $obj->run();
            }
            
            $objs['ELB::' . $elb['LoadBalancerName']] = $obj->getInfo();
            unset($obj);
            
            
            $elbSGList = $this->getELBSecurityGroup($elb);
            
            foreach($elbSGList as $group){
                if(!isset($securityGroups[$group['GroupId']])){
                    $securityGroups[$group['GroupId']] = $group;
                }
            }
        }
        
        $elbClassicList = $this->getELBClassic();
        $driver = 'ec2_elbClassic';
        foreach($elbClassicList as $elb){
            if (class_exists($driver)){
                __info('... (ELB::Load Balancer Classic) inspecting ' . $elb['LoadBalancerName']);
                $obj = new $driver($elb, $this->elbClassicClient);
                $obj->run();
            }
            
            $objs['ELB Classic::' . $elb['LoadBalancerName']] = $obj->getInfo();
            unset($obj);
            
            $elbSGList = $this->getELBSecurityGroup($elb);
            
            foreach($elbSGList as $group){
                if(!isset($securityGroups[$group['GroupId']])){
                    $securityGroups[$group['GroupId']] = $group;
                }
            }
        }
        
        
        $driver = 'ec2_sg';
        foreach($securityGroups as $group){
            if(isset($objs[$group['GroupId']])){
                continue;
            }
            if (class_exists($driver)){
                __info('... (EC2::Security Group) inspecting ' . $group['GroupId']);
                $obj = new $driver($group, $this->ec2Client);
                $obj->run();
                
                $objs['SG::' . $group['GroupId']] = $obj->getInfo();
                unset($obj);
            }
        }
        
        $asgList = $this->getAsg();
        $driver = 'ec2_asg';
        foreach($asgList as $asg){
            if(class_exists($driver)){
                __info('... (ASG::Auto Scaling Group) inspecting ' . $asg['AutoScalingGroupName']);
                $obj = new $driver($asg, $this->asgClient, $this->elbClient, $this->elbClassicClient, $this->ec2Client);
                $obj->run();
            }
            
            $objs['ASG::' . $asg['AutoScalingGroupName']] = $obj->getInfo();
            unset($obj);
        }

        return $objs;
    }
    
    function __loadDrivers(){
        $path = __DIR__ .'/drivers/';
        $files = scandir($path);
        foreach($files as $file){
            if ($file[0] == '.')
                continue;
            
            include_once($path . $file);
        }
    }
}