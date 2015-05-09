<?php

try {

    // $selfMac = isset($_POST["selfMac"]) ? $_POST["selfMac"] : null;
    $strFrom = isset($_POST["from"]) ? $_POST["from"] : null;
    $strTo = isset($_POST["to"]) ? $_POST["to"] : null;

    // if (is_null($selfMac)) {
    //     http_response_code(500);
    //     header('Content-Type: text/plain');
    //     echo 'selfMac attr not provided';
    //     die(1);
    // }

    date_default_timezone_set('UTC');

    $to = !is_null($strTo) && strlen($strTo) > 0 ? DateTime::createFromFormat('Y-m-d H:i:s', $strTo) : new DateTime();
    $from = !is_null($strFrom) && strlen($strTo) > 0 ? DateTime::createFromFormat('Y-m-d H:i:s', $strFrom) : (new DateTime())->sub(new DateInterval('PT10S'));

    $dir = dirname($_SERVER['DOCUMENT_ROOT']) . '/sqlite_db';
    if (!file_exists($dir)) {
        mkdir($dir, 0644, true);
    }
    $db = new SQLite3($dir . '/ibeacons.sqlite3');
    unset($dir);

    $s_q_mac_r = $db->prepare("SELECT uuid, major, minor FROM traces WHERE datetime BETWEEN :dateStart AND :dateEnd GROUP BY uuid, major, minor");
    $s_q_mac_r->bindValue(':dateStart', $from->format('Y-m-d H:i:s'), SQLITE3_TEXT);
    $s_q_mac_r->bindValue(':dateEnd', $to->format('Y-m-d H:i:s'), SQLITE3_TEXT);
    $q_mac_r = $s_q_mac_r->execute();
//     $q_mac_r = $db->query('SELECT uuid, major, minor
//                      FROM traces
//                      WHERE '.'
//                    datetime BETWEEN \'' . $from->format('Y-m-d H:i:s') . '\' AND \'' . $to->format('Y-m-d H:i:s') . '\'
//                      GROUP BY uuid, major, minor');
    // $q_mac_r->execute(array(':selfMac' => hexdec($selfMac))); # performace: slow

    $arr_stat = array();

    $s_q_ib = $db->prepare('SELECT AVG(txpower) AS txpower, AVG(rssi) AS rssi, MIN(datetime) AS firstSeen, MAX(datetime) AS lastSeen FROM (
                                SELECT _idx, txpower, rssi, datetime
                                FROM (
                                    SELECT @rownum := @rownum + 1 AS _idx, txpower, rssi, datetime
                                    FROM traces, (SELECT @rownum := 0) r
                                    WHERE uuid = :uuid && major = :major && minor = :minor AND datetime BETWEEN :dateStart AND :dateEnd
                                ) limited_traces
                                WHERE _idx >= ROUND(FOUND_ROWS() * 0.1)
                                && _idx < ROUND(FOUND_ROWS() * 0.9)
                            ) AS stat_traces');
    while ($row_mac_r = $q_mac_r->fetchArray(SQLITE3_ASSOC)) {
        $s_q_ib->bindValue(':uuid', $row_mac_r['uuid'], SQLITE3_BLOB);
        $s_q_ib->bindValue(':major', $row_mac_r['major'], SQLITE3_INTEGER);
        $s_q_ib->bindValue(':minor', $row_mac_r['minor'], SQLITE3_INTEGER);
        $s_q_mac_r->bindValue(':dateStart', $from->format('Y-m-d H:i:s'), SQLITE3_TEXT);
        $s_q_mac_r->bindValue(':dateEnd', $to->format('Y-m-d H:i:s'), SQLITE3_TEXT);

        $q_ib = $s_q_ib->execute();

        if (!$q_ib->numColumns() || $q_ib->columnType(0) == SQLITE3_NULL) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'empty result when getting avg txpower and rssi for uuid: ' . bin2hex($row_mac_r['uuid']) . ', major: ' . $row_mac_r['major'] . ', minor: ' . $row_mac_r['minor'];
            die(1);
        }

        $row_ib = $q_ib->fetchArray(SQLITE3_ASSOC);

        // actually a overhead-excluded version of arr_push
        $arr_stat[] = [
            'uuid' => bin2hex($row_mac_r['uuid']),
            'major' => $row_mac_r['major'],
            'minor' => $row_mac_r['minor'],
            'rssi' => floatval($row_ib['rssi']),
            'txpower' => floatval($row_ib['txpower']),
            'firstSeen' => $row_ib['firstSeen'],
            'lastSeen' => $row_ib['lastSeen']
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($arr_stat, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo $e;
    die(1);
}