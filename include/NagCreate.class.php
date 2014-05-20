<?php
//
// Copyright (c) 2013, Zynga Inc.
// https://github.com/zynga/saigon
// Author: Matt West (https://github.com/mhwest13)
// License: BSD 2-Clause
//

class NagCreateException extends Exception {}

class NagCreate {
    /* Declarations */
    // RHEL / CentOS Based
    const PROD_DIR = '/usr/local/nagios/etc';
    const PROD_MGDIR = '/usr/local/etc';
    const PROD_INCDIR = '/objects';
    const PROD_BIN = '/usr/local/nagios/bin/nagios';
    const PROD_INIT = '/etc/init.d/nagios';
    // Debian / Ubuntu Based
    // const PROD_DIR = '/etc/nagios3';
    // const PROD_MGDIR = '/etc/mod-gearman';
    // const PROD_INCDIR = '/conf.d';
    // const PROD_BIN = '/usr/sbin/nagios3';
    // const PROD_INIT = '/etc/init.d/nagios3';
    // Global
    const TMP_DIR = '/tmp/saigon';
    /* Error Response Info */
    const PRODDIRERR = 'Unable to make or find directory for ';
    const PRODWRITEERR = 'Unable to write to directory ';
    const TMPMKDIRERR = 'Unable to make directory for tmp nagios configuration storage at ';
    const TMPNONWRITEABLE = 'Write permissions aren\'t set up properly for ';
    const NONAGIOSBIN = 'Unable to detect Nagios binary for testing configuration at ';
    const NONAGIOSINIT = 'Unable to detect Nagios init script specified at ';
    const FILEHEADER = "# This file has been autogenerated, any changes you make to this file\n# will be overridden the next time the consumer runs\n\n";

    private static $deployment = null;
    private static $deployFiles = array('commands.cfg', 'contact-groups.cfg', 'contacts.cfg', 'contact-templates.cfg', 'hostgroups.cfg', 'hosts.cfg', 'host-templates.cfg', 'service-groups.cfg', 'services.cfg', 'service-templates.cfg', 'service-dependencies.cfg', 'service-escalations.cfg', 'timeperiods.cfg');
    private static $coreFiles = array('resource.cfg', 'cgi.cfg', 'nagios.cfg');
    private static $modgearmanFiles = array('mod_gearman_neb.conf', 'mod_gearman_worker.conf');
    private static $m_localcache = array();
    private static $m_subdeployment = false;
    private static $m_logger;

    private static function checkProdPerms() {
        if (!file_exists(self::PROD_DIR.self::PROD_INCDIR))
            if (!mkdir(self::PROD_DIR, 0755, true))
                throw new NagCreateException(self::PRODDIRERR.self::PROD_DIR.self::PROD_INCDIR);
        chmod(self::PROD_DIR, 0755);
        if (!is_writeable(self::PROD_DIR.self::PROD_INCDIR))
            throw new NagCreateException(self::PRODWRITEERR.self::PROD_DIR.self::PROD_INCDIR);
        if (!is_writeable(self::PROD_DIR))
            throw new NagCreateException(self::PRODWRITEERR.self::PROD_DIR);
    }

    private static function checkModgearmanPerms() {
        if (!file_exists(self::PROD_MGDIR))
            if (!mkdir(self::PROD_MGDIR, 0755, true))
                throw new NagCreateException(self::PRODDIRERR.self::PROD_MGDIR);
        chmod(self::PROD_MGDIR, 0755);
        if (!is_writeable(self::PROD_MGDIR))
            throw new NagCreateException(self::PRODWRITEERR.self::PROD_MGDIR);
    }

    private static function checkPerms($deployment) {
        if (!file_exists(self::TMP_DIR.'/'.$deployment.self::PROD_INCDIR))
            if (!mkdir(self::TMP_DIR.'/'.$deployment.self::PROD_INCDIR, 0755, true))
                throw new NagCreateException(self::TMPMKDIRERR.self::TMP_DIR.'/'.$deployment.self::PROD_INCDIR);
        chmod(self::TMP_DIR.'/'.$deployment, 0755);
        chmod(self::TMP_DIR.'/'.$deployment.self::PROD_INCDIR, 0755);
        if (!is_writeable(self::TMP_DIR.'/'.$deployment))
            throw new NagCreateException(self::TMPNONWRITEABLE.self::TMP_DIR.'/'.$deployment.self::PROD_INCDIR);
    }

