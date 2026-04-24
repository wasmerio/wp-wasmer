# WP Wasmer

WP Wasmer integrates a WordPress installation with the Wasmer platform. The plugin exposes Wasmer-specific REST endpoints, WP-CLI commands, and admin integrations used by Wasmer-managed WordPress deployments.

## Local Testing

Install the test dependencies:

```bash
cd wasmer/tests
pnpm install
```

Run the test suite:

```bash
cd wasmer/tests
pnpm test
```

Start a local WordPress instance for manual testing:

```bash
cd wasmer/tests
pnpm run server:wp-68-protected
```

Example manual checks:

```bash
curl "http://localhost:8080/?rest_route=/wasmer/v1/check"
curl "http://localhost:8080/?rest_route=/wasmer/v1/liveconfig"
curl -L "http://localhost:8080/?rest_route=/wasmer/v1/magiclogin&magiclogin=123"
```

## Documentation

- [Documentation hub](docs/README.md)
- [REST API reference](docs/rest-api.md)
- [WP-CLI commands](docs/wp-cli.md)
- [Wasmer integration](docs/wasmer-integration.md)
- [Admin and update behavior](docs/admin-update-behavior.md)

## Support

For issues, feature requests, or contributions, visit the [GitHub repository](https://github.com/wasmerio/wp-wasmer).

## License

GPL-3.0
