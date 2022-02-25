<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

$localhost = in_array($_SERVER['REMOTE_ADDR'], array( 'localhost', '127.0.0.1', '::1' ));

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// HELPERS

function toJSON($response, $result, $code = 200) {
    $response->getBody()->write(json_encode($result));
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($code);
}

function getRow($app, $id) {
    $sth = $app->dbh->prepare("SELECT * FROM `seiko_szerviz` WHERE service_number = :id");
    $sth->bindParam(':id', $id, PDO::PARAM_STR);
    $sth->execute();
    $result = $sth->fetchAll(PDO::FETCH_ASSOC);
    return $result;
}

// SLIM APP

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

if (!$localhost) {
    $app->setBasePath('/seiko');
}

// DATABASE

$dbInit = function ($request, $handler) use($app, $localhost) {
    $app->dbh = $localhost
        ? new PDO("mysql:dbname=b22534", "root", "root18")
        : new PDO("mysql:host=a046um.forpsi.com;port=3306;dbname=b22534", "b22534", "utWT9RsB");
    $app->dbh->exec("set names utf8");

    return $handler->handle($request);
};
$app->add($dbInit);

// ERROR

$customErrorHandler = function (
    $request,
    Throwable $exception
) use ($app) {
    $code = $exception->getCode();
    return toJSON(
        $app->getResponseFactory()->createResponse(),
        [ 'error' => $exception->getMessage() ],
        $code >= 400 && $code < 404 ? $code : 500
    );
};

$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

// TEST ROUTES

$app->get('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Hello Seiko api!");
    return $response;
});

$app->get('/test', function (Request $request, Response $response, $args) {
    $result = ['message' => 'Test'];
    return toJSON($response, $result);
});

$app->post('/test', function (Request $request, Response $response, $args) {
    $result = ['body' => $request->getParsedBody()];
    return toJSON($response, $result);
});

$app->get('/test/error', function (Request $request, Response $response, $args) {
    throw new Exception('Test error', 400);
});

// SEIKO

$app->get('/status/{id}', function (Request $request, Response $response, $args) use ($app) {
    $result = getRow($app, $args['id']);
    if (count ($result) == 1) {
        return toJSON($response, $result);
    } else {
        throw new Exception('Status not found', 400);
    }

    return $response;
});

$app->post('/status/{id}', function (Request $request, Response $response, $args) use ($app) {
    $body = $request->getParsedBody();
    if (!isset( $body['status']) ){
        throw new Exception('Status is missing body', 400);
    }

    $sth = $app->dbh->prepare("SELECT * FROM `seiko_szerviz` WHERE service_number = :id");
    $sth->bindParam(':id', $args['id'], PDO::PARAM_STR);
    $sth->execute();
    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    if (count ($result) == 1) {
        $sth = $app->dbh->prepare("UPDATE `seiko_szerviz` SET service_status = :st WHERE service_number = :id");
        $action = 'update';
    } else {
        $sth = $app->dbh->prepare("INSERT INTO `seiko_szerviz` (service_number, service_status) VALUES (:id, :st) ");
        $action = 'insert';
    }

    $sth->bindParam(':id', $args['id'], PDO::PARAM_STR);
    $sth->bindParam(':st',  $body['status'], PDO::PARAM_STR);
    $sth->execute();
    $result = [
        'data' => getRow($app, $args['id']),
        'action' => $action
    ];
    return toJSON($response, $result);

    return $response;
});

$app->run();