    private static function objToArray(stdClass $obj, $sortresults = false) {
        $tmpJson = json_encode($obj);
        $tmpArray = json_decode($tmpJson, true);
        if ($sortresults === true) {
            ksort($tmpArray);
        }
        return $tmpArray;
    }

    public static function restartNagios() {
        if (file_exists(self::PROD_INIT)) {
            $command = self::PROD_INIT.' restart';
            exec($command.' 2>&1', $output, $exitcode);
            $results = array();
            $results['output'] = $output;
            $results['exitcode'] = $exitcode;
            return $results;
        }
        throw new NagCreateException(self::NONAGIOSINIT.self::PROD_INIT);
    }

    public static function findNagiosBin() {
        if (file_exists(self::PROD_BIN)) {
            return self::PROD_BIN;
        }
        throw new NagCreateException(self::NONAGIOSBIN.self::PROD_BIN);
    }

    public static function resetLocalCache() {
        self::$m_localcache = array();
    }

    public static function setSubDeployment($subdeployment) {
        self::$m_subdeployment = $subdeployment;
    }

    public static function getSubDeployment() {
        return self::$m_subdeployment;
    }

    public static function buildDeployment($deployment, $revision = false, $diff = false, $forcebuild = false, $shardposition = false) {
        self::checkPerms($deployment);
        self::$deployment = $deployment;
        self::$m_logger = new NagLogger();
        self::deleteDeployment();
        if ($revision === false) {
            /* 
                No revision means we are dealing with a request from a consumer
                so we will fetch Deployment information from Nagios Configurator API
            */
            $response = NagRequest::fetchDeploymentData($deployment, self::getSubDeployment());
            $responseObj = json_decode($response);
            $helper = new NagHelpers();
            $helper->setAliasTemplate($responseObj->miscsettings->aliastemplate);
            $helper->setGlobalNegate($responseObj->miscsettings->deploynegate);
            if ((SHARDING === true) && ($responseObj->miscsettings->ensharding == 'on')) {
                $helper->enableSharding(
                    $responseObj->miscsettings->shardkey,
                    $responseObj->miscsettings->shardcount,
                    SHARDING_POSITION
                );
            }
        } else {
            /* 
                A revision means we are dealing with a request from the UI
                so we will fetch Deployment information from Redis store directly
            */
            $response = RevDeploy::getDeploymentData($deployment, $revision, true);
            $responseObj = json_decode($response);
            $helper = new NagHelpers(true);
            $helper->setAliasTemplate($responseObj->miscsettings->aliastemplate);
            $helper->setGlobalNegate($responseObj->miscsettings->deploynegate);
            if ( $shardposition !== false) {
                $deploymentInfo = RevDeploy::getDeploymentInfo($deployment);
                $helper->enableSharding(
                    $deploymentInfo['shardkey'],
                    $deploymentInfo['shardcount'],
                    $shardposition
                );
            }
        }
        /* Create Initial Files for Nagios */
        if (!empty($responseObj->timeperiods)) {
            self::createTimeperiodFile($responseObj->timeperiods);
        }
        if (!empty($responseObj->commands)) {
            self::createCommandFile($responseObj->commands);
        }
        if (!empty($responseObj->contacttemplates)) {
            self::createContactTemplateFile($responseObj->contacttemplates);
        }
        if (!empty($responseObj->contacts)) {
            self::createContactFile($responseObj->contacts);
        }
        if (!empty($responseObj->contactgroups)) {
            self::createContactGroupFile($responseObj->contactgroups);
        }
        if (!empty($responseObj->hosttemplates)) {
            self::createHostTemplateFile($responseObj->hosttemplates);
        }
        if (!empty($responseObj->hostgroups)) {
            self::createHostGroupFile($responseObj->hostgroups);
        }
        if (!empty($responseObj->servicetemplates)) {
            self::createServiceTemplateFile($responseObj->servicetemplates);
        }
        if (!empty($responseObj->servicegroups)) {
            self::createServiceGroupFile($responseObj->servicegroups);
        }
        if (!empty($responseObj->resourcecfg)) {
            self::createResourceConfigFile($responseObj->resourcecfg);
        }
        if (!empty($responseObj->cgicfg)) {
            self::createCgiConfigFile($responseObj->cgicfg);
        }
        if (!empty($responseObj->modgearmancfg)) {
            self::createModgearmanConfigFile($responseObj->modgearmancfg);
        }
        if (!empty($responseObj->nagioscfg)) {
            self::createNagiosConfigFile($responseObj->nagioscfg);
        }
        /* Initialize Helper object for storing information for hosts and services */
        if (!empty($responseObj->hostsearches)) {
            if (($diff === true) && (!empty(self::$m_localcache))) {
                foreach (self::$m_localcache as $module => $items) {
                    foreach ($items as $host => $hostData) {
                        $helper->importHost($host, $hostData);
                    }
                }
            } else {
                foreach ($responseObj->hostsearches as $md5Key => $hsObj) {
                    if (($subdeployment = self::getSubDeployment()) !== false) {
                        if ((isset($hsObj->subdeployment)) && ($hsObj->subdeployment != $subdeployment)) {
                            continue;
                        }
                    }
                    $module = $hsObj->location;
                    if (preg_match("/^RS-(\w+)-(\w+)$/", $module)) {
                        $modObj = new RightScale;
                    }
                    elseif (preg_match("/^AWSEC2-(\w+)-(\w+)$/", $module)) {
                        $modObj = new AWSEC2;
                    }
                    else {
                        $modObj = new $module;
                    }
                    $hostInfo = $modObj->getSearchResults($hsObj);
                    if (empty($hostInfo)) {
                        self::$m_logger->addToLog("Empty Results Detected for {$hsObj->location} : {$hsObj->srchparam}");
                        continue;
                    }
                    foreach ($hostInfo as $host => $hostData) {
                        $helper->importHost($host, $hostData);
                        if ($diff === true) {
                            if ((!isset(self::$m_localcache[$module])) ||
                                (!is_array(self::$m_localcache[$module])))
                            {
                                self::$m_localcache[$module] = array();
                            }
                            self::$m_localcache[$module][$host] = $hostData;
                        }
                    }
                }
            }
        }
        if (!empty($responseObj->statichosts)) {
            foreach ($responseObj->statichosts as $encIP => $tmpObj) {
                if (($subdeployment = self::getSubDeployment()) !== false) {
                    if ((isset($tmpObj->subdeployment)) && ($tmpObj->subdeployment != $subdeployment)) {
                        continue;
                    }
                }
                $helper->importStaticHost(NagMisc::recursive_object_to_array($tmpObj));
            }
        }
        /* Import Service and Node => Service Pointer Data */
        if ($forcebuild === false) {
            if (empty($responseObj->services)) return "Initial Service Data was Empty"; 
            elseif (empty($responseObj->nodetemplates)) return "Initial NodeTemplate Data was Empty";
        }
        $helper->scrubHosts();
        if (!empty($responseObj->services)) $helper->importServices($responseObj->services);
        if (!empty($responseObj->nodetemplates)) $helper->importNodeTemplate($responseObj->nodetemplates, self::getSubDeployment());
        if (!empty($responseObj->servicedependencies)) $helper->importServiceDependencies($responseObj->servicedependencies);
        if (!empty($responseObj->serviceescalations)) $helper->importServiceEscalations($responseObj->serviceescalations);
        $hostCache = $helper->returnHosts();
        $serviceCache = $helper->returnServices($hostCache);
        $serviceDependencyCache = $helper->returnServiceDependencies($hostCache);
        $serviceEscalationsCache = $helper->returnServiceEscalations($hostCache);
        /* Create core host / service nagios config files */
        if ($forcebuild === false) {
            if (empty($hostCache)) return "Host Cache is Empty, unable to create Host File";
            elseif (empty($serviceCache)) return "Service Cache is Empty, unable to create Services File";
        }
        if (!empty($hostCache)) self::createHostFile($hostCache);
        if (!empty($serviceCache)) self::createServiceFile($serviceCache);
        if (!empty($serviceDependencyCache)) self::createServiceDependencyFile($serviceDependencyCache);
        if (!empty($serviceEscalationsCache)) self::createServiceEscalationsFile($serviceEscalationsCache);
        return true;
    }

