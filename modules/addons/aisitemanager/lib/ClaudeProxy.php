<?php
/**
 * AI Site Manager — Claude API Proxy
 * WebJIVE · https://web-jive.com
 *
 * Orchestrates all communication with the Anthropic API.
 * Responsibilities:
 *   - Build the system prompt with customer context.
 *   - Define the five file-management tools Claude can use.
 *   - Run the tool-use loop: call Claude → execute tool calls → repeat until
 *     Claude produces a final text response.
 *   - Return the response text and a log of file operations to the AJAX layer.
 *
 * The tool-use loop is synchronous (request/response, no streaming).
 * SSE streaming can be added in a future version.
 *
 * Tools available to Claude:
 *   1. list_directory  — list directory contents within public_html
 *   2. read_file       — read a file (prefers staged version over live)
 *   3. write_file      — write to .ai_staging/ (never directly to live)
 *   4. create_directory — create a directory inside .ai_staging/
 *   5. delete_file     — mark a file for deletion (applied on commit)
 */

namespace WHMCS\Module\Addon\AiSiteManager;

class ClaudeProxy
{
    /** Anthropic API endpoint. */
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /** Model ID as specified in the project requirements. */
    private const MODEL = 'claude-sonnet-4-6';

    /** API version header required by Anthropic. */
    private const API_VERSION = '2023-06-01';

    /** Maximum tokens Claude may generate per response turn. */
    private const MAX_TOKENS = 8192;

    /** Maximum tool-use loop iterations before giving up (prevents infinite loops). */
    private const MAX_TOOL_ITERATIONS = 10;

    /**
     * Maximum bytes returned by a single read_file tool call.
     * Keep this well under ~24 KB (≈6 000 tokens) so that reading a file
     * plus the existing context never blows the 30 000-token/min rate limit.
     */
    private const DEFAULT_MAX_READ_BYTES = 24576; // 24 KB

    /**
     * When the Anthropic API returns a rate-limit error (HTTP 429) we wait
     * this many seconds before retrying, doubling on each subsequent attempt.
     */
    private const RATE_LIMIT_RETRY_DELAY = 5;

    /** Maximum number of automatic retries on a 429 response. */
    private const RATE_LIMIT_MAX_RETRIES = 3;

    /**
     * Maximum number of tool-result content blocks to keep in the rolling
     * context window during a tool-use loop.  Older blocks are replaced with
     * a short "[truncated]" stub so file contents don't accumulate across
     * every iteration and inflate the token count.
     */
    private const MAX_TOOL_RESULT_BLOCKS = 4;

    /** @var string Anthropic API key. */
    private string $apiKey;

    /** @var FtpClient Connected FTP client. */
    private FtpClient $ftp;

    /** @var StagingManager Staging operations. */
    private StagingManager $staging;

    /** @var int Maximum context messages to include. */
    private int $maxContextMessages;

    /** @var int Maximum file read size in bytes. */
    private int $maxReadBytes;

    /** @var bool Tracks whether any write happened during this request. */
    private bool $stagingWasWritten = false;

    /**
     * @param string         $apiKey             Anthropic API key.
     * @param FtpClient      $ftp                An already-connected FTP client.
     * @param StagingManager $staging            Staging manager instance.
     * @param int            $maxContextMessages Max messages to include in context.
     * @param int            $maxReadBytes       Max bytes for file reads.
     */
    public function __construct(
        string         $apiKey,
        FtpClient      $ftp,
        StagingManager $staging,
        int            $maxContextMessages = 10,
        int            $maxReadBytes       = self::DEFAULT_MAX_READ_BYTES
    ) {
        $this->apiKey             = $apiKey;
        $this->ftp                = $ftp;
        $this->staging            = $staging;
        $this->maxContextMessages = $maxContextMessages;
        $this->maxReadBytes       = $maxReadBytes;
    }

    // =========================================================================
    // Public interface
    // =========================================================================

