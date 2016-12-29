<?php

class API {
  private static function updateAgent($QUERY, $agent) {
    global $FACTORIES;
    
    $agent->setLastIp(Util::getIP());
    $agent->setLastAct($QUERY['action']);
    $agent->setLastTime(time());
    $FACTORIES->getAgentFactory()->update($agent);
  }
  
  private static function checkValues($QUERY, $values) {
    foreach ($values as $value) {
      if (!isset($QUERY[$value])) {
        return false;
      }
    }
    return true;
  }
  
  public static function setBenchmark($QUERY) {
    global $FACTORIES;
    
    // agent submits benchmark for task
    $task = $FACTORIES::getTaskFactory()->get($QUERY["taskId"]);
    if ($task == null) {
      API::sendErrorResponse("bench", "Invalid task ID!");
    }
    $qF = new QueryFilter("token", $QUERY['token'], "=");
    $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => $qF), true);
    $qF1 = new QueryFilter("agentId", $agent->getId(), "=");
    $qF2 = new QueryFilter("taskId", $task->getId(), "=");
    $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF1, $qF2)), true);
    if ($assignment == null) {
      API::sendErrorResponse("keyspace", "You are not assigned to this task!");
    }
    
    $type = $QUERY['type'];
    $benchmark = $QUERY['result'];
    
    //TODO: validate benchmark depending on the benchmarking type
    /*$split = explode(":", $benchmark);
    if(sizeof($split) != 2){
      API::sendErrorResponse("bench", "Invalid benchmark results!");
    }
    
    if ($split[0] <= 0 || $split[1] <= 0) {
      $agent->setIsActive(0);
      $FACTORIES::getAgentFactory()->update($agent);
      API::sendErrorResponse("bench", "Benchmark didn't measure anything!");
    }*/
    $assignment->setBenchmark($benchmark);
    $FACTORIES::getAssignmentFactory()->update($assignment);
    API::sendResponse(array("action" => "bench", "response" => "SUCCESS", "benchmark" => "OK"));
  }
  
  public static function setKeyspace($QUERY) {
    global $FACTORIES;
    
    // agent submits keyspace size for this task
    $keyspace = intval($QUERY["keyspace"]);
    $task = $FACTORIES::getTaskFactory()->get($QUERY['taskId']);
    if ($task == null) {
      API::sendErrorResponse("keyspace", "Invalid task ID!");
    }
    $qF = new QueryFilter("token", $QUERY['token'], "=");
    $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => $qF), true);
    $qF1 = new QueryFilter("agentId", $agent->getId(), "=");
    $qF2 = new QueryFilter("taskId", $task->getId(), "=");
    $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF1, $qF2)), true);
    if ($assignment == null) {
      API::sendErrorResponse("keyspace", "You are not assigned to this task!");
    }
    
    if ($task->getKeyspace() == 0) {
      // keyspace is still required
      $task->setKeyspace($keyspace);
      $FACTORIES::getTaskFactory()->update($task);
    }
    API::sendResponse(array("action" => "keyspace", "response" => "SUCCESS", "keyspace" => "OK"));
  }
  
  private static function sendChunk($chunk) {
    AbstractModelFactory::getDB()->query("COMMIT");
    API::sendResponse(array("action" => "task", "response" => "SUCCESS", "chunk" => $chunk->getId(), "skip" => $chunk->getSkip(), "length" => $chunk->getLength()));
  }
  
  private static function calculateChunkSize($benchmark, $tolerance = 1){
    global $CONFIG;
    
    $benchmark = explode(":", $benchmark);
    if(sizeof($benchmark) != 2 || $benchmark[0] <= 0 || $benchmark[1] <= 0){
      return 0;
    }
    
    $chunkTime = $CONFIG->getVal('chunktime');
    
    //TODO: check if time adjustments are needed
    $benchmark[1] *= 2/3;
    
    $factor = $chunkTime*1000/$benchmark[1];
    if($factor <= 0.25){
      $benchmark[0] /= 4;
    }
    else if($factor <= 0.5){
      $benchmark[0] /= 2;
    }
    else{
      $factor = floor($factor);
    }
    if($factor == 0){
      $factor = 1;
    }
    $size = $benchmark[0]*$factor;
    //TODO: apply here calculation to adjust to match size
    
    return $size*$tolerance;
  }
  
  private static function handleExistingChunk($chunk, $agent, $task, $assignment){
    global $FACTORIES;
    
    $disptolerance = 1.2; //TODO: add this to config
    
    $agentChunkSize = API::calculateChunkSize($assignment->getBenchmark(), 1);
    $agentChunkSizeMax = API::calculateChunkSize($assignment->getBenchmark(), $disptolerance);
    if($chunk->getProgress() == 0 && $agentChunkSizeMax > $chunk->getLength()){
      //chunk has not started yet
      $chunk->setRprogress(0);
      $chunk->setDispatchTime(time());
      $chunk->setSolveTime(0);
      $chunk->setState(0);
      $chunk->setAgentId($agent->getId());
      $FACTORIES::getChunkFactory()->update($chunk);
      API::sendChunk($chunk);
    }
    else if($chunk->getProgress() == 0){
      //split chunk into two parts
      $firstPart = $chunk;
      $firstPart->setLength($agentChunkSize);
      $firstPart->setAgentId($agent->getId());
      $firstPart->setDispatchTime(time());
      $firstPart->setSolveTime(0);
      $firstPart->setState(0);
      $firstPart->setRprogress(0);
      $FACTORIES::getChunkFactory()->update($firstPart);
      $secondPart = new Chunk(0, $task->getId(), $firstPart->getSkip() + $firstPart->getLength(), $chunk->getLength() - $firstPart->getLength(), null, 0, 0, 0, 0, 0, 0, 0);
      $FACTORIES::getChunkFactory()->save($secondPart);
      API::sendChunk($firstPart);
    }
    else{
      $chunk->setLength($chunk->getProgress());
      $chunk->setRprogress(10000);
      $chunk->setState(9);
      $FACTORIES::getChunkFactory()->update($chunk);
      API::createNewChunk($agent, $task, $assignment);
    }
  }
  
  private static function createNewChunk($agent, $task, $assignment){
    global $FACTORIES;
    
    $disptolerance = 1.2; //TODO: add to config
    
    $remaining = $task->getKeyspace() - $task->getProgress();
    $agentChunkSize = API::calculateChunkSize($assignment->getBenchmark(), 1);
    $start = $task->getProgress();
    $length = $agentChunkSize;
    if($remaining/$length <= $disptolerance){
      $length = $remaining;
    }
    $newProgress = $task->getProgress() + $length;
    $task->setProgress($newProgress);
    $FACTORIES::getTaskFactory()->update($task);
    $chunk = new Chunk(0, $task->getId(), $start, $length, $agent->getId(), time(), 0, 0, 0, 0, 0, 0);
    $FACTORIES::getChunkFactory()->save($chunk);
    API::sendChunk($chunk);
  }

  
  public static function getChunk($QUERY) {
    global $FACTORIES, $CONFIG;
    
    $task = $FACTORIES::getTaskFactory()->get($QUERY['taskId']);
    if ($task == null) {
      API::sendErrorResponse("chunk", "Invalid task ID!");
    }
    
    //check if agent is assigned
    $qF = new QueryFilter("token", $QUERY['token'], "=");
    $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => $qF), true);
    $qF1 = new QueryFilter("agentId", $agent->getId(), "=");
    $qF2 = new QueryFilter("taskId", $task->getId(), "=");
    $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF1, $qF2)), true);
    if ($assignment == null) {
      API::sendErrorResponse("chunk", "You are not assigned to this task!");
    }
    else if ($task->getKeyspace() == 0) {
      API::sendResponse(array("action" => "task", "response" => "SUCCESS", "chunk" => "keyspace_required"));
    }
    else if ($assignment->getBenchmark() == 0) {
      API::sendResponse(array("action" => "task", "response" => "SUCCESS", "chunk" => "benchmark"));
    }
  
    AbstractModelFactory::getDB()->query("START TRANSACTION");
    $qF = new QueryFilter("taskId", $task->getId(), "=");
    $chunks = $FACTORIES::getChunkFactory()->filter(array('filter' => $qF));
    $dispatched = 0;
    foreach ($chunks as $chunk) {
      if($chunk->getAgentId() == $agent->getId() && $chunk->getLength() != $chunk->getProgress()){
        //this is the case when the agent got interrupted in some way, so he can just continue with his chunk he was working on
        $chunk->setDispatchTime(time()); //reset time count to make sure the spent time gets calculated correctly
        $chunk->setSolveTime(0);
        $FACTORIES::getChunkFactory()->update($chunk);
        API::sendChunk($chunk);
      }
      $dispatched += $chunk->getLength();
    }
    if ($task->getProgress() == $task->getKeyspace() || $task->getKeyspace() == $dispatched) {
      API::sendResponse(array("action" => "task", "response" => "SUCCESS", "chunk" => "fully_dispatched"));
    }
  
    $qF1 = new ComparisonFilter("progress", "length", "<");
    $qF2 = new QueryFilter("taskId", $task->getId(), "=");
    $oF = new OrderFilter("skip", "ASC");
    $chunks = $FACTORIES::getChunkFactory()->filter(array('filter' => array($qF1, $qF2), 'order' => $oF));
    foreach($chunks as $chunk){
      if($chunk->getAgent == $agent->getId()){
        API::sendChunk($chunk);
      }
      $timeoutTime = time() - $CONFIG->getVal('chunktimeout');
      if($chunk->getState() == 6 || $chunk->getState() == 10 || max($chunk->getDispatchTime(), $chunk->getSolveTime()) < $timeoutTime){
        API::handleExistingChunk($chunk, $agent, $task, $assignment);
      }
    }
    $chunk = API::createNewChunk($agent, $task, $assignment);
    API::sendChunk($chunk);
  }
  
  public static function sendErrorResponse($action, $msg) {
    $ANS = array();
    $ANS['action'] = $action;
    $ANS['response'] = "ERROR";
    $ANS['message'] = $msg;
    header("Content-Type: application/json");
    echo json_encode($ANS, true);
    die();
  }
  
  public static function checkToken($QUERY) {
    global $FACTORIES;
    
    $qF = new QueryFilter("token", $QUERY['token'], "=");
    $token = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);
    if ($token != null) {
      return false;
    }
    return true;
  }
  
  private static function sendResponse($RESPONSE) {
    header("Content-Type: application/json");
    echo json_encode($RESPONSE, true);
    die();
  }
  
  public static function registerAgent($QUERY) {
    global $FACTORIES, $CONFIG;
    
    //check required values
    if (!API::checkValues($QUERY, array('voucher', 'gpus', 'uid', 'name', 'os'))) {
      API::sendErrorResponse("register", "Invalid registering query!");
    }
    
    $qF = new QueryFilter("voucher", $QUERY['voucher'], "=");
    $voucher = $FACTORIES::getRegVoucherFactory()->filter(array('filter' => array($qF)), true);
    if ($voucher == null) {
      API::sendErrorResponse("register", "Provided voucher does not exist.");
    }
    
    $gpu = $QUERY["gpus"];
    $uid = htmlentities($QUERY["uid"], false, "UTF-8");
    $name = htmlentities($QUERY["name"], false, "UTF-8");
    $os = intval($QUERY["os"]);
    
    //determine if the client has cpu only
    $cpuOnly = 1;
    foreach ($gpu as $card) {
      $card = strtolower($card);
      if ((strpos($card, "amd") !== false) || (strpos($card, "ati ") !== false) || (strpos($card, "radeon") !== false) || strpos($card, "nvidia") !== false || strpos($card, "gtx") !== false || strpos($card, "ti") !== false) {
        $cpuOnly = 0;
      }
    }
    
    //create access token & save agent details
    $token = Util::randomString(10);
    $gpu = htmlentities(implode("\n", $gpu), false, "UTF-8");
    $agent = new Agent(0, $name, $uid, $os, $gpu, "", "", $CONFIG->getVal('agenttimeout'), "", 1, 0, $token, "register", time(), Util::getIP(), null, $cpuOnly);
    $FACTORIES::getRegVoucherFactory()->delete($voucher);
    if ($FACTORIES::getAgentFactory()->save($agent)) {
      API::sendResponse(array("action" => "register", "response" => "SUCCESS", "token" => $token));
    }
    else {
      API::sendErrorResponse("register", "Could not register you to server.");
    }
  }
  
  public static function loginAgent($QUERY) {
    global $FACTORIES, $CONFIG;
    
    if (!API::checkValues($QUERY, array('token'))) {
      API::sendErrorResponse("login", "Invalid login query!");
    }
    
    // login to master server with previously provided token
    $qF = new QueryFilter("token", $QUERY['token'], "=");
    $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);
    if ($agent == null) {
      // token was not found
      API::sendErrorResponse("login", "Unknown token, register again!");
    }
    API::updateAgent($QUERY, $agent);
    API::sendResponse(array("action" => "login", "response" => "SUCCESS", "timeout" => $CONFIG->getVal("agenttimeout")));
  }
  
  public static function checkClientUpdate($QUERY) {
    global $SCRIPTVERSION, $SCRIPTNAME;
    
    // check if provided hash is the same as script and send file contents if not
    if (!API::checkValues($QUERY, array('version'))) {
      API::sendErrorResponse('update', 'Version value missing!');
    }
    
    $version = $QUERY['version'];
    
    if ($version != $SCRIPTVERSION) {
      API::sendResponse(array('action' => 'update', 'response' => 'SUCCESS', 'version' => 'NEW', 'data' => file_get_contents(dirname(__FILE__) . "/../static/$SCRIPTNAME")));
    }
    else {
      API::sendResponse(array('action' => 'update', 'response' => 'SUCCESS', 'version' => 'OK'));
    }
  }
  
  public static function downloadApp($QUERY) {
    global $FACTORIES;
    
    if (!API::checkValues($QUERY, array('token', 'type'))) {
      API::sendErrorResponse("download", "Invalid download query!");
    }
    $qF = new QueryFilter("token", $QUERY['token'], "=");
    $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);
    
    // provide agent with requested download
    switch ($QUERY['type']) {
      case "7zr":
        // downloading 7zip
        $filename = "7zr" . (($agent->getOs() == 1) ? ".exe" : "");
        header_remove("Content-Type");
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
        echo file_get_contents(dirname(__FILE__) . "/../static/" . $filename);
        die();
      case "hashcat":
        $oF = new OrderFilter("time", "DESC LIMIT 1");
        $hashcat = $FACTORIES::getHashcatReleaseFactory()->filter(array('order' => array($oF)), true);
        if ($hashcat == null) {
          API::sendErrorResponse("download", "No Hashcat release available!");
        }
        
        $postfix = array("bin", "exe");
        $executable = "hashcat64." . $postfix[$agent->getOs()];
        
        if ($agent->getHcVersion() == $hashcat->getVersion() && (!isset($QUERY['force']) || $QUERY['force'] != '1')) {
          API::sendResponse(array("action" => 'download', 'response' => 'SUCCESS', 'version' => 'OK', 'executable' => $executable));
        }
        
        $url = $hashcat->getUrl();
        $files = explode("\n", str_replace(" ", "\n", $hashcat->getCommonFiles()));
        $files[] = $executable;
        $rootdir = $hashcat->getRootdir();
        
        $agent->setHcVersion($hashcat->getVersion());
        $FACTORIES::getAgentFactory()->update($agent);
        API::sendResponse(array('action' => 'download', 'response' => 'SUCCESS', 'version' => 'NEW', 'url' => $url, 'files' => $files, 'rootdir' => $rootdir, 'executable' => $executable));
        break;
      default:
        API::sendErrorResponse('download', "Unknown download type!");
    }
  }
  
  public static function agentError($QUERY) {
    global $FACTORIES;
    
    //check required values
    if (!API::checkValues($QUERY, array('token', 'task', 'message'))) {
      API::sendErrorResponse("error", "Invalid error query!");
    }
    
    //check agent and task
    $qF = new QueryFilter("token", $QUERY['token'], "=");
    $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);
    $task = $FACTORIES::getTaskFactory()->get($QUERY['task']);
    if ($task == null) {
      API::sendErrorResponse("error", "Invalid task!");
    }
    
    //check assignment
    $qF1 = new QueryFilter("agentId", $agent->getId(), "=");
    $qF2 = new QueryFilter("taskId", $task->getId(), "=");
    $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF1, $qF2)), true);
    if ($assignment == null) {
      API::sendErrorResponse("error", "You are not assigned to this task!");
    }
    
    //save error message
    $error = new AgentError(0, $agent->getId(), $task->getId(), time(), $QUERY['message']);
    $FACTORIES::getAgentErrorFactory()->save($error);
    
    if ($agent->getIgnoreErrors() == 0) {
      //deactivate agent
      $agent->setIsActive(0);
      $FACTORIES::getAgentFactory()->update($agent);
    }
    API::sendResponse(array('action' => 'error', 'response' => 'SUCCESS'));
  }
  
  public static function getFile($QUERY) {
    global $FACTORIES;
    
    //check required values
    if (!API::checkValues($QUERY, array('token', 'task', 'file'))) {
      API::sendErrorResponse("file", "Invalid file query!");
    }
    
    // let agent download adjacent files
    $task = $FACTORIES::getTaskFactory()->get($QUERY['task']);
    if ($task == null) {
      API::sendErrorResponse('file', "Invalid task!");
    }
    
    $file = $QUERY['file'];
    $qF = new QueryFilter("filename", $file, "=");
    $file = $FACTORIES::getFileFactory()->filter(array('filter' => $qF), true);
    if ($file == null) {
      API::sendErrorResponse('file', "Invalid file!");
    }
    
    $qF = new QueryFilter("token", $QUERY['token'], "=");
    $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);
    
    $qF1 = new QueryFilter("taskId", $task->getId(), "=");
    $qF2 = new QueryFilter("agentId", $agent->getId(), "=");
    $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF1, $qF2)), true);
    if ($assignment == null) {
      API::sendErrorResponse('file', "Client is not assigned to this task!");
    }
    
    $qF1 = new QueryFilter("taskId", $task->getId(), "=");
    $qF2 = new QueryFilter("fileId", $file->getId(), "=");
    $taskFile = $FACTORIES::getTaskFileFactory()->filter(array('filter' => array($qF1, $qF2)), true);
    if ($taskFile == null) {
      API::sendErrorResponse('file', "This files is not used for the specified task!");
    }
    
    if ($agent->getIsTrusted() < $file->getSecret()) {
      API::sendErrorResponse('file', "You have no access to get this file!");
    }
    $filename = $file->getFilename();
    $extension = explode(".", $filename)[sizeof(explode(".", $filename)) - 1];
    //TODO: make correct url here
    API::sendResponse(array('action' => 'file', 'filename' => $filename, 'extension' => $extension, 'response' => 'SUCCESS', 'url' => "https://".$_SERVER['HTTP_HOST'].'/src/get.php?file=' . $file->getId() . "&token=" . $agent->getToken()));
  }
  
  public static function getHashes($QUERY) {
    global $FACTORIES;
    
    //check required values
    if (!API::checkValues($QUERY, array('token', 'hashlist'))) {
      API::sendErrorResponse("hashes", "Invalid hashes query!");
    }
    
    $hashlist = $FACTORIES::getHashlistFactory()->get($QUERY['hashlist']);
    if ($hashlist == null) {
      API::sendErrorResponse('hashes', "Invalid hashlist!");
    }
    
    $qF = new QueryFilter("token", $QUERY['token'], "=");
    $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);
    if ($agent == null) {
      API::sendErrorResponse('hashes', "Invalid agent!");
    }
    
    $qF = new QueryFilter("agentId", $agent->getId(), "=");
    $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF)), true);
    if ($assignment == null) {
      API::sendErrorResponse('hashes', "Agent is not assigned to a task!");
    }
    
    $task = $FACTORIES::getTaskFactory()->get($assignment->getTaskId());
    if ($task == null) {
      API::sendErrorResponse('hashes', "Assignment contains invalid task!");
    }
    
    if ($task->getHashlistId() != $hashlist->getId()) {
      API::sendErrorResponse('hashes', "This hashlist is not used for the assigned task!");
    }
    else if ($agent->getIsTrusted() < $hashlist->getSecret()) {
      API::sendErrorResponse('hashes', "You have not access to this hashlist!");
    }
    $LINEDELIM = "\n";
    if ($agent->getOs() == 1) {
      $LINEDELIM = "\r\n";
    }
    
    $hashlists = array();
    $format = $hashlist->getFormat();
    if ($hashlist->getFormat() == 3) {
      //we have a superhashlist
      $qF = new QueryFilter("superHashlistId", $hashlist->getId(), "=");
      $lists = $FACTORIES->getSuperHashlistHashlistFactory()->filter(array('filter' => array($qF)));
      foreach ($lists as $list) {
        $hl = $FACTORIES::getHashlistFactory()->get($list->getHashlistId());
        if ($hl->getSecret() > $agent->getIsTrusted()) {
          continue;
        }
        $hashlists[] = $list->getHashlistId();
      }
    }
    else {
      $hashlists[] = $hashlist->getId();
    }
    
    if (sizeof($hashlists) == 0) {
      API::sendErrorResponse('hashes', "No hashlists selected!");
    }
    $count = 0;
    switch ($format) {
      case 0:
        header_remove("Content-Type");
        header('Content-Type: text/plain');
        foreach ($hashlists as $list) {
          $limit = 0;
          $size = 50000;
          do {
            $oF = new OrderFilter("hashId", "ASC LIMIT $limit,$size");
            $qF1 = new QueryFilter("hashlistId", $list, "=");
            $qF2 = new QueryFilter("isCracked", "0", "=");
            $current = $FACTORIES::getHashFactory()->filter(array('filter' => array($qF1, $qF2), 'order' => array($oF)));
            
            $output = "";
            $count += sizeof($current);
            foreach ($current as $entry) {
              $output .= $entry->getHash();
              if (strlen($entry->getSalt()) > 0) {
                $output .= $hashlist->getSaltSeparator() . $entry->getSalt();
              }
              $output .= $LINEDELIM;
            }
            echo $output;
            
            $limit += $size;
          } while (sizeof($current) > 0);
        }
        break;
      case 1:
      case 2:
        header_remove("Content-Type");
        header('Content-Type: application/octet-stream');
        foreach ($hashlists as $list) {
          $qF1 = new QueryFilter("hashlistId", $list->getId(), "=");
          $qF2 = new QueryFilter("plaintext", "null", "=");
          $current = $FACTORIES::getHashBinaryFactory()->filter(array('filter' => array($qF1, $qF2)));
          $count += sizeof($current);
          $output = "";
          foreach ($current as $entry) {
            $output .= $entry->getHash();
          }
          echo $output;
        }
        break;
    }
    
    //update that the agent has downloaded the hashlist
    foreach ($hashlists as $list) {
      $qF1 = new QueryFilter("agentId", $agent->getId(), "=");
      $qF2 = new QueryFilter("hashlistId", $list, "=");
      $check = $FACTORIES::getHashlistAgentFactory()->filter(array('filter' => array($qF1, $qF2)), true);
      if ($check == null) {
        $downloaded = new HashlistAgent(0, $list, $agent->getId());
        $FACTORIES::getHashlistAgentFactory()->save($downloaded);
      }
    }
    
    if ($count == 0) {
      API::sendErrorResponse('hashes', "No hashes are available to crack!");
    }
  }
  
  public static function getTask($QUERY) {
    global $FACTORIES;
    
    $qF = new QueryFilter("token", $QUERY['token'], "=");
    $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => array($qF)), true);
    if ($agent == null) {
      API::sendErrorResponse('task', "Invalid token!");
    }
    else if ($agent->getIsActive() == 0) {
      API::sendResponse(array('action' => 'task', 'response' => 'SUCCESS', 'task' => 'NONE'));
    }
    
    $qF = new QueryFilter("agentId", $agent->getId(), "=");
    $assignment = $FACTORIES::getAssignmentFactory()->filter(array('filter' => array($qF)), true);
    $assignedTask = null;
    if ($assignment == null) {
      //search which task we should assign to the agent
      $nextTask = Util::getNextTask($agent);
      if ($nextTask == null) {
        API::sendResponse(array('action' => 'task', 'response' => 'SUCCESS', 'task' => 'NONE'));
      }
      $assignment = new Assignment(0, $nextTask->getId(), $agent->getId(), 0);
      $FACTORIES::getAssignmentFactory()->save($assignment);
      $assignedTask = $nextTask;
    }
    else {
      //check if the agent is assigned to the correct task, if not assign him the right one
      $task = $FACTORIES::getTaskFactory()->get($assignment->getTaskId());
      $hashlist = $FACTORIES::getHashlistFactory()->get($task->getHashlistId());
      $qF = new QueryFilter("taskId", $task->getId(), "=");
      $chunks = $FACTORIES::getChunkFactory()->filter(array('filter' => $qF));
      $sumProgress = 0;
      $chunkIds = array();
      foreach($chunks as $chunk){
        $sumProgress += $chunk->getProgress();
        $chunkIds[] = $chunk->getId();
      }
      $finished = false;
      
      //check if the task is finished
      if (($task->getKeyspace() == $sumProgress && $task->getKeyspace() != 0) || $hashlist->getCracked() == $hashlist->getHashCount()) {
        //task is finished
        $task->setPriority(0);
        //TODO: make massUpdate
        foreach($chunks as $chunk){
          $chunk->setProgress($chunk->getLength());
          $chunk->setRprogress(10000);
          $FACTORIES::getChunkFactory()->update($chunk);
        }
        $task->setProgress($task->getKeyspace());
        $FACTORIES::getTaskFactory()->update($task);
        $finished = true;
      }
      
      $highPriorityTask = Util::getNextTask($agent, $task->getPriority());
      if ($highPriorityTask != null) {
        //there is a more important task
        $FACTORIES::getAssignmentFactory()->delete($assignment);
        $assignment = new Assignment(0, $highPriorityTask->getId(), $agent->getId(), 0);
        $FACTORIES::getAssignmentFactory()->save($assignment);
        $assignedTask = $highPriorityTask;
      }
      else {
        if (!$finished) {
          $assignedTask = $task;
        }
        else{
          $qF = new QueryFilter("agentId", $agent->getId(), "=");
          $FACTORIES::getAssignmentFactory()->massDeletion(array('filter' => $qF));
          $assignedTask = Util::getNextTask($agent);
          if($assignedTask != null) {
            $assignment = new Assignment(0, $assignedTask->getId(), $agent->getId(), 0);
            $FACTORIES::getAssignmentFactory()->save($assignment);
          }
        }
      }
    }
    
    if ($assignedTask == null) {
      //no task available
      API::sendResponse(array('action' => 'task', 'response' => 'SUCCESS', 'task' => 'NONE'));
    }
    
    $qF = new QueryFilter("taskId", $assignedTask->getId(), "=");
    $jF = new JoinFilter($FACTORIES::getFileFactory(), "fileId", "fileId");
    $joinedFiles = $FACTORIES::getTaskFileFactory()->filter(array('join' => $jF, 'filter' => $qF));
    $files = array();
    for ($x = 0; $x < sizeof($joinedFiles['File']); $x++) {
      $files[] = $joinedFiles['File'][$x]->getFilename();
    }
    
    $hashlist = $FACTORIES::getHashlistFactory()->get($assignedTask->getHashlistId());
    
    API::sendResponse(array(
        'action' => 'task',
        'response' => 'SUCCESS',
        'task' => $assignedTask->getId(),
        'wait' => $agent->getWait(),
        'attackcmd' => $assignedTask->getAttackCmd(),
        'cmdpars' => $agent->getCmdPars() . " --hash-type=" . $hashlist->getHashTypeId(),
        'hashlist' => $assignedTask->getHashlistId(),
        'bench' => 'new', //TODO: here we should tell him new or continue depending if he was already working on this hashlist or not
        'statustimer' => $assignedTask->getStatusTimer(),
        'files' => $files
      )
    );
  }
  
  //TODO Handle the case where an agent needs reassignment
  public static function solve($QUERY) {
    global $FACTORIES;
    
    // upload cracked hashes to server
    $cid = intval($QUERY["chunk"]);
    $keyspaceProgress = floatval($QUERY["keyspaceProgress"]);
    $combinationProgress = floatval($QUERY["progress"]);      //Normalized between 1-10k
    $combinationTotal = floatval($QUERY["total"]);           //TODO Not sure what this variable does
    $speed = floatval($QUERY["speed"]);
    $state = intval($QUERY["state"]);     //Util::getStaticArray($states, $state)
    $action = $QUERY["action"];
    $token = $QUERY["token"];
    
    /**
     * This part sends a lot of DB-Requests. It may need to be optimized in the future.
     */
    $chunk = $FACTORIES::getChunkFactory()->get($cid);
    if ($chunk == null) {
      API::sendErrorResponse($action, "Invalid chunk id " . $cid);
    }
    
    $qF = new QueryFilter("token", $token, "=");
    $agent = $FACTORIES::getAgentFactory()->filter(array('filter' => $qF), true);
    if ($agent == null) {
      API::sendErrorResponse($action, "Invalid agent token" . $token);
    }
    if ($chunk->getAgentId() != $agent->getId()) {
      API::sendErrorResponse($action, "You are not assigned to this chunk");
    }
    
    $task = $FACTORIES::getTaskFactory()->get($chunk->getTaskId());
    if ($task == null) {
      API::sendErrorResponse($action, "No task exists for the given chunk");
    }
    
    $hashList = $FACTORIES::getHashlistFactory()->get($task->getHashlistId());
    if ($hashList->getSecret() > $agent->getIsTrusted()) {
      API::sendErrorResponse($action, "Unknown Error. The API does not trust you with more information");
    }
    if ($hashList == null) {    //There are preconfigured task with hashlistID == null, but a solving task should never be preconfigured
      API::sendErrorResponse($action, "The given task does not have a corresponding hashList");
    }
    
    // agent is assigned to this chunk (not necessarily task!)
    // it can be already assigned to other task, but is still computing this chunk until it realizes it
    $skip = $chunk->getSkip();
    $length = $chunk->getLength();
    $taskID = $task->getId();
    
    /*
     * Calculate the relative progress inside of the chunk
     */
    $chunkCombinationStart = $combinationTotal/($skip+$length)*$skip;
    $currentRelativeProgress = round(($combinationProgress - $chunkCombinationStart)/($combinationTotal - $chunkCombinationStart)*10000);
    $keyspaceProgress -= $skip;
    
    //if by accident the number of the combinationProgress overshoots the limit
    if($currentRelativeProgress > 10000){
      $currentRelativeProgress = 10000;
    }
    
    //avoid rounding errors
    if ($combinationProgress < $combinationTotal && $currentRelativeProgress == 10000) {
      $currentRelativeProgress--;
    }
    else if ($combinationProgress > 0 && $currentRelativeProgress == 0) {
      $currentRelativeProgress++;
    }
    
    // workaround for hashcat not sending correct final curku(keyspaceprogress) =skip+len when done with chunk
    if ($combinationProgress == $combinationTotal) {
      $keyspaceProgress = $length;
    }
    
    /*
     * Save chunk updates
     */
    $chunk->setRprogress($currentRelativeProgress);
    $chunk->setProgress($keyspaceProgress);
    $chunk->setSolveTime(time());
    $aborting = false;
    if($chunk->getState() == 6){
      $aborting = true;
    }
    $chunk->setState($state);
    $FACTORIES::getChunkFactory()->update($chunk);
    
    
    $hlistar = Util::checkSuperHashlist($hashList);
    $hlistarIds = array();
    foreach($hlistar as $hl){
      $hlistarIds[] = $hl->getId();
    }
    $format = $FACTORIES::getHashlistFactory()->get($hlistar[0]->getId())->getFormat();
    
    // reset values
    $skipped = 0;
    $cracked = array();
    foreach ($hlistar as $l) {
      $cracked[$l->getId()] = 0;
    }
    
    // process solved hashes, should there be any
    $crackedHashes = $QUERY['cracks'];
    foreach ($crackedHashes as $crackedHash) {
      if ($crackedHash == "") {
        continue;
      }
      //TODO: get separator from config
      $splitLine = explode(":", $crackedHash);
      AbstractModelFactory::getDB()->query("START TRANSACTION");
      switch ($format) {
        case 0:
          //TODO search for hash in DB
          //get salt
          //replace hash + salt from the line -> plaintext remains
          // save regular password
          $hashFilter = new QueryFilter("hash", $splitLine[0], "=");
          $hashListFilter = new ContainFilter("hashlistId", $hlistarIds);
          $isCrackedFilter = new QueryFilter("isCracked", 0, "=");
          $hashes = $FACTORIES::getHashFactory()->filter(array("filter" => array($isCrackedFilter, $hashFilter, $hashListFilter)));
          if(sizeof($hashes) == 0){
            continue;
          }
          $salt = $hashes[0]->getSalt();
          if (strlen($salt) == 0) {
            // unsalted hashes
            $plain = str_replace($hashes[0]->getHash() . ':', "", $crackedHash);
          }
          else {
            // salted hashes
            $plain = str_replace($hashes[0]->getHash() . ':' . $hashes[0]->getSalt() . ':', "", $crackedHash);
          }
          if (sizeof($hashes) == 0) {
            $skipped++;
          }
          foreach ($hashes as $hash) {  //TODO Mass-update
            $cracked[$hash->getHashlistId()]++;
            $hash->setPlaintext($plain);
            $hash->setChunkId($chunk->getId());
            $hash->setIsCracked(1);
            $FACTORIES::getHashFactory()->update($hash);
          }
          break;
        case 1:
          // save cracked wpa password
          $network = $splitLine[0];
          $plain = $splitLine[1];
          // QUICK-FIX WPA/WPA2 strip mac address
          if (preg_match("/.+:[0-9a-f]{12}:[0-9a-f]{12}$/", $network) === 1) {
            // TODO: extend DB model by MACs and implement detection
            $network = substr($network, 0, strlen($network) - 26);
          }
          $essIDFilter = new QueryFilter("essid", $network, "=");
          $hashes = $FACTORIES::getHashBinaryFactory()->filter(array("filter" => $essIDFilter));
          if (sizeof($hashes) == 0) {
            $skipped++;
          }
          foreach ($hashes as $hash) {
            $cracked[$hash->getHashlistId()]++;
            $hash->setIsCracked(1);
            $hash->setChunk($chunk->getId());
            $hash->setPlaintext($plain);
            $FACTORIES::getHashFactory()->update($hash);
          }
          break;
        case 2:
          // save binary password
          // TODO Fix issue with superhashlists
          $plain = $splitLine[1];
          break;
      }
      AbstractModelFactory::getDB()->query("COMMIT");
    }
    
    //insert #Cracked hashes and update in hashlist how many hashes were cracked
    AbstractModelFactory::getDB()->query("START TRANSACTION");
    $sumCracked = 0;
    foreach ($cracked as $listId => $cracks) {
      $list = $FACTORIES::getHashlistFactory()->get($listId);
      $list->setCracked($cracks + $list->getCracked());
      $FACTORIES::getHashlistFactory()->update($list);
      $sumCracked += $cracks;
    }
    $chunk = $FACTORIES::getChunkFactory()->get($chunk->getId());
    $chunk->setCracked($chunk->getCracked() + $sumCracked);
    $FACTORIES::getChunkFactory()->update($chunk);
    AbstractModelFactory::getDB()->query("COMMIT");
    
    if ($chunk->getState() == 10) { //TODO Don't compare with 10
      // the chunk was manually interrupted
      $chunk->setState(6); //TODO Don't use 6
      $FACTORIES::getChunkFactory()->update($chunk);
      API::sendErrorResponse($action, "Chunk was manually interrupted.");
    }
    /** Check if the task is done */
    $taskdone = false;
    if ($combinationProgress == $combinationTotal && $task->getProgress() == $task->getKeyspace()) {
      // chunk is done and the task has been fully dispatched
      $incompleteFilter = new QueryFilter("rprogress", 10000, "<");
      $taskFilter = new QueryFilter("taskId", $taskID, "=");
      $count = $FACTORIES::getChunkFactory()->countFilter(array("filter" => array($incompleteFilter, $taskFilter)));
      if ($count == 0) {
        // this was the last incomplete chunk!
        $taskdone = true;
      }
    }
    
    if ($taskdone) {
      // task is fully dispatched and this last chunk is done, deprioritize it
      $task->setPriority(0);
      $FACTORIES::getTaskFactory()->update($task);
      
      // TODO: notificate task done
    }
    
    $hashlists = Util::checkSuperHashlist($hashList);
    $hashlistIds = array();
    foreach($hashlists as $hl){
      $hashlistIds[] = $hl->getId();
    }
    $toZap = array();
    
    if($aborting){
      $chunk->setSpeed(0);
      $FACTORIES::getChunkFactory()->update($chunk);
      API::sendErrorResponse($action, "Chunk was aborted!");
    }
    
    switch ($state) {
      case 4:
        // the chunk has finished (exhausted)
        $chunk->setSpeed(0);
        $FACTORIES::getChunkFactory()->update($chunk);
        break;
      case 5:
        // the chunk has finished (cracked whole hashList)
        // deprioritize all tasks and unassign all agents
        $qF = new ContainFilter("hashlistId", $hashlistIds);
        $uS = new UpdateSet("priority", "0");
        $FACTORIES::getTaskFactory()->massUpdate(array('update' => $uS, 'filter' => $qF));
        
        $chunk->setSpeed(0);
        $FACTORIES::getChunkFactory()->update($chunk);
        
        //TODO: notificate hashList done
        break;
      case 6:
        // the chunk was aborted
        $chunk->setSpeed(0);
        $FACTORIES::getChunkFactory()->update($chunk);
        API::sendErrorResponse($action, "Chunk was aborted!");
        break;
      default:
        // the chunk isn't finished yet, we will send zaps
        $qF1 = new ComparisonFilter("cracked", "hashCount", "<");
        $qF2 = new ContainFilter("hashlistId", $hashlistIds);
        $count = $FACTORIES::getHashlistFactory()->countFilter(array('filter' => array($qF1, $qF2)));
        if ($count == 0) {
          //stop agent
          API::sendResponse(array("action" => $action, "response" => "SUCCESS", "cracked" => $sumCracked, "skipped" => $skipped, "agent" => "stop"));
        }
        $chunk->setSpeed($speed);
        $FACTORIES::getChunkFactory()->update($chunk);
        
        $qF1 = new ContainFilter("hashlistId", $hashlistIds);
        $qF2 = new QueryFilter("solveTime", $agent->getLastAct(), ">=");
        $zaps = $FACTORIES::getZapFactory()->filter(array('filter' => array($qF1, $qF2)));
        foreach ($zaps as $zap) {
          $toZap[] = $zap->getHash();
        }
        $agent->setLastAct(time());
        $FACTORIES::getAgentFactory()->update($agent);
        
        // update hashList age for agent to this task
        break;
    }
    Util::zapCleaning();
    API::sendResponse(array("action" => $action, "response" => "SUCCESS", "cracked" => $sumCracked, "skipped" => $skipped, "zaps" => $toZap));
  }
}