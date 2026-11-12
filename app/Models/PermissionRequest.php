<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A tool call off the repository's allowlist that the agent's session asked Foreman to allow (ADR 0010). */
class PermissionRequest extends Model
{
    public const ALLOW = 'allow';

    /** Allowed, and its pattern added to the repository's allowlist. */
    public const ALWAYS = 'always';

    public const DENY = 'deny';

    /** Nobody answered before it expired. */
    public const TIMEOUT = 'timeout';

    private const NEEDS_HUMAN = " If you can't do the work without it, end with needs_human and say what you need.";

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** Save the CLI's `can_use_tool` request, when `$event` is one, for the operator to answer before it expires. */
    public static function record(Run $run, array $event): void
    {
        if (($event['type'] ?? null) !== 'control_request' || ($event['request']['subtype'] ?? null) !== 'can_use_tool') {
            return;
        }

        $minutes = $run->repository->permission_timeout_minutes;
        $request = $run->permissionRequests()->create([
            'request_id' => $event['request_id'],
            'tool_name' => $event['request']['tool_name'] ?? 'tool',
            'input' => $event['request']['input'] ?? [],
            'expires_at' => now()->addMinutes($minutes),
        ]);
        $run->log("Waiting up to {$minutes} min for you to allow: {$request->summary()}");
    }

    /**
     * Settle the request, once: whichever of the operator and the timeout comes first wins. An "always" adds
     * `$pattern` to the repository's allowlist. Gives whether this call settled it.
     */
    public function decide(string $decision, ?string $pattern = null): bool
    {
        $settled = static::whereKey($this->id)->whereNull('decision')
            ->update(['decision' => $decision, 'pattern' => $pattern, 'decided_at' => now()]) === 1;

        if ($settled && $decision === self::ALWAYS) {
            $repo = $this->run->repository;
            $tools = array_map('trim', explode(',', (string) $repo->allowed_tools));

            if (! in_array($pattern, $tools, true)) {
                $repo->update(['allowed_tools' => rtrim((string) $repo->allowed_tools, ',').",{$pattern}"]);
            }
        }

        $this->refresh();

        return $settled;
    }

    /** The call on one line: a Bash command as it is, any other tool by name and input. */
    public function summary(): string
    {
        $text = $this->tool_name === 'Bash'
            ? (string) ($this->input['command'] ?? '')
            : $this->tool_name.' '.json_encode($this->input, JSON_UNESCAPED_SLASHES);

        return (string) str($text)->squish()->limit(300);
    }

    /** An allowlist rule for calls like this one: the command's first word for Bash, the tool's name for the rest. */
    public function suggestedPattern(): string
    {
        if ($this->tool_name !== 'Bash') {
            return $this->tool_name;
        }

        $word = strtok(trim((string) ($this->input['command'] ?? '')), " \t\n");

        return $word === false ? 'Bash' : "Bash({$word}:*)";
    }

    /** The answer as a line of the CLI's stream-json input. */
    public function controlResponse(): string
    {
        return json_encode(['type' => 'control_response', 'response' => [
            'subtype' => 'success', 'request_id' => $this->request_id, 'response' => $this->response(),
        ]], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
    }

    /** The answer for the CLI's `can_use_tool` request. An "always" also allows the pattern for the rest of the session. */
    private function response(): array
    {
        return match ($this->decision) {
            self::ALLOW => ['behavior' => 'allow', 'updatedInput' => $this->input],
            self::ALWAYS => ['behavior' => 'allow', 'updatedInput' => $this->input, 'updatedPermissions' => [
                ['type' => 'addRules', 'rules' => [self::rule($this->pattern)], 'behavior' => 'allow', 'destination' => 'session'],
            ]],
            self::DENY => ['behavior' => 'deny', 'message' => 'Your operator denied this call in Foreman.'.self::NEEDS_HUMAN],
            default => ['behavior' => 'deny', 'message' => "Nobody approved this call in Foreman within {$this->timeoutMinutes()} min, so it was denied.".self::NEEDS_HUMAN],
        };
    }

    /** The run log's line for the answer. */
    public function decisionLine(): string
    {
        return match ($this->decision) {
            self::ALLOW => "You allowed: {$this->summary()}",
            self::ALWAYS => "You allowed {$this->pattern} for this repository: {$this->summary()}",
            self::DENY => "You denied: {$this->summary()}",
            default => "No answer within {$this->timeoutMinutes()} min. Denied: {$this->summary()}",
        };
    }

    private function timeoutMinutes(): int
    {
        return (int) $this->created_at->diffInMinutes($this->expires_at, true);
    }

    /** `Bash(./vault:*)` as the CLI's rule: tool name and content. */
    private static function rule(string $pattern): array
    {
        preg_match('/^([^(]+)(?:\((.*)\))?$/s', $pattern, $m);

        return isset($m[2]) ? ['toolName' => $m[1], 'ruleContent' => $m[2]] : ['toolName' => $pattern];
    }
}
