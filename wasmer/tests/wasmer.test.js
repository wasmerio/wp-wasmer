#!/usr/bin/env node

import { spawn, spawnSync } from "child_process";
import { describe, it, before, after } from "node:test";
import { createSchema } from "graphql-yoga";
import { createServer } from "node:http";
import { resolve, dirname } from "node:path";
import { createYoga } from "graphql-yoga";
import fs from "node:fs";
import { fileURLToPath } from "node:url";
import fetchCookie from "fetch-cookie";

import assert from "node:assert";

const PORT = process.env.PORT || 8080;
const HOST = "127.0.0.1";
const SERVER_URL = `http://${HOST}:${PORT}`;

const LATEST_WP_VERSION = "7.0";
const WASMER_PLUGIN_VERSION = "0.5.0";
const WP_VERSION = process.env.WP_VERSION || "6.8.2";
const PHP_VERSION = process.env.PHP_VERSION || "8.3";

let server;
let tempBlueprintFile;
let mockGraphQLServer;

// Records every purgeAppCdnCache mutation received by the mock GraphQL API.
const purgeCalls = [];

async function createPHPServer(signal, blueprintFilename = "wp-blueprint-protected.json") {
  let filename = fileURLToPath(import.meta.url);
      // Start the mock GraphQL API first: blueprint steps (e.g. plugin
      // activation) already trigger CDN cache purge requests against it.
      createMockGraphQLServer();

      // Start the server
      server = spawn(
        "node",
        [
          "wasmer/tests/node_modules/@wp-now/wp-now/main.js",
          "start",
          `--wp=${WP_VERSION}`,
          `--php=${PHP_VERSION}`,
          `--port=${PORT}`,
          "--skip-browser",
          `--blueprint=${resolve(dirname(filename), blueprintFilename)}`,
          "--reset",
        ],
        {
          stdio: ["ignore", "pipe", "pipe"],
          signal,
          killSignal: "SIGKILL",
          cwd: resolve(dirname(filename), "../.."),
        }
      );

      // (optional) forward logs for debugging
      let serverStarted = new Promise((resolve, reject) => {
        server.stdout.on("data", (d) => {
          if (d.includes("Server running at")) {
            resolve();
          }
          process.stdout.write(`[srv] ${d}`);
        });
        server.stderr.on("data", (d) => process.stderr.write(`[srv] ${d}`));
      });

      // Wait until it's up
      await serverStarted;
}

function createMockGraphQLServer() {
      const schema = createSchema({
        typeDefs: /* GraphQL */ `
          type Query {
            viewer: User
            node(id: ID!): Node
          }
          type Mutation {
            purgeAppCdnCache(app: ID!): PurgeAppCdnCachePayload
          }
          type PurgeAppCdnCachePayload {
            success: Boolean
          }
          type User {
            email: String
          }
          interface Node {
            id: ID!
          }
          enum OwnerAction {
            DEPLOY_APP
          }
          type DeployApp implements Node {
            id: ID!
            viewerCan(action: OwnerAction!): Boolean!
          }
        `,
        resolvers: {
          Query: {
            viewer: (parent, args, context) => {
              if (
                context.request.headers.get("authorization") == "Bearer 123"
              ) {
                return { email: "admin@localhost.com" };
              }
              return null;
            },
            node: (parent, args, context) => {
              if (
                context.request.headers.get("authorization") == "Bearer 123"
              ) {
                return { id: "123", viewerCan: true, __typename: "DeployApp" };
              }
              return null;
            },
          },
          Mutation: {
            purgeAppCdnCache: (parent, args, context) => {
              const auth = context.request.headers.get("authorization");
              purgeCalls.push({ app: args.app, auth });
              return { success: auth === "Bearer api-token-123" };
            },
          },
        },
      });

      // Create a Yoga instance with a GraphQL schema.
      const yoga = createYoga({ schema });

      // Pass it into a server to hook into request handlers.
      mockGraphQLServer = createServer(async (req, res) => {
        console.log(
          `[graphql] Request (Authorization: ${req.headers["authorization"]})`
        );
        const result = await yoga(req, res);
        console.log("[graphql] Response");
        return result;
      });

      // Start the server and you're done!
      mockGraphQLServer.listen(4000, () => {
        console.info("Server is running on http://localhost:4000/graphql");
      });
}

