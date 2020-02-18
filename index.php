<?php
require 'vendor/autoload.php';

use Inhere\Route\Router;
use ShortUUID\ShortUUID;
use Medoo\Medoo;
use Apple\ApnPush\Certificate\Certificate;
use Apple\ApnPush\Protocol\Http\Authenticator\CertificateAuthenticator;
use Apple\ApnPush\Sender\Builder\Http20Builder;
use Apple\ApnPush\Exception\SendNotification\SendNotificationException;
use Apple\ApnPush\Model\{Receiver, DeviceToken, Alert, Aps, Payload, Notification, Expiration, Priority, ApnId, CollapseId};

define('DB_PATH', __DIR__ . '/bark.db');
define('CERT_PATH', __DIR__ . '/cert-20200229.pem');

function responseString(int $code, string $message): string
{
    return json_encode(
        array(
            'code' => $code,
            'message' => $message
        ),
        JSON_UNESCAPED_UNICODE
    );
}
function responseData(int $code, array $data, string $message): string
{
    return json_encode(
        array(
            'code' => $code,
            'data' => $data,
            'message' => $message
        ),
        JSON_UNESCAPED_UNICODE
    );
}
function push(string $category, string $title, string $body, string $deviceToken, array $params): string
{
    $certificate = new Certificate(CERT_PATH, '');
    $authenticator = new CertificateAuthenticator($certificate);
    $builder = new Http20Builder($authenticator);
    $sender = $builder->build();
    $receiver = new Receiver(new DeviceToken($deviceToken), 'me.fin.bark');
    $alert = (new Alert())->withBody($body);
    if (!empty($title)) {
        $alert = $alert->withTitle($title);
    }
    $aps = (new Aps($alert))
        ->withBadge($params['badge'] ?? 0)
        ->withSound('1107')
        ->withCategory('myNotificationCategory')
        ->withMutableContent(true);
    $payload = (new Payload($aps));
    foreach ($params as $k => $v) {
        $payload = $payload->withCustomData($k, $v);
    }
    $notification = (new Notification($payload));
    try {
        $message = $sender->send($receiver, $notification);
        if (empty($message)) {
            return '';
        } else {
            return '与苹果推送服务器传输数据失败';
        }
    } catch (SendNotificationException $e) {
        return '推送发送失败';
    }
}
function ping()
{
    echo responseData(200, array('version' => '1.0.0'), 'pong');
}
function register()
{
    $su = new ShortUUID();
    $deviceToken = $_REQUEST['devicetoken'];
    $key = $su->uuid();
    if (empty($deviceToken)) {
        header('HTTP/1.1 400 Bad Request');
        echo responseString(400, 'deviceToken 不能为空');
        exit();
    }
    $old_key = $_REQUEST['key'];
    $db = $GLOBALS['db'];
    $rows = $db->has('device', array('key' => $old_key));
    if ($rows) {
        $db->update('device', array('deviceToken' => $deviceToken), array('key' => $key));
        $key = $old_key;
    } else {
        $data = $db->insert('device', array('deviceToken' => $deviceToken, 'key' => $key));
    }
    $error = $db->error();
    if ($error[0] === "00000") {
        echo responseData(200, array('key' => $key), '注册成功');
    } else {
        echo responseString(400,'注册设备失败');
    }
}
function index(array $params)
{
    $params = array_merge($_POST, $params);
    $key = $params['key'];
    $title = $params['title'] ?? '';
    $body = $params['body'] ?? '';
    $category = $params['category'] ?? '';
    $db = $GLOBALS['db'];
    $deviceToken = $db->get('device', 'deviceToken', array('key' => $key));
    if (empty($deviceToken)) {
        header('HTTP/1.1 400 Bad Request');
        echo responseString(400, '找不到 Key 对应的 DeviceToken, 请确保 Key 正确! Key 可在 App 端注册获得。');
        exit();
    }
    if (empty($body)) {
        $body = '无推送文字内容';
    }
    $pushParams = array();
    foreach ($_REQUEST as $k => $v) {
        if (strlen($v) > 0) {
            $pushParams[strtolower($k)] = $v;
        }
    }
    $error = push($category, $title, $body, $deviceToken, $pushParams);
    if ($error) {
        header('HTTP/1.1 400 Bad Request');
        echo responseString(400, $error);
    } else {
        echo responseString(200, '');
    }
}
$firstTime = file_exists(DB_PATH) ? true : false;
$db = new medoo(
    array(
        'database_type' => 'sqlite',
        'database_file' => DB_PATH
    )
);
if ($firstTime === false) {
    $db->query('CREATE TABLE device(id INTEGER PRIMARY KEY AUTOINCREMENT, deviceToken CHAR(64) NOT NULL , key CHAR(22) NOT NULL)');
}

$router = new Router();
$router->map(['get', 'post'], '/ping', 'ping');
$router->map(['get', 'post'], '/register', 'register');
$router->map(['get', 'post'], '/{key}', 'index');
$router->map(['get', 'post'], '/{key}/{body}', 'index');
$router->map(['get', 'post'], '/{key}/{title}/{body}', 'index');
// $router->map(['get', 'post'], '/{key}/{category}/{title}/{body}', 'index');
$router->any(
    '*',
    function () {
        header('HTTP/1.1 404 Not Found');
        echo 'page 404 not found';
    }
);
$router->dispatch();