    public static function returnDeploymentConfigs($deployment) {
        $return = array();
        foreach (self::$deployFiles as $file) {
            $longFile = self::TMP_DIR.'/'.$deployment.self::PROD_INCDIR.'/'.$file;
            $return[$file] = FileUtils::returnContents($longFile);
        }
        foreach (self::$coreFiles as $file) {
            if ($file == 'nagios.cfg') $longFile = self::TMP_DIR.'/'.$deployment.'/'.$file.'.in';
            else $longFile = self::TMP_DIR.'/'.$deployment.'/'.$file;
            $return[$file] = FileUtils::returnContents($longFile);
        }
        foreach (self::$modgearmanFiles as $file) {
            $longFile = self::TMP_DIR.'/'.$deployment.'/'.$file;
            $return[$file] = FileUtils::returnContents($longFile);
        }
        return $return;
    }

    public static function testDeployment($deployment) {
        self::checkPerms($deployment);
        self::$deployment = $deployment;
        if (!file_exists(self::TMP_DIR.'/'.$deployment.'/nagios.cfg.in')) {
            if (!file_exists(self::TMP_DIR.'/'.$deployment.'/nagios.cfg')) self::createTestingNagiosConfig();
        } else {
            self::createModifiedTestingNagiosConfig();
        }
        $command = self::findNagiosBin().' -vx '.self::TMP_DIR.'/'.$deployment.'/nagios.cfg 2>&1';
        exec($command, $output, $exitcode);
        $results = array();
        $results['output'] = $output;
        $results['exitcode'] = $exitcode;
        return $results;
    }

