<?php

require_once dirname(__DIR__, 1) . "/vendor/autoload.php";

use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;



function writeJSON($results, $subdir): bool {
    $results['date'] = date("Y-m-d H:i:s");
    $filename = preg_replace('/\s+/', '_', strtolower($results["name"]));
    $file = fopen(dirname(__DIR__,1) . '/data/'. $subdir . '/' . $filename .'.json', "w");
    fputs($file, json_encode($results, JSON_PRETTY_PRINT));
    return fclose($file);
};

return function (App $app) {
    $DEBUG = $_ENV["DEBUG"] === "true";


    /*
 * Display the Authorize page (GET /)
 */
    $app->get("/sp-authorization", function(Request $request, Response $response, $args): Response {
        return $this->get("view")->render($response, "authorize.html");
    });

    $app->get("/ads-authorization", function(Request $request, Response $response, $args): Response {
        return $this->get("view")->render($response, "ad_authorize.html");
    });

    /*
 * Redirect to the Amazon OAuth application authorization page when users submit
 * the authorization form (POST /)
 */
    $app->post("/sp-authorization", function(Request $request, Response $response, $args) use ($DEBUG): Response {
        session_start();
        $state = bin2hex(random_bytes(256));
        $_SESSION["spapi_auth_state"] = $state;
        $_SESSION["spapi_auth_time"] = time();

        $oauthUrl = $_ENV["SP_AUTH_GRANT_URL"];
        $oauthPath = $_ENV["SP_AUTH_GRANT_ENDPOINT"];
        $oauthQueryParams = [
            "application_id" => $_ENV["SPAPI_APP_ID"],
            "state" => $state,
        ];

        if ($DEBUG) {
            $oauthQueryParams["version"] = "beta";
        }

        $uri = new Uri($oauthUrl);
        $uri = $uri->withScheme("https")
            ->withPath($oauthPath);
        $uri = $uri->withQueryValues($uri, $oauthQueryParams);

        $response = $response->withHeader("Referrer-Policy", "no-referrer");
        $response = $response->withHeader("Location", strval($uri));
        return $response;
    });

    /*
     * When the user approves the application on Amazon's authorization page, they are redirected
     * to the URL specified in the application config on Seller Central. A number of query parameters
     * are passed, including an LWA (Login with Amazon) token which we can use to fetch the  user's
     * SP API refresh token. With that refresh token, we can generate access tokens that enable us to
     * make SP API requests on the user's behalf.
     */
    $app->get("/redirect", function (Request $request, Response $response, $args): Response {
        $queryString = $request->getUri()->getQuery();
        parse_str($queryString, $queryParams);

        $outerThis = $this;
        $render = function($params = []) use ($outerThis, $response) {
            return $outerThis->get("view")->render($response, "redirect.html", $params);
        };

        $missing = [];
        foreach (["state", "spapi_oauth_code", "selling_partner_id"] as $requiredParam) {
            if (!isset($queryParams[$requiredParam])) {
                $missing[] = $requiredParam;
            }
        }
        if (count($missing) > 0) {
            return $render(["err" => true, "missing" => $missing]);
        }

        session_start();
        if (!isset($_SESSION)) {
            return $render(["err" => true, "no_session" => true]);
        }
        if ($queryParams["state"] !== $_SESSION["spapi_auth_state"]) {
            return $render(["err" => true, "invalid_state"]);
        }
        if (time() - $_SESSION["spapi_auth_time"] > 1800) {
            return $render(["err" => true, "expired" => true]);
        }

        [
            "spapi_oauth_code" => $oauthCode,
            "selling_partner_id" => $sellingPartnerId,
        ] = $queryParams;

        $client = new GuzzleHttp\Client();
        $res = null;
        try {
            $res = $client->post($_ENV["OAUTH_URL"], [
                GuzzleHttp\RequestOptions::JSON => [
                    "grant_type" => "authorization_code",
                    "code" => $oauthCode,
                    "client_id" => $_ENV["LWA_CLIENT_ID"],
                    "client_secret" => $_ENV["LWA_CLIENT_SECRET"],
                ]
            ]);
        } catch (GuzzleHttp\Exception\ClientException $e) {
            $info = json_decode($e->getResponse()->getBody()->getContents(), true);
            if ($info["error"] === "invalid_grant") {
                return $render(["err" => "bad_oauth_token"]);
            } else {
                throw $e;
            }
        }

        $body = json_decode($res->getBody(), true);

        [
            "refresh_token" => $refreshToken,
            "access_token" => $accessToken,
            "expires_in" => $secsTillExpiration,
        ] = $body;

        $config_arr = [
            "lwaClientId" => $_ENV["LWA_CLIENT_ID"],
            "lwaClientSecret" => $_ENV["LWA_CLIENT_SECRET"],
            "lwaRefreshToken" => $refreshToken,
            // If you don't pass the lwaAccessToken key/value, the library will automatically generate an access
            // token based on the refresh token above
            "lwaAccessToken" => $accessToken,
            "awsAccessKeyId" => $_ENV["AWS_ACCESS_KEY_ID"],
            "awsSecretAccessKey" => $_ENV["AWS_SECRET_ACCESS_KEY"],
            "endpoint" => SellingPartnerApi\Endpoint::NA,
        ];

        if (isset($_ENV["ROLE_ARN"])) {
            $config_arr += ["roleArn" => $_ENV["ROLE_ARN"]];
        }

        $config = new SellingPartnerApi\Configuration($config_arr);
        $api = new SellingPartnerApi\Api\SellersApi($config);

        $params = $body;
        try {
            $result = $api->getMarketplaceParticipations();
            $params["success"] = true;
        } catch (Exception $e) {
            print_r($e);
        }
        $params["selling_partner_id"] = $sellingPartnerId;
        $params["name"] = getSellerName($sellingPartnerId);
        $writeSuccess = writeJSON($params, 'sp');
        if (! $writeSuccess) {
            print_r("Error writing JSON");
        }
        return $render($params);
    });

    $app->post("/ads-authorization", function(Request $request, Response $response, $args) use ($DEBUG): Response {
        session_start();
        $_SESSION["adsapi_auth_time"] = time();

        $oauthUrl = $_ENV["ADS_OAUTH_URL"];
        $oauthPath = $_ENV["ADS_OAUTH_ENDPOINT"];
        $oauthQueryParams = [
            "client_id" => $_ENV["ADS_CLIENT_ID"],
            "scope" => "advertising::campaign_management",
            "response_type" => "code",
            "redirect_uri" => $_ENV["ADS_REDIRECT"]
        ];

        $uri = new Uri($oauthUrl);
        $uri = $uri->withScheme("https")
            ->withPath($oauthPath);
        $uri = $uri->withQueryValues($uri, $oauthQueryParams);

        $response = $response->withHeader("Referrer-Policy", "no-referrer");
        $response = $response->withHeader("Location", strval($uri));
        return $response;
    });

    $app->get("/ads-redirect", function (Request $request, Response $response, $args): Response {
        $queryString = $request->getUri()->getQuery();
        parse_str($queryString, $queryParams);

        $outerThis = $this;
        $render = function($params = []) use ($outerThis, $response) {
            return $outerThis->get("view")->render($response, "ads-redirect.html", $params);
        };

        $missing = [];
        foreach (["scope", "code"] as $requiredParam) {
            if (!isset($queryParams[$requiredParam])) {
                $missing[] = $requiredParam;
            }
        }
        if (count($missing) > 0) {
            return $render(["err" => true, "missing" => $missing]);
        }

        session_start();
        if (!isset($_SESSION)) {
            return $render(["err" => true, "no_session" => true]);
        }

        [
            "code" => $oauthCode,
            "scope" => $scope
        ] = $queryParams;

        $client = new GuzzleHttp\Client();
        $res = null;
        try {
            $res = $client->post($_ENV["OAUTH_URL"], [
                GuzzleHttp\RequestOptions::JSON => [
                    "grant_type" => "authorization_code",
                    "code" => $oauthCode,
                    "client_id" => $_ENV["ADS_CLIENT_ID"],
                    "client_secret" => $_ENV["ADS_CLIENT_SECRET"],
                    "redirect_uri" => $_ENV["ADS_REDIRECT"]
                ]
            ]);
        } catch (GuzzleHttp\Exception\ClientException $e) {
            $info = json_decode($e->getResponse()->getBody()->getContents(), true);
            if ($info["error"] === "invalid_grant") {
                return $render(["err" => "bad_oauth_token"]);
            } else {
                throw $e;
            }
        }

        $body = json_decode($res->getBody(), true);

        [
            "refresh_token" => $refreshToken,
            "access_token" => $accessToken,
            "expires_in" => $secsTillExpiration,
        ] = $body;

        if (!isset($_SESSION)) {
            return $render(["err" => true, "no_session" => true]);
        }

        try {
            $res1 = $client->get("https://advertising-api.amazon.com/v2/profiles", [
                GuzzleHttp\RequestOptions::HEADERS => [
                    "Content-Type" => "application/json",
                    "Authorization" => 'Bearer '. $accessToken,
                    "Amazon-Advertising-API-ClientId" => $_ENV["ADS_CLIENT_ID"]
                ]
            ]);
        } catch (GuzzleHttp\Exception\ClientException $e) {
            $info1 = json_decode($e->getResponse()->getBody()->getContents(), true);
            if ($info1["error"] === "invalid_grant") {
                return $render(["err" => "bad_oauth_token"]);
            } else {
                throw $e;
            }
        }

        $body1 = json_decode($res1->getBody(), true);
        if (is_array($body1) == true) {
            foreach ($body1 as $prof) {
                if ($prof['countryCode'] == "US") {
                    $body["ad_profile_id"] = $prof["profileId"] ?? '';
                    $actInfo = $prof["accountInfo"] ?? [];
                    $body["ad_account_id"] = $actInfo["id"] ?? '';
                    $body["marketplace_string_id"] = $actInfo["marketplaceStringId"] ?? '';
                    $body["name"] = $actInfo["name"] ?? '';
                }
            }
        }
        $params = $body;
        $writeSuccess = writeJSON($params, 'ads');
        return $render($params);
    });
};

function getSellerName($sellerID) {
    $c_url = curl_init();
    $defaults = array(
        CURLOPT_URL => "https://www.amazon.com/sp?seller=".$sellerID,
        CURLOPT_HEADER => 0,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => 'gzip,deflate',
        CURLOPT_AUTOREFERER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4896.75 Safari/537.36'
    );
    curl_setopt_array($c_url, $defaults);

    $c_html = curl_exec($c_url);
    $htmlDOM = new DOMDocument();
    $htmlDOM->validateOnParse = true;
    libxml_use_internal_errors(true);
    $htmlDOM->loadHTML($c_html);
    $htmlDOM->preserveWhiteSpace = false;
    return $htmlDOM->getElementById("sellerName")->nodeValue;
}