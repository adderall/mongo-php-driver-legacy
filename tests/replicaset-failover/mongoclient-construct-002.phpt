--TEST--
MongoClient::__construct() during failover (2)
--SKIPIF--
<?php require_once "tests/utils/replicaset-failover.inc" ?>
--FILE--
<?php
require_once "tests/utils/server.inc";

$server = new MongoShellServer;
$rs = $server->getReplicasetConfig();

function log_server_type($server) {
    printf("Server type: %s (%d)\n", $server["type"] == 2 ? "PRIMARY" : ($server["type"] == 4 ? "SECONDARY" : "UNKNOWN"), $server["type"]);
}

$ctx = stream_context_create(array("mongodb" => array(
    "log_query" => "log_server_type",
    "log_cmd_insert" => "log_server_type",
)));

$mc = new MongoClient($rs["dsn"], array("replicaSet" => $rs["rsname"]), array("context" => $ctx));

$coll = $mc->selectCollection("ctorfailover", "test2");
$coll->drop();
$data = array("x" => "The world is not enough");
$coll->insert($data, array('w' => 'majority'));
$id = $data["_id"];

echo "About to kill master\n";
$server->killMaster();
echo "Master killed\n";


$t = time();
try {
    echo "Attempting insert\n";
    $coll->insert($data);
    echo "failed, somehow managed to insert when no primary was found\n";
} catch(Exception $e) {
    var_dump(get_class($e), $e->getMessage(), $e->getCode());
}

echo "Doing secondary read\n";
$data = $coll->setReadPreference(MongoClient::RP_SECONDARY); 
$coll->findOne(array("_id" => $id));

echo "Doing primary read, should fail since we don't have primary\n";
try {
    $coll->setReadPreference(MongoClient::RP_PRIMARY); 
    $coll->findOne(array("_id" => $id));
    echo "Did a primary read without a primary?!\n";
} catch(Exception $e) {
    var_dump(get_class($e), $e->getMessage(), $e->getCode());
}
// Since the cleanup is part of this test it can take a while.. this section 
// should definitily not take more then 3secs
// The only reason we have this here though is to verify we aren't wasting 
// time in attempting to reconnect to master and blocking
var_dump(time()-$t > 3);
?>
--CLEAN--
<?php require_once "tests/utils/fix-master.inc"; ?>
--EXPECTF--
Server type: PRIMARY (2)
Server type: PRIMARY (2)
About to kill master
Master killed
Attempting insert
Server type: PRIMARY (2)
string(20) "MongoCursorException"
string(%d) "%s:%d: Remote server has closed the connection"
int(32)
Doing secondary read
Server type: SECONDARY (4)
Doing primary read, should fail since we don't have primary
string(24) "MongoConnectionException"
string(26) "No candidate servers found"
int(71)
bool(false)
