<?php

namespace Tests\Support;

use Illuminate\Process\PendingProcess;
use Symfony\Component\Process\InputStream;
use WeakMap;

/** Reads what Foreman wrote to a faked `claude` process's stdin: the prompt first, then the operator's messages. */
final class ClaudeStdin
{
    /** @var WeakMap<InputStream,list<array>>|null Reading a stream empties it, so what was read is kept. */
    private static ?WeakMap $read = null;

    /**
     * The stream-json messages written so far, in order.
     *
     * @return list<array>
     */
    public static function messages(PendingProcess $process): array
    {
        self::$read ??= new WeakMap;
        $stream = $process->input;

        // A one-shot gets its prompt as a plain string
        if (! $stream instanceof InputStream) {
            return [];
        }
        $messages = self::$read[$stream] ?? [];
        $closed = $stream->isClosed();

        // Once drained, the stream yields '' for as long as it's open
        foreach ($stream as $chunk) {
            if ($chunk === '') {
                break;
            }

            $messages[] = json_decode($chunk, true);
        }

        // Iterating reopens the stream, so it is closed again for tests that check it
        if ($closed) {
            $stream->close();
        }

        return self::$read[$stream] = $messages;
    }

    /** The agent's prompt: the first message's text. Empty for a process with no live stdin. */
    public static function prompt(PendingProcess $process): string
    {
        return self::messages($process)[0]['message']['content'] ?? '';
    }
}