    /**
     * Send a user message to Claude and return the AI response.
     *
     * Handles the full tool-use loop: keeps calling the API until Claude
     * returns a plain text response (stop_reason = 'end_turn').
     *
     * @param  string     $userMessage    The message the customer typed in the chat.
     * @param  array      $chatHistory    Previous conversation rows from the DB.
     *                                    Each row: ['role' => 'user'|'assistant', 'message' => '...']
     * @param  string     $customerName   Customer's first name for the system prompt.
     * @param  string     $customerDomain Customer's primary domain for the system prompt.
     * @param  array|null $attachment     Optional file attachment descriptor:
     *                                    For images: ['type'=>'image','filename'=>'...','ftp_path'=>'...',
     *                                                 'ftp_note'=>'...','mime_type'=>'...','data'=>'<base64>']
     *                                    For text:   ['type'=>'text','filename'=>'...','content'=>'...']
     * @return array {
     *   'response'       => string   Claude's final text response to show in chat.
     *   'operations'     => array    Log of file operations executed.
     *   'staging_written'=> bool     True if any write/delete/mkdir occurred.
     * }
     * @throws \RuntimeException on API error or too many tool iterations.
     */
    public function chat(
        string $userMessage,
        array  $chatHistory,
        string $customerName,
        string $customerDomain,
        ?array $attachment = null
    ): array {
        $this->stagingWasWritten = false;
        $operations = [];

        // Build the messages array from stored history + new user message.
        $messages = $this->buildMessages($chatHistory, $userMessage, $attachment);

        $systemPrompt = $this->buildSystemPrompt($customerName, $customerDomain);
        $tools        = $this->getToolDefinitions();

        // Tool-use loop.
        $iterations = 0;
        while ($iterations < self::MAX_TOOL_ITERATIONS) {
            $iterations++;

            $apiResponse = $this->callApi($messages, $tools, $systemPrompt);

            if ($apiResponse['stop_reason'] === 'tool_use') {
                // Claude wants to call one or more tools.
                $assistantContent = $apiResponse['content'];

                // Append assistant's message (including tool_use blocks) to messages.
                $messages[] = ['role' => 'assistant', 'content' => $assistantContent];

                // Execute each tool call and collect results.
                $toolResults = [];
                foreach ($assistantContent as $block) {
                    if ($block['type'] !== 'tool_use') {
                        continue;
                    }

                    $toolResult = $this->executeTool(
                        $block['name'],
                        $block['input'],
                        $operations
                    );

                    $toolResults[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content'     => $toolResult,
                    ];
                }

                // Append tool results as a user message (Anthropic API convention).
                $messages[] = ['role' => 'user', 'content' => $toolResults];

                // Trim old tool_result blocks from earlier messages so that
                // large file contents don't re-inflate the input token count
                // on every subsequent loop iteration.
                $messages = $this->trimOldToolResults($messages);

            } elseif ($apiResponse['stop_reason'] === 'end_turn' || $apiResponse['stop_reason'] === 'max_tokens') {
                // Claude has produced its final text response (or was cut off at the token limit).
                $responseText = '';
                foreach ($apiResponse['content'] as $block) {
                    if ($block['type'] === 'text') {
                        $responseText .= $block['text'];
                    }
                }

                return [
                    'response'        => $responseText,
                    'operations'      => $operations,
                    'staging_written' => $this->stagingWasWritten,
                ];
            } else {
                // Truly unexpected stop reason.
                throw new \RuntimeException(
                    "Claude returned unexpected stop_reason: {$apiResponse['stop_reason']}"
                );
            }
        }

        throw new \RuntimeException(
            'Claude tool-use loop exceeded maximum iterations (' . self::MAX_TOOL_ITERATIONS . '). ' .
            'The request was too complex or encountered a loop.'
        );
    }

    // =========================================================================
    // API call
    // =========================================================================

