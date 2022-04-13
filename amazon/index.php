<?php

namespace App\Error\Renderer;

require_once __DIR__ . "/vendor/autoload.php";

use DI\Container;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

final class HtmlErrorRenderer implements ErrorRendererInterface
{
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        $title = 'Error';
        $message = 'An error has occurred.';

        if ($exception instanceof HttpNotFoundException) {
            $title = 'Page not found';
            $message = 'This page could not be found.';
        }

        return $this->renderHtmlPage($title, $message);
    }

    public function renderHtmlPage(string $title = '', string $message = ''): string
    {
        $title = htmlentities($title, ENT_COMPAT|ENT_HTML5, 'utf-8');
        $message = htmlentities($message, ENT_COMPAT|ENT_HTML5, 'utf-8');

        return <<<EOT
<!DOCTYPE html>
<html lang="en">
<head>
  <title>Error</title>
  <link rel="stylesheet"
     href="https://cdnjs.cloudflare.com/ajax/libs/mini.css/3.0.1/mini-default.css">
</head>
<body>
  <h1>$title</h1>
  <p>$message</p>
</body>
</html>
EOT;
    }
}


// Load environment variables from ../.env
$dotenv = Dotenv::createImmutable(__DIR__.'\app' );
$dotenv->load();
// Boolean values in .env are loaded as strings
$DEBUG = $_ENV["DEBUG"] === "true";

if ($_ENV["ERRORS"] ?? false) {
    error_reporting(0);
}

$container = new Container();
AppFactory::setContainer($container);

$container->set("view", function() {
    return Twig::create(__DIR__ . "/html");
});

// Create app
$app = AppFactory::create();
$app -> setBasePath('/amazon');
// Register routes
$routes = require __DIR__ . '/app/routes.php';

$routes($app);

// Add middleware
$app->add(TwigMiddleware::createFromContainer($app));
$app->addRoutingMiddleware();

$displayErrorDetails = (bool)($_ENV['ERRORS'] ?? false);
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->registerErrorRenderer('text/html', HtmlErrorRenderer::class);

// Run app
$app->run();