    public static function moveDeployment($deployment, $verbose = false) {
        self::checkProdPerms();
        self::$deployment = $deployment;
        $moveReturn = array();
        foreach (self::$deployFiles as $file) {
            $moveReturn[$file] = self::moveDeployFile($file, $verbose);
        }
        foreach (self::$coreFiles as $file) {
            $moveReturn[$file] = self::moveCoreFile($file, $verbose);
        }
        /* modgearman has its own perl consumer, only preventing moving, build still
            happens so it can be displayed in UI
        foreach (self::$modgearmanFiles as $file) {
            $moveReturn[$file] = self::moveModgearmanFile($file, $verbose);
        }
        */
        $results = array();
        foreach ($moveReturn as $file => $return) {
            if ($return === false) continue;
            array_push($results, $file);
        }
        return $results;
    }

    private static function deleteDeployment($verbose = false) {
        foreach (self::$deployFiles as $file) {
            $srcFile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
            if (!file_exists($srcFile)) continue;
            unlink($srcFile);
        }
        foreach (self::$coreFiles as $file) {
            if ($file == 'nagios.cfg') $srcFile = self::TMP_DIR.'/'.self::$deployment.'/'.$file.'.in';
            else $srcFile = self::TMP_DIR.'/'.self::$deployment.'/'.$file;
            if (!file_exists($srcFile)) continue;
            unlink($srcFile);
        }
        foreach (self::$modgearmanFiles as $file) {
            $srcFile = self::TMP_DIR.'/'.self::$deployment.'/'.$file;
            if (!file_exists($srcFile)) continue;
            unlink($srcFile);
        }
    }

    private static function moveDeployFile($file, $verbose = false) {
        $srcFile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $destFile = self::PROD_DIR.self::PROD_INCDIR.'/'.$file;
        if (!file_exists($srcFile)) return false;
        $srcMd5 = FileUtils::returnContentsMD5($srcFile);
        $destMd5 = FileUtils::returnContentsMD5($destFile);
        if ($srcMd5 == $destMd5) return false;
        FileUtils::moveFile($srcFile, $destFile, $verbose);
        return true;
    }

    private static function moveCoreFile($file, $verbose = false) {
        /* Move legit nagios cfg, not the test cfg we created */
        if ($file == 'nagios.cfg') $srcFile = self::TMP_DIR.'/'.self::$deployment.'/'.$file.'.in';
        else $srcFile = self::TMP_DIR.'/'.self::$deployment.'/'.$file;
        $destFile = self::PROD_DIR.'/'.$file;
        if (!file_exists($srcFile)) return false;
        $srcMd5 = FileUtils::returnContentsMD5($srcFile);
        $destMd5 = FileUtils::returnContentsMD5($destFile);
        if ($srcMd5 == $destMd5) return false;
        FileUtils::moveFile($srcFile, $destFile, $verbose);
        return true;
    }