    /**
     * Make a single call to the Anthropic Messages API.
     *
     * @param  array  $messages     Conversation messages array.
     * @param  array  $tools        Tool definitions.
     * @param  string $systemPrompt System prompt string.
     * @return array                Decoded API response body.
     * @throws \RuntimeException    on HTTP error or API-level error.
     */
    private function callApi(array $messages, array $tools, string $systemPrompt): array
    {
        // Wrap system prompt as a cacheable content block.
        // Prompt caching is GA — no beta header required.
        $systemBlock = [[
            'type'          => 'text',
            'text'          => $systemPrompt,
            'cache_control' => ['type' => 'ephemeral'],
        ]];

        // Mark the last tool as the cache boundary.
        // The cached prefix covers system + all tool definitions — easily
        // over the 1024-token minimum for claude-sonnet-4-6.
        $cachedTools = $tools;
        $cachedTools[count($cachedTools) - 1]['cache_control'] = ['type' => 'ephemeral'];

        $payload = [
            'model'      => self::MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'system'     => $systemBlock,
            'tools'      => $cachedTools,
            'messages'   => $messages,
        ];

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_TIMEOUT        => 120, // Tool loops can take time.
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        $attempt = 0;
        $delay   = self::RATE_LIMIT_RETRY_DELAY;

        while (true) {
            $ch = curl_init(self::API_URL);
            curl_setopt_array($ch, $curlOpts);

            $body  = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno) {
                throw new \RuntimeException("Anthropic API cURL error: {$error}");
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException(
                    "Anthropic API returned non-JSON response (HTTP {$code})."
                );
            }

            // Rate limit: retry with exponential backoff.
            if ($code === 429 || (isset($decoded['error']['type']) && $decoded['error']['type'] === 'rate_limit_error')) {
                $attempt++;
                if ($attempt > self::RATE_LIMIT_MAX_RETRIES) {
                    throw new \RuntimeException(
                        'The AI assistant is temporarily busy due to high demand. ' .
                        'Please wait a moment and try your request again.'
                    );
                }
                sleep($delay);
                $delay *= 2; // Exponential backoff.
                continue;
            }

            if (isset($decoded['error'])) {
                throw new \RuntimeException(
                    "Anthropic API error [{$decoded['error']['type']}]: {$decoded['error']['message']}"
                );
            }

            if ($code !== 200) {
                throw new \RuntimeException(
                    "Anthropic API returned HTTP {$code}: " . substr($body, 0, 500)
                );
            }

            return $decoded;
        }
    }

    // =========================================================================
    // Token-management helpers
    // =========================================================================

    /**
     * Reduce input-token bloat by replacing the content of older tool_result
     * messages with a short stub.
     *
     * During a multi-step tool-use loop every API call re-sends the full
     * messages array.  If Claude already read a large file in turn 1, that
     * file content gets re-transmitted on turns 2, 3, 4 … blowing through
     * the per-minute token budget fast.
     *
     * Strategy: walk backwards through $messages counting user turns whose
     * content is an array of tool_result blocks.  Keep the most recent
     * MAX_TOOL_RESULT_BLOCKS intact; replace the content of older ones with
     * a one-line "[file contents omitted to save tokens]" stub so Claude still
     * knows the tool was called but doesn't re-read the large payload.
     *
     * @param  array $messages Full messages array.
     * @return array           Messages array with old tool results stubbed out.
     */
    private function trimOldToolResults(array $messages): array
    {
        // Collect indices of user-role tool_result turns (newest first).
        $toolResultIndices = [];
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];
            if (
                ($msg['role'] ?? '') === 'user' &&
                is_array($msg['content']) &&
                isset($msg['content'][0]['type']) &&
                $msg['content'][0]['type'] === 'tool_result'
            ) {
                $toolResultIndices[] = $i;
            }
        }

        // Stub out everything beyond the most-recent MAX_TOOL_RESULT_BLOCKS.
        $toStub = array_slice($toolResultIndices, self::MAX_TOOL_RESULT_BLOCKS);
        foreach ($toStub as $idx) {
            $stubbed = [];
            foreach ($messages[$idx]['content'] as $block) {
                $stubbed[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $block['tool_use_id'],
                    'content'     => '[file contents omitted to save context tokens]',
                ];
            }
            $messages[$idx]['content'] = $stubbed;
        }

        return $messages;
    }

    // =========================================================================
    // Message construction
    // =========================================================================

    /**
     * Build the messages array from stored chat history and the new user message.
     *
     * Limits history to the last $maxContextMessages entries to stay within
     * Claude's context window and control API costs.
     *
     * If an attachment is provided, the current user turn is built as a
     * multipart content array rather than a plain string:
     *   - Images get an Anthropic vision image block + a text block.
     *   - Text files have their contents prepended to the text block.
     *
     * @param  array       $chatHistory Rows from mod_aisitemanager_chat_history.
     * @param  string      $newMessage  The current user message.
     * @param  array|null  $attachment  Attachment descriptor (see chat() docblock).
     * @return array                    Messages array ready for the API.
     */
    private function buildMessages(array $chatHistory, string $newMessage, ?array $attachment = null): array
    {
        // Trim to the most recent messages.
        $history = array_slice($chatHistory, -$this->maxContextMessages);

        $messages = [];
        foreach ($history as $row) {
            // Handle both object and array format from Capsule.
            $role    = is_object($row) ? $row->role    : $row['role'];
            $message = is_object($row) ? $row->message : $row['message'];
            $messages[] = ['role' => $role, 'content' => $message];
        }

        // Build the new user turn, incorporating any attachment.
        if ($attachment === null) {
            // Plain text message — simple string content.
            $messages[] = ['role' => 'user', 'content' => $newMessage];
        } elseif ($attachment['type'] === 'image') {
            // Image attachment: vision block + text block.
            $ftpPath = $attachment['ftp_path'] ?? '';
            $ftpNote = $attachment['ftp_note'] ?? '';

            // Build the text that accompanies the image.
            $imageContext = "The customer has attached an image file: \"{$attachment['filename']}\".";
            if ($ftpPath !== '') {
                $imageContext .= " It has been uploaded to the website at the path \"{$ftpPath}\" "
                    . "so you can reference it in HTML as \"images/{$attachment['filename']}\".";
            } else {
                $imageContext .= " Note: the image could not be uploaded to the server{$ftpNote}, "
                    . "so you cannot reference it as a URL yet — but you can see it above.";
            }
            if ($newMessage !== '') {
                $imageContext .= "\n\nCustomer's message: " . $newMessage;
            }

            $messages[] = [
                'role'    => 'user',
                'content' => [
                    [
                        'type'   => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $attachment['mime_type'],
                            'data'       => $attachment['data'],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $imageContext,
                    ],
                ],
            ];
        } else {
            // Text file attachment: prepend file contents to the message.
            $filename = $attachment['filename'] ?? 'file';
            $content  = $attachment['content']  ?? '';

            $fileContext  = "The customer has attached a text file: \"{$filename}\".\n";
            $fileContext .= "=== Contents of {$filename} ===\n";
            $fileContext .= $content;
            $fileContext .= "\n=== End of {$filename} ===";

            if ($newMessage !== '') {
                $fileContext .= "\n\nCustomer's message: " . $newMessage;
            }

            $messages[] = ['role' => 'user', 'content' => $fileContext];
        }

        return $messages;
    }

    // =========================================================================
    // System prompt
    // =========================================================================

    /**
     * Build the system prompt injected at the start of every Claude session.
     *
     * @param  string $customerName   Customer's first name.
     * @param  string $customerDomain Customer's primary domain.
     * @return string
     */
    private function buildSystemPrompt(string $customerName, string $customerDomain): string
    {
        return <<<PROMPT
You are an AI web assistant for {$customerName}'s website at {$customerDomain}, provided by WebJIVE — a Little Rock, Arkansas web design and hosting company.

## Interface layout
The customer is looking at a two-panel interface:
- LEFT panel: this chat conversation.
- RIGHT panel: a live preview iframe that always shows their website. When there are staged (uncommitted) changes, the preview shows the staged version. When there are no staged changes, the preview shows the live site.

The preview panel is ALWAYS visible and updates automatically when changes are staged. You do NOT need to do anything to make the preview show — it is already there. If the customer asks to "show the preview", "show the current page", "see the live site", or anything similar, tell them to look at the panel on the right side of the screen — the preview is already showing their site live.

NEVER say you are unable to show a visual preview or that you are text-only. The preview panel handles all visual display — just point the customer to the right side of the screen.

## Working with files
You have access to their website files through a set of tools. All changes you make go to a staging area first and are NOT live until the customer clicks Commit. Always tell the customer what you changed and remind them to click Commit when they are ready to publish.

Before writing any file, read it first so you understand the existing content and structure. Before deleting any file, confirm with the customer explicitly.

**IMPORTANT — editing existing files:** Always use `edit_file` (not `write_file`) when modifying an existing file. Read the file first, then call `edit_file` with the exact snippet to replace (`old_string`) and the replacement (`new_string`). Only use `write_file` when creating a brand-new file that does not exist yet.

## Tone and communication
Keep all responses friendly and non-technical. This customer is a small business owner, not a developer. Avoid jargon. If something will take multiple steps, tell them what you're about to do before doing it.

Never reveal file paths, server details, credentials, or any technical infrastructure details to the customer. If asked about technical details, politely redirect to what they actually want to accomplish.

When you make changes, briefly summarize what you did in plain language and remind the customer to click Commit when ready.
PROMPT;
    }

    // =========================================================================
    // Tool definitions
    // =========================================================================

    /**
     * Return the five tool definitions for the Anthropic API.
     *
     * These definitions describe the shape of each tool's input so Claude
     * knows how to call them correctly.
     *
     * @return array Tool definitions array.
     */
    private function getToolDefinitions(): array
    {
        return [
            [
                'name'         => 'list_directory',
                'description'  => 'List the files and folders inside a directory of the website. Use this to explore the site structure before making changes. The root directory contains all website files.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'Directory path relative to the website root. Use "/" or "" for the root. Example: "css" or "images/gallery".',
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name'         => 'read_file',
                'description'  => 'Read the current contents of a file on the website. Always read a file before editing it so you understand its current content.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'File path relative to the website root. Example: "index.html" or "css/style.css".',
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name'         => 'write_file',
                'description'  => 'Write content to a file on the website. Changes go to a staging area first — they are NOT live until the customer commits. Always read the file first, then write the complete updated version.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'File path relative to the website root. Example: "index.html" or "css/style.css".',
                        ],
                        'content' => [
                            'type'        => 'string',
                            'description' => 'The complete new file contents. Must include the entire file, not just the changed parts.',
                        ],
                    ],
                    'required' => ['path', 'content'],
                ],
            ],
            [
                'name'         => 'create_directory',
                'description'  => 'Create a new directory (folder) inside the website. The directory is created in the staging area.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'Directory path relative to the website root. Example: "images/new-gallery".',
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name'         => 'edit_file',
                'description'  => 'Make a targeted edit to an existing file by replacing a specific piece of text. Use this instead of write_file when editing large files — provide just the text to find and the replacement text. Always read the file first so you can copy the exact text to replace. The old_string must match exactly (including whitespace and indentation). Only the first occurrence is replaced.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'File path relative to the website root. Example: "index.html" or "css/style.css".',
                        ],
                        'old_string' => [
                            'type'        => 'string',
                            'description' => 'The exact text to find and replace. Must match the file content character-for-character, including whitespace and indentation. Include enough surrounding context (1-3 lines) to make it unique in the file.',
                        ],
                        'new_string' => [
                            'type'        => 'string',
                            'description' => 'The text to replace old_string with. Use an empty string to delete the matched text.',
                        ],
                    ],
                    'required' => ['path', 'old_string', 'new_string'],
                ],
            ],
            [
                'name'         => 'delete_file',
                'description'  => 'Mark a file for deletion. The file will not actually be removed until the customer commits. IMPORTANT: Always confirm with the customer before deleting any file.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'File path relative to the website root. Example: "old-page.html".',
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
        ];
    }

    // =========================================================================
    // Tool execution
    // =========================================================================

    /**
     * Execute a tool call from Claude and return the result string.
     *
     * All tool calls are logged to $operations for display in the chat UI.
     * Path validation happens in FtpClient and StagingManager — any traversal
     * attempt will throw an exception that becomes an error result for Claude.
     *
     * @param  string $toolName  Name of the tool Claude wants to call.
     * @param  array  $input     Tool input parameters as decoded JSON.
     * @param  array  &$ops      Reference to the operations log.
     * @return string            Result string to send back to Claude.
     */
    private function executeTool(string $toolName, array $input, array &$ops): string
    {
        try {
            switch ($toolName) {
                case 'list_directory':
                    return $this->toolListDirectory($input['path'] ?? '/', $ops);

                case 'read_file':
                    return $this->toolReadFile($input['path'] ?? '', $ops);

                case 'write_file':
                    return $this->toolWriteFile(
                        $input['path']    ?? '',
                        $input['content'] ?? '',
                        $ops
                    );

                case 'create_directory':
                    return $this->toolCreateDirectory($input['path'] ?? '', $ops);

                case 'edit_file':
                    return $this->toolEditFile(
                        $input['path']       ?? '',
                        $input['old_string'] ?? '',
                        $input['new_string'] ?? '',
                        $ops
                    );

                case 'delete_file':
                    return $this->toolDeleteFile($input['path'] ?? '', $ops);

                default:
                    return "Error: Unknown tool '{$toolName}'.";
            }
        } catch (\InvalidArgumentException $e) {
            // Path validation failure — report to Claude but don't crash.
            return "Error: Invalid path — {$e->getMessage()}";
        } catch (\OverflowException $e) {
            return "Error: File too large to read — {$e->getMessage()}";
        } catch (\RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    // -------------------------------------------------------------------------
    // Individual tool implementations
    // -------------------------------------------------------------------------

    private function toolListDirectory(string $path, array &$ops): string
    {
        $ops[] = ['type' => 'list', 'path' => $path];

        $entries = $this->ftp->listDirectory($path);

        if (empty($entries)) {
            return "The directory is empty.";
        }

        $lines = [];
        foreach ($entries as $entry) {
            $icon  = $entry['type'] === 'dir' ? '[dir]' : '[file]';
            $size  = $entry['type'] === 'file' ? " ({$entry['size']} bytes)" : '';
            $lines[] = "{$icon} {$entry['name']}{$size}";
        }

        return implode("\n", $lines);
    }

    private function toolReadFile(string $path, array &$ops): string
    {
        if (empty($path)) {
            return "Error: No file path provided.";
        }

        $ops[] = ['type' => 'read', 'path' => $path];

        $content = $this->staging->readFile($path, $this->maxReadBytes);
        return $content;
    }

    private function toolWriteFile(string $path, string $content, array &$ops): string
    {
        if (empty($path)) {
            return "Error: No file path provided.";
        }
        if ($content === '') {
            return "Error: File content cannot be empty. If you want to delete a file, use delete_file instead.";
        }

        $this->staging->writeFile($path, $content);
        $this->stagingWasWritten = true;

        $ops[] = ['type' => 'write', 'path' => $path];

        return "Successfully staged '{$path}' for writing. The change will be live after the customer commits.";
    }

    private function toolEditFile(string $path, string $oldString, string $newString, array &$ops): string
    {
        if (empty($path)) {
            return "Error: No file path provided.";
        }
        if ($oldString === '') {
            return "Error: old_string cannot be empty. Use write_file to create a new file.";
        }

        // Read the current file (prefers staged version over live).
        $current = $this->staging->readFile($path, $this->maxReadBytes);

        if (strpos($current, $oldString) === false) {
            return "Error: The text to replace was not found in '{$path}'. Make sure old_string exactly matches the file content, including whitespace and indentation. Try reading the file again to get the exact text.";
        }

        // Replace only the first occurrence.
        $pos      = strpos($current, $oldString);
        $modified = substr($current, 0, $pos) . $newString . substr($current, $pos + strlen($oldString));

        $this->staging->writeFile($path, $modified);
        $this->stagingWasWritten = true;

        $ops[] = ['type' => 'edit', 'path' => $path];

        return "Successfully edited '{$path}'. The change is staged and will be live after the customer commits.";
    }

    private function toolCreateDirectory(string $path, array &$ops): string
    {
        if (empty($path)) {
            return "Error: No directory path provided.";
        }

        $this->staging->createDirectory($path);
        $this->stagingWasWritten = true;

        $ops[] = ['type' => 'mkdir', 'path' => $path];

        return "Successfully created directory '{$path}' in staging.";
    }

    private function toolDeleteFile(string $path, array &$ops): string
    {
        if (empty($path)) {
            return "Error: No file path provided.";
        }

        $this->staging->markForDeletion($path);
        $this->stagingWasWritten = true;

        $ops[] = ['type' => 'delete', 'path' => $path];

        return "File '{$path}' is marked for deletion. It will be removed from the live site when the customer commits.";
    }
}
