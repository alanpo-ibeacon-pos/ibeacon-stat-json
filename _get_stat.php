<?php

/*

$selfMac = $_POST["selfMac"];
$strFrom = $_POST["from"];
$strTo = $_POST["to"];

if (is_null($selfMac)) {
    http_response_code(500);
    echo 'selfMac attr not provided';
    die(1);
}

$to = $strTo ?: new DateTime();
$from = $strFrom ?: $to->sub(new DateInterval('T10S'));

//$db = new PDO('mysql:host=moodle-db.cndunymmm6cz.ap-southeast-1.rds.amazonaws.com;port=3306;dbname=2014fyp_ips', '2014fyp_ips', 'alanpo2593');
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=ibeacon_traces', 'ibeacon', '1Beac0n');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$q_mac_r = $db->query('SELECT uuid, major, minor FROM traces WHERE selfMac = ' . hexdec($selfMac));
// $q_mac_r->execute(array(':selfMac' => hexdec($selfMac))); # performace: slow
if($q_mac_r->rowCount() == 0) die(0);

$arr_stat = array();

while ($row_mac_r = $q_mac_r->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_NEXT)) {
    $uuid = $row_mac_r['uuid'];
    $major = $row_mac_r['major'];
    $minor = $row_mac_r['minor'];

    $q_ib = $db->prepare('SELECT AVG(txpower) AS txpower, AVG(rssi) AS rssi FROM (
                            SELECT _idx, txpower, rssi
                            FROM (
                                SELECT @rownum := @rownum + 1 AS _idx, txpower, rssi
                                FROM traces, (SELECT @rownum := 0) r
                                WHERE uuid = :uuid && major = :major && minor = :minor
                            ) limited_traces
                            WHERE _idx >= ROUND(FOUND_ROWS() * 0.1)
                            && _idx < ROUND(FOUND_ROWS() * 0.8)
                        ) AS stat_traces');
    $q_ib->execute(array(
        ':uuid' => $uuid,
        ':major' => $major,
        ':minor' => $minor
    ));

    if ($q_ib->rowCount() != 1) {
        http_response_code(500);
        echo 'empty result when getting avg txpower and rssi for uuid: ' . bin2hex($uuid) . ', major: ' . $major . ', minor: ' . $minor;
        die(1);
    }

    $row_ib = $q_ib->fetch(PDO::FETCH_ASSOC);

    // actually a overhead-excluded version of arr_push
    $arr_stat[bin2hex($uuid)] = [
        'rssi' => $row_ib['rssi'],
        'txpower' => $row_ib['txpower']
    ];
}

echo json_encode($arr_stat, JSON_PRETTY_PRINT);