    private static function moveModgearmanFile($file, $verbose = false) {
        $srcFile = self::TMP_DIR.'/'.self::$deployment.'/'.$file;
        $destFile = self::PROD_MGDIR.'/'.$file;
        if (!file_exists($srcFile)) return false;
        self::checkModgearmanPerms();
        $srcMd5 = FileUtils::returnContentsMD5($srcFile);
        $destMd5 = FileUtils::returnContentsMD5($destFile);
        if ($srcMd5 == $destMd5) return false;
        FileUtils::moveFile($srcFile, $destFile, $verbose);
        return true;
    }

    private static function createTestingNagiosConfig() {
        $fileContents = explode("\n", file_get_contents(BASE_PATH.'/misc/testing-nagios.cfg'));
        $newContents = array();
        foreach ($fileContents as $line) {
            if (preg_match('/^cfg_dir=%CHANGEME%$/', $line)) {
                $line = preg_replace('/^cfg_dir=%CHANGEME%$/', 'cfg_dir='.self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR, $line);
                array_push($newContents, $line);
            } elseif (preg_match('/^resource_file=%CHANGEME%$/', $line)) {
                if (file_exists(self::TMP_DIR.'/'.self::$deployment.'/resource.cfg')) {
                    $line = preg_replace('/^resource_file=%CHANGEME%$/', 'resource_file='.self::TMP_DIR.'/'.self::$deployment.'/resource.cfg', $line);
                } else {
                    $line = preg_replace('/^resource_file=%CHANGEME%$/', 'resource_file='.self::PROD_DIR.'/resource.cfg', $line);
                }
                array_push($newContents, $line);
            } else {
                array_push($newContents, $line);
            }
        }
        file_put_contents(self::TMP_DIR.'/'.self::$deployment.'/nagios.cfg', implode("\n", $newContents));
        chmod(self::TMP_DIR.'/'.self::$deployment.'/nagios.cfg', 0644);
    }

    private static function createModifiedTestingNagiosConfig() {
        $file = self::TMP_DIR.'/'.self::$deployment.'/nagios.cfg.in';
        $fileContents = explode("\n", file_get_contents($file));
        $newContents = array();
        foreach ($fileContents as $line) {
            if (preg_match('/^cfg_dir=/', $line)) {
                $newline = 'cfg_dir='.self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR;
                array_push($newContents, $newline);
            } elseif (preg_match('/^check_result_path=/', $line)) {
                $newline = 'check_result_path=/var/tmp';
                array_push($newContents, $newline);
            } else {
                array_push($newContents, $line);
            }
        }
        file_put_contents(self::TMP_DIR.'/'.self::$deployment.'/nagios.cfg', implode("\n", $newContents));
        chmod(self::TMP_DIR.'/'.self::$deployment.'/nagios.cfg', 0644);
    }

    private static function formatDataLine($key, $value) {
        $keylength = strlen($key);
        if ($keylength >= 36) {
            return "\t$key\t$value\n";
        }
        else {
            $spacecount = (36 - $keylength);
            return "\t$key" . str_repeat(" ", $spacecount) . "$value\n";
        }
    }

