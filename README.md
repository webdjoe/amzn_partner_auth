# Website Authentication flow for Amazon Selling Partner API and Advertising API

This is a PHP based website authentication flow for Amazon's Selling Partner API and Advertising API. Amazon's new SP-API allows stores to grant access to Amazon Partners through a web-based Login-with-Amazon (LWA) authorization. The Amazon Advertising API uses a similiar LwA authorization flow for Amazon Partners.

Based on jlevers and his [SP API OAuth Template](https://jesseevers.com/spapi-oauth). Check out his [Selling Partner API for PHP](https://github.com/jlevers/selling-partner-api)

Packages are managed by Composer. Install dependencies with `composer update`

Uses the Slim PHP library to route calls and handle redirects. Twig templates are used to dynamically render pages.

NOTE: `.htaccess` files are used for directory and file security. This does not have hardened security and is not meant to be production ready. PHP errors may reveal Amazon credentials.

## Endpoints

Defaults subdirectory to `/amazon/` so that it can run parallel to the rest of the site. 

Advertising API Authorization Start Page Endpoint:
`https://SITE.COM/amazon/ads-authorization`

Page template: [amazon/html/ads_authorize.html](amazon/html/ad_authorize.html)

Advertising Redirect URL:
`https://SITE.COM/amazon/ads-redirect`

Page template: [amazon/html/ads-redirect.html](amazon/html/ads-redirect.html)

Selling Partner API Authorization Start Page Endpoint:
`https://SITE.COM/amazon/sp-authorization`

Page Template: [amazon/html/authorize.html](amazon/html/authorize.html)

Selling Partner API Redirect URL:
`https://SITE.COM/amazon/redirect`

Page Template: [amazon/html/redirect.html](amazon/html/redirect.html)

## Output Refresh Token & Seller Info to JSON Files

The [data](amazon/data) folder contains two directories: `sp` and `ads` where the client's seller id, refresh token & name are written in a JSON file. The JSON filename is the name of the Seller.

The advertising API only outputs the US based advertising profile and profile ID, along with the rest of the Seller information.

```json
{
    "access_token": "Atza|ACCESS TOKEN",
    "refresh_token": "Atzr|REFRESH TOKEN",
    "token_type": "bearer",
    "expires_in": 3600,
    "ad_profile_id": "00000000000000",
    "ad_account_id": "A00AA00AA00AA",
    "marketplace_string_id": "AAAAAAAAAAAA",
    "name": "SELLER NAME",
    "date": "2020-01-01 01:01:01"
}
```

The Selling Partner API outputs the seller's ID, refresh token and name of Seller. The name of the seller is parsed from their Amazon Storefront.

```json
{
  "access_token": "Atza|ACCESS TOKEN",
  "refresh_token": "Atzr|REFRESH TOKEN",
  "token_type": "bearer",
  "expires_in": 3600,
  "success": true,
  "selling_partner_id": "AE0000000",
  "name": "SELLER NAME",
  "date": "2020-01-01 01:01:01"
}
```

## Configuration

Use the [env.sample](amazon/app/env.example) in the app folder and rename to `.env` file to configure credentials and application ID.



## Selling Partner Authentication Flow

This is the new Selling Partner API authentication flow through a website. A button or link can initiate the workflow where the client logs into their Amazon account and grants access.

To develop a Seller Central app:
 1. Sign up for a Seller Central Account & verify 
 2. register as a developer & submit application
 3. create AWS credentials that give permissions to access the execute-api. Can be an STS based role or user credentials
 4. go to Partner Network -> Develop Apps in seller central and create a new application
 5. Create a new app with AWS credential, URL redirect & obtain LWA id and secret

Detailed instructions can be found [here](https://developer-docs.amazon.com/sp-api/docs/creating-and-configuring-iam-policies-and-entities)

 1. Authorize Button to start authorization flow
```
GET https://sellercentral.amazon.com/apps/authorize/consent?state=RANDOM&application_id=SPAPI_APP_ID
```
  - state (random number to prevent cross site forgery)
  - Selling partner app ID - found in Partner Network->Develop Apps

Redirect URL Returns:
```
GET https://REDIRECT_URL/
       ?spapi_oauth_code=CODE
       &state=STATE
       &selling_partner_id=CLIENT_SP_ID
```
   - spapi_oauth_code - code used to obtain refresh token
   - state (should match state from call)
   - selling_partner_id - ID of client

2. Obtain refresh and authorization token

```
POST https://api.amazon.com/auth/o2/token
Content-Type: application/x-www-form-urlencoded;charset=UTF-8

{ 
    grant_type: authorization_code,
    code: spapi_oauth_code,
    client_id: LWA_CLIENT_ID,
    client_secret: LWA_CLIENT_SECRET
}
```
JSON Payload contains:

- grant_type - code when using authorization code to obtain refresh token
- code - obtained from query in redirect url
- client_id - Given when app created on Seller Central "Develop App"
- client_secret - Given when app created on Seller Central "Develop App"

Returns in JSON payload:

      1. refresh_token - used to obtain access token after expiration
      2. access_token - used to make calls - expires in 6 minutes
      3. expires_in - expiration time of access_token

3. After access_token expires, use refresh token to make get new access token

```
POST https://api.amazon.com/auth/o2/token
Content-Type: application/x-www-form-urlencoded;charset=UTF-8

{ 
    grant_type: refresh_token,
    code: spapi_oauth_code,
    client_id: LWA_CLIENT_ID,
    client_secret: LWA_CLIENT_SECRET
}
```
   
## Ads API Authorization Flow

The Amazon Advertising API authorization flow differs slightly from the Selling Partner API authorization flow. The general process to get started is:
1. Create Login with Amazon application on the developer console
2. Apply for permission from Amazon to access advertising API
3. Assign API access to the Login with Amazon Application

A detailed walkthrough can be found here: [Amazon Ads API onboarding overview](https://advertising.amazon.com/API/docs/en-us/setting-up/overview)

Once approved and the advertising API is linked with the LwA application, this is the web based authorization flow:

1. Click Authorize button

Calls Ads API authorization grant endpoint with: 
- LwA client id linked to the Ads API
- scope - advertising::campaign_management unless you have an older account
- response_type - use 'code' to obtain an authorization code
- redirect_uri - the uri defined when creating the LwA app

```
GET https://www.amazon.com/ap/oa
    ?client_id=ADS_LWA_CLIENT_ID
    &scope=advertising::campaign_management
    &response_type=code
    &redirect_uri=REDIRECT_URI
```

Returns Redirect URI with query parameters:
````
GET https://REDIRECT_URI/
    ?scope=advertising::campaign_management
    ?code=OAUTH_CODE
````

2. Obtain Refresh Token with OAuth Code

Call Amazon OAuth url with authorization code

```
POST https://api.amazon.com/auth/o2/token
Content-Type: application/x-www-form-urlencoded;charset=UTF-8

{ 
    grant_type: authorization_code,
    code: OAUTH_CODE,
    client_id: ADS_LWA_CLIENT_ID,
    client_secret: ADS_LWA_CLIENT_SECRET,
    redirect_uri: REDIRECT_URI
}
```
Returns:

```
{
  "refresh_token": refresh_token,
  "access_token": access_token,
  "expires_in": 3600
}
```

3. Use refresh token to obtain access token after expiration

Call OAuth endpoint with Refresh Token

```
POST https://api.amazon.com/auth/o2/token
Content-Type: application/x-www-form-urlencoded;charset=UTF-8

{ 
    grant_type: refresh_token,
    refresh_token: REFRESH_TOKEN,
    client_id: ADS_LWA_CLIENT_ID,
    client_secret: ADS_LWA_CLIENT_SECRET,
}
```
