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
    $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
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

function checkClient($app, $client) {
    if ($app->client !== $client) {
        throw new Exception('Access denied', 403);
    }
}

// SLIM APP INIT

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

if (!$localhost) {
    // must be set if the app runs in a subdirectory on the web server
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

// API_KEY

$checkApiKey = function ($request, $handler) use($app) {
    $api_keys = [
        '15427a4577a60e811db3a362eefd2c0b' => 'exchange',
        'ab2469cd5cb8ad4591cae4b9e1a72d8f' => 'win-client'
    ];

    $queryParams = $request->getQueryParams();
    if (isset($queryParams['api_key']) && isset($api_keys[$queryParams['api_key']])) {
        $app->client = $api_keys[$queryParams['api_key']];
        return $handler->handle($request);
    }
    else {
        throw new Exception('Missing or wrong api key', 401);
    }
};
$app->add($checkApiKey);

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

$app->get('/test', function (Request $request, Response $response, $args) {
    $result = ['message' => 'Hello Seiko api!'];
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

$app->get('/status', function (Request $request, Response $response) use ($app) {
    checkClient($app, 'win-client');

    $sth = $app->dbh->prepare("SELECT * FROM `seiko_szerviz`");
    $sth->execute();
    $result = $sth->fetchAll(PDO::FETCH_ASSOC);
    return toJSON($response, $result);
});

$app->get('/status/exchange', function (Request $request, Response $response, $args) use ($app) {
    checkClient($app, 'exchange');

    $customFieldName = 'service_number';
    $responseFieldName = 'service_status';

    $queryParamKey = "custom_field_$customFieldName";

    $queryParams = $request->getQueryParams();
    if (isset($queryParams[$queryParamKey])) {
        $result = getRow($app, $queryParams[$queryParamKey]);
        if (count ($result) == 1) {
            return toJSON($response, [ $responseFieldName => $result[0]['service_status'] ]);
        } else {
            throw new Exception('Status not found', 400);
        }
    }
    else {
        throw new Exception("Missing query parameter: $queryParamKey", 400);
    }
});

$app->get('/status/{id}', function (Request $request, Response $response, $args) use ($app) {
    checkClient($app, 'win-client');

    $result = getRow($app, $args['id']);
    if (count ($result) == 1) {
        return toJSON($response, $result[0]);
    } else {
        throw new Exception('Record not found', 404);
    }
});

$app->post('/status/{id}', function (Request $request, Response $response, $args) use ($app) {
    checkClient($app, 'win-client');

    $body = $request->getParsedBody();
    if (!isset( $body['status']) ){
        throw new Exception('Status is missing in body', 400);
    }

    // check for an existing record
    $result = getRow($app, $args['id']);

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
        'data' => getRow($app, $args['id']), // returning the updated record
        'action' => $action
    ];
    return toJSON($response, $result);
});

$app->run();