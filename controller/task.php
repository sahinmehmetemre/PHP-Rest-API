<?php

require_once('db.php');
require_once('../model/Response.php');
require_once('../model/Task.php');

try{
  $writeDB = DB::connectWriteDB();
  $readDB = DB::connectReadDB();
}
catch(PDOException $e){
  error_log("Connection error - ".$e, 0);
  $response = new Response(500, false);
  $response->addMessage("Database connection error");
  $response->send();
  exit();
}


// BEGIN OF AUTH SCRIPT
// Authenticate user with access token
// check to see if access token is provided in the HTTP Authorization header and that the value is longer than 0 chars
// don't forget the Apache fix in .htaccess file
if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1)
{
  $response = new Response(401, false);
  (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage("Access token is missing from the header") : false);
  (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access token cannot be blank") : false);
  $response->send();
  exit;
}

// get supplied access token from authorisation header - used for delete (log out) and patch (refresh)
$accesstoken = $_SERVER['HTTP_AUTHORIZATION'];

// attempt to query the database to check token details - use write connection as it needs to be synchronous for token
try {
  // create db query to check access token is equal to the one provided
  $query = $writeDB->prepare('select userid, accesstokenexpiry, useractive, loginattempts from tblsessions, tblusers where tblsessions.userid = tblusers.id and accesstoken = :accesstoken');
  $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
  $query->execute();

  // get row count
  $rowCount = $query->rowCount();

  if($rowCount === 0) {
    // set up response for unsuccessful log out response
    $response = new Response(401, false);
    $response->addMessage("Invalid access token");
    $response->send();
    exit;
  }
  
  // get returned row
  $row = $query->fetch(PDO::FETCH_ASSOC);

  // save returned details into variables
  $returned_userid = $row['userid'];
  $returned_accesstokenexpiry = $row['accesstokenexpiry'];
  $returned_useractive = $row['useractive'];
  $returned_loginattempts = $row['loginattempts'];
  
  // check if account is active
  if($returned_useractive != 'Y') {
    $response = new Response(401, false);
    $response->addMessage("User account is not active");
    $response->send();
    exit;
  }

  // check if account is locked out
  if($returned_loginattempts >= 3) {
    $response = new Response(401, false);
    $response->addMessage("User account is currently locked out");
    $response->send();
    exit;
  }

  // check if access token has expired
  if(strtotime($returned_accesstokenexpiry) < time()) {
    $response = new Response(401, false);
    $response->addMessage("Access token has expired");
    $response->send();
    exit;
  }  
}
catch(PDOException $ex) {
  $response = new Response(500, false);
  $response->setHttpStatusCode(500);
  $response->setSuccess(false);
  $response->addMessage("There was an issue authenticating - please try again");
  $response->send();
  exit;
}

// END OF AUTH SCRIPT


if(array_key_exists("taskid", $_GET)){
  $taskid = $_GET['taskid'];

  if($taskid == '' || !is_numeric($taskid)){
    $response = new Response(400, false);
    $response->addMessage("Task ID cannot be blank and must be numeric");
    $response->send();
    exit();
  }

  if($_SERVER['REQUEST_METHOD'] === 'GET'){

    try{
      $query = $readDB->prepare('select id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where id = :taskid and userid = :userid');
      $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $rowCount = $query->rowCount();

      if($rowCount === 0){
        $response = new Response(404, false);
        $response->addMessage("Task not found");
        $response->send();
        exit();
      }

      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
        $taskArray[] = $task->returnTaskAsArray();
      }

      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['tasks'] = $taskArray;

      $response = new Response(200, true);
      $response->addMessage('Task returned');
      $response->toCache(true);
      $response->setData($returnData);
      $response->send();
      exit();
    }
    catch(TaskException $e){
      $response = new Response(500, false);
      $response->addMessage($e->getMessage());
      $response->send();
      exit();
    }
    catch(PDOException $e){
      error_log("Databse query error - ".$e, 0);
      $response = new Response(500, false);
      $response->addMessage("Failed to get task");
      $response->send();
      exit();
    }
  }
  elseif($_SERVER['REQUEST_METHOD'] === 'DELETE'){

    try{
      $query = $writeDB->prepare('delete from tbltasks where id = :taskid and userid = :userid');
      $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $rowCount = $query->rowCount();

      if($rowCount === 0){
        $response = new Response(404, false);
        $response->addMessage('Task not found');
        $response->send();
        exit();
      }

      $response = new Response(200, true);
      $response->addMessage('Task deleted');
      $response->send();
      exit();
    }
    catch(PDOException $e){
      error_log("Databse query error - ".$e, 0);
      $response = new Response(500, false);
      $response->addMessage("Failed to delete task");
      $response->send();
      exit();
    }

  }
  elseif($_SERVER['REQUEST_METHOD'] === 'PATCH'){

    try{

      if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
        $response = new Response(400, false);
        $response->addMessage("Content type header not set to JSON");
        $response->send();
        exit;
      }

      $rawPatchData = file_get_contents('php://input');

      if(!$jsonData = json_decode($rawPatchData)){
        $response = new Response(400, false);
        $response->addMessage("Request body is not valid JSON");
        $response->send();
        exit;
      }

      $title_updated = false;
      $description_updated = false;
      $deadline_updated = false;
      $completed_updated = false;

      $queryFields = "";

      if(isset($jsonData->title)){
        $title_updated = true;
        $queryFields .= "title = :title, ";
      }

      if(isset($jsonData->description)){
        $description_updated = true;
        $queryFields .= "description = :description, ";
      }

      if(isset($jsonData->deadline)){
        $deadline_updated = true;
        $queryFields .= "deadline = STR_TO_DATE(:deadline, '%d/%m/%Y %H:%i'), ";
      }

      if(isset($jsonData->completed)){
        $completed_updated = true;
        $queryFields .= "completed = :completed, ";
      }

      $queryFields = rtrim($queryFields, ", ");

      if($title_updated === false && $description_updated === false && $deadline_updated === false && $completed_updated === false){
        $response = new Response(400, false);
        $response->addMessage("No task fields provided");
        $response->send();
        exit;
      }

      $query = $writeDB->prepare('select id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where id = :taskid and userid = :userid');
      $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $rowCount = $query->rowCount();

      if($rowCount === 0){
        $response = new Response(404, false);
        $response->addMessage("No task found to update");
        $response->send();
        exit;
      }

      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
      }

      $queryString = "update tbltasks set ".$queryFields." where id = :taskid and userid = :userid";
      $query = $writeDB->prepare($queryString);

      if($title_updated === true){
        $task->setTitle($jsonData->title);
        $up_title = $task->getTitle();
        $query->bindParam(':title', $up_title, PDO::PARAM_STR);
      }

      if($description_updated === true){
        $task->setDescription($jsonData->description);
        $up_description = $task->getDescription();
        $query->bindParam(':description', $up_description, PDO::PARAM_STR);
      }

      if($deadline_updated === true){
        $task->setDeadline($jsonData->deadline);
        $up_deadline = $task->getDeadline();
        $query->bindParam(':deadline', $up_deadline, PDO::PARAM_STR);
      }

      if($completed_updated === true){
        $task->setCompleted($jsonData->completed);
        $up_completed = $task->getCompleted();
        $query->bindParam(':completed', $up_completed, PDO::PARAM_STR);
      }

      $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $rowCount = $query->rowCount();

      if($rowCount === 0){
        $response = new Response(400, false);
        $response->addMessage("Task not updated");
        $response->send();
        exit;
      }

      $query = $writeDB->prepare('select id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where id = :taskid and userid = :userid');
      $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $rowCount = $query->rowCount();

      if($rowCount === 0){
        $response = new Response(404, false);
        $response->addMessage("Task not found after update");
        $response->send();
        exit;
      }

      $taskArray = array();

      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
        $taskArray[] = $task->returnTaskAsArray();
      }

      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['tasks'] = $taskArray;

      $response = new Response(200, true);
      $response->addMessage("Task updated");
      $response->setData($returnData);
      $response->send();
      exit;
    }
    catch(TaskException $e){
      $response = new Response(400, false);
      $response->addMessage($e->getMessage());
      $response->send();
      exit;
    }
    catch(PDOException $e){
      error_log("Database query error - ".$e,0);
      $response = new Response(400, false);
      $response->addMessage("Failed to update the task, check your data for errors - ".$e->getMessage());
      $response->send();
      exit;
    }

  }
  else{
    $response = new Response(405, false);
    $response->addMessage("Request method not allowed");
    $response->send();
    exit();
  }

}
elseif(array_key_exists("completed", $_GET)){
  $completed = $_GET['completed'];

  if($completed !== 'Y' && $completed !== 'N'){
    $response = new Response(400, false);
    $response->addMessage('Completed filter must be Y or N');
    $response->send();
    exit();
  }

  if($_SERVER['REQUEST_METHOD'] === 'GET'){

    try{

      $query = $readDB->prepare('select id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where completed = :completed and userid = :userid');
      $query->bindParam(':completed', $completed, PDO::PARAM_STR);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $rowCount = $query->rowCount();

      $taskArray = array();

      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
        $taskArray[] = $task->returnTaskAsArray();
      }

      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['tasks'] = $taskArray;

      $response = new Response(200, true);
      $response->addMessage('Tasks returned');
      $response->toCache(true);
      $response->setData($returnData);
      $response->send();
      exit;
    }
    catch(TaskException $e){
      $response = new Response(500, false);
      $response->addMessage($e->getMessage());
      $response->send();
      exit;
    }
    catch(PDOException $e){
      error_log("Database query error - ".$e, 0);
      $response = new Response(500, false);
      $response->addMessage("Failed to get tasks");
      $response->send();
      exit;
    }

  }
  else{
    $response = new Response(405, false);
    $response->addMessage('Request method not allowed');
    $response->send();
    exit();
  }
}
elseif(array_key_exists("page", $_GET)){

  if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $page = $_GET['page'];

    if($page == '' || !is_numeric($page)){
      $response = new Response(400, false);
      $response->addMessage("Page number can not be blank and must be numeric");
      $response->send();
      exit;
    }

    $limitPerPage = 20;

    try{

      $query = $readDB->prepare('select count(id) as totalNoOfTasks from tbltasks where userid = :userid');
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $row = $query->fetch(PDO::FETCH_ASSOC);

      $tasksCount = intval($row['totalNoOfTasks']);

      $numOfPages = ceil($tasksCount/$limitPerPage);

      if($numOfPages == 0){
        $numOfPages = 1;
      }

      if($page > $numOfPages || $page == 0){
        $response = new Response(404, false);
        $response->addMessage("Page not found");
        $response->send();
        exit;
      }

      $offset = ($page == 1 ? 0 : ($limitPerPage*($page-1)));

      $query = $readDB->prepare('select id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where userid = :userid limit :pglimit offset :offset');
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->bindParam(":pglimit", $limitPerPage, PDO::PARAM_INT);
      $query->bindParam(":offset", $offset, PDO::PARAM_INT);
      $query->execute();

      $rowCount = $query->rowCount();

      $taskArray = array();

      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
        $taskArray[] = $task->returnTaskAsArray();
      }

      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['total_rows'] = $tasksCount;
      $returnData['total_pages'] = $numOfPages;
      $returnData['has_next_page'] = ($page < $numOfPages ? true : false);
      $returnData['has_previous_page'] = ($page > 1 ? true : false);
      $returnData['tasks'] = $taskArray;

      $response = new Response(200, true);
      $response->addMessage("Page ".$page." returned");
      $response->toCache(true);
      $response->setData($returnData);
      $response->send();
      exit;
    }
    catch(TaskException $e){
      $response = new Response(500, false);
      $response->addMessage($e->getMessage());
      $response->send();
      exit;
    }
    catch(PDOException $e){
      error_log("Database query error - ".$e, 0);
      $response = new Response(500, false);
      $response->addMessage("Failed to get tasks");
      $response->send();
      exit;
    }

  }
  else{
    $response = new Response(405, false);
    $response->addMessage("Request method not allowed");
    $response->send();
    exit;
  }

}
elseif(empty($_GET)){

  if($_SERVER['REQUEST_METHOD'] === 'GET'){

    try{

      $query = $readDB->prepare('select id, title,description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where userid = :userid');
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $rowCount = $query->rowCount();

      $taskArray = array();

      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
        $taskArray[] = $task->returnTaskAsArray();
      }

      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['tasks'] = $taskArray;

      $response = new Response(200, true);
      $response->addMessage('Tasks returned');
      $response->toCache(true);
      $response->setData($returnData);
      $response->send();
      exit;
    }
    catch(TaskException $e){
      $response = new Response(500, false);
      $response->addMessage($e->getMessage());
      $response->send();
      exit;
    }
    catch(PDOException $e){
      error_log('Database query error - '.$e, 0);
      $response = new Response(500, false);
      $response->addMessage('Failed to get tasks');
      $response->send();
      exit;
    }

  }
  elseif($_SERVER['REQUEST_METHOD'] === 'POST'){

    try{

      if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
        $response = new Response(400, false);
        $response->addMessage("Content type header is not set to JSON");
        $response->send();
        exit;
      }

      $rawPOSTData = file_get_contents('php://input');

      if(!$jsonData = json_decode($rawPOSTData)){
        $response = new Response(400, false);
        $response->addMessage("Resquest body is not valid JSON");
        $response->send();
        exit;
      }

      if(!isset($jsonData->title) || !isset($jsonData->completed)){
        $response = new Response(400, false);
        (!isset($jsonData->title) ? $response->addMessage("Title field is mandatory and must be provided") : false);
        (!isset($jsonData->completed) ? $response->addMessage("Completed field is mandatory and must be provided") : false);
        $response->send();
        exit;
      }

      $newTask = new Task(null, $jsonData->title, (isset($jsonData->description) ? $jsonData->description : null), (isset($jsonData->deadline) ? $jsonData->deadline : null), $jsonData->completed);

      $title = $newTask->getTitle();
      $description = $newTask->getDescription();
      $deadline = $newTask->getDeadline();
      $completed = $newTask->getCompleted();

      $query = $writeDB->prepare('insert into tbltasks (title, description, deadline, completed) values (:title, :description, STR_TO_DATE(:deadline, "%d/%m/%Y %H:%i"), :completed, :userid)');
      $query->bindParam(":title", $title, PDO::PARAM_STR);
      $query->bindParam(":description", $description, PDO::PARAM_STR);
      $query->bindParam(":deadline", $deadline, PDO::PARAM_STR);
      $query->bindParam(":completed", $completed, PDO::PARAM_STR);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $rowCount = $query->rowCount();

      if($rowCount === 0){
        $response = new Response(500, false);
        $response->addMessage("Failed to create task");
        $response->send();
        exit;
      }

      $lastTaskID = $writeDB->lastInsertId();

      $query = $readDB->prepare('select id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where id = :taskid and userid = :userid');
      $query->bindParam(":taskid", $lastTaskID, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();
      
      $rowCount = $query->rowCount();

      if($rowCount === 0){
        $response = new Response(500, false);
        $response->addMessage("Failed to retrieve task after creation");
        $response->send();
        exit;
      }

      $taskArray = array();

      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
        $taskArray[] = $task->returnTaskAsArray();
      }

      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['tasks'] = $taskArray;

      $response = new Response(201, true);
      $response->addMessage("Task created");
      $response->setData($returnData);
      $response->send();
      exit;

    }
    catch(TaskException $e){
      $response = new Response(400, false);
      $response->addMessage($e->getMessage());
      $response->send();
      exit;
    }
    catch(PDOException $e){
      error_log("Database query error - ".$e, 0);
      $response = new Response(400, false);
      $response->addMessage("Failed to inset the task, check submitted data for errors");
      $response->send();
      exit;
    }

  }
  else{
    $response = new Response(405, false);
    $response->addMessage('Request method not allowed');
    $response->send();
    exit;
  }

}
else{
  $response = new Response(404, false);
  $response->addMessage("Endpoint not found");
  $response->send();
  exit;
}