# WP Wasmer Plugin

A WordPress plugin that integrates WordPress installations with the [Wasmer](https://wasmer.io/) platform, providing REST API endpoints, admin interface enhancements, and management features.

## Overview

The WP Wasmer plugin enables seamless integration between WordPress sites and the Wasmer platform. It provides:

- REST API endpoints for health checks, configuration retrieval, and magic login
- Admin interface enhancements with Wasmer Control Panel access
- WordPress core update management (blocks manual updates, redirects to Wasmer)
- WP-CLI commands for automated installations
- WP-CLI access to the liveconfig payload

## Installation

1. Copy the plugin files to your WordPress installation's `wp-content/plugins/wp-wasmer/` directory
2. Activate the plugin through the WordPress admin interface or via WP-CLI

## Configuration

The plugin requires the following environment variables to be set:

- `WASMER_WEBSITE_URL` - The base URL for the Wasmer website (e.g., `https://wasmer.io`)
- `WASMER_GRAPHQL_URL` - The GraphQL API endpoint URL (e.g., `https://registry.wasmer.io/graphql`)
- `WASMER_APP_ID` - The unique identifier for your Wasmer app instance
- `WASMER_PERISHABLE_TIMESTAMP` (optional) - Unix timestamp indicating when the app will expire

These can be set via:
- Environment variables in your hosting environment
- A must-use plugin (mu-plugin) that sets them via `putenv()`

## REST API Endpoints

All endpoints are registered under the `/wasmer/v1/` namespace and are accessible at:
```
/?rest_route=/wasmer/v1/{endpoint}
```

### Endpoints

#### 1. Health Check - `/wasmer/v1/check`

A simple health check endpoint to verify the plugin is active and responding.

**Method:** `GET`

**URL:** `/?rest_route=/wasmer/v1/check`

**Response:**
```json
{
  "status": "success"
}
```

**Headers:**
- `Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0`
- `Pragma: no-cache`
- `Expires: 0`

**Example:**
```bash
curl "http://localhost:8080/?rest_route=/wasmer/v1/check"
```

#### 2. Live Configuration - `/wasmer/v1/liveconfig`

Returns comprehensive information about the WordPress installation, including plugins, themes, PHP configuration, MySQL version, and more.

**Method:** `GET`

**URL:** `/?rest_route=/wasmer/v1/liveconfig`

**Response:**
```json
{
  "liveconfig_version": "1",
  "wasmer_plugin": {
    "version": "0.3.2",
    "dir": "/var/www/html/wp-content/plugins/wp-wasmer/",
    "url": "http://localhost:8080/wp-content/plugins/wp-wasmer/"
  },
  "wordpress": {
    "version": "6.7.1",
    "latest_version": "6.8.2",
    "url": "http://localhost:8080",
    "language": "en_US",
    "timezone": "UTC",
    "debug": false,
    "debug_log": false,
    "is_main_site": true,
    "plugins": [
      {
        "slug": "wp-wasmer",
        "icon": null,
        "url": null,
        "name": "WP Wasmer",
        "version": "0.3.2",
        "description": "Wasmer Plugin for WordPress",
        "is_active": true,
        "latest_version": null
      }
    ],
    "themes": [
      {
        "slug": "twentytwentyfour",
        "name": "Twenty Twenty-Four",
        "version": "1.3",
        "latest_version": "1.3",
        "is_active": true
      }
    ],
    "users": {
      "total": 1,
      "admins": 1
    },
    "posts": {
      "count": "1"
    },
    "pages": {
      "count": "1"
    }
  },
  "php": {
    "version": "8.3.0-dev",
    "architecture": "32",
    "memory_limit": "128M",
    "max_execution_time": "0",
    "max_input_time": "-1",
    "max_input_vars": "1000"
  },
  "mysql": {
    "version": "8.0",
    "server": "3.40.1"
  }
}
```

**Headers:**
- `Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0`
- `Pragma: no-cache`
- `Expires: 0`

**Response Fields:**

- `liveconfig_version` - Version of the liveconfig API format
- `wasmer_plugin` - Information about the Wasmer plugin itself
- `wordpress` - WordPress installation details:
  - `version` - Current WordPress version
  - `latest_version` - Latest available WordPress version
  - `plugins` - Array of installed plugins with their details
  - `themes` - Array of installed themes with their details
  - `users` - User statistics (total users, admin count)
  - `posts` / `pages` - Content counts
- `php` - PHP configuration and version information
- `mysql` - MySQL/MariaDB version information

**Example:**
```bash
curl "http://localhost:8080/?rest_route=/wasmer/v1/liveconfig"
```

## WP-CLI Commands

If the plugin is active in a WordPress installation with WP-CLI available, you can print the liveconfig payload directly from the shell:

```bash
wp wasmer liveconfig
```

This command returns the same JSON payload as `/?rest_route=/wasmer/v1/liveconfig`.

#### 3. Magic Login - `/wasmer/v1/magiclogin`

Provides token-based authentication that automatically logs in a user to the WordPress admin. The token is validated against the Wasmer GraphQL API.

**Method:** `GET`

**URL:** `/?rest_route=/wasmer/v1/magiclogin&magiclogin={token}`

**Query Parameters:**
- `magiclogin` (required) - Authentication token to validate

**How it works:**
1. The plugin validates the token by querying the Wasmer GraphQL API
2. If valid, it retrieves the user's email from the GraphQL response
3. It finds or creates an administrator user matching that email
4. The user is automatically logged in via WordPress authentication cookies
5. The user is redirected to the WordPress admin dashboard

**GraphQL Query:**
The plugin executes the following GraphQL query to validate the token:
```graphql
query ($appid: ID!) {
  viewer {
    email
  }
  node(id: $appid) {
    ... on DeployApp {
      id
    }
  }
}
```

**Success Response:**
- **Status Code:** `302` (Redirect)
- **Location:** `{admin_url}/?platform=wasmer`
- **Set-Cookie:** WordPress authentication cookies (`wordpress_logged_in_*`)
- **Headers:**
  - `Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0`
  - `Pragma: no-cache`

**Error Responses:**
- `403` - Invalid or expired token
- `400` - GraphQL query failed
- `500` - Missing token, `WASMER_GRAPHQL_URL`, or `WASMER_APP_ID`

**User Selection Logic:**
1. First, searches for an administrator user with an email matching the GraphQL response
2. If no match found, selects the first available administrator user
3. If no administrators exist, the login fails

**Example:**
```bash
curl -L "http://localhost:8080/?rest_route=/wasmer/v1/magiclogin&magiclogin=your-token-here"
```

**Note:** The `-L` flag follows redirects. Without it, you'll see the redirect headers but won't be logged in.

## Admin Interface Features

### Top Bar Menu

The plugin adds a "Wasmer" menu item to the WordPress admin top bar with:

- **Wasmer Control Panel** - Link to the Wasmer dashboard for the current app
- **Claim App** (if `WASMER_PERISHABLE_TIMESTAMP` is set) - Link to claim the app before expiration, with a countdown notification

The menu displays a notification badge if the app has an expiration timestamp set.

### WordPress Core Update Management

The plugin implements several features to manage WordPress core updates:

1. **Blocks Manual Core Updates** - Prevents users from manually updating WordPress core through the WordPress admin interface
2. **Disables Automatic Updates** - Disables WordPress automatic background updates
3. **Admin Notices** - Shows notices on the Updates page (`/wp-admin/update-core.php`) directing users to use Wasmer WordPress Settings for updates
4. **Error Messages** - Displays user-friendly error messages when users attempt manual core updates

When a user attempts to update WordPress core manually, they'll see a message directing them to:
```
{wasmer_base_url}/id/{WASMER_APP_ID}/settings/wordpress
```

### Plugin and Theme Updates

Automatic updates for plugins and themes are disabled by default. Manual updates through the WordPress admin interface remain available.

## Testing Locally

Prerequisites:
- Node.js (for running tests)
- pnpm (package manager)
- Access to `@wp-now/wp-now` for local WordPress development

The plugin includes a comprehensive test suite located in `wasmer/tests/wasmer.test.js`. The tests use Node.js's built-in test runner and require a local WordPress instance.

### Setup

1. Install test dependencies:
```bash
cd wasmer/tests
pnpm install
```

2. Set environment variables (optional):
```bash
export WP_VERSION=6.7.1  # WordPress version to test (default: 6.7.1)
export PHP_VERSION=8.3    # PHP version to use (default: 8.3)
export PORT=8080          # Port for the test server (default: 8080)
```

### Running the Test Suite

```bash
cd wasmer/tests
node wasmer.test.js
```

Or using Node.js test runner:
```bash
cd wasmer/tests
node --test wasmer.test.js
```

### What the Tests Cover

The test suite includes:

1. **Basic WordPress Functionality**
   - Verifies WordPress homepage loads correctly

2. **Admin Interface Tests**
   - Verifies Wasmer Control Panel link appears in admin
   - Tests WordPress upgrade notices
   - Verifies Wasmer-specific update notices

3. **REST API Tests**
   - **Magic Login:**
     - Tests failure with wrong token (expects 403)
     - Tests success with proper token (expects 302 redirect)
     - Verifies authentication cookies are set
     - Verifies cache-control headers
     - Verifies redirect location
   - **Health Check:**
     - Tests `/wasmer/v1/check` endpoint
     - Verifies response format and cache headers
   - **Live Config:**
     - Tests `/wasmer/v1/liveconfig` endpoint
     - Verifies comprehensive configuration data structure
     - Validates WordPress, PHP, and MySQL information

### Test Blueprint

The tests use a WordPress blueprint file (`wp-blueprint-protected.json`) that:
- Sets up environment variables for the plugin
- Installs and activates the `wp-force-login` plugin (to test password-protected scenarios)
- Configures WordPress for testing

### Mock GraphQL Server

The test suite includes a mock GraphQL server running on port 4000 that:
- Validates Bearer tokens (expects `Bearer 123` for successful authentication)
- Returns mock user and app data for the magic login flow

### Manual Testing

For manual testing of endpoints:

1. **Start a local WordPress instance** (using wp-now or similar):
```bash
npx @wp-now/wp-now start --wp=6.7.1 --php=8.3 --port=8080 --blueprint=wasmer/tests/wp-blueprint-protected.json
```

2. **Set environment variables** in a mu-plugin or via `putenv()`:
```php
putenv("WASMER_WEBSITE_URL=http://wasmer.xyz");
putenv("WASMER_GRAPHQL_URL=http://localhost:4000/graphql");
putenv("WASMER_APP_ID=abc");
```

3. **Test endpoints:**
```bash
# Health check
curl "http://localhost:8080/?rest_route=/wasmer/v1/check"

# Live config
curl "http://localhost:8080/?rest_route=/wasmer/v1/liveconfig"

# Magic login (requires valid token from GraphQL)
curl -L "http://localhost:8080/?rest_route=/wasmer/v1/magiclogin&magiclogin=123"
```

#### Testing Magic Login

To test the magic login endpoint, you'll need:

1. A GraphQL server that accepts Bearer token authentication
2. The GraphQL server should implement the query structure expected by the plugin
3. A valid token that returns user email and app ID in the GraphQL response

The test suite includes a mock GraphQL server for this purpose.

---

## License

GPL-3.0

## Support

For issues, feature requests, or contributions, please visit the [GitHub repository](https://github.com/wasmerio/wp-wasmer).
