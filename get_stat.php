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

    $s_q_mac_r = $db->prepare("SELECT hex(uuid) AS uuid, major, minor FROM traces WHERE datetime BETWEEN :dateStart AND :dateEnd GROUP BY uuid, major, minor");
    $s_q_mac_r->bindValue(':dateStart', $from->format('Y-m-d H:i:s'), SQLITE3_TEXT);
    $s_q_mac_r->bindValue(':dateEnd', $to->format('Y-m-d H:i:s'), SQLITE3_TEXT);
    $q_mac_r = $s_q_mac_r->execute();

    $arr_stat = array();

    while ($row_mac_r = $q_mac_r->fetchArray(SQLITE3_ASSOC)) {
        $uuid = $row_mac_r['uuid'];
        $major = $row_mac_r['major'];
        $minor = $row_mac_r['minor'];

//        $s_q_ib = $db->prepare('SELECT txpower, rssi, datetime
//                                FROM traces
//                                WHERE uuid = :uuid
//                                AND major = :major
//                                AND minor = :minor
//                                AND datetime BETWEEN :dateStart AND :dateEnds
//                                ORDER BY datetime ASC');
//        $s_q_ib->bindValue(':uuid', pack('H*', $uuid), SQLITE3_TEXT);
//        $s_q_ib->bindValue(':major', $major, SQLITE3_INTEGER);
//        $s_q_ib->bindValue(':minor', $minor, SQLITE3_INTEGER);
//        $s_q_ib->bindValue(':dateStart', $from->format('Y-m-d H:i:s'), SQLITE3_TEXT);
//        $s_q_ib->bindValue(':dateEnd', $to->format('Y-m-d H:i:s'), SQLITE3_TEXT);
//
//        $q_ib = $s_q_ib->execute();

        $sql = 'SELECT txpower, rssi, datetime
                                FROM traces
                                WHERE uuid = x\'' . $uuid . '\'
                                AND major =  ' . $major . '
                                AND minor = ' . $minor . '
                                AND datetime BETWEEN \'' . $from->format('Y-m-d H:i:s') . '\' AND \'' . $to->format('Y-m-d H:i:s') . '\'
                                ORDER BY datetime ASC';
        $q_ib = $db->query($sql);
        unset($sql);

        if (!$q_ib->numColumns() /*|| $q_ib->columnType(0) == SQLITE3_NULL*/) {
            throw new Exception('empty result when getting avg txpower and rssi for uuid: ' . bin2hex($row_mac_r['uuid']) . ', major: ' . $row_mac_r['major'] . ', minor: ' . $row_mac_r['minor']);
        }

        $firstSeen = null;
        $lastSeen = null;
        $arr_bcn_data = array();
        while ($row_ib = $q_ib->fetchArray(SQLITE3_ASSOC)) {

            if ($firstSeen == null) $firstSeen = $row_ib['datetime'];
            $lastSeen = $row_ib['datetime'];
            $arr_bcn_data[] = $row_ib;
        }

        $sumTxPower = 0.0;
        $sumRssi = 0.0;
        $sampleCount = 0;
        $first10 = (int) ( count($arr_bcn_data) * 0.1 );
        $last10 = (int) ( count($arr_bcn_data) * 0.9 );
        for ($sampleCount = $first10; $sampleCount < $last10; $sampleCount++) {
            $e = $arr_bcn_data[$sampleCount];

            $sumTxPower += $e['txpower'];
            $sumRssi += $e['rssi'];
        }
        $sampleCount -= $first10;

        // actually an overhead-excluded version of arr_push
        $arr_stat[] = [
            'uuid' => strtolower($uuid),
            'major' => $major,
            'minor' => $minor,
            'rssi' => ($sumRssi / $sampleCount),
            'txpower' => ($sumTxPower / $sampleCount),
            'firstSeen' => $firstSeen,
            'lastSeen' => $lastSeen
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($arr_stat, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo $e->getMessage();
    die(1);
}