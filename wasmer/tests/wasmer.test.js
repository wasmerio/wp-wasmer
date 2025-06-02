#!/usr/bin/env node

import { spawn } from "child_process";
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

const LATEST_WP_VERSION = "6.8.1";
const WASMER_PLUGIN_VERSION = "0.1.8";
const WP_VERSION = process.env.WP_VERSION || "6.7.1";
const PHP_VERSION = process.env.PHP_VERSION || "8.3";

let server;
let tempBlueprintFile;
let mockGraphQLServer;

describe("WP-Now PHP/WordPress Server", async ({ signal }) => {
  before(
    async () => {
      let filename = fileURLToPath(import.meta.url);
      // 1) Start the server
      server = spawn(
        "node",
        [
          "wasmer/tests/node_modules/@wp-now/wp-now/main.js",
          "start",
          `--wp=${WP_VERSION}`,
          `--php=${PHP_VERSION}`,
          "--skip-browser",
          `--port=${PORT}`,
          `--blueprint=${resolve(dirname(filename), "wp-blueprint.json")}`,
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

      // Wait until it’s up
      await serverStarted;

      const schema = createSchema({
        typeDefs: /* GraphQL */ `
          type Query {
            viewer: User
            node(id: ID!): Node
          }
          type User {
            email: String
          }
          interface Node {
            id: ID!
          }
          type DeployApp implements Node {
            id: ID!
          }
        `,
        resolvers: {
          Query: {
            viewer: (parent, args, context) => {
              if (
                context.request.headers.get("authorization") == "Bearer 123"
              ) {
                return { email: "test@test.com" };
              }
              return null;
            },
            node: (parent, args, context) => {
              if (
                context.request.headers.get("authorization") == "Bearer 123"
              ) {
                return { id: "123", __typename: "DeployApp" };
              }
              return null;
            },
          },
        },
      });

      // Create a Yoga instance with a GraphQL schema.
      const yoga = createYoga({ schema });

      // Pass it into a server to hook into request handlers.
      mockGraphQLServer = createServer(async (req, res) => {
        console.log(`[graphql] Request (Authorization: ${req.headers["authorization"]})`);
        const result = await yoga(req, res);
        console.log("[graphql] Response");
        return result;
      });

      // Start the server and you're done!
      mockGraphQLServer.listen(4000, () => {
        console.info("Server is running on http://localhost:4000/graphql");
      });
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
    describe("Upgrade WP alert appears", async () => {
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
            body.indexOf('>WordPress 6.8.1</a> is available!' > -1, "Expected WordPress upgrade alert")
          );
        });
        it("Wasmer notice appears in update-core.php", async () => {
          const req = await fetch(`${SERVER_URL}/wp-admin/update-core.php`);
          assert.equal(req.status, 200, "Expected status 200");
          const body = await req.text();
          assert.ok(
            body.indexOf(
              `Update to version ${LATEST_WP_VERSION} from Wasmer WordPress Settings`
            ) > -1,
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
        assert.equal(req.status, 302, "Expected status 302");
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
      const req = await fetch(
        `${SERVER_URL}/?rest_route=/wasmer/v1/liveconfig`
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
          //     name: '"Wasmer-Tests" on the Dashboard',
          //     slug: "wasmer-tests",
          //     url: null,
          //     version: "",
          //   },
          //   {
          //     description:
          //       "Used by millions, Akismet is quite possibly the best way in the world to <strong>protect your blog from spam</strong>. Akismet Anti-spam keeps your site protected even while you sleep. To get started: activate the Akismet plugin and then go to your Akismet Settings page to set up your API key.",
          //     icon: "https://ps.w.org/akismet/assets/icon-128x128.png?rev=2818463",
          //     is_active: false,
          //     latest_version: "5.4",
          //     name: "Akismet Anti-spam: Spam Protection",
          //     slug: "akismet",
          //     url: "https://wordpress.org/plugins/akismet/",
          //     version: "5.3.7",
          //   },
          //   {
          //     description:
          //       "This is not just a plugin, it symbolizes the hope and enthusiasm of an entire generation summed up in two words sung most famously by Louis Armstrong: Hello, Dolly. When activated you will randomly see a lyric from <cite>Hello, Dolly</cite> in the upper right of your admin screen on every page.",
          //     icon: "https://ps.w.org/hello-dolly/assets/icon-128x128.jpg?rev=2052855",
          //     is_active: false,
          //     latest_version: "1.7.2",
          //     name: "Hello Dolly",
          //     slug: "hello-dolly",
          //     url: "https://wordpress.org/plugins/hello-dolly/",
          //     version: "1.7.2",
          //   },
          //   {
          //     description: "Wasmer Plugin for WordPress",
          //     icon: null,
          //     is_active: true,
          //     latest_version: null,
          //     name: "WP Wasmer",
          //     slug: "wp-wasmer",
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
          //     name: "Twenty Twenty-Five",
          //     slug: "twentytwentyfive",
          //     version: "1.2",
          //   },
          //   {
          //     is_active: true,
          //     latest_version: "1.3",
          //     name: "Twenty Twenty-Four",
          //     slug: "twentytwentyfour",
          //     version: "1.3",
          //   },
          //   {
          //     is_active: false,
          //     latest_version: "1.6",
          //     name: "Twenty Twenty-Three",
          //     slug: "twentytwentythree",
          //     version: "1.6",
          //   },
          //   {
          //     is_active: false,
          //     latest_version: "2.0",
          //     name: "Twenty Twenty-Two",
          //     slug: "twentytwentytwo",
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
