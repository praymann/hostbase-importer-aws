<?php

namespace Hostbase;

use Shift31\HostbaseClient;
use Aws;
use Aws\Ec2;
use Aws\Rds;

class AWSImporter
{

    protected $hbClient;

    protected $awsSdk;

    private $awsRegions;

    private $baseDomain;

    private $filterRegex;

    /**
     * @param $config
     */
    public function __construct($config)
    {
        $this->hbClient = new HostbaseClient($config['hostbaseUrl']);

        $this->awsSdk = new Aws\Sdk([
            'version' => 'latest',
            'credentials' => [
                'key' => $config['accessKey'],
                'secret' => $config['secretKey'],
            ]
        ]);

        ## Below is due to https://github.com/aws/aws-sdk-php/issues/243
        ##
        ## Negates having to have hardcoded regions :)
        ##
        putenv("AWS_ACCESS_KEY_ID={$config['accessKey']}");
        putenv("AWS_SECRET_ACCESS_KEY={$config['secretKey']}");

        $command = 'aws ec2 describe-regions --output json';
        $this->awsRegions = json_decode(shell_exec("{$command}"));
        ##

        $this->baseDomain = trim($config['baseDomain'], '.');

        $this->filterRegex = isset($config['filterRegex']) ? rtrim($config['filterRegex'], '/') . '|' . 'Tags/' : null;
    }

    protected function importToHostbase ($fqdn, $data)
    {
        echo "Importing $fqdn..." . PHP_EOL;

        try {
            // add
            echo "adding..." . PHP_EOL;
            $this->hbClient->store($data);
        } catch (\Exception $e) {
            echo $e->getMessage() . PHP_EOL;
            try {
                // update
                echo "updating..." . PHP_EOL;
                $this->hbClient->update($fqdn, $data);
            } catch (\Exception $e) {
                echo $e->getMessage() . PHP_EOL;
            }
        }
    }

    /**
     * Import ec2 instances
     */
    public function importEc2()
    {
        # Go through each region, get instances
        foreach($this->awsRegions->Regions as $region) {

            $ec2 = $this->awsSdk->createEc2(['region' => $region->RegionName]);
            $allInstances = $ec2->describeInstances()['Reservations'];

            if(!empty($allInstances)) {
                foreach ($allInstances as $reservation) {
                    foreach ($reservation['Instances'] as $instance) {
                        $data = array();

                        while ($thisTag = current($instance['Tags'])) {
                            if ($thisTag['Key'] == 'Name') {

                                if (preg_match('/^aws2/', $thisTag['Value'])) {
                                    $fqdn = preg_replace('/^aws2/', '', strtolower($thisTag['Value'] . '.' . preg_replace('/aws1/', 'aws2', $this->baseDomain)));
                                } else {
                                    $fqdn = strtolower($thisTag['Value'] . '.' . $this->baseDomain);
                                }

                                $data['fqdn'] = $fqdn;

                            } else {
                                $data[strtolower($thisTag['Key'])] = strtolower($thisTag['Value']);
                            }
                            next($instance['Tags']);
                        }

                        # If the $fqdn varible didn't get set before this point, ignore this instance
                        if (!isset($fqdn)) {
                            echo "Instance: " . $instance['InstanceId'] . " has no 'Name' tag defined, skipping..." . PHP_EOL;
                            continue;
                        }

                        foreach ($instance as $key => $value) {

                            # Ignore the keys that match our regex filter from config.ini
                            if (preg_match($this->filterRegex, $key)) {
                                continue;
                            }

                            if ($value == 'false') {
                                $value = false;
                            }

                            if ($value == 'true') {
                                $value = true;
                            }

                            # No need to have Array for these, make them flat
                            if ($key == 'State') {
                                $data[$key] = $value['Name'];
                                continue;
                            }
                            if ($key == 'Monitoring') {
                                $data[$key] = $value['State'];
                                continue;
                            }
                            if ($key == 'Placement') {
                                foreach ($value as $k => $v) {
                                    $data[$k] = $v;
                                }
                                continue;
                            }

                            $data[$key] = $value;

                        }

                        $this->importToHostbase($fqdn,$data);
                    }
                }
            }
        }
    }

