<?php
/**
 * @file reports.php
 * @brief Reports functions
 */
ini_set("memory_limit","2048M");
ini_set('error_reporting', E_ERROR);
set_time_limit(0);
date_default_timezone_set('Africa/Cairo');

require_once 'db.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * @brief Write log into console and file
 * @param string $text  [Log message text]
 */
function write_log($text)
{
    $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    echo $log;
    file_put_contents('export.log', $log, FILE_APPEND | LOCK_EX);
}

/**
 * Send email notification
 * @param string $title     [Email title]
 * @param string $text      [Email text]
 */
function send_email($subject, $message)
{
    $mail = new PHPMailer;
    $mail->setFrom('tracking@runwaysale.co.za');
    $mail->addReplyTo('tracking@runwaysale.co.za');
    $mail->addAddress('pj.f@runwaysale.co.za');
    $mail->addAddress('gaziz.n@runwaysale.co.za');
    $mail->Subject = $subject;
    $mail->msgHTML($message, __DIR__);
    if (!$mail->send()) {
        write_log('Mailer Error: '. $mail->ErrorInfo);
    } else {
        write_log('Message sent!');
    }
}

/**
 * Send daily report function
 * @param array $results    [Scrapers statuses from sites table]
 */
function send_daily_report($results)
{
    $subject = 'Daily tracking report '.date('Y-m-d');
    $message = '<body><h1>Tracking daily report</h1></br><h2>Report date: '.date('Y-m-d').'</h2></br>';
    $message .= '<table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Source</th>
                <th>Status</th>
				<th>Delivered</th>
                <th>Products Scraped</th>
                <th>Products Total</th>
                <th>Variants Scraped</th>
                <th>Export date</th>
            </tr>
        </thead><tbody>';
    foreach ($results as $result)
    {
        $message .= '<tr>';
        $message .= '<td>'.$result['site_id'].'</td>';
        $message .= '<td>'.$result['site_name'].'</td>';
        $message .= '<td>'.$result['status'].'</td>';
		$message .= '<td>'.$result['delivered'].'</td>';
        $message .= '<td>'.$result['products_count'].'</td>';
        $message .= '<td>'.$result['products_total'].'</td>';
        $message .= '<td>'.$result['variants_count'].'</td>';
        $message .= '<td>'.$result['export_date'].'</td>';
        $message .= '</tr>';
    }
    $message .= '</tbody></table></body>';
    send_email($subject, $message);
}

write_log('Select sites from DB');
$db = new PDO("mysql:host=".$db_servername.";dbname=".$db_database, $db_username, $db_password);
$query = "SELECT * FROM sites WHERE disabled=0 ORDER BY site_id";
$req = $db->query($query);
$results = [];
foreach ($req as $row)
{
    $result['site_id'] = $row['site_id'];
    $result['site_name'] = $row['site_name'];
    $result['site_url'] = $row['site_url'];
    if ($row['status'] == 1)
        $result['status'] = "<p style='color: green; margin: 5px;'>ok</p>";
    else
        $result['status'] = "<p style='color: red; margin: 5px;'>fail</p>";
	if ($row['delivered'] == 1)
        $result['delivered'] = "<p style='color: green; margin: 5px;'>ok</p>";
    else
        $result['delivered'] = "<p style='color: red; margin: 5px;'>fail</p>";
    if ($row['products_count'] != null)
        $result['products_count'] = $row['products_count'];
    else
        $result['products_count'] = 0;
    if ($row['products_total'] != null)
        $result['products_total'] = $row['products_total'];
    else
        $result['products_total'] = 0;
    if ($row['variants_count'] != null)
        $result['variants_count'] = $row['variants_count'];
    else
        $result['variants_count'] = 0;
    if ($row['export_date'] != null)
        $result['export_date'] = $row['export_date'];
    else
        $result['export_date'] = 'Not exported';

    $results[] = $result;
}
write_log('Send report');
send_daily_report($results);
write_log('Finished');

?>