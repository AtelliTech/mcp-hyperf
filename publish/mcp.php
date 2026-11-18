<?php

declare(strict_types=1);

return [
    // server info returned by `initialize` (2025-06-18) and on every 2026-07-28 result
    'name' => 'MCP Server',
    'version' => '1.0.0',

    // optional instructions for the model, returned by `initialize` and `server/discover`
    'instructions' => '',

    // classes implementing AtelliTech\Hyperf\Mcp\McpTool, resolved from the container
    'tools' => [],

    // classes implementing AtelliTech\Hyperf\Mcp\McpPrompt, resolved from the container
    'prompts' => [],

    // classes implementing AtelliTech\Hyperf\Mcp\McpResource or McpResourceTemplate, resolved from the container
    'resources' => [],

    'max_body_size' => 20971520,

    // 2026-07-28 caching hints on server/discover, */list and resources/read results.
    // Keep 'private' if results depend on the authenticated user.
    'cache_ttl_ms' => 0,
    'cache_scope' => 'private',
];
