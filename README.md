# mcp-hyperf

A stateless MCP server that serves tools, prompts and resources and uses the Streamable HTTP transport. Mount it on a route in your existing Hyperf HTTP server: it doesn't start its own server, open SSE streams or keep sessions.

- `POST` handles JSON-RPC: `tools/list`, `tools/call`, `prompts/list`, `prompts/get`, `resources/list`, `resources/templates/list` and `resources/read`, plus the version-specific methods below. Notifications get `202`.
- The `tools` capability is always advertised. `prompts` and `resources` are advertised only when you register at least one. List changes and resource subscriptions aren't supported.
- `GET` and `DELETE` return `405`.
- If the request has an `Origin` header, its host must match the `Host` header. Otherwise the server returns `403`.
- Supported protocol versions: `2026-07-28` and `2025-06-18`, on the same endpoint.

**Contents**

- [Protocol versions](#protocol-versions)
- [Requirements](#requirements)
- [Install](#install)
- [Write a tool](#write-a-tool)
- [Write a prompt](#write-a-prompt)
- [Write a resource](#write-a-resource)
- [Register them](#register-them)
- [Mount the route](#mount-the-route)

## Protocol versions

The server picks the version for each request on its own. A request is treated as `2026-07-28` when its `params._meta` contains `io.modelcontextprotocol/protocolVersion`, its method is `server/discover`, or its `MCP-Protocol-Version` header is `2026-07-28`. Every other request uses `2025-06-18`.

| | `2025-06-18` | `2026-07-28` |
|---|---|---|
| Handshake | `initialize` | none; `server/discover` is available |
| `ping` | yes | no (`-32601`) |
| Required per request | — | `_meta` with `io.modelcontextprotocol/protocolVersion` and `io.modelcontextprotocol/clientCapabilities` (else `400`, `-32602`) |
| Header checks | `MCP-Protocol-Version`, if sent, must be supported | `MCP-Protocol-Version`, `Mcp-Method` and `Mcp-Name` must match the body (else `400`, `-32020`) |
| Unsupported version | `400` | `400`, `-32022` with `data.supported` / `data.requested` |
| Results | as before | also `resultType: "complete"` and `_meta["io.modelcontextprotocol/serverInfo"]` |
| Resource not found | `-32002` | `-32602` with `data.uri`. A `McpException::resourceNotFound()` you throw is converted. |
| Unknown method | HTTP `200`, `-32601` | HTTP `404`, `-32601` |

On `2026-07-28`, results from `server/discover`, the `*/list` methods and `resources/read` carry the caching hints `ttlMs` and `cacheScope`. Set them with `cache_ttl_ms` (default `0`) and `cache_scope` (`private` by default, or `public`) in the config. Keep `private` if what a user sees depends on `$context`.

Authentication is up to your app. Run your normal auth middleware on the route, then pass the authenticated user to `handle()` as `$context`. Every tool, prompt and resource receives that value.

## Requirements

- PHP >= 8.3
- Hyperf >= 3.1
- An MCP client that speaks protocol version `2025-06-18` or `2026-07-28` over Streamable HTTP

## Install

Install the package:

```bash
composer require atellitech/mcp-hyperf
```

Publish the config file to `config/autoload/mcp.php`:

```bash
php bin/hyperf.php vendor:publish atellitech/mcp-hyperf
```

## Write a tool

```php
use AtelliTech\Hyperf\Mcp\McpTool;
use AtelliTech\Hyperf\Mcp\Schema;
use AtelliTech\Hyperf\Mcp\ToolException;

final class ListThingsTool implements McpTool
{
    public function name(): string { return 'list_things'; }

    public function description(): string { return 'List things of the current user.'; }

    public function inputSchema(): array
    {
        return Schema::object([
            'page' => ['type' => 'integer', 'minimum' => 1],
        ]);
    }

    public function call(array $arguments, mixed $context): mixed
    {
        // $context is whatever you passed to McpServer::handle(), e.g. the user
        if (($arguments['page'] ?? 1) > 100) {
            throw new ToolException('page too large'); // becomes an isError result
        }

        return ['items' => []]; // non-string results are JSON encoded
    }
}
```

## Write a prompt

A prompt is a message template the user picks in the client. Argument values are always strings. If a required argument is missing, the server returns `Invalid params` and doesn't call `get()`.

```php
use AtelliTech\Hyperf\Mcp\McpPrompt;
use AtelliTech\Hyperf\Mcp\PromptArgument;
use AtelliTech\Hyperf\Mcp\PromptMessage;

final class ReviewThingPrompt implements McpPrompt
{
    public function name(): string { return 'review_thing'; }

    public function description(): string { return 'Review one thing and suggest fixes.'; }

    public function arguments(): array
    {
        return [
            PromptArgument::make('id', 'ID of the thing', required: true),
            PromptArgument::make('tone', 'friendly or strict'),
        ];
    }

    public function get(array $arguments, mixed $context): array|string
    {
        // a string becomes a single user text message
        return [
            PromptMessage::user("Review thing {$arguments['id']}."),
            PromptMessage::resource('user', "app://things/{$arguments['id']}", '{"name":"…"}', 'application/json'),
        ];
    }
}
```

## Write a resource

Use `McpResource` for a resource with a fixed URI:

```php
use AtelliTech\Hyperf\Mcp\McpResource;
use AtelliTech\Hyperf\Mcp\ResourceContents;

final class LimitsResource implements McpResource
{
    public function uri(): string { return 'app://limits'; }

    public function name(): string { return 'Limits'; }

    public function description(): string { return 'Quota limits of the current user.'; }

    public function mimeType(): string { return 'application/json'; }

    public function read(mixed $context): array|ResourceContents|string
    {
        return json_encode(['max_things' => 100]); // or ResourceContents::blob($bytes, 'image/png')
    }
}
```

Use `McpResourceTemplate` for a family of resources. `{var}` matches one path segment and is URL-decoded. `{+var}` matches anything, including `/`. Return `null` from `read()` when the resource doesn't exist, and the client gets a `Resource not found` error: `-32002` on `2025-06-18`, `-32602` on `2026-07-28`.

```php
use AtelliTech\Hyperf\Mcp\McpException;
use AtelliTech\Hyperf\Mcp\McpResourceTemplate;
use AtelliTech\Hyperf\Mcp\ResourceContents;

final class ThingResource implements McpResourceTemplate
{
    public function uriTemplate(): string { return 'app://things/{id}'; }

    public function name(): string { return 'Thing'; }

    public function description(): string { return 'One thing of the current user.'; }

    public function mimeType(): string { return 'application/json'; }

    public function read(string $uri, array $variables, mixed $context): array|ResourceContents|string|null
    {
        if (! ctype_digit($variables['id'])) {
            throw McpException::invalidParams('id must be numeric'); // becomes a JSON-RPC error
        }

        return null; // not found
    }
}
```

Fixed URIs are matched first. Templates are tried in the order you register them.

Prompts and resources report errors as JSON-RPC errors, so throw `McpException` from them. Tools throw `ToolException`, which the model reads as an `isError` result.

## Register them

Register everything in `config/autoload/mcp.php`. The classes are resolved from the container.

```php
// config/autoload/mcp.php
return [
    'name' => 'My API',
    'version' => '1.0.0',
    'instructions' => 'What the tools are for…',
    'tools' => [ListThingsTool::class],
    'prompts' => [ReviewThingPrompt::class],
    'resources' => [LimitsResource::class, ThingResource::class], // McpResource or McpResourceTemplate
    'cache_ttl_ms' => 0,        // 2026-07-28 caching hint
    'cache_scope' => 'private', // or 'public' if results don't depend on the user
];
```

## Mount the route

```php
// MCP Controller
use AtelliTech\Hyperf\Mcp\Hyperf\HttpResponder;
use AtelliTech\Hyperf\Mcp\McpRequest;
use AtelliTech\Hyperf\Mcp\McpServer;

public function handle(McpServer $server, HttpResponder $responder): ResponseInterface
{
    return $responder->respond(
        $server->handle(McpRequest::fromPsr($this->request), $this->currentUser())
    );
}
```

```php
// Routes
Router::addRoute(['GET', 'POST', 'DELETE'], '/mcp', 'App\Controller\McpController@handle', [
    'middleware' => [YourAuthMiddleware::class],
]);
```

You can use the core (`McpServer`, `McpRequest`, `McpResponse` and the `McpTool`, `McpPrompt`, `McpResource` and `McpResourceTemplate` interfaces) without Hyperf. Construct `McpServer` yourself and turn the `McpResponse` into whatever response type your framework uses.