    /**
     * Import RDS dbinstances
     */
    public function importRds()
    {
        # Go through each region, get instances
        foreach($this->awsRegions->Regions as $region) {

            $rds = $this->awsSdk->createRds(['region' => $region->RegionName]);
            $allDBInstances = $rds->describeDBInstances()['DBInstances'];

            if(!empty($allDBInstances)) {
                foreach ($allDBInstances as $instance) {
                    $data = array();

                    $fqdn = strtolower($instance['Endpoint']['Address']);

                    $data['fqdn'] = $fqdn;

                    # If the $fqdn varible didn't get set before this point, ignore this instance
                    if (!isset($fqdn)) {
                        echo "DB Instance: " . $instance['DBInstanceIdentifier'] . " has no endpoint address defined, skipping..." . PHP_EOL;
                        continue;
                    }

                    foreach ($instance as $key => $value) {

                        # Ignore the keys that match our regex filter from config.ini
                        if (preg_match($this->filterRegex, $key)) {
                            continue;
                        }

                        if ($value == 'false') {
                            $value = false;
                        }

                        if ($value == 'true') {
                            $value = true;
                        }


                        $data[$key] = $value;

                    }

                    $this->importToHostbase($fqdn,$data);

                }
            }
        }
    }

    /**
     * Import ElastiCache instances
     */
    public function importElastiCache()
    {
        # Go through each region, get instances
        foreach($this->awsRegions->Regions as $region) {

            $ec = $this->awsSdk->createElastiCache(['region' => $region->RegionName]);
            $allCacheClusters = $ec->describeCacheClusters(['ShowCacheNodeInfo' => true])['CacheClusters'];

            if (!empty($allCacheClusters)) {
                foreach ($allCacheClusters as $cluster) {
                    $data = array();

                    $fqdn = strtolower($cluster['ConfigurationEndpoint']['Address']);

                    $data['fqdn'] = $fqdn;

                    # If the $fqdn varible didn't get set before this point, ignore this instance
                    if (!isset($fqdn)) {
                        echo "Cluster Instance: " . $cluster['CacheClusterId'] . " has no endpoint address defined, skipping..." . PHP_EOL;
                        continue;
                    }

                    foreach ($cluster as $key => $value) {

                        # Ignore the keys that match our regex filter from config.ini
                        if (preg_match($this->filterRegex, $key)) {
                            continue;
                        }

                        if ($value == 'false') {
                            $value = false;
                        }

                        if ($value == 'true') {
                            $value = true;
                        }

                        $data[$key] = $value;
                    }

                    $this->importToHostbase($fqdn, $data);
                }
            }
        }
    }

    /**
     * Import Elb resources
     */
    public function importElb()
    {
        # Go through each region, get elb resource
        foreach ($this->awsRegions->Regions as $region) {

            $elb = $this->awsSdk->createElasticLoadBalancing(['region' => $region->RegionName]);
            $allElasticLBs = $elb->describeLoadBalancers()['LoadBalancerDescriptions'];

            if (!empty($allElasticLBs)) {
                foreach ($allElasticLBs as $lb) {
                    $data = array();

                    $fqdn = strtolower($lb['DNSName']);

                    $data['fqdn'] = $fqdn;

                    # If the $fqdn varible didn't get set before this point, ignore this instance
                    if (!isset($fqdn)) {
                        echo "ELB Resource: " . $lb['LoadBalancerName'] . " has no DNS name..." . PHP_EOL;
                        continue;
                    }

                    foreach ($lb as $key => $value) {

                        # Ignore the keys that match our regex filter from config.ini
                        if (preg_match($this->filterRegex, $key)) {
                            continue;
                        }

                        if ($value == 'false') {
                            $value = false;
                        }

                        if ($value == 'true') {
                            $value = true;
                        }


                        $data[$key] = $value;

                    }

                    $this->importToHostbase($fqdn, $data);

                }
            }
        }
    }
}