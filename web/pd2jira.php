<?php
$messages = json_decode(file_get_contents("php://input"));

$email_address = 'INSERT_JIRA_EMAIL';
$pd_subdomain = 'INSERT_PD_SUBDOMAIN';
$pd_api_token = 'INSERT_PD_ACCESS_TOKEN';

if ($messages) foreach ($messages->messages as $webhook) {
  $webhook_type = $webhook->type;

  switch ($webhook_type) {
    case "incident.trigger":
      if(file_exists('lock.txt') && file_get_contents('lock.txt') > (time() - 5)){
        die('Should not run!');
      }
      file_put_contents('lock.txt', time());
      $incident_id = $webhook->data->incident->id;
      $incident_number = $webhook->data->incident->incident_number;
      $ticket_url = $webhook->data->incident->html_url;
      $pd_requester_id = $webhook->data->incident->assigned_to_user->id;
      $service_name = $webhook->data->incident->service->name;
      $assignee = $webhook->data->incident->assigned_to_user->name;
      if ($webhook->data->incident->trigger_summary_data->subject) {
        $trigger_summary_data = $webhook->data->incident->trigger_summary_data->subject;
      }
      else {
        $trigger_summary_data = $webhook->data->incident->trigger_summary_data->description;
      }

      $summary = "PagerDuty Service: $service_name, Incident #$incident_number, Summary: $trigger_summary_data";
      $summary = (strlen($summary) > 255) ? substr($summary,0,252) . '...' : $summary;

      $verb = "triggered";

      //Let's make sure the note wasn't already added (Prevents a 2nd Jira ticket in the event the first request takes long enough to not succeed according to PagerDuty)
      $url = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/notes";
      $return = http_request($url, "", "GET", "token", "", $pd_api_token);
      if ($return['status_code'] == '200') {
        $response = json_decode($return['response'], true);
        if (array_key_exists("notes", $response)) {
          foreach ($response['notes'] as $value) {
            $startsWith = "JIRA ticket";
            if (substr($value['content'], 0, strlen($startsWith)) === $startsWith) {
              break 2; //Skip it cause it would be a duplicate
            }
          }
        }
      }

      //Create the JIRA ticket when an incident has been triggered
      $subject = "$summary";
      $body = "A new PagerDuty incident has been triggered.\n\n{$trigger_summary_data}.\n\nPlease go to $ticket_url to view the incident.";
      $email_sent = mail($email_address, $subject, $body);
      if ($email_sent) {
        //Update the PagerDuty ticket with the JIRA ticket information.
        $url = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/notes";
        $data = array('note'=>array('content'=>"JIRA ticket has been created for this incident."),'requester_id'=>"$pd_requester_id");
        $data_json = json_encode($data);
        http_request($url, $data_json, "POST", "token", "", $pd_api_token);
      }
      else {
        //Log the error
        error_log("Error: The email failed to send to the Jira handler.");
        //Update the PagerDuty ticket if the JIRA ticket isn't made.
        $url = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/notes";
        $data = array('note'=>array('content'=>"Error: The email failed to send to the Jira handler."),'requester_id'=>"$pd_requester_id");
        $data_json = json_encode($data);
        http_request($url, $data_json, "POST", "token", "", $pd_api_token);
      }
      break;
    default:
      continue;
  }
}

function http_request($url, $data_json, $method, $auth_type, $username, $token) {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  if ($auth_type == "token") {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data_json),"Authorization: Token token=$token"));
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
  }
  else if ($auth_type == "basic") {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data_json)));
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$token");
  }
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  if ($data_json != "") {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);
  }
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response  = curl_exec($ch);
  $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if(curl_errno($ch)){
    error_log('Curl error: ' . curl_error($ch));
  }
  curl_close($ch);
  return array('status_code'=>"$status_code",'response'=>"$response");
}
?>
