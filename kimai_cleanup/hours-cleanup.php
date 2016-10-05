#!/usr/bin/php
<?php
// Default: assumes output is straight from Kimai
// Date,In,Out,h'm,Time,Rate (by hour),Dollar,Customer,Project,Task,Comment,Location,Tracking Number,Username,cleared

# Ensure sufficient arguments.
if (empty($argv[1])) {
  echo "Usage: hours-cleanup FILENAME\n";
  echo "  FILENAME: Name of a Kimai export CSV file; this file is searched for\n";
  echo "    in the current directory, and in /tmp/.\n";
  exit;
}


// Writable temp directory.
$tmp = '/tmp';

$check_dirs = array(
  '',
  '.',
  $tmp,
);
$file_found = FALSE;
$location = '';
foreach ($check_dirs as $dir) {
  $filename = $argv[1];
  if ($dir) {
    $location = "in $dir";
  }
  echo "Checking for file $filename $location ... ";
  $filepath = "$dir/$filename";
  if(file_exists($filepath)) {
    echo "found.\n";
    $file_found = TRUE;
    break;
  }
  else {
    echo "not found\n";
  }
}

if (!$file_found) {
  echo ("File not found.\n");
  exit;
}


$fp = fopen($filepath, 'r');

// throw away the header row
fgetcsv($fp);

$rows = array();
$sort = array();
while ($row = fgetcsv($fp)) {
  $newrow = array();

  // Get columns 
  $newrow['date'] = preg_replace('/^(\d+)\.(\d+).$/', '$2/$1/'. date('Y'), $row[0]);
  $newrow['in'] = $row[1];
  $newrow['out'] = $row[2];
  $newrow['hm'] = $row[3];
  $newrow['customer'] = $row[7];
  $newrow['project'] = $row[8];
  $newrow['task'] = $row[9];
  $newrow['comment'] = $row[10];
  $newrow['trackingno'] = $row[12];
  $newrow['sorttimestamp'] = strtotime($newrow['date'] . ' ' . $newrow['in']);

  // Sort rows by client/project/datetime
  $row_sort_string =
    // client
    $newrow['customer']
    // project
    // . '|'. $newrow['project']
    // date and time
    . '|'. $newrow['sorttimestamp']
  ;

  $sort[] = $row_sort_string;
  $rows[] = $newrow;

  unset($row);

}
fclose($fp);

// Sort rows by client/project/datetime
array_multisort($sort, $rows);

// Adjust for final display:
$last_project_comment = array();
foreach ($rows as &$row) {
  $row['client'] = (isset($row['client']) ? $row['client'] : '');
  $project_key = $row['client'] . '|' . $row['project'];

  // Initialize array member.
  $last_project_comment[$project_key] = (isset($last_project_comment[$project_key]) ? $last_project_comment[$project_key] : '');
  
  if ($row['comment']) {
    $comment = $row['comment'];
  }
  else {
    $comment = $last_project_comment[$project_key];
  }

  $row['extra_comment'] = $last_project_comment[$project_key] = $comment;

  $row = array(
    'zef_in' => strtotime("{$row['date']} {$row['in']}"),
    'trackingno' => $row['trackingno'],
    'date' => $row['date'],
    'hm' => $row['hm'],
    'project' => $row['project'],
    'rate' => '80',
    'extra_comment' => $row['extra_comment'],
    'comment' => $row['comment'],
    'customer' => $row['customer'],
    'in' => $row['in'],
    'out' => $row['out'],
    'task' => $row['task'],
  );
}
unset($row);

$columns_ordered = array(
  array(
    'key' => 'zef_in',
    'label' => 'zef_in',
  ),
  array(
    'key' => 'trackingno',
    'label' => 'zef_trackingnr',
  ),
  array(
    'key' => 'date',
    'label' => 'Date',
  ),
  array(
    'key' => 'hours',
    'label' => 'Hours',
  ),
  array(
    'key' => 'project',
    'label' => 'Project',
  ),
  array(
    'key' => 'extra_comment',
    'label' => 'Comment',
  ),
  array(
    'key' => 'rate',
    'label' => 'Rate',
  ),
  array(
    'key' => 'comment',
    'label' => 'Original comment',
  ),
  array(
    'key' => 'customer',
    'label' => 'Client',
  ),
  array(
    'key' => 'in',
    'label' => 'in',
  ),
  array(
    'key' => 'out',
    'label' => 'out',
  ),
  array(
    'key' => 'task',
    'label' => 'task',
  ),
);

$header_row = array();
foreach ($columns_ordered as $column) {
  $header_row[] = $column['label'];
}

$file_prefix = uniqid();
$cleaned_file = "$tmp/{$file_prefix}_cleaned.csv";
echo "Cleaned data: $cleaned_file\n";
$op = fopen($cleaned_file, 'w');
fputcsv($op, $header_row);
foreach ($rows as $row) {
  fputcsv($op, $row);
}

$date_tasks = array();
foreach ($rows as $row) {
  // Initialize arrays
  $date_tasks[$row['customer']] = (isset($date_tasks[$row['customer']]) ? $date_tasks[$row['customer']] : array());
  $date_tasks[$row['customer']][$row['date']] = (isset($date_tasks[$row['customer']][$row['date']]) ? $date_tasks[$row['customer']][$row['date']] : array());
  $date_tasks[$row['customer']][$row['date']][$row['extra_comment']] = (isset($date_tasks[$row['customer']][$row['date']][$row['extra_comment']]) ? $date_tasks[$row['customer']][$row['date']][$row['extra_comment']] : array());
  if (empty($date_tasks[$row['customer']][$row['date']][$row['extra_comment']]['seconds'])) {
    $date_tasks[$row['customer']][$row['date']][$row['extra_comment']]['seconds'] = 0;
  }

  list($hours, $minutes, $seconds) = explode(':', $row['hm']);
  $minutes += ($hours * 60);
  $seconds += ($minutes * 60);
  $date_tasks[$row['customer']][$row['date']][$row['extra_comment']]['seconds'] += $seconds;
  if (!isset($date_tasks[$row['customer']][$row['date']][$row['extra_comment']]['row'])) {
    $date_tasks[$row['customer']][$row['date']][$row['extra_comment']]['row'] = $row;
    $date_tasks[$row['customer']][$row['date']][$row['extra_comment']]['row']['extra_comment'] = $row['extra_comment'];
  }
}

$consolidated_file = "$tmp/{$file_prefix}_consolidated.csv";
echo "Consolidated data: $consolidated_file\n";
$op = fopen($consolidated_file, 'w');
fputcsv($op, $header_row);

foreach($date_tasks as $client => $dates) {
  foreach($dates as $date => $tasks) {
    foreach($tasks as $task => $task_properties) {
      $task_row = $task_properties['row'];
      $task_row['hours'] = secondstotimestring($task_properties['seconds']);
      $task_row['date'] = $date;

      $row = array();
      foreach ($columns_ordered as $column) {
        $row[] = $task_row[$column['key']];
      }
      
      fputcsv($op, $row);
    }
  }
}

echo "\nLazy command line for OpenOffice users:\n";
echo "oocalc $cleaned_file $consolidated_file\n\n";
echo "Done.\n";
exit;



function secondstotimestring($seconds) {
  $hours = floor($seconds / 3600);
  $minutes = floor(($seconds / 60) % 60);
  $seconds = $seconds % 60;
  return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
}