describe("WP-Now PHP/WordPress Server", async ({ signal }) => {
  before(
    async () => {
      await createPHPServer(signal);
    },
    { signal }
  );

  it('responds to GET / with content containing "WordPress"', async () => {
    const body = await (await fetch(`${SERVER_URL}/`)).text();
    assert.ok(
      body.includes("WordPress"),
      'Expected homepage to include "WordPress"'
    );
  });

  describe("Wordpress ADMIN", () => {
    describe("Upgrade WP alert appears", () => {
      it("Has the right link to Wasmer", async () => {
        const fetchWithCookie = fetchCookie(fetch);
        const reqMagicLogin = await fetchWithCookie(
          `${SERVER_URL}/?rest_route=/wasmer/v1/magiclogin&magiclogin=123`,
          { redirect: "manual" }
        );
        const req = await fetchWithCookie(`${SERVER_URL}/wp-admin/`);
        assert.equal(req.status, 200, "Expected status 200");
        const body = await req.text();
        // Matches this url: <a class='ab-item' role="menuitem" href='http://wasmer.xyz/id/abc' title='Go to Wasmer Control Panel' rel='noopener noreferrer'>Wasmer Control Panel</a>
        const regex =
          /<a[^>]*href=('|")http:\/\/wasmer\.xyz\/id\/abc('|")[^>]*>Wasmer Control Panel<\/a>/;
        assert.match(
          body,
          regex,
          "Expected to find at least one Wasmer Control Panel link"
        );
        assert.match(
          body,
          /<script[^>]+src=('|")[^'"]*\/wasmer\/admin-menu\.js[^'"]*('|")/,
          "Expected the Wasmer admin menu script to be enqueued"
        );
        assert.match(
          body,
          /window\.wasmerAdminMenu\s*=/,
          "Expected the Wasmer admin menu configuration to use an inline script registration"
        );
      });
      if (WP_VERSION !== LATEST_WP_VERSION) {
        // If an upgrade is available, the upgrade alert should appear
        it("normal notice appears in homepage", async () => {
          const fetchWithCookie = fetchCookie(fetch);
          const reqMagicLogin = await fetchWithCookie(
            `${SERVER_URL}/?rest_route=/wasmer/v1/magiclogin&magiclogin=123`,
            { redirect: "manual" }
          );
          const req = await fetchWithCookie(`${SERVER_URL}/wp-admin/`);
          assert.equal(req.status, 200, "Expected status 200");
          const body = await req.text();
          assert.ok(
            body.indexOf(
              ">WordPress 6.8.1</a> is available!" > -1,
              "Expected WordPress upgrade alert"
            )
          );
        });
        it("Wasmer notice appears in update-core.php", async () => {
          const fetchWithCookie = fetchCookie(fetch);
          await fetchWithCookie(
            `${SERVER_URL}/?rest_route=/wasmer/v1/magiclogin&magiclogin=123`,
            { redirect: "manual" }
          );
          const req = await fetchWithCookie(
            `${SERVER_URL}/wp-admin/update-core.php`
          );
          assert.equal(req.status, 200, "Expected status 200");
          const body = await req.text();
          assert.match(
            body,
            /Update to version [^<]+ from Wasmer WordPress Settings/,
            "Expected Wasmer WordPress Settings notice"
          );
        });
      }
    });
  });

  describe("REST API", () => {
    describe("magic login", async () => {
      it("fails with wrong token", async () => {
        const req = await fetch(
          `${SERVER_URL}/?rest_route=/wasmer/v1/magiclogin&magiclogin=wrong`,
          { redirect: "manual" }
        );
        assert.equal(req.status, 403, "Expected status 403");
      });

      it("succeeds with proper token", async () => {
        const fetchWithCookie = fetchCookie(fetch);
        const req = await fetchWithCookie(
          `${SERVER_URL}/?rest_route=/wasmer/v1/magiclogin&magiclogin=123`,
          { redirect: "manual" }
        );
        assert.equal(req.status, 302, `Expected status 302: ${await req.clone().text()}`);
        assert.match(
          req.headers.get("cache-control"),
          /no-cache/i,
          "Expected cache-control to be no-cache"
        );
        assert.equal(
          req.headers.get("Location"),
          "http://localhost:8080/wp-admin/?platform=wasmer",
          "Expected to redirect to wp-admin"
        );
        assert.match(
          req.headers.get("set-cookie"),
          /wordpress_logged_in_/i,
          "Expected set-cookie to be wordpress_logged_in_"
        );
        assert.ok(
          !req.headers.has("Expires"),
          "Expected not any expires header"
        );

        // We are now logged in in the admin page, so we can check the dashboard
        const dashboardReq = await fetchWithCookie(`${SERVER_URL}/wp-admin/`);
        assert.equal(dashboardReq.status, 200, "Expected status 200");
        const dashboardBody = await dashboardReq.text();
        // console.log("MagicLogin DASHBOARD", dashboardBody);
        assert.ok(
          dashboardBody.includes("Dashboard"),
          "Expected dashboard to include 'Dashboard'"
        );
        assert.ok(
          dashboardBody.includes("Manage on Wasmer"),
          "Expected dashboard to include 'Manage on Wasmer' widget"
        );
        // assert.ok(
        //   dashboardBody.includes("64-bit PHP required"),
        //   "Expected dashboard widget to include 64-bit PHP warning"
        // );
        // assert.ok(
        //   dashboardBody.includes("Google for WooCommerce"),
        //   "Expected dashboard widget to list Google for WooCommerce"
        // );
      });
    });

    it("Check works", async () => {
      const req = await fetch(`${SERVER_URL}/?rest_route=/wasmer/v1/check`);
      assert.equal(req.status, 200, "Expected status 200");
      assert.match(
        req.headers.get("cache-control"),
        /no-cache/i,
        "Expected cache-control to be no-cache"
      );
      const content = await req.json();
      assert.deepStrictEqual(content, {
        status: "success",
      });
    });

    it("Liveconfig works", async () => {
      const unauthorized = await fetch(
        `${SERVER_URL}/?rest_route=/wasmer/v1/liveconfig`
      );
      assert.equal(unauthorized.status, 401, "Expected authentication to be required");

      const req = await fetch(
        `${SERVER_URL}/?rest_route=/wasmer/v1/liveconfig`,
        { headers: { Authorization: "Bearer api-token-123" } }
      );
      assert.equal(req.status, 200, "Expected status 200");
      assert.match(
        req.headers.get("cache-control"),
        /no-cache/i,
        "Expected cache-control to be no-cache"
      );
      const content = await req.json();
      delete content.wordpress.themes;
      delete content.wordpress.plugins;
      content.wordpress.latest_version = LATEST_WP_VERSION;
      assert.deepStrictEqual(content, {
        liveconfig_version: "1",
        mysql: {
          server: "3.40.1",
          version: "8.0",
        },
        php: {
          architecture: "32",
          max_execution_time: "0",
          max_input_time: "-1",
          max_input_vars: "1000",
          memory_limit: "128M",
          version: "8.3.0-dev",
        },
        wasmer_plugin: {
          dir: "/var/www/html/wp-content/plugins/wp-wasmer/",
          url: "http://localhost:8080/wp-content/plugins/wp-wasmer/",
          version: WASMER_PLUGIN_VERSION,
        },
        wordpress: {
          debug: false,
          debug_log: false,
          is_main_site: true,
          language: "en_US",
          latest_version: LATEST_WP_VERSION,
          pages: {
            count: "1",
          },
          // plugins: [
          //   {
          //     description: "",
          //     icon: null,
          //     is_active: true,
          //     latest_version: null,
          //     name: "wasmer-tests",
          //     slug: "wasmer-tests",
          //     title: '"Wasmer-Tests" on the Dashboard',
          //     url: null,
          //     version: "",
          //   },
          //   {
          //     description:
          //       "Used by millions, Akismet is quite possibly the best way in the world to <strong>protect your blog from spam</strong>. Akismet Anti-spam keeps your site protected even while you sleep. To get started: activate the Akismet plugin and then go to your Akismet Settings page to set up your API key.",
          //     icon: "https://ps.w.org/akismet/assets/icon-128x128.png?rev=2818463",
          //     is_active: false,
          //     latest_version: "5.4",
          //     name: "akismet",
          //     slug: "akismet",
          //     title: "Akismet Anti-spam: Spam Protection",
          //     url: "https://wordpress.org/plugins/akismet/",
          //     version: "5.3.7",
          //   },
          //   {
          //     description:
          //       "This is not just a plugin, it symbolizes the hope and enthusiasm of an entire generation summed up in two words sung most famously by Louis Armstrong: Hello, Dolly. When activated you will randomly see a lyric from <cite>Hello, Dolly</cite> in the upper right of your admin screen on every page.",
          //     icon: "https://ps.w.org/hello-dolly/assets/icon-128x128.jpg?rev=2052855",
          //     is_active: false,
          //     latest_version: "1.7.2",
          //     name: "hello",
          //     slug: "hello",
          //     title: "Hello Dolly",
          //     url: "https://wordpress.org/plugins/hello-dolly/",
          //     version: "1.7.2",
          //   },
          //   {
          //     description: "Wasmer Plugin for WordPress",
          //     icon: null,
          //     is_active: true,
          //     latest_version: null,
          //     name: "wp-wasmer",
          //     slug: "wp-wasmer",
          //     title: "WP Wasmer",
          //     url: null,
          //     version: WASMER_PLUGIN_VERSION,
          //   },
          // ],
          posts: {
            count: "1",
          },
          // themes: [
          //   {
          //     is_active: false,
          //     latest_version: "1.2",
          //     name: "twentytwentyfive",
          //     slug: "twentytwentyfive",
          //     title: "Twenty Twenty-Five",
          //     version: "1.2",
          //   },
          //   {
          //     is_active: true,
          //     latest_version: "1.3",
          //     name: "twentytwentyfour",
          //     slug: "twentytwentyfour",
          //     title: "Twenty Twenty-Four",
          //     version: "1.3",
          //   },
          //   {
          //     is_active: false,
          //     latest_version: "1.6",
          //     name: "twentytwentythree",
          //     slug: "twentytwentythree",
          //     title: "Twenty Twenty-Three",
          //     version: "1.6",
          //   },
          //   {
          //     is_active: false,
          //     latest_version: "2.0",
          //     name: "twentytwentytwo",
          //     slug: "twentytwentytwo",
          //     title: "Twenty Twenty-Two",
          //     version: "1.6",
          //   },
          // ],
          timezone: "UTC",
          url: "http://localhost:8080",
          users: {
            admins: 1,
            total: 1,
          },
          version: WP_VERSION,
        },
      });
    });
  });

  // Note: this suite runs before the spawnSync-based suites below; their
  // long synchronous blocking lets pooled keep-alive sockets go stale, which
  // makes the first fetch afterwards fail.
  describe("CDN cache purge", () => {
    async function loggedInFetch() {
      const fetchWithCookie = fetchCookie(fetch);
      await fetchWithCookie(
        `${SERVER_URL}/?rest_route=/wasmer/v1/magiclogin&magiclogin=123`,
        { redirect: "manual" }
      );
      return fetchWithCookie;
    }

    async function restNonce(fetchWithCookie) {
      const req = await fetchWithCookie(
        `${SERVER_URL}/wp-admin/admin-ajax.php?action=rest-nonce`
      );
      assert.equal(req.status, 200, "Expected rest-nonce to return 200");
      return (await req.text()).trim();
    }

    async function createPost(fetchWithCookie, nonce, status) {
      return fetchWithCookie(`${SERVER_URL}/?rest_route=/wp/v2/posts`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": nonce,
        },
        body: JSON.stringify({
          title: `CDN purge test (${status})`,
          content: "CDN purge test content",
          status,
        }),
      });
    }

    it("does not purge when saving a draft", async () => {
      const fetchWithCookie = await loggedInFetch();
      const nonce = await restNonce(fetchWithCookie);

      purgeCalls.length = 0;
      const req = await createPost(fetchWithCookie, nonce, "draft");
      assert.equal(req.status, 201, "Expected draft post to be created");
      assert.equal(
        purgeCalls.length,
        0,
        "Expected no CDN purge for a draft save"
      );
    });

    it("purges the CDN cache exactly once when a post is published", async () => {
      const fetchWithCookie = await loggedInFetch();
      const nonce = await restNonce(fetchWithCookie);

      purgeCalls.length = 0;
      const req = await createPost(fetchWithCookie, nonce, "publish");
      assert.equal(req.status, 201, "Expected post to be published");

      assert.equal(
        purgeCalls.length,
        1,
        "Expected exactly one CDN purge call (coalesced)"
      );
      assert.equal(purgeCalls[0].app, "abc", "Expected purge for app 'abc'");
      assert.equal(
        purgeCalls[0].auth,
        "Bearer api-token-123",
        "Expected purge to use the Wasmer API token"
      );
    });

    it("purges via the admin bar button", async () => {
      const fetchWithCookie = await loggedInFetch();

      const adminReq = await fetchWithCookie(`${SERVER_URL}/wp-admin/`);
      assert.equal(adminReq.status, 200, "Expected status 200");
      const body = await adminReq.text();

      const match = body.match(
        /href=('|")([^'"]*admin-post\.php\?action=wasmer_purge_cdn_cache[^'"]*)('|")/
      );
      assert.ok(match, "Expected admin bar to contain the purge link");
      const purgeUrl = match[2].replace(/&amp;|&#0?38;/g, "&");

      purgeCalls.length = 0;
      const purgeReq = await fetchWithCookie(purgeUrl, {
        redirect: "manual",
      });
      assert.equal(purgeReq.status, 302, "Expected purge to redirect");
      assert.match(
        purgeReq.headers.get("Location"),
        /wasmer-cdn-purged=1/,
        "Expected redirect to signal a successful purge"
      );
      assert.equal(
        purgeCalls.length,
        1,
        "Expected exactly one CDN purge call"
      );
    });
  });

  describe("Admin bar", () => {
    it("only registers the Wasmer admin bar menu for logged-in admin pages", () => {
      const req = spawnSync(
        "node",
        [
          "wasmer/tests/node_modules/@wp-now/wp-now/main.js",
          "php",
          "wasmer/tests/admin-bar-menu.php",
        ],
        {
          cwd: resolve(dirname(fileURLToPath(import.meta.url)), "../.."),
          encoding: "utf8",
        }
      );

      assert.equal(req.status, 0, req.stderr || req.stdout);
      assert.match(req.stdout, /ok/);
    });
  });

  describe("WP-CLI", () => {
    it("resolves migration destinations through WordPress paths", () => {
      const req = spawnSync(
        "node",
        [
          "wasmer/tests/node_modules/@wp-now/wp-now/main.js",
          "php",
          "wasmer/tests/import-paths.php",
        ],
        {
          cwd: resolve(dirname(fileURLToPath(import.meta.url)), "../.."),
          encoding: "utf8",
        }
      );

      assert.equal(req.status, 0, req.stderr || req.stdout);
      assert.match(req.stdout, /ok/);
    });

    it("uses WP-CLI extension names for liveconfig slugs", () => {
      const req = spawnSync(
        "node",
        [
          "wasmer/tests/node_modules/@wp-now/wp-now/main.js",
          "php",
          "wasmer/tests/liveconfig-slugs.php",
        ],
        {
          cwd: resolve(dirname(fileURLToPath(import.meta.url)), "../.."),
          encoding: "utf8",
        }
      );

      assert.equal(req.status, 0, req.stderr || req.stdout);
      assert.match(req.stdout, /ok/);
    });

    it("registers wasmer liveconfig", () => {
      const req = spawnSync(
        "node",
        [
          "wasmer/tests/node_modules/@wp-now/wp-now/main.js",
          "php",
          "wasmer/tests/wp-cli-liveconfig.php",
        ],
        {
          cwd: resolve(dirname(fileURLToPath(import.meta.url)), "../.."),
          encoding: "utf8",
        }
      );

      assert.equal(req.status, 0, req.stderr || req.stdout);
      assert.match(req.stdout, /ok/);
    });

    it("prints automatic migration app data as standalone JSON", () => {
      const req = spawnSync(
        "node",
        [
          "wasmer/tests/node_modules/@wp-now/wp-now/main.js",
          "php",
          "wasmer-migrate/tests/wp-cli-auto.php",
        ],
        {
          cwd: resolve(dirname(fileURLToPath(import.meta.url)), "../.."),
          encoding: "utf8",
        }
      );

      assert.equal(req.status, 0, req.stderr || req.stdout);
      assert.match(req.stdout, /ok/);
    });

    it("generates a randomized default migration app name", () => {
      const req = spawnSync(
        "node",
        [
          "wasmer/tests/node_modules/@wp-now/wp-now/main.js",
          "php",
          "wasmer-migrate/tests/auto-app-name.php",
        ],
        {
          cwd: resolve(dirname(fileURLToPath(import.meta.url)), "../.."),
          encoding: "utf8",
        }
      );

      assert.equal(req.status, 0, req.stderr || req.stdout);
      assert.match(req.stdout, /ok/);
    });
  });

  after(
    () => {
      console.log("teardown");
      if (server) server.kill("SIGKILL");
      if (mockGraphQLServer) mockGraphQLServer.close();
      if (tempBlueprintFile) fs.unlinkSync(tempBlueprintFile.path);
    },
    { signal }
  );
});