    private static function createTimeperiodFile(stdClass $timeperiods) {
        $file = 'timeperiods.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($timeperiods as $tpName => $tpArray) {
            fwrite($fileHandle, "define timeperiod {\n");
            foreach ($tpArray as $key => $value) {
                if ($key == 'deployment') continue;
                if ((empty($value)) && ($value == null)) continue;
                if ($key == 'timeperiod_name') {
                    $data = self::formatDataLine('name', $value);
                    $data .= self::formatDataLine('timeperiod_name', $value);
                    fwrite($fileHandle, $data);
                }
                elseif ($key != 'times') {
                    $data = self::formatDataLine($key, $value);
                    fwrite($fileHandle, $data);
                }
            }
            foreach ($tpArray->times as $timeName => $timeObj) {
                $data = self::formatDataLine($timeObj->directive, $timeObj->range);
                fwrite($fileHandle, $data);
            }
            fwrite($fileHandle, "}\n\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createCommandFile(stdClass $commands) {
        $file = 'commands.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($commands as $cmdName => $cmdObj) {
            fwrite($fileHandle, "define command {\n");
            foreach ($cmdObj as $key => $value) {
                if ((empty($value)) && ($value == null)) continue;
                if (($key != 'command_line') && ($key != 'command_name')) continue;
                if ($key == 'command_line') {
                    $data = self::formatDataLine($key, base64_decode($value));
                } else {
                    $data = self::formatDataLine($key, $value);
                }
                fwrite($fileHandle, $data);
            }
            fwrite($fileHandle, "}\n\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createContactTemplateFile(stdClass $contactTemplates) {
        $file = 'contact-templates.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($contactTemplates as $ctName => $ctObj) {
            $ctObj = self::objToArray($ctObj, true);
            fwrite($fileHandle, "define contact {\n");
            foreach ($ctObj as $key => $value) {
                if ($key == 'deployment') continue;
                if ((empty($value)) && ($value == null)) continue;
                if (is_array($value)) {
                    asort($value);
                    $value = implode(',', $value);
                }
                $data = self::formatDataLine($key, $value);
                fwrite($fileHandle, $data);
            }
            fwrite($fileHandle, "}\n\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createContactFile(stdClass $contacts) {
        $file = 'contacts.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($contacts as $contact => $contactObj) {
            $contactObj = self::objToArray($contactObj, true);
            fwrite($fileHandle, "define contact {\n");
            foreach ($contactObj as $key => $value) {
                if ($key == 'deployment') continue;
                if ((empty($value)) && ($value == null)) continue;
                if (is_array($value)) {
                    asort($value);
                    $value = implode(',', $value);
                }
                $data = self::formatDataLine($key, $value);
                fwrite($fileHandle, $data);
            }
            fwrite($fileHandle, "}\n\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createContactGroupFile(stdClass $contactGroups) {
        $file = 'contact-groups.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($contactGroups as $cgName => $cgObj) {
            $cgObj = self::objToArray($cgObj, true);
            fwrite($fileHandle, "define contactgroup {\n");
            foreach ($cgObj as $key => $value) {
                if ($key == 'deployment') continue;
                if ((empty($value)) && ($value == null)) continue;
                if (is_array($value)) {
                    asort($value);
                    $value = implode(',', $value);
                }
                $data = self::formatDataLine($key, $value);
                fwrite($fileHandle, $data);
            }
            fwrite($fileHandle, "}\n\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createHostTemplateFile(stdClass $hostTemplates) {
        $file = 'host-templates.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($hostTemplates as $htName => $htObj) {
            $htObj = self::objToArray($htObj, true);
            fwrite($fileHandle, "define host {\n");
            foreach ($htObj as $key => $value) {
                if ($key == 'deployment') continue;
                if ((empty($value)) && ($value == null)) continue;
                if (is_array($value)) {
                    asort($value);
                    $value = implode(',', $value);
                }
                $data = self::formatDataLine($key, $value);
                fwrite($fileHandle, $data);
            }
            fwrite($fileHandle, "}\n\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createHostGroupFile(stdClass $hostGroups) {
        $file = 'hostgroups.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($hostGroups as $hgName => $hgObj) {
            $data = self::formatDataLine('hostgroup_name', $hgObj->hostgroup_name);
            $data .= self::formatDataLine('alias', $hgObj->alias);
            fwrite($fileHandle, "define hostgroup {\n");
            fwrite($fileHandle, $data);
            fwrite($fileHandle, "}\n\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createHostFile(array $hostArray) {
        ksort($hostArray);
        $file = 'hosts.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($hostArray as $hName => $hArray) {
            ksort($hArray);
            fwrite($fileHandle, "define host {\n");
            foreach ($hArray as $key => $value) {
                if ((empty($value)) && ($value == null)) continue;
                if (is_array($value)) {
                    asort($value);
                    $value = implode(',', $value);
                }
                $data = self::formatDataLine($key, $value);
                fwrite($fileHandle, $data);
            }
            if ((!isset($hArray['use'])) || (empty($hArray['use']))) {
                $data = self::formatDataLine('use', 'generic-server');
                fwrite($fileHandle, $data);
            }
            fwrite($fileHandle, "}\n\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createServiceTemplateFile(stdClass $serviceTemplates) {
        $file = 'service-templates.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($serviceTemplates as $stName => $stObj) {
            fwrite($fileHandle, "define service {\n");
            $stObj = self::objToArray($stObj, true);
            foreach ($stObj as $key => $value) {
                if (($key == 'deployment') || ($key == 'alias')) continue;
                if ((empty($value)) && ($value == null)) continue;
                if (preg_match('/^carg/', $key)) continue;
                if (is_array($value)) {
                    asort($value);
                    $value = implode(',', $value);
                }
                if ($key != 'check_command') {
                    $data = self::formatDataLine($key, $value);
                    fwrite($fileHandle, $data);
                } else {
                    for ($i=1;$i<=32; $i++) {
                        $key = 'carg'.$i;
                        if ((isset($stObj->$key)) && (!empty($stObj->$key))) {
                            $value .= "!".$stObj->$key;
                        }
                    }
                    $data = self::formatDataLine('check_command', $value);
                    fwrite($fileHandle, $data);
                }
            }
            fwrite($fileHandle, "}\n\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createServiceGroupFile(stdClass $serviceGroups) {
        $file = 'service-groups.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($serviceGroups as $sgName => $sgObj) {
            $data = self::formatDataLine('servicegroup_name', $sgObj->servicegroup_name);
            $data .= self::formatDataLine('alias', $sgObj->alias);
            fwrite($fileHandle, "define servicegroup {\n");
            fwrite($fileHandle, $data);
            fwrite($fileHandle, "}\n\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createServiceFile(array $serviceArray) {
        $file = 'services.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($serviceArray as $svc => $svcArray) {
            ksort($svcArray);
            fwrite($fileHandle, "define service {\n");
            foreach ($svcArray as $key => $value) {
                if ($key == 'name') continue;
                if (preg_match('/^carg/', $key)) continue;
                if (is_array($value)) {
                    asort($value);
                    $value = implode(',', $value);
                }
                if ($key != 'check_command') {
                    $data = self::formatDataLine($key, $value);
                    fwrite($fileHandle, $data);
                } else {
                    for ($i=1;$i<=32; $i++) {
                        $key = 'carg'.$i;
                        if ((isset($svcArray[$key])) && (!empty($svcArray[$key]))) {
                            $value .= "!".$svcArray[$key];
                        }
                    }
                    $data = self::formatDataLine('check_command', $value);
                    fwrite($fileHandle, $data);
                }
            }
            fwrite($fileHandle, "}\n\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createServiceDependencyFile(array $serviceDependencies) {
        $file = 'service-dependencies.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($serviceDependencies as $svcDep => $svcDepArray) {
            ksort($svcDepArray);
            fwrite($fileHandle, "define servicedependency {\n");
            foreach ($svcDepArray as $key => $value) {
                if ((empty($value)) && ($value == null)) continue;
                if (is_array($value)) {
                    asort($value);
                    $value = implode(',', $value);
                }
                $data = self::formatDataLine($key, $value);
                fwrite($fileHandle, $data);
            }
            fwrite($fileHandle, "}\n\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createServiceEscalationsFile(array $serviceEscalations) {
        $file = 'service-escalations.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.self::PROD_INCDIR.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($serviceEscalations as $svcEsc => $svcEscArray) {
            ksort($svcEscArray);
            fwrite($fileHandle, "define serviceescalation {\n");
            foreach ($svcEscArray as $key => $value) {
                if ((empty($value)) && ($value == null)) continue;
                if (is_array($value)) {
                    asort($value);
                    $value = implode(',', $value);
                }
                $data = self::formatDataLine($key, $value);
                fwrite($fileHandle, $data);
            }
            fwrite($fileHandle, "}\n\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createResourceConfigFile(stdClass $resourcecfg) {
        $file = 'resource.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($resourcecfg as $key => $value) {
            fwrite($fileHandle, "$" . $key . "$=" . base64_decode($value) . "\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createModgearmanConfigFile(stdClass $modgearmancfg) {
        $corekeys = array(
            'debug', 'eventhandler', 'services', 'hosts', 'do_hostchecks', 'encryption', 'server',
            'dupeserver', 'hostgroups', 'servicegroups', 'logfile', 'key'
        );
        $nebkeys = array(
            'result_workers', 'use_uniq_jobs', 'localhostgroup', 'localservicegroup', 'queue_custom_variable',
            'perfdata', 'perfdata_mode', 'orphan_host_checks', 'orphan_service_checks', 'accept_clear_results'
        );
        $workerkeys = array(
            'job-timeout', 'min-worker', 'max-worker', 'idle-timeout', 'max-jobs', 'max-age', 'spawn-rate', 'fork_on_exec',
            'show_error_output', 'workaround_rc_25', 'load_limit1', 'load_limit5', 'load_limit15', 'dup_results_are_passive',
            'enable_embedded_perl', 'use_embedded_perl_implicitly', 'use_perl_cache', 'p1_file'
        );
        $files = array('mod_gearman_neb.conf', 'mod_gearman_worker.conf');
        $neb = array();
        $worker = array();
        foreach ($modgearmancfg as $key => $value) {
            if ( ($key == 'logfile') || ($key == 'p1_file') ) {
                $value = base64_decode($value);
            }
            if ( in_array($key, $corekeys) ) {
                $neb[$key] = $value;
                $worker[$key] = $value;
            } elseif ( in_array($key, $nebkeys) ) {
                $neb[$key] = $value;
            } elseif ( in_array($key, $workerkeys) ) {
                $worker[$key] = $value;
            } else {
                continue;
            }
        }
        foreach ($files as $file) {
            $data = array();
            if ($file == 'mod_gearman_neb.conf') {
                $data = $neb;
            } elseif ($file == 'mod_gearman_worker.conf') {
                $data = $worker;
            }
            $outfile = self::TMP_DIR.'/'.self::$deployment.'/'.$file;
            $fileHandle = fopen($outfile, 'w');
            fwrite($fileHandle, self::FILEHEADER);
            foreach ($data as $key => $value) {
                fwrite($fileHandle, "$key=$value\n");
            }
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createCgiConfigFile(stdClass $cgiconfig) {
        $file = 'cgi.cfg';
        $outfile = self::TMP_DIR.'/'.self::$deployment.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($cgiconfig as $key => $value) {
            if (($key == 'main_config_file') || ($key == 'physical_html_path') ||
                ($key == 'ping_syntax') || ($key == 'url_html_path') ||
                ($key == 'splunk_url'))
            {
                $b64dec = base64_decode($value, true);
                if ($b64dec !== false) {
                    $value = $b64dec;
                }
            }
            fwrite($fileHandle, "$key=$value\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

    private static function createNagiosConfigFile(stdClass $nagioscfg) {
        $file = 'nagios.cfg.in';
        $outfile = self::TMP_DIR.'/'.self::$deployment.'/'.$file;
        $fileHandle = fopen($outfile, 'w');
        fwrite($fileHandle, self::FILEHEADER);
        foreach ($nagioscfg as $key => $value) {
            if (
                ($key == 'broker_module') || ($key == 'cfg_dir') || ($key == 'check_result_path') ||
                ($key == 'command_file') || ($key == 'debug_file') || ($key == 'host_perfdata_command') ||
                ($key == 'host_perfdata_file') || ($key == 'host_perfdata_file_processing_command') ||
                ($key == 'host_perfdata_file_template') || ($key == 'illegal_macro_output_chars') ||
                ($key == 'illegal_object_name_chars') || ($key == 'lock_file') || ($key == 'log_archive_path') ||
                ($key == 'log_file') || ($key == 'object_cache_file') || ($key == 'ochp_command') ||
                ($key == 'ocsp_command') || ($key == 'p1_file') || ($key == 'precached_object_file') ||
                ($key == 'resource_file') || ($key == 'service_perfdata_command') || ($key == 'service_perfdata_file') ||
                ($key == 'service_perfdata_file_processing_command') || ($key == 'service_perfdata_file_template') ||
                ($key == 'state_retention_file') || ($key == 'status_file') || ($key == 'temp_file') ||
                ($key == 'temp_path')
            ) {
                $value = base64_decode($value);
            } elseif (preg_match('/^broker_module_/', $key)) {
                $key = "broker_module";
                $value = base64_decode($value);
            }
            fwrite($fileHandle, "$key=$value\n");
        }
        fclose($fileHandle);
        chmod($outfile, 0644);
    }